<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

error_log("[DEBUG] POST => " . print_r($_POST, true));
error_log("[DEBUG] FILES => " . print_r($_FILES, true));

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}
if (
    !isset($_POST['csrf_token']) ||
    !isset($_SESSION['csrf_token']) ||
    $_POST['csrf_token'] !== $_SESSION['csrf_token']
) {
    die("Token CSRF inválido.");
}

$division_id = isset($_POST['division_id']) ? intval($_POST['division_id']) : 0;

$estadoGestion  = isset($_POST['estadoGestion'])      ? trim($_POST['estadoGestion'])      : '';
$motivo         = isset($_POST['motivo'])             ? trim($_POST['motivo'])             : '';
$comentario     = isset($_POST['comentario'])         ? trim($_POST['comentario'])         : '';

$valores        = (isset($_POST['valor']) && is_array($_POST['valor']))                      ? $_POST['valor']                  : [];
$observaciones  = (isset($_POST['observacion']) && is_array($_POST['observacion']))          ? $_POST['observacion']            : [];
$motivoSelect   = (isset($_POST['motivoSelect']) && is_array($_POST['motivoSelect']))        ? $_POST['motivoSelect']           : [];
$motivoNoImpl   = (isset($_POST['motivoNoImplementado']) && is_array($_POST['motivoNoImplementado']))
                  ? $_POST['motivoNoImplementado'] : [];

$idCampana      = isset($_POST['idCampana'])  ? intval($_POST['idCampana'])  : 0;
$nombreCampana  = isset($_POST['nombreCampana'])  ? trim($_POST['nombreCampana'])  : '';
$idLocal        = isset($_POST['idLocal'])    ? intval($_POST['idLocal'])    : 0;

$usuario_id     = intval($_SESSION['usuario_id']);
$empresa_id     = intval($_SESSION['empresa_id']);

$latitudLocal   = isset($_POST['latitudLocal']) ? floatval($_POST['latitudLocal']) : 0.0;
$longitudLocal  = isset($_POST['longitudLocal'])? floatval($_POST['longitudLocal']) : 0.0;

$latGestion = isset($_POST['latGestion']) ? floatval($_POST['latGestion']) : 0.0;
$lngGestion = isset($_POST['lngGestion']) ? floatval($_POST['lngGestion']) : 0.0;

$errores = [];
if (empty($estadoGestion)) {
    $errores[] = "El estado de gestión es obligatorio.";
}
if (($estadoGestion === 'pendiente' || $estadoGestion === 'cancelado') && empty($motivo)) {
    $errores[] = "El motivo es obligatorio para estados Pendiente o Cancelado.";
}
if ($idCampana <= 0 || $idLocal <= 0) {
    $errores[] = "Parámetros de campaña o local inválidos.";
}
if (!empty($errores)) {
    $errorMsg = urlencode(implode(" ", $errores));
    header("Location: gestionar.php?idCampana=$idCampana&nombreCampana=" . urlencode($nombreCampana)
           . "&idLocal=$idLocal&status=error&mensaje=$errorMsg");
    exit();
}


function convertirAWebP($sourcePath, $destPath, $quality = 80) {
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick($sourcePath);
            if ($img->getNumberImages() > 1) {
                $img = $img->coalesceImages();
                foreach ($img as $frame) {
                    $frame->setImageFormat('webp');
                    $frame->setImageCompressionQuality($quality);
                }
                $img = $img->deconstructImages();
            } else {
                $img->setImageFormat('webp');
                $img->setImageCompressionQuality($quality);
            }
            $img->writeImages($destPath, true);
            $img->clear();
            $img->destroy();
            return true;
        } catch (Exception $e) {
            // fallback a GD
        }
    }

    $info = getimagesize($sourcePath);
    if (!$info) return false;
    switch ($info['mime']) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $image = imagecreatefrompng($sourcePath);
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($sourcePath);
            break;
        default:
            return false;
    }
    if (!$image) return false;
    $res = imagewebp($image, $destPath, $quality);
    imagedestroy($image);
    return (bool)$res;
}

/**
 * Sube una foto individual (específica o genérica), guardando HEIC como original y el resto a WebP.
 * Retorna array con:
 *  - 'url'  => URL pública (ej: /visibility2/app/uploads/.../archivo.webp)
 *  - 'path' => ruta absoluta en disco para posible limpieza en rollback
 */
function guardarFotoUnitaria(array $file, string $subdir, string $prefix, int $idLocal): array {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Error de subida de imagen ($prefix).");
    }
    $baseDir = __DIR__ . "/uploads/" . trim($subdir, "/") . "/";
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
        throw new Exception("No se pudo crear el directorio de imágenes ($subdir).");
    }
    $tmp      = $file['tmp_name'];
    $origName = $file['name'];
    $mime     = mime_content_type($tmp);

    if (in_array($mime, ['image/heic', 'image/heif'])) {
        $ext      = pathinfo($origName, PATHINFO_EXTENSION) ?: 'heic';
        $filename = "{$prefix}{$idLocal}_" . uniqid() . ".{$ext}";
        $destino  = $baseDir . $filename;
        if (!move_uploaded_file($tmp, $destino)) {
            throw new Exception("No se pudo guardar archivo HEIC/HEIF ($prefix).");
        }
    } else {
        $filename = "{$prefix}{$idLocal}_" . uniqid() . ".webp";
        $destino  = $baseDir . $filename;
        if (!convertirAWebP($tmp, $destino, 80)) {
            throw new Exception("No se pudo convertir la imagen a WebP ($prefix).");
        }
    }
    $url = "/visibility2/app/uploads/" . trim($subdir, "/") . "/" . $filename;
    return ['url' => $url, 'path' => $destino];
}

// ---------------------------------------------------
// 3) Subida de Fotos de MATERIALES (si las hay)
// ---------------------------------------------------
function reestructurarFiles($filePost) {
    $files = [];
    foreach ($filePost['name'] as $idFQ => $fileNames) {
        foreach ($fileNames as $idx => $name) {
            if ($filePost['error'][$idFQ][$idx] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'id_formularioQuestion' => $idFQ,
                'index'                 => $idx,
                'name'                  => $filePost['name'][$idFQ][$idx],
                'type'                  => $filePost['type'][$idFQ][$idx],
                'tmp_name'              => $filePost['tmp_name'][$idFQ][$idx],
                'error'                 => $filePost['error'][$idFQ][$idx],
                'size'                  => $filePost['size'][$idFQ][$idx]
            ];
        }
    }
    return $files;
}

function insertGestionVisita(
    mysqli $conn,
    int    $visita_id,
    int    $usuario_id,
    int    $idCampana,
    int    $idLocal,
    int    $idFQ,
    int    $idMaterial,
    string $fechaVisita,
    string $estadoGestion,
    int    $valorReal,
    string $observacion,
    string $motivoNoImpl,
    ?string $fotoUrl = null,
    float  $fotoLat = 0.0,
    float  $fotoLng = 0.0,
    float  $latGestion = 0.0,
    float  $lngGestion = 0.0
) {
    $sql = "
      INSERT INTO gestion_visita
        (visita_id, id_usuario, id_formulario, id_local, id_formularioQuestion, id_material,
         fecha_visita, estado_gestion, valor_real, observacion, motivo_no_implementacion,
         foto_url, lat_foto, lng_foto, latitud, longitud)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
      "iiiiiississsdddd",
       $visita_id,
       $usuario_id,
       $idCampana,
       $idLocal,
       $idFQ,
       $idMaterial,
       $fechaVisita,
       $estadoGestion,
       $valorReal,
       $observacion,
       $motivoNoImpl,
       $fotoUrl,
       $fotoLat,
       $fotoLng,
       $latGestion,
       $lngGestion
    );
    $stmt->execute();
    $stmt->close();
}

$archivosReestructurados = [];
if (isset($_FILES['fotos'])) {
    $archivosReestructurados = reestructurarFiles($_FILES['fotos']);
}

$imagenes = [];
$erroresSubida = [];

if (!empty($archivosReestructurados)) {
    foreach ($archivosReestructurados as $archivo) {
        $idFQ      = intval($archivo['id_formularioQuestion']);
        $idxFoto   = intval($archivo['index']);
        $name      = $archivo['name'];
        $tmpName   = $archivo['tmp_name'];
        $errorFile = $archivo['error'];
        $sizeFile  = $archivo['size'];

        if ($errorFile !== UPLOAD_ERR_OK) {
            $erroresSubida[] = "Error al subir foto '$name' (formularioQuestion=$idFQ).";
            continue;
        }
        $tipoMIME = mime_content_type($tmpName);
        if (strpos($tipoMIME, 'image/') !== 0) {
            $erroresSubida[] = "El archivo '$name' no es una imagen válida (idFQ=$idFQ).";
            continue;
        }
        $maxTamanho = 200 * 1024 * 1024;
        if ($sizeFile > $maxTamanho) {
            $erroresSubida[] = "La foto '$name' excede 200MB (idFQ=$idFQ).";
            continue;
        }

        // Obtener nombre de material desde FQ
        $sqlGetMat = "SELECT material FROM formularioQuestion WHERE id=? LIMIT 1";
        $stmtGetM = $conn->prepare($sqlGetMat);
        if (!$stmtGetM) {
            $erroresSubida[] = "Error SELECT material (idFQ=$idFQ): " . $conn->error;
            continue;
        }
        $stmtGetM->bind_param("i", $idFQ);
        $stmtGetM->execute();
        $resGetM = $stmtGetM->get_result();
        if ($rowMat = $resGetM->fetch_assoc()) {
            $matName = $rowMat['material'];
        } else {
            $erroresSubida[] = "No se encontró formularioQuestion.id=$idFQ.";
            $stmtGetM->close();
            continue;
        }
        $stmtGetM->close();

        // Resolver id de material por división
        $sqlCheckMat = "SELECT id FROM material WHERE nombre=? AND id_division=? LIMIT 1";
        $stmtCh = $conn->prepare($sqlCheckMat);
        if (!$stmtCh) {
            $erroresSubida[] = "Error SELECT material: " . $conn->error;
            continue;
        }
        $stmtCh->bind_param("si", $matName, $division_id);
        $stmtCh->execute();
        $rCh = $stmtCh->get_result();

        if ($rCh->num_rows > 0) {
            $rowExist = $rCh->fetch_assoc();
            $idMaterial = (int)$rowExist['id'];
        } else {
            $sqlInsMat = "INSERT INTO material (nombre, id_division) VALUES (?, ?)";
            $stmtIns = $conn->prepare($sqlInsMat);
            if (!$stmtIns) {
                $erroresSubida[] = "Error INSERT material: " . $conn->error;
                $stmtCh->close();
                continue;
            }
            $stmtIns->bind_param("si", $matName, $division_id);
            if (!$stmtIns->execute()) {
                $erroresSubida[] = "Error insert material '$matName': " . $stmtIns->error;
                $stmtIns->close();
                continue;
            }
            $idMaterial = $stmtIns->insert_id;
            $stmtIns->close();
        }
        $stmtCh->close();

        // Directorio por fecha y material
        $fechaHoy = date('Y-m-d');
        $dirBase  = __DIR__ . '/uploads/';
        $dirFecha = $dirBase . $fechaHoy . '/';
        $dirMat   = $dirFecha . 'material_' . $idMaterial . '/';

        if (!is_dir($dirMat) && !mkdir($dirMat, 0755, true)) {
            $erroresSubida[] = "No se pudo crear directorio para material=$idMaterial.";
            continue;
        }

        // Convertir y guardar WebP
        $uniqueWebp   = uniqid('foto_', true) . '.webp';
        $destinoWebp  = $dirMat . $uniqueWebp;
        if (!convertirAWebP($tmpName, $destinoWebp, 80)) {
            $erroresSubida[] = "Error al convertir a WebP '$name' (material=$idMaterial).";
            continue;
        }

        $rutaRel = 'uploads/' . $fechaHoy . '/material_' . $idMaterial . '/' . $uniqueWebp;
        $imagenes[] = [
            'url'   => $rutaRel,
            'idMat' => $idMaterial,
            'idFQ'  => $idFQ,
            'idx'   => $idxFoto
        ];
    }

    if (!empty($erroresSubida)) {
        $errores = array_merge($erroresSubida, $errores);
    }
}

if (!empty($errores)) {
    $errorMsg = urlencode(implode(" ", $errores));
    header("Location: gestionar.php?idCampana=$idCampana"
         . "&nombreCampana=" . urlencode($nombreCampana)
         . "&idLocal=$idLocal&status=error&mensaje=$errorMsg");
    exit();
}

// Recibir el id de la visita (obligatorio)
if (!isset($_POST['visita_id']) || intval($_POST['visita_id']) <= 0) {
    throw new Exception("Falta el identificador de visita.");
}
$visita_id = intval($_POST['visita_id']);

// ---------------------------------------------------
// 4) Iniciar Transacción y Verificar Permisos
// ---------------------------------------------------
$conn->begin_transaction();

// Llevar registro de archivos creados para limpiar si hay rollback
$stateFilesCreated = [];
$fechaVisita = date('Y-m-d H:i:s');
$obsGeneral = ""; // para evitar notices

try {
    $sqlVer = "
        SELECT COUNT(*) AS cnt
        FROM formularioQuestion
        WHERE id_formulario=? 
          AND id_local=? 
          AND id_usuario=?
    ";
    $stmtVer = $conn->prepare($sqlVer);
    if (!$stmtVer) throw new Exception("Error verificación: " . $conn->error);
    $stmtVer->bind_param("iii", $idCampana, $idLocal, $usuario_id);
    $stmtVer->execute();
    $rowVer = $stmtVer->get_result()->fetch_assoc();
    if ((int)$rowVer['cnt'] === 0) {
        throw new Exception("No tienes permisos o no existe la campaña/local.");
    }
    $stmtVer->close();

    // ---------------------------------------------------
    // 5) Actualizar Materiales / Estados
    // ---------------------------------------------------
    if ($estadoGestion === 'implementado_auditado' || $estadoGestion === 'solo_implementado' || $estadoGestion === 'solo_retirado') {
        // Actualización global
        $sqlUpdateAll = "
            UPDATE formularioQuestion
            SET fechaVisita = ?,
                countVisita = countVisita + 1,
                pregunta     = ?
            WHERE id_formulario = ?
              AND id_local      = ?
              AND id_usuario    = ?
        ";
        $stmtAll = $conn->prepare($sqlUpdateAll);
        if (!$stmtAll) throw new Exception("Error preparando actualización global: " . $conn->error);
        $stmtAll->bind_param("ssiii", $fechaVisita, $estadoGestion, $idCampana, $idLocal, $usuario_id);
        if (!$stmtAll->execute()) throw new Exception("Error actualizando registros globales: " . $stmtAll->error);
        $stmtAll->close();

        // Actualización por valor implementado
        foreach ($valores as $idFQ => $valorImp) {
            $valorImp = trim($valorImp);
            if ($valorImp === '') continue;

            if (!ctype_digit($valorImp) || intval($valorImp) <= 0) {
                throw new Exception("Valor implementado (ID=$idFQ) debe ser entero positivo.");
            }
            $valorNum = intval($valorImp);

            // Validar no exceda propuesto
            $stmtVal = $conn->prepare("SELECT valor_propuesto FROM formularioQuestion WHERE id=? LIMIT 1");
            $stmtVal->bind_param("i", $idFQ);
            $stmtVal->execute();
            $rVal = $stmtVal->get_result();
            $rowV = $rVal->fetch_assoc();
            $stmtVal->close();
            if ($rowV && $valorNum > intval($rowV['valor_propuesto'])) {
                throw new Exception("Valor ($valorNum) excede valor_propuesto ({$rowV['valor_propuesto']}) en ID=$idFQ.");
            }

            $obs = isset($observaciones[$idFQ])
                 ? htmlspecialchars(trim($observaciones[$idFQ]), ENT_QUOTES, 'UTF-8')
                 : '';
            $sqlUpMat = "
                UPDATE formularioQuestion
                SET valor      = ?,
                    motivo     = '-',
                    observacion= ?,
                    latGestion = ?,
                    lngGestion = ?
                WHERE id             = ?
                  AND id_formulario = ?
                  AND id_local      = ?
                  AND id_usuario    = ?
            ";
            $stmtUp = $conn->prepare($sqlUpMat);
            if (!$stmtUp) throw new Exception("Error preparando actualización de material (ID=$idFQ): " . $conn->error);
            $stmtUp->bind_param(
                "isddiiii",
                $valorNum,
                $obs,
                $latGestion,
                $lngGestion,
                $idFQ,
                $idCampana,
                $idLocal,
                $usuario_id
            );
            if (!$stmtUp->execute()) {
                throw new Exception("Error actualizando material (ID=$idFQ): " . $stmtUp->error);
            }
            $stmtUp->close();

            // Identificar id de material para historial
            $stmtMatFetch = $conn->prepare("
                SELECT m.id
                  FROM material m
                  JOIN formularioQuestion fq 
                    ON fq.material = m.nombre 
                   AND m.id_division = ?
                 WHERE fq.id = ?
                 LIMIT 1
            ");
            $stmtMatFetch->bind_param("ii", $division_id, $idFQ);
            $stmtMatFetch->execute();
            $resMatFetch = $stmtMatFetch->get_result();
            $idMaterial = $resMatFetch->num_rows ? intval($resMatFetch->fetch_assoc()['id']) : 0;
            $stmtMatFetch->close();

            // Historial
            insertGestionVisita(
              $conn,
              $visita_id, 
              $usuario_id,
              $idCampana,
              $idLocal,
              $idFQ,
              $idMaterial,
              $fechaVisita,
              $estadoGestion,
              $valorNum,  
              $obs,
              "",
              null, 0.0, 0.0,
              $latGestion, $lngGestion
            );
        }

        // Motivos de no implementación por material
        if (is_array($motivoSelect)) {
            foreach ($motivoSelect as $idFQ => $motivoSel) {
                if (!isset($valores[$idFQ]) || trim($valores[$idFQ]) === '') {
                    $motNImpl  = isset($motivoNoImpl[$idFQ]) ? trim($motivoNoImpl[$idFQ]) : '';
                    $obsConcat = $motivoSel . ' - ' . $motNImpl;
                    $sqlUpNo = "
                        UPDATE formularioQuestion
                        SET observacion = CONCAT(COALESCE(observacion, ''), ' ', ?)
                        WHERE id             = ?
                          AND id_formulario = ?
                          AND id_local      = ?
                          AND id_usuario    = ?
                    ";
                    $stmtNo = $conn->prepare($sqlUpNo);
                    if (!$stmtNo) throw new Exception("Error preparando actualización de no implementación (ID=$idFQ): " . $conn->error);
                    $stmtNo->bind_param("siiii", $obsConcat, $idFQ, $idCampana, $idLocal, $usuario_id);
                    if (!$stmtNo->execute()) {
                        throw new Exception("Error actualizando no implementado (ID=$idFQ): " . $stmtNo->error);
                    }
                    $stmtNo->close();

                    // Historial “no implementado”
                    $stmtMatFetch = $conn->prepare("
                        SELECT m.id
                          FROM material m
                          JOIN formularioQuestion fq 
                            ON fq.material = m.nombre 
                           AND m.id_division = ?
                         WHERE fq.id = ?
                         LIMIT 1
                    ");
                    $stmtMatFetch->bind_param("ii", $division_id, $idFQ);
                    $stmtMatFetch->execute();
                    $resMatFetch = $stmtMatFetch->get_result();
                    $idMaterial = $resMatFetch->num_rows ? intval($resMatFetch->fetch_assoc()['id']) : 0;
                    $stmtMatFetch->close();

                    insertGestionVisita(
                      $conn,
                      $visita_id, 
                      $usuario_id,
                      $idCampana,
                      $idLocal,
                      $idFQ,
                      $idMaterial, 
                      $fechaVisita,
                      'no_implementado',
                      0,
                      $obsConcat,
                      '',
                      null, 0.0, 0.0,
                      $latGestion, $lngGestion
                    );
                }
            }
        }
    }
    elseif ($estadoGestion === 'pendiente') {
        // *** SIEMPRE exigir al menos una foto (específica o genérica) ***
        // Prioridad: específica según motivo -> genérica de pendiente
        $fotoUrl = null;

        // Ensambla observación general
        $obsGeneral = $motivo;
        if ($comentario !== '') {
            $obsGeneral .= ' - ' . $comentario;
        }

        // Intentar específica según motivo
        if ($motivo === 'local_cerrado' && isset($_FILES['fotoLocalCerrado']) && $_FILES['fotoLocalCerrado']['error'] === UPLOAD_ERR_OK) {
            $up = guardarFotoUnitaria($_FILES['fotoLocalCerrado'], 'local_cerrado', 'localcerrado_', $idLocal);
            $stateFilesCreated[] = $up['path'];
            $fotoUrl = $up['url'];
            $obsGeneral .= " | Foto: " . $fotoUrl;
        } elseif ($motivo === 'local_no_existe' && isset($_FILES['fotoLocalNoExiste']) && $_FILES['fotoLocalNoExiste']['error'] === UPLOAD_ERR_OK) {
            $up = guardarFotoUnitaria($_FILES['fotoLocalNoExiste'], 'local_no_existe', 'localnoexiste_', $idLocal);
            $stateFilesCreated[] = $up['path'];
            $fotoUrl = $up['url'];
            $obsGeneral .= " | Foto: " . $fotoUrl;
        }

        // Si no hay específica válida, usar genérica de pendiente
        if (!$fotoUrl) {
            if (isset($_FILES['fotoPendienteGenerica']) && $_FILES['fotoPendienteGenerica']['error'] === UPLOAD_ERR_OK) {
                $up = guardarFotoUnitaria($_FILES['fotoPendienteGenerica'], 'pendiente_generica', 'pendiente_', $idLocal);
                $stateFilesCreated[] = $up['path'];
                $fotoUrl = $up['url'];
                $obsGeneral .= " | Foto: " . $fotoUrl;
            } else {
                throw new Exception("Debe adjuntar al menos una foto para el estado Pendiente.");
            }
        }

        $sqlPend = "
            UPDATE formularioQuestion
            SET fechaVisita = ?,
                countVisita = countVisita + 1,
                observacion = ?,
                pregunta    = 'en proceso'
            WHERE id_formulario = ?
              AND id_local      = ?
              AND id_usuario    = ?
        ";
        $stmtP = $conn->prepare($sqlPend);
        if (!$stmtP) throw new Exception("Error update pendiente: " . $conn->error);
        $stmtP->bind_param("ssiii",
            $fechaVisita,
            $obsGeneral,
            $idCampana,
            $idLocal,
            $usuario_id
        );
        if (!$stmtP->execute()) {
            throw new Exception("Error al actualizar pendiente: " . $stmtP->error);
        }
        $stmtP->close();

        // Historial
        insertGestionVisita(
          $conn,
          $visita_id, 
          $usuario_id,
          $idCampana,
          $idLocal,
          0, 0,
          $fechaVisita,
          $estadoGestion,
          0,
          $obsGeneral,
          '',
          $fotoUrl,
          0.0,
          0.0,
          $latGestion,
          $lngGestion
        );
    }
    elseif ($estadoGestion === 'cancelado') {
        // *** SIEMPRE exigir al menos una foto (específica o genérica) ***
        $estadoNum  = 2;
        $obsGeneral = $motivo;
        if ($comentario !== '') {
            $obsGeneral .= ' - ' . $comentario;
        }

        $fotoUrl = null;

        // Específica si el motivo lo requiere
        if ($motivo === 'mueble_no_esta_en_sala' && isset($_FILES['fotoMuebleNoSala']) && $_FILES['fotoMuebleNoSala']['error'] === UPLOAD_ERR_OK) {
            $up = guardarFotoUnitaria($_FILES['fotoMuebleNoSala'], 'mueble_no_existe', 'mueble_', $idLocal);
            $stateFilesCreated[] = $up['path'];
            $fotoUrl = $up['url'];
            $obsGeneral .= " | Foto Mueble: " . $fotoUrl;
        }

        // Si no hay específica válida, usar genérica de cancelado
        if (!$fotoUrl) {
            if (isset($_FILES['fotoCanceladoGenerica']) && $_FILES['fotoCanceladoGenerica']['error'] === UPLOAD_ERR_OK) {
                $up = guardarFotoUnitaria($_FILES['fotoCanceladoGenerica'], 'cancelado_generica', 'cancelado_', $idLocal);
                $stateFilesCreated[] = $up['path'];
                $fotoUrl = $up['url'];
                $obsGeneral .= " | Foto: " . $fotoUrl;
            } else {
                throw new Exception("Debe adjuntar al menos una foto para el estado Cancelado.");
            }
        }

        $sqlCan = "
            UPDATE formularioQuestion
            SET estado      = ?,
                fechaVisita = ?,
                countVisita = countVisita + 1,
                observacion = ?,
                pregunta    = 'cancelado'
            WHERE id_formulario = ?
              AND id_local      = ?
              AND id_usuario    = ?
        ";
        $stmtC = $conn->prepare($sqlCan);
        if (!$stmtC) throw new Exception("Error update cancelado: " . $conn->error);
        $stmtC->bind_param(
            "issiii",
            $estadoNum,
            $fechaVisita,
            $obsGeneral,
            $idCampana,
            $idLocal,
            $usuario_id
        );
        if (!$stmtC->execute()) {
            throw new Exception("Error al actualizar cancelado: " . $stmtC->error);
        }
        $stmtC->close();

        // Historial
        insertGestionVisita(
          $conn,
          $visita_id, 
          $usuario_id,
          $idCampana,
          $idLocal,
          0, 0,
          $fechaVisita,
          $estadoGestion,
          0,
          $obsGeneral,
          '',
          $fotoUrl,
          0.0,
          0.0,
          $latGestion,
          $lngGestion
        );
    }
    elseif ($estadoGestion === 'solo_auditoria') {
        $sqlAud = "
            UPDATE formularioQuestion
            SET fechaVisita = ?,
                countVisita = countVisita + 1,
                latGestion  = ?,
                lngGestion  = ?,
                pregunta    = 'solo_auditoria'
            WHERE id_formulario = ?
              AND id_local      = ?
              AND id_usuario    = ?
        ";
        $stmtAud = $conn->prepare($sqlAud);
        if (!$stmtAud) throw new Exception("Error update solo_auditoria: " . $conn->error);
        $stmtAud->bind_param(
            "sddiii",
            $fechaVisita,
            $latGestion,
            $lngGestion,
            $idCampana,
            $idLocal,
            $usuario_id
        );
        if (!$stmtAud->execute()) {
            throw new Exception("Error al actualizar solo_auditoria: " . $stmtAud->error);
        }
        $stmtAud->close();

        insertGestionVisita(
          $conn,
          $visita_id, 
          $usuario_id,
          $idCampana,
          $idLocal,
          0, 0,
          $fechaVisita,
          $estadoGestion,
          0,
          '', // obs
          '',
          null,
          0.0,
          0.0,
          $latGestion,
          $lngGestion
        );
    }

    // ---------------------------------------------------
    // 6) Procesar preguntas de la encuesta
    //    (implementado_auditado / solo_auditoria)
    // ---------------------------------------------------
    if ($estadoGestion === 'implementado_auditado' || $estadoGestion === 'solo_auditoria') {
        if (isset($_POST['respuesta']) && is_array($_POST['respuesta'])) {
            foreach ($_POST['respuesta'] as $id_form_question => $respValue) {
                $sqlTipo = "SELECT id_question_type FROM form_questions WHERE id=? LIMIT 1";
                $stmtT = $conn->prepare($sqlTipo);
                if (!$stmtT) {
                    throw new Exception("Error tipoPregunta: " . $conn->error);
                }
                $stmtT->bind_param("i", $id_form_question);
                $stmtT->execute();
                $stmtT->bind_result($tipoPregunta);
                if (!$stmtT->fetch()) {
                    throw new Exception("Pregunta no existe (ID=$id_form_question).");
                }
                $stmtT->close();

                if (($tipoPregunta == 1 || $tipoPregunta == 2) && intval($respValue) === 0) {
                    continue;
                }

                if ($tipoPregunta == 1 || $tipoPregunta == 2) {
                    $id_option = intval($respValue);
                    $sqlOpt = "SELECT option_text FROM form_question_options WHERE id=? AND id_form_question=? LIMIT 1";
                    $stmtO = $conn->prepare($sqlOpt);
                    if (!$stmtO) {
                        throw new Exception("Error option_text: " . $conn->error);
                    }
                    $stmtO->bind_param("ii", $id_option, $id_form_question);
                    $stmtO->execute();
                    $stmtO->bind_result($option_text);
                    if (!$stmtO->fetch()) {
                        throw new Exception("Opción inválida ($id_option) para pregunta $id_form_question.");
                    }
                    $stmtO->close();

                    $answer_text = htmlspecialchars($option_text, ENT_QUOTES, 'UTF-8');
                    $valor = 0;
                    if (isset($_POST['valorRespuesta'][$id_form_question][$id_option]) &&
                        is_numeric($_POST['valorRespuesta'][$id_form_question][$id_option])) {
                        $valor = intval($_POST['valorRespuesta'][$id_form_question][$id_option]);
                    }

                    $sqlResp = "
                      INSERT INTO form_question_responses
                      (visita_id, id_form_question, id_local, id_usuario, answer_text, id_option, created_at, valor)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ";
                    $stmtR = $conn->prepare($sqlResp);
                    if (!$stmtR) {
                        throw new Exception("Error insert resp: " . $conn->error);
                    }
                    $now = date('Y-m-d H:i:s');
                    $stmtR->bind_param("iiiisisi",
                        $visita_id,
                        $id_form_question,
                        $idLocal,
                        $usuario_id,
                        $answer_text,
                        $id_option,
                        $now,
                        $valor
                    );
                    if (!$stmtR->execute()) {
                        throw new Exception("Error al insertar respuesta (ID=$id_form_question): " . $stmtR->error);
                    }
                    $stmtR->close();

                } elseif ($tipoPregunta == 3) {
                    if (!is_array($respValue)) {
                        throw new Exception("Respuesta inválida (no array) en pregunta $id_form_question.");
                    }
                    $filtered = array_filter($respValue, function($v) {
                        return intval($v) !== 0;
                    });
                    if (empty($filtered)) {
                        continue;
                    }
                    foreach ($filtered as $optVal) {
                        $optVal = intval($optVal);
                        $sqlOpt = "SELECT option_text FROM form_question_options WHERE id=? AND id_form_question=? LIMIT 1";
                        $stmtO = $conn->prepare($sqlOpt);
                        if (!$stmtO) {
                            throw new Exception("Error option_text multiple: " . $conn->error);
                        }
                        $stmtO->bind_param("ii", $optVal, $id_form_question);
                        $stmtO->execute();
                        $stmtO->bind_result($option_text);
                        if (!$stmtO->fetch()) {
                            throw new Exception("Opción inválida ($optVal) en pregunta $id_form_question.");
                        }
                        $stmtO->close();

                        $answer_text = htmlspecialchars($option_text, ENT_QUOTES, 'UTF-8');
                        $valor = 0;
                        if (isset($_POST['valorRespuesta'][$id_form_question][$optVal]) &&
                            is_numeric($_POST['valorRespuesta'][$id_form_question][$optVal])) {
                            $valor = intval($_POST['valorRespuesta'][$id_form_question][$optVal]);
                        }

                        $sqlResp = "
                          INSERT INTO form_question_responses
                          (visita_id, id_form_question, id_local, id_usuario, answer_text, id_option, created_at, valor)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ";
                        $stmtR = $conn->prepare($sqlResp);
                        if (!$stmtR) {
                            throw new Exception("Error insert resp multiple: " . $conn->error);
                        }
                        $now = date('Y-m-d H:i:s');
                        $stmtR->bind_param("iiiisisd",
                            $visita_id,
                            $id_form_question,
                            $idLocal,
                            $usuario_id,
                            $answer_text,
                            $optVal,
                            $now,
                            $valor
                        );
                        if (!$stmtR->execute()) {
                            throw new Exception("Error insert resp multiple: " . $stmtR->error);
                        }
                        $stmtR->close();
                    }

                } elseif ($tipoPregunta == 4) {
                $respTxt = htmlspecialchars(trim((string)$respValue), ENT_QUOTES, 'UTF-8');
                if ($respTxt === '') { // si venía vacío, no guardes nada
                    continue;
                }
            
                $sqlResp = "
                  INSERT INTO form_question_responses
                  (visita_id, id_form_question, id_local, id_usuario, answer_text, id_option, created_at, valor)
                  VALUES (?, ?, ?, ?, ?, 0, ?, 0)
                ";
                $stmtR = $conn->prepare($sqlResp);
                if (!$stmtR) { throw new Exception('Error insert resp texto: ' . $conn->error); }
                $now = date('Y-m-d H:i:s');
                $stmtR->bind_param(
                    'iiiiss',
                    $visita_id,
                    $id_form_question,
                    $idLocal,
                    $usuario_id,
                    $respTxt,
                    $now
                );
                if (!$stmtR->execute()) {
                    throw new Exception('Error insert resp texto: ' . $stmtR->error);
                }
                $stmtR->close();
            
            // --- tipo 5: NÚMERO (input number) ---
            } elseif ($tipoPregunta == 5) {
                $respNumStr = trim((string)$respValue);
                if ($respNumStr === '' || !is_numeric($respNumStr)) {
                    continue; // nada que guardar si no es numérico
                }
                $respNum = (int)$respNumStr; // si “valor” es INT; usa (float) si tu columna es DECIMAL/DOUBLE
            
                $sqlResp = "
                  INSERT INTO form_question_responses
                  (visita_id, id_form_question, id_local, id_usuario, answer_text, id_option, created_at, valor)
                  VALUES (?, ?, ?, ?, ?, 0, ?, ?)
                ";
                $stmtR = $conn->prepare($sqlResp);
                if (!$stmtR) { throw new Exception('Error insert resp num: ' . $conn->error); }
                $now = date('Y-m-d H:i:s');
                $stmtR->bind_param(
                    'iiiissi',
                    $visita_id,
                    $id_form_question,
                    $idLocal,
                    $usuario_id,
                    $respNumStr, // guardo el número también como texto en answer_text
                    $now,
                    $respNum     // y en "valor" como entero
                );
                if (!$stmtR->execute()) {
                    throw new Exception('Error insert resp num: ' . $stmtR->error);
                }
                $stmtR->close();

                } elseif ($tipoPregunta == 6) {
                    $respDate = htmlspecialchars(trim($respValue), ENT_QUOTES, 'UTF-8');
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $respDate)) {
                        $respDate = '';
                    }
                    $sqlResp = "
                      INSERT INTO form_question_responses
                      (visita_id, id_form_question, id_local, id_usuario, answer_text, id_option, created_at, valor)
                      VALUES (?, ?, ?, ?, ?, 0, ?, 0)
                    ";
                    $stmtR = $conn->prepare($sqlResp);
                    if (!$stmtR) {
                        throw new Exception("Error insert resp fecha: " . $conn->error);
                    }
                    $now = date('Y-m-d H:i:s');
                    $stmtR->bind_param("iiiiss",
                        $visita_id,
                        $id_form_question,
                        $idLocal,
                        $usuario_id,
                        $respDate,
                        $now
                    );
                    if (!$stmtR->execute()) {
                        throw new Exception("Error insert resp fecha: " . $stmtR->error);
                    }
                    $stmtR->close();

                } else {
                    $respTxt = htmlspecialchars(trim($respValue), ENT_QUOTES, 'UTF-8');
                    $sqlOther = "
                      INSERT INTO form_question_responses
                      (visita_id, id_form_question, id_local, id_usuario, answer_text, id_option, created_at, valor)
                      VALUES (?, ?, ?, ?, ?, 0, ?, 0)
                    ";
                    $stmtOth = $conn->prepare($sqlOther);
                    if (!$stmtOth) {
                        throw new Exception("Error insert resp desconocida: " . $conn->error);
                    }
                    $now = date('Y-m-d H:i:s');
                    $stmtOth->bind_param("iiiiss",
                        $visita_id,
                        $id_form_question,
                        $idLocal,
                        $usuario_id,
                        $respTxt,
                        $now
                    );
                    if (!$stmtOth->execute()) {
                        throw new Exception("Error insert resp desconocida: " . $stmtOth->error);
                    }
                    $stmtOth->close();
                }
            }
        }
    }

    // ---------------------------------------------------
    // 7) Insertar Fotos de materiales en fotoVisita + historial
    // ---------------------------------------------------
    if (!empty($imagenes)) {
        $sqlFV = "
          INSERT INTO fotoVisita
            (visita_id, url, id_usuario, id_formulario, id_local, id_material, id_formularioQuestion, fotoLat, fotoLng)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmtFV = $conn->prepare($sqlFV);
        if (!$stmtFV) throw new Exception("Error insert fotoVisita: " . $conn->error);

        foreach ($imagenes as $img) {
            $urlFoto = $img['url'];  // relativo: uploads/...
            $idMat   = $img['idMat'];
            $idFQ    = $img['idFQ'];
            $idxFoto = $img['idx'];
            $latFoto = 0.0;
            $lngFoto = 0.0;
            if (isset($_POST['coordsFoto'][$idFQ][$idxFoto]['lat']) &&
                isset($_POST['coordsFoto'][$idFQ][$idxFoto]['lng'])) {
                $latFoto = floatval($_POST['coordsFoto'][$idFQ][$idxFoto]['lat']);
                $lngFoto = floatval($_POST['coordsFoto'][$idFQ][$idxFoto]['lng']);
            }

            $stmtFV->bind_param("isiiiiidd",
               $visita_id,
               $urlFoto,
               $usuario_id,
               $idCampana,
               $idLocal,
               $idMat,
               $idFQ,
               $latFoto,
               $lngFoto
            );
            if (!$stmtFV->execute()) {
                throw new Exception("Error insert fotoVisita => " . $stmtFV->error);
            }

        }
        $stmtFV->close();
    }

    // ---------------------------------------------------
    // 8) Cerrar visita y commit
    // ---------------------------------------------------
    $upd = $conn->prepare("
        UPDATE visita
           SET latitud   = ?,
               longitud  = ?,
               fecha_fin = NOW()
         WHERE id        = ?
    ");
    $upd->bind_param("ddi", $latGestion, $lngGestion, $visita_id);
    if (!$upd->execute()) {
        throw new Exception("Error actualizando visita: " . $upd->error);
    }
    $upd->close();

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();

    // Limpiar imágenes de materiales ya convertidas
    foreach ($imagenes as $img) {
        $rutaF = __DIR__ . '/' . $img['url'];
        if (file_exists($rutaF)) @unlink($rutaF);
    }
    // Limpiar las fotos de estado (pendiente/cancelado) ya creadas
    foreach ($stateFilesCreated as $p) {
        if (is_string($p) && file_exists($p)) @unlink($p);
    }

    error_log("Error en procesar_gestion.php: " . $e->getMessage());
    $msg = urlencode("Hubo un error al procesar la gestión: " . $e->getMessage());
    header("Location: gestionar.php?idCampana=$idCampana"
         . "&nombreCampana=" . urlencode($nombreCampana)
         . "&idLocal=$idLocal"
         . "&status=error"
         . "&mensaje=$msg");
    exit();
}

$_SESSION['success'] = "La gestión se subió correctamente.";
header("Location: index.php");
exit();

$conn->close();
?>
