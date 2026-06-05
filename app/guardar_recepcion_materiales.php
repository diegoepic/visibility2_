<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);

if (session_status() === PHP_SESSION_NONE) session_start();

/* ── CSRF ── */
if (
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    http_response_code(419);
    echo json_encode(['status' => 'error', 'message' => 'CSRF inválido o ausente']);
    exit();
}

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Sesión no iniciada']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método inválido']);
    exit();
}

require_once __DIR__ . '/con_.php';

date_default_timezone_set('America/Santiago');

$usuario_id    = intval($_SESSION['usuario_id']);
$empresa_id    = intval($_SESSION['empresa_id']);
$id_formulario = intval($_POST['id_formulario'] ?? 0);

if ($id_formulario <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Campaña no especificada']);
    exit();
}

/* ── Verificar campaña ── */
$stmtF = $conn->prepare("
    SELECT f.id
    FROM formulario f
    WHERE f.id         = ?
      AND f.id_empresa = ?
      AND f.tipo       = 1
      AND f.modalidad  IN ('implementacion_auditoria','solo_implementacion')
      AND f.deleted_at IS NULL
    LIMIT 1
");
$stmtF->bind_param('ii', $id_formulario, $empresa_id);
$stmtF->execute();
$ok = $stmtF->get_result()->fetch_assoc();
$stmtF->close();
if (!$ok) {
    echo json_encode(['status' => 'error', 'message' => 'Campaña no válida o sin acceso']);
    exit();
}

/* ── Verificar que el ejecutor tiene locales asignados ── */
$stmtPerm = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM formularioQuestion
    WHERE id_formulario = ? AND id_usuario = ?
    LIMIT 1
");
$stmtPerm->bind_param('ii', $id_formulario, $usuario_id);
$stmtPerm->execute();
$perm = $stmtPerm->get_result()->fetch_assoc();
$stmtPerm->close();
if ((int)$perm['cnt'] === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Sin locales asignados en esta campaña']);
    exit();
}

/* ── Validar materiales recibidos ── */
$idsFQ         = array_map('intval',   (array)($_POST['id_formularioQuestion'] ?? []));
$idsMat        = array_map('intval',   (array)($_POST['id_material']           ?? []));
$nombresMat    = (array)($_POST['nombre_material']    ?? []);
$cantPropuesta = (array)($_POST['cantidad_propuesta'] ?? []);
$cantRecibida  = (array)($_POST['cantidad_recibida']  ?? []);

$nMat = count($nombresMat);
if ($nMat === 0) {
    echo json_encode(['status' => 'error', 'message' => 'No se enviaron materiales']);
    exit();
}

/* Al menos un material debe tener cantidad > 0 */
$algunaPos = false;
for ($i = 0; $i < $nMat; $i++) {
    if (floatval($cantRecibida[$i] ?? 0) > 0) {
        $algunaPos = true;
        break;
    }
}
if (!$algunaPos) {
    echo json_encode(['status' => 'error', 'message' => 'Debes ingresar cantidad recibida para al menos un material']);
    exit();
}

/* ── Foto guía de despacho ── */
if (!isset($_FILES['foto_guia']) || $_FILES['foto_guia']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'La foto de la guía de despacho es obligatoria']);
    exit();
}

$tmpFoto  = $_FILES['foto_guia']['tmp_name'];
$origName = $_FILES['foto_guia']['name'];
$sizeFile = $_FILES['foto_guia']['size'];

$mimeType  = @mime_content_type($tmpFoto);
$ext       = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$looksImg  = (strpos((string)$mimeType, 'image/') === 0) || in_array($ext, ['heic', 'heif']);
if (!$looksImg) {
    echo json_encode(['status' => 'error', 'message' => 'El archivo no es una imagen válida']);
    exit();
}
if ($sizeFile > 5 * 1024 * 1024) {
    echo json_encode(['status' => 'error', 'message' => 'La imagen excede 5 MB']);
    exit();
}

/* ── Convertir a WebP y guardar ── */
function convertToWebP_guia($srcPath, $dstPath, $maxDim = 1280, $quality = 80): bool
{
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($srcPath);
            if ($img->getNumberImages() > 1) {
                $img = $img->coalesceImages();
                $img->setIteratorIndex(0);
            }
            if (method_exists($img, 'autoOrient')) $img->autoOrient();
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if ($w > $maxDim || $h > $maxDim) {
                if ($w >= $h) $img->resizeImage($maxDim, 0, Imagick::FILTER_LANCZOS, 1, true);
                else          $img->resizeImage(0, $maxDim, Imagick::FILTER_LANCZOS, 1, true);
            }
            $img->setImageFormat('webp');
            $img->setImageCompressionQuality($quality);
            $img->writeImage($dstPath);
            $img->clear();
            $img->destroy();
            return true;
        } catch (Exception $e) {
            // continúa con fallback GD
        }
    }

    $info = @getimagesize($srcPath);
    $mime = $info ? $info['mime'] : '';

    switch ($mime) {
        case 'image/jpeg':
            $im = @imagecreatefromjpeg($srcPath);
            if ($im && function_exists('exif_read_data')) {
                $exif = @exif_read_data($srcPath);
                if (!empty($exif['Orientation'])) {
                    if ($exif['Orientation'] == 3) $im = imagerotate($im, 180, 0);
                    elseif ($exif['Orientation'] == 6) $im = imagerotate($im, -90, 0);
                    elseif ($exif['Orientation'] == 8) $im = imagerotate($im, 90, 0);
                }
            }
            break;
        case 'image/png':
            $im = @imagecreatefrompng($srcPath);
            if ($im) { imagepalettetotruecolor($im); imagealphablending($im, true); imagesavealpha($im, true); }
            break;
        case 'image/webp':
            $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false;
            if ($im === false) return copy($srcPath, $dstPath);
            break;
        default:
            return false;
    }

    if (!$im) return false;

    $w = imagesx($im);
    $h = imagesy($im);
    if ($w > $maxDim || $h > $maxDim) {
        if ($w >= $h) { $nW = $maxDim; $nH = (int)($h * $maxDim / $w); }
        else          { $nH = $maxDim; $nW = (int)($w * $maxDim / $h); }
        $dst2 = imagecreatetruecolor($nW, $nH);
        imagealphablending($dst2, false);
        imagesavealpha($dst2, true);
        imagecopyresampled($dst2, $im, 0, 0, 0, 0, $nW, $nH, $w, $h);
        imagedestroy($im);
        $im = $dst2;
    }

    $ok = function_exists('imagewebp') ? imagewebp($im, $dstPath, $quality) : imagejpeg($im, $dstPath, 85);
    imagedestroy($im);
    return (bool)$ok;
}

$dirGuia = __DIR__ . '/uploads/recepcion_materiales/';
if (!is_dir($dirGuia)) mkdir($dirGuia, 0755, true);

$uniqueName = uniqid('guia_', true) . '.webp';
$destino    = $dirGuia . $uniqueName;

if (!convertToWebP_guia($tmpFoto, $destino)) {
    echo json_encode(['status' => 'error', 'message' => 'No se pudo procesar la imagen de la guía']);
    exit();
}
@chmod($destino, 0644);

$urlGuia = "/visibility2/app/uploads/recepcion_materiales/{$uniqueName}";

/* ── Validar y sanitizar campos del formulario ── */
$numero_guia = trim($_POST['numero_guia'] ?? '');
$observacion = trim($_POST['observacion'] ?? '');

if ($numero_guia === '') {
    if (file_exists($destino)) @unlink($destino);
    echo json_encode(['status' => 'error', 'message' => 'El número de guía de despacho es obligatorio']);
    exit();
}

/* fecha_recepcion: validar formato y que no sea futura */
$fecha_recepcion_raw = trim($_POST['fecha_recepcion'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_recepcion_raw)) {
    if (file_exists($destino)) @unlink($destino);
    echo json_encode(['status' => 'error', 'message' => 'Fecha de recepción inválida']);
    exit();
}
$fecha_recepcion = $fecha_recepcion_raw;
if ($fecha_recepcion > date('Y-m-d')) {
    if (file_exists($destino)) @unlink($destino);
    echo json_encode(['status' => 'error', 'message' => 'La fecha de recepción no puede ser futura']);
    exit();
}

if ($observacion === '') $observacion = null;

$conn->begin_transaction();
try {
    $stmtRec = $conn->prepare("
        INSERT INTO material_recepcion
            (id_formulario, id_usuario, id_empresa, fecha_recepcion, numero_guia, foto_guia_url, observacion)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtRec->bind_param('iiissss', $id_formulario, $usuario_id, $empresa_id, $fecha_recepcion, $numero_guia, $urlGuia, $observacion);
    $stmtRec->execute();
    $id_recepcion = (int)$stmtRec->insert_id;
    $stmtRec->close();

    $stmtDet = $conn->prepare("
        INSERT INTO material_recepcion_detalle
            (id_recepcion, id_formularioQuestion, id_material, nombre_material, cantidad_propuesta, cantidad_recibida)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    for ($i = 0; $i < $nMat; $i++) {
        $idFQ      = $idsFQ[$i]  > 0 ? $idsFQ[$i]  : null;
        $idM       = $idsMat[$i] > 0 ? $idsMat[$i] : null;
        $nombre    = substr(trim((string)($nombresMat[$i] ?? '')), 0, 200);
        $propuesta = max(0.0, (float)($cantPropuesta[$i] ?? 0));
        $recibida  = max(0.0, (float)($cantRecibida[$i]  ?? 0));

        if ($nombre === '') continue;

        $stmtDet->bind_param('iiisdd', $id_recepcion, $idFQ, $idM, $nombre, $propuesta, $recibida);
        $stmtDet->execute();
    }
    $stmtDet->close();

    $conn->commit();

    echo json_encode([
        'status'       => 'success',
        'message'      => 'Recepción registrada correctamente',
        'id_recepcion' => $id_recepcion,
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    if (file_exists($destino)) @unlink($destino);
    error_log('guardar_recepcion_materiales error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error interno al guardar. Intenta de nuevo.']);
}
exit();
