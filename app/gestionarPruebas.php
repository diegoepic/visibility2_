<?php
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

mysqli_report(MYSQLI_REPORT_OFF);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Regla "cadena propia, si no general" de la encuesta. Vive en lib/dominio/ porque la
   comparten el pintado (acá), el guardado (procesar_gestion_pruebas.php), el formulario
   viejo (gestionar.php) y la API móvil. Ver el encabezado de ese archivo. */
require_once __DIR__ . '/lib/dominio/preguntas_cadena.php';


if (!$conn || !($conn instanceof mysqli)) {
    die("No hay conexión válida a la base de datos.");
}

$conn->set_charset("utf8mb4");

/* =========================
   Helpers DB sin get_result()
   ========================= */
function dbFetchAllAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error al preparar consulta: " . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        die("Error al ejecutar consulta: " . htmlspecialchars($err, ENT_QUOTES, 'UTF-8'));
    }
    $result = $stmt->get_result();
    $rows   = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function dbFetchOneAssoc(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $rows = dbFetchAllAssoc($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function dbFetchValue(mysqli $conn, string $sql, string $types = '', array $params = [], string $field = '') {
    $row = dbFetchOneAssoc($conn, $sql, $types, $params);
    if (!$row) return null;
    if ($field !== '') return $row[$field] ?? null;
    return reset($row);
}

function getParentQuestionId($conn, $id_dependency_option) {
    $row = dbFetchOneAssoc(
        $conn,
        "SELECT id_form_question FROM form_question_options WHERE id = ? LIMIT 1",
        "i",
        [(int)$id_dependency_option]
    );
    return $row ? (int)$row['id_form_question'] : null;
}

function obtenerOpciones($conn, $id_form_question) {
    $rows = dbFetchAllAssoc(
        $conn,
        "SELECT id, option_text, reference_image
         FROM form_question_options
         WHERE id_form_question = ?
         ORDER BY sort_order ASC",
        "i",
        [(int)$id_form_question]
    );

    $opciones = [];
    foreach ($rows as $row_opc) {
        $opciones[] = [
            'id'              => $row_opc['id'],
            'text'            => htmlspecialchars($row_opc['option_text'], ENT_QUOTES, 'UTF-8'),
            'reference_image' => $row_opc['reference_image']
        ];
    }

    return $opciones;
}

/* =========================
   Verificación campaña y local
   ========================= */
if (isset($_GET['idCampana']) && isset($_GET['nombreCampana']) && isset($_GET['idLocal'])) {
    $idCampana      = intval($_GET['idCampana']);
    $nombreCampana  = htmlspecialchars($_GET['nombreCampana'], ENT_QUOTES, 'UTF-8');
    $idLocal        = intval($_GET['idLocal']);
} else {
    die("Parámetros inválidos.");
}

$usuario_id = intval($_SESSION['usuario_id']);
$empresa_id = intval($_SESSION['empresa_id']);

$sql_validar = "
    SELECT
        f.nombre AS nombreCampanaDB,
        f.id_division AS idDivision,
        f.modalidad AS modalidadCampana,
        l.codigo AS codigoLocal,
        l.nombre AS nombreLocal,
        l.direccion AS direccionLocal,
        l.lat AS lat,
        l.lng AS lng,
        l.id_cadena AS idCadenaLocal,
        IFNULL(v.nombre_vendedor, '') AS vendedor
    FROM formularioQuestion fq
    INNER JOIN formulario AS f ON f.id = fq.id_formulario
    INNER JOIN local AS l ON l.id = fq.id_local
    LEFT JOIN vendedor AS v ON v.id = l.id_vendedor
    WHERE fq.id_formulario = ?
      AND fq.id_local = ?
      AND fq.id_usuario = ?
      AND f.id_empresa = ?
    LIMIT 1
";

$row = dbFetchOneAssoc(
    $conn,
    $sql_validar,
    "iiii",
    [$idCampana, $idLocal, $usuario_id, $empresa_id]
);

if (!$row) {
    die("No tienes permisos para gestionar esta campaña o la campaña no existe.");
}

$modalidad = $row['modalidadCampana'];
$isRetiro      = ($modalidad === 'retiro');
$actionLabel   = $isRetiro ? 'Retirar'     : 'Implementar';
$sectionLabel  = $isRetiro ? 'Retiro'      : 'Implementación';
$nombreCampanaDB = htmlspecialchars($row['nombreCampanaDB'], ENT_QUOTES, 'UTF-8');
$idDivision    = intval($row['idDivision']);
$codigoLocal   = htmlspecialchars($row['codigoLocal'] ?? '', ENT_QUOTES, 'UTF-8');
$nombreLocal   = htmlspecialchars($row['nombreLocal'], ENT_QUOTES, 'UTF-8');
$direccionLocal= htmlspecialchars($row['direccionLocal'], ENT_QUOTES, 'UTF-8');
$nombreVendedor = htmlspecialchars($row['vendedor'] ?? '', ENT_QUOTES, 'UTF-8');
$latitud       = floatval($row['lat']);
$longitud      = floatval($row['lng']);
$idCadenaLocal = intval($row['idCadenaLocal'] ?? 0);
$codigoLocalDisplay = $codigoLocal !== '' ? $codigoLocal : 'No aplica';
$nombreVendedorDisplay = $nombreVendedor !== '' ? $nombreVendedor : 'No aplica';

/* =========================
   ¿Encuesta pendiente?
   ========================= */
$sql_respuestas = "
    SELECT COUNT(*) as cnt
    FROM form_question_responses AS fqr
    INNER JOIN form_questions AS fq ON fqr.id_form_question = fq.id
    WHERE fqr.id_local = ?
      AND fqr.id_usuario = ?
      AND fq.id_formulario = ?
";

$respuestasCount = (int) dbFetchValue(
    $conn,
    $sql_respuestas,
    "iii",
    [$idLocal, $usuario_id, $idCampana],
    'cnt'
);

$encuestaPendiente = true;

/* =========================
   Preguntas
   ========================= */
$preguntas = [];
if ($encuestaPendiente) {
    /* Sets por cadena: si la cadena del local tiene preguntas propias en la campaña
       ve SOLO esas, si no ve las generales. La regla y la guarda de migración están en
       lib/dominio/preguntas_cadena.php. */
    $filtroCadena = preguntas_cadena_filtro($conn, (int)$idCampana, (int)$idCadenaLocal);
    $condCadena   = $filtroCadena['cond'];
    $tiposPreg    = $filtroCadena['tipos'];
    $paramsPreg   = $filtroCadena['params'];

    $sql_encuesta = "
      SELECT
        id AS id_form_question,
        question_text,
        id_question_type,
        id_dependency_option,
        is_valued,
        is_required
      FROM form_questions
      WHERE id_formulario = ?{$condCadena}
      ORDER BY sort_order ASC
    ";

    $preguntas = dbFetchAllAssoc(
        $conn,
        $sql_encuesta,
        $tiposPreg,
        $paramsPreg
    );
}

$parentPreguntas = [];
$conditionalPreguntas = [];
foreach ($preguntas as $preg) {
    $tipo = intval($preg['id_question_type']);
    $dep  = isset($preg['id_dependency_option']) ? intval($preg['id_dependency_option']) : 0;
    if ($dep > 0 && ($tipo === 2 || $tipo === 3)) {
        $conditionalPreguntas[] = $preg;
    } else {
        $parentPreguntas[] = $preg;
    }
}

/* =========================
   Mensajes
   ========================= */
$status  = isset($_GET['status']) ? $_GET['status'] : '';
$mensaje = isset($_GET['mensaje']) ? htmlspecialchars($_GET['mensaje'], ENT_QUOTES, 'UTF-8') : '';

/* =========================
   Materials
   ========================= */
$sql_materiales = "
    SELECT
      fq.id,
      fq.material,
      fq.categoria,
      fq.marca,
      fq.valor_propuesto,
      fq.etapa_material,
      MAX(fq.valor) AS valor,
      MAX(fq.fechaVisita) AS fechaVisita,
      MAX(fq.observacion) AS observacion,
      MAX(m.ref_image) AS ref_image,
      (
        SELECT GROUP_CONCAT(DISTINCT CONCAT('https://visibility.cl/visibility2/app/', fv2.url) SEPARATOR '||')
        FROM fotoVisita fv2
        WHERE fv2.id_formularioQuestion = fq.id AND fv2.kind = 'armado'
      ) AS urls_armado,
      (
        SELECT GROUP_CONCAT(DISTINCT CONCAT('https://visibility.cl/visibility2/app/', fv2.url) SEPARATOR '||')
        FROM fotoVisita fv2
        WHERE fv2.id_formularioQuestion = fq.id AND fv2.kind = 'entregado'
      ) AS urls_entregado,
      (
        SELECT GROUP_CONCAT(DISTINCT CONCAT('https://visibility.cl/visibility2/app/', fv2.url) SEPARATOR '||')
        FROM fotoVisita fv2
        WHERE fv2.id_formularioQuestion = fq.id AND fv2.kind = 'implementado'
      ) AS urls_implementado,
      (
        SELECT GROUP_CONCAT(DISTINCT CONCAT('https://visibility.cl/visibility2/app/', fv2.url) SEPARATOR '||')
        FROM fotoVisita fv2
        WHERE fv2.id_formularioQuestion = fq.id AND fv2.kind = 'retirado'
      ) AS urls_retirado
    FROM formularioQuestion fq
    -- El catalogo tiene UNIQUE(nombre, id_division): el mismo nombre existe en
    -- varias divisiones (STOPPER esta en REDBULL, ABL y CPW). Sin este filtro el
    -- MAX(m.ref_image) de arriba traia la foto de cualquiera de ellas -- en la
    -- practica la de quien tuviera imagen cargada -- y el ejecutor veia material
    -- ajeno. $idDivision sale de formulario.id_division, no de la sesion, para
    -- que un usuario MC abriendo una campana de otra division siga viendo bien.
    LEFT JOIN material m
           ON m.nombre = fq.material
          AND m.id_division = ?
          AND m.deleted_at IS NULL
    WHERE fq.id_local = ?
      AND fq.id_formulario = ?
      AND fq.id_usuario = ?
    GROUP BY fq.id, fq.material, fq.categoria, fq.marca, fq.valor_propuesto, fq.etapa_material
";

// El parametro del JOIN va ANTES que los del WHERE.
$rowsMateriales = dbFetchAllAssoc(
    $conn,
    $sql_materiales,
    "iiii",
    [$idDivision, $idLocal, $idCampana, $usuario_id]
);

$materiales = [];
foreach ($rowsMateriales as $mat) {
    $matNombre   = trim($mat['material']  ?? '');
    $matCategoria= trim($mat['categoria'] ?? '');
    $matMarca    = trim($mat['marca'] ?? '');
    $matLabel    = $matNombre;
    if ($matCategoria !== '') { $matLabel .= ' - ' . $matCategoria; }
    if ($matMarca !== '')     { $matLabel .= ' - ' . $matMarca; }
    $fotosPorEtapa = [];
    foreach (['armado','entregado','implementado','retirado'] as $et) {
        $raw = trim($mat['urls_' . $et] ?? '');
        $fotosPorEtapa[$et] = $raw !== '' ? array_values(array_filter(explode('||', $raw))) : [];
    }
    $materiales[] = [
        'id'              => intval($mat['id']),
        'material'        => htmlspecialchars($matNombre,    ENT_QUOTES, 'UTF-8'),
        'material_label'  => htmlspecialchars($matLabel,     ENT_QUOTES, 'UTF-8'),
        'categoria'       => htmlspecialchars($matCategoria, ENT_QUOTES, 'UTF-8'),
        'marca'           => htmlspecialchars($matMarca,     ENT_QUOTES, 'UTF-8'),
        'etapa_material'  => htmlspecialchars(trim($mat['etapa_material'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'fotos_por_etapa' => $fotosPorEtapa,
        'valor_propuesto' => htmlspecialchars($mat['valor_propuesto'] ?? '', ENT_QUOTES, 'UTF-8'),
        'valor'           => htmlspecialchars($mat['valor'] ?? '', ENT_QUOTES, 'UTF-8'),
        'fechaVisita'     => htmlspecialchars($mat['fechaVisita'] ?? '', ENT_QUOTES, 'UTF-8'),
        'observacion'     => htmlspecialchars($mat['observacion'] ?? '', ENT_QUOTES, 'UTF-8'),
        'ref_image'       => htmlspecialchars($mat['ref_image'] ?? '', ENT_QUOTES, 'UTF-8'),
    ];
}

/* =========================
   Apoyos (solo modalidad implementacion_por_etapas)
   - $ejecutores: otros usuarios activos de la empresa, para el multiselect.
   - $apoyosPorFQ: apoyos ya registrados por material (para mostrarlos al reabrir).
   ========================= */
$ejecutores  = [];
$apoyosPorFQ = [];
if (($modalidad ?? '') === 'implementacion_por_etapas') {
    $ejecutores = dbFetchAllAssoc(
        $conn,
        "SELECT id, TRIM(CONCAT(COALESCE(nombre,''),' ',COALESCE(apellido,''))) AS nombre
           FROM usuario
          WHERE id_empresa = ? AND activo = 1 AND id <> ?
          ORDER BY nombre",
        "ii",
        [$empresa_id, $usuario_id]
    );

    $rowsApoyos = dbFetchAllAssoc(
        $conn,
        "SELECT ga.id_formularioQuestion AS idFQ,
                TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellido,''))) AS nombre
           FROM gestion_apoyo ga
           INNER JOIN usuario u ON u.id = ga.id_usuario_apoyo
          WHERE ga.id_formulario = ? AND ga.id_local = ?
          GROUP BY ga.id_formularioQuestion, ga.id_usuario_apoyo, u.nombre, u.apellido
          ORDER BY nombre",
        "ii",
        [$idCampana, $idLocal]
    );
    foreach ($rowsApoyos as $ra) {
        $apoyosPorFQ[(int)$ra['idFQ']][] = htmlspecialchars(trim($ra['nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Renderiza el multiselect de apoyos para un material + los apoyos ya registrados.
 */
function renderApoyosHtml(int $idFQ, array $ejecutores, array $apoyosPorFQ): string {
    $opts = '';
    foreach ($ejecutores as $e) {
        $eid    = (int)$e['id'];
        $enombre= htmlspecialchars(trim($e['nombre'] ?? '') ?: ('Usuario ' . $eid), ENT_QUOTES, 'UTF-8');
        $opts  .= "<option value='{$eid}'>{$enombre}</option>";
    }
    $registrados = '';
    if (!empty($apoyosPorFQ[$idFQ])) {
        $registrados = "<small class='text-muted'>Apoyos registrados: " . implode(', ', $apoyosPorFQ[$idFQ]) . "</small>";
    }
    $selectHtml = $opts !== ''
        ? "<select multiple class='form-control apoyos-select' name='apoyos[{$idFQ}][]' size='4'>{$opts}</select>"
        : "<p class='form-control-static text-muted'>No hay otros ejecutores disponibles.</p>";
    return "
      <div class='form-group'>
        <label>Apoyos (otros ejecutores que ayudaron):</label>
        {$selectHtml}
        {$registrados}
      </div>";
}

/**
 * Renderiza las fotos ya registradas de un material, agrupadas y etiquetadas por etapa.
 * No incluye ningún input — es puramente informativo/histórico.
 */
function renderFotosPorEtapaHtml(array $fotosPorEtapa): string {
    $etapaLabels = ['armado' => 'Armado', 'entregado' => 'Entregado', 'implementado' => 'Implementado', 'retirado' => 'Retirado'];
    $html = '';
    foreach ($etapaLabels as $et => $label) {
        $urls = $fotosPorEtapa[$et] ?? [];
        if (empty($urls)) continue;
        $thumbs = '';
        foreach ($urls as $urlFoto) {
            $urlEsc = htmlspecialchars($urlFoto, ENT_QUOTES, 'UTF-8');
            $thumbs .= "<div class='draft-foto-thumb'><img src='{$urlEsc}' class='draft-foto-img' style='cursor:pointer' onclick=\"verImagenGrande('{$urlEsc}')\"><span class='draft-foto-badge badge-uploaded'>Subida</span></div>";
        }
        $html .= "<div style='margin-bottom:6px'><small class='text-muted'><strong>Fotos de {$label}:</strong></small><div>{$thumbs}</div></div>";
    }
    return $html;
}

$totalSteps = $encuestaPendiente ? 3 : 2;
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Gestionar Campaña - <?php echo $nombreCampanaDB; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <!-- Bootstrap / Font Awesome -->
    <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/main-responsive.css">
    <link rel="stylesheet" href="assets/plugins/select2/select2.css">
    <style>
      /* select2 3.x viene sin los sprites (select2.png / select2-spinner.gif) en este proyecto:
         se reemplazan los íconos por texto/estilos propios para que el multiselect sea usable. */
      .select2-container-multi .select2-choices { min-height: 38px; border:1px solid #ccc; border-radius:4px; padding:2px 4px; }
      .select2-container-multi .select2-choices .select2-search-choice { padding:3px 6px 3px 20px; position:relative; }
      .select2-search-choice-close { background:none !important; width:14px; height:14px; left:4px; top:5px; text-decoration:none; }
      .select2-search-choice-close:before { content:"\00d7"; position:absolute; left:0; top:-1px; color:#888; font-size:15px; line-height:15px; }
      .select2-search-choice-close:hover:before { color:#c9302c; }
      .select2-search input { background:#fff !important; }
      .select2-container .select2-choice .select2-arrow b { background:none !important; }
      .select2-container .select2-choice .select2-arrow b:before { content:"\25BC"; font-size:10px; color:#888; display:block; text-align:center; line-height:26px; }
      .select2-drop { z-index: 10060; } /* por encima de modales bootstrap */
      .select2-container { z-index: 10050; }
    </style>
    <!-- Librerias de foto servidas desde el propio servidor (assets/vendor/, version fija).
         Antes venian de cdn.jsdelivr.net y en modo avion no existian: las fotos se encolaban
         sin comprimir y sin EXIF. Van precacheadas en sw.js. Ver assets/vendor/README.md. -->
    <script src="/visibility2/app/assets/vendor/browser-image-compression.js"></script>
    <script src="/visibility2/app/assets/vendor/heic2any.min.js"></script>
    <script src="/visibility2/app/assets/vendor/exifr.umd.js"></script>
    <script>
      // Dueño de los trabajos que se encolen en esta página. offline-queue.js lo sella en
      // cada job y se niega a enviar los de otro usuario del mismo teléfono. Va ANTES de
      // cargar la cola para que el primer enqueue ya salga sellado.
      window.__USUARIO_ID = <?php echo (int)$usuario_id; ?>;
      // Mismo id, otro nombre. Lo leen gestion_offline.js y gestion_geo.js; se declara aca
      // porque era la unica linea de PHP que quedaba dentro del bloque de geolocalizacion.
      window.USER_ID = <?php echo (int)$usuario_id; ?>;

      /* Todo lo que el JavaScript de esta pagina necesita saber de PHP, en un solo sitio.
         Antes iba incrustado en medio de los dos bloques grandes de JavaScript, y era lo
         unico que impedia sacarlos a assets/js/ (T6-6). Se comprobo una por una que ninguna
         de estas variables se reasigna despues de este punto, asi que emitirlas aca da
         exactamente los mismos valores que emitirlas donde estaban. */
      window.__GESTION = {
        usuarioId:         <?php echo (int)$usuario_id; ?>,
        idLocal:           <?php echo (int)$idLocal; ?>,
        idCampana:         <?php echo (int)$idCampana; ?>,
        nombreLocal:       <?php echo json_encode($nombreLocal, JSON_UNESCAPED_UNICODE); ?>,
        nombreCampana:     <?php echo json_encode($nombreCampanaDB, JSON_UNESCAPED_UNICODE); ?>,
        direccionLocal:    <?php echo json_encode($direccionLocal, JSON_UNESCAPED_UNICODE); ?>,
        totalSteps:        <?php echo (int)$totalSteps; ?>,
        encuestaPendiente: <?php echo $encuestaPendiente ? 'true' : 'false'; ?>,
        actionLabel:       <?php echo json_encode($actionLabel, JSON_UNESCAPED_UNICODE); ?>,
        sectionLabel:      <?php echo json_encode($sectionLabel, JSON_UNESCAPED_UNICODE); ?>,
        modalidad:         <?php echo json_encode($modalidad, JSON_UNESCAPED_UNICODE); ?>,
        ejecutores:        <?php echo json_encode($ejecutores, JSON_UNESCAPED_UNICODE); ?>,
        division:          <?php echo json_encode($idDivision, JSON_UNESCAPED_UNICODE); ?>
      };
    </script>
    <!-- Offline libs -->
    <script src="/visibility2/app/assets/js/db.js"></script>
    <script src="assets/js/journal_db.js"></script>
    <script src="/visibility2/app/assets/js/v2_cache.js"></script>
    <script src="/visibility2/app/assets/js/offline-queue.js"></script>
    <script src="/visibility2/app/assets/js/gestion_draft.js"></script>
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
navigator.serviceWorker.register('/visibility2/app/sw.js', { scope: '/visibility2/app/' }).catch(()=>{});
        });
      }
    </script>

   <script>
  //  data designada para mostrar en journal
window.OfflineQueue = Object.assign({}, window.Queue, {
    enqueueJSON: (url, data, opts={}) => {
      const options = {
        type: opts.type || 'generic',
        sendCSRF: opts.sendCSRF !== false,
        id: opts.idempotencyKey || opts.id || undefined,   // <-- pasa la key
        dedupeKey: opts.dedupeKey || undefined,
        dependsOn: opts.dependsOn || undefined,
        client_guid: opts.client_guid || undefined,
        meta: opts.meta || undefined
      };
      return window.Queue.smartPost(url, data, options);
    },
    enqueueForm: (url, formEl, opts={}) =>
      window.Queue.enqueueFromForm(url, formEl, opts)
  });
  
  
  
  
</script>
    

<style>
input[type=file][id^="fotoPregunta_"] {
  position:absolute !important;
  width:1px;height:1px;padding:0;margin:-1px;border:0;overflow:hidden;clip:rect(0,0,0,0);
}
/* Controles bonitos */
.mc-file-actions{ display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
.mc-btn{
  --mc-bg: #e9eef5; --mc-border: rgba(0,0,0,.08); --mc-shadow: rgba(0,0,0,.10);
  display:inline-flex; align-items:center; gap:.5rem;
  padding:.55rem .9rem; border-radius:9999px; border:1px solid var(--mc-border);
  background: linear-gradient(180deg, var(--mc-bg), #fff);
  box-shadow: 0 2px 6px var(--mc-shadow);
  font-weight:600; letter-spacing:.2px;
  transition: transform .08s ease, box-shadow .15s ease, background .15s ease;
  cursor:pointer; user-select:none;
}
.mc-btn i{ font-size:1rem; opacity:.9 }
.mc-btn--gallery{ --mc-bg:#e6f0ff; color:#0b5ed7; }
.mc-btn--camera { --mc-bg:#e8f7ee; color:#198754; }
.mc-btn:hover{ transform: translateY(-1px); box-shadow:0 6px 16px rgba(0,0,0,.14); }
.mc-btn:active{ transform: translateY(0); box-shadow:0 2px 8px rgba(0,0,0,.18) inset; }
.mc-btn:focus-visible{ outline: none; box-shadow:0 0 0 3px rgba(13,110,253,.25), 0 2px 6px rgba(0,0,0,.10); }
.mc-btn[disabled]{ opacity:.6; cursor:not-allowed; transform:none; }
.mc-btn.is-loading{ position:relative; pointer-events:none; }
.mc-btn.is-loading::after{
  content:""; width:1em; height:1em; border-radius:50%;
  border:2px solid currentColor; border-right-color:transparent;
  animation: mc-spin .7s linear infinite; margin-left:.25rem;
}
@keyframes mc-spin{ to{ transform:rotate(360deg); } }

/* Toasts */
.mc-toasts { position: fixed; top: 16px; right: 16px; z-index: 1060; pointer-events: none; }
.mc-toast { min-width: 280px; max-width: 360px; margin-bottom: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.15); border-radius: 6px; pointer-events: all; }
.mc-toast .close { float: right; font-size: 18px; line-height: 1; opacity: .6; }
.mc-toast .close:hover { opacity: .9; }

/* A) Preview fotos encuesta */
[id^="previewFoto_"] { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
[id^="previewFoto_"] .upload-instance { display: flex; flex-direction: column; align-items: center; width: 100px; }
[id^="previewFoto_"] .upload-instance img.thumbnail { width: 100px; height: 100px; object-fit: cover; border: 1px solid #ccc; border-radius: 4px; margin-top: 4px; }
[id^="previewFoto_"] .upload-instance .upload-bar { width: 0%; height: 4px; background: #4caf50; transition: width 0.2s ease; border-radius: 2px; }
[id^="previewFoto_"] .upload-instance .upload-bar.error { background: #f44336; }
[id^="previewFoto_"] .upload-instance img.thumbnail:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
[id^="previewFoto_"] .upload-instance .alert { width: 100px; padding: 4px 6px; font-size: 0.85rem; text-align: center; margin-top: 4px; box-sizing: border-box; }

/* B) Preview fotos materiales */
.preview-container { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.preview-container .upload-instance{ display:flex; flex-direction:column; align-items:center; width:100px; }
.preview-container .upload-instance img.thumbnail{ width:100px; height:100px; object-fit:cover; border:1px solid #ccc; border-radius:4px; margin-top:4px; }
.preview-container .upload-instance .upload-bar{ width:0%; height:4px; background:#4caf50; transition:width .2s ease; border-radius:2px; }
.preview-container .upload-instance .upload-bar.error{ background:#f44336; }
.preview-container .upload-instance img.thumbnail:hover{ box-shadow:0 2px 8px rgba(0,0,0,.2); }
.preview-container .upload-instance .alert{ width:100px; padding:4px 6px; font-size:.85rem; text-align:center; margin-top:4px; box-sizing:border-box; }

/* Otros */
.form-group { border: 1px solid #d0d7de; border-radius: 8px; padding: 12px; background: #fff; }
.modal-img { width: 300px!important; height: 500px!important; }
.upload-bar { width:0%; height:4px; background:#4caf50; margin:4px 0; transition:width .2s ease; }
.upload-bar.error { background:#f44336; }
.progress { margin-bottom: 20px; }
.progress-bar-striped { background-color: #337ab7; }
.wizard-step { display: none; }
.question-container { margin-bottom: 15px; }
.conditional-question { display: none; }
.img-container { position: relative; display: inline-block; margin: 5px; }
.delete-button { position: absolute; top: 5px; right: 5px; background-color: red; color: white; border: none; border-radius: 50%; width: 20px; height: 20px; cursor: pointer; }
.thumbnail { max-width: 100px; max-height: 100px; }
.photo-source { margin-bottom: 5px; width: 60%; max-width: 200px; }
.coords-foto { display: none; }
.modal-img { width: 100%; height: auto; }

/* Geolocalización obligatoria */
.geo-blocker{
  position: fixed; inset: 0;
  background: rgba(0,0,0,.65);
  z-index: 99999;
  display: none;
  align-items: center; justify-content: center;
}
.geo-card{
  background:#fff; border-radius:8px; padding:20px;
  max-width: 440px; width: 92%;
  box-shadow: 0 10px 24px rgba(0,0,0,.25);
  text-align:center;
}
.geo-card h4{ margin-top:0; font-weight:700 }
#geoErrorMsg{ margin-top:.5rem }
.gps-badge{
  display:inline-block; margin-left:10px; padding:2px 8px;
  border-radius:16px; font-size:.85rem;
  background:#f8d7da; color:#721c24;
}
.gps-badge.ok{ background:#d4edda; color:#155724; }
/* ── Thumbnails de draft (fotos ya subidas / pendientes) ─────────── */
.draft-foto-thumb{
  position:relative;display:inline-block;margin:4px;vertical-align:top;
}
.draft-foto-img{
  width:80px;height:80px;object-fit:cover;border-radius:4px;
  border:2px solid #ccc;display:block;
}
.draft-foto-badge{
  display:block;font-size:10px;text-align:center;
  padding:1px 4px;border-radius:2px;margin-top:2px;
}
.badge-uploaded{ background:#d4edda;color:#155724; }
.badge-pending{  background:#fff3cd;color:#856404; }
.badge-uploading{background:#cce5ff;color:#004085; }
.badge-error{    background:#f8d7da;color:#721c24; }
.draft-foto-del-btn{
  position:absolute;top:2px;right:2px;background:red;color:#fff;
  border:none;border-radius:50%;width:18px;height:18px;
  font-size:12px;line-height:16px;cursor:pointer;padding:0;
}
/* ── Indicador de guardado ────────────────────────────────────────── */
.draft-save-saved   { border-color:#28a745!important;color:#155724!important; }
.draft-save-saving  { border-color:#007bff!important;color:#004085!important; }
.draft-save-pending { border-color:#ffc107!important;color:#856404!important; }
.draft-save-error   { border-color:#dc3545!important;color:#721c24!important; }
</style>
</head>
<body>
    
    <!-- navbar -->
    <div class="navbar navbar-inverse navbar-fixed-top">
       <div class="container">
          <div class="navbar-header">
             <button data-target=".navbar-collapse" data-toggle="collapse" class="navbar-toggle" type="button">
               <span class="icon-bar"></span>
               <span class="icon-bar"></span>
               <span class="icon-bar"></span>
             </button>
             <a class="navbar-brand" href="#">VISIBILITY 2</a>
          </div>
          <div class="navbar-tools">
             <div class="nickname">
                <?php echo htmlspecialchars($_SESSION['usuario_nombre'].' '.$_SESSION['usuario_apellido'], ENT_QUOTES, 'UTF-8'); ?>
                <span id="geoStatusBadge" class="gps-badge">GPS requerido</span>
             </div>
             <ul class="nav navbar-right">
                <li class="dropdown current-user">
                   <a data-toggle="dropdown" data-hover="dropdown" class="dropdown-toggle" data-close-others="true" href="#">
                     <i class="fa fa-chevron-down"></i>
                   </a>
                   <ul class="dropdown-menu">
                      <li><a href="perfil.php"><i class="fa fa-user"></i> &nbsp;Perfil</a></li>
                      <li><a href="logout.php"><i class="fa fa-sign-out"></i> &nbsp;Cerrar sesión</a></li>
                   </ul>
                </li>
             </ul>
          </div>
       </div>
    </div>

    <div class="main-container" style="margin-top:70px;">
       <div class="main-content">
          <div class="container">
             <div class="row">
                <div class="col-sm-12">
                   <div class="page-header">
                        <h2 style="font-size: 1.5rem;">
                        Gestionar Campaña: <strong><?php echo $nombreCampanaDB; ?></strong><br>
                        en Local: <strong><?php echo $codigoLocalDisplay; ?> - <?php echo $nombreLocal; ?></strong>
                      </h2>
                      <p>Dirección: <strong><?php echo $direccionLocal; ?></strong></p>
                      <p>Vendedor: <strong><?php echo $nombreVendedorDisplay; ?></strong></p>
                   </div>
                </div>
             </div>

             <!-- Mensajes -->
             <?php
             if ($status === 'success') {
                 echo '<div class="alert alert-success">La gestión se ha realizado correctamente.</div>';
             } elseif ($status === 'error') {
                 if (!empty($mensaje)) {
                     echo '<div class="alert alert-danger">' . $mensaje . '</div>';
                 } else {
                     echo '<div class="alert alert-danger">Hubo un error al realizar la gestión. Por favor, intenta nuevamente.</div>';
                 }
             }
             ?>

             <!-- Progreso -->
             <div class="progress">
               <div class="progress-bar progress-bar-striped active" id="wizardProgress"
                    role="progressbar" aria-valuenow="33" aria-valuemin="0" aria-valuemax="100"
                    style="width: <?php echo (100 / $totalSteps); ?>%;">
                  Paso 1 de <?php echo $totalSteps; ?>
               </div>
             </div>

             <!-- FORM -->
             <form id="gestionarForm" method="post" action="/visibility2/app/procesar_gestion_pruebas.php" enctype="multipart/form-data">
                 <!-- Hidden -->
                 <input type="hidden" id="visita_id" name="visita_id" value="">
                 <input type="hidden" id="latGestion" name="latGestion" value="">
                 <input type="hidden" id="lngGestion" name="lngGestion" value="">
                 <!-- Offline/Idempotencia -->
                 <input type="hidden" id="client_guid" name="client_guid" value="">
                 <input type="hidden" id="idemp_create" value="">

                 <!-- Banner de recuperación de gestión (oculto por defecto, se muestra via JS) -->
                 <div id="gestion-recovery-banner" style="display:none;"
                      class="alert alert-info alert-dismissible" role="alert">
                   <strong>Gestión recuperada automáticamente.</strong>
                   <span id="recovery-fotos-msg"></span>
                   <span id="recovery-inputs-msg"></span>
                 </div>

                 <!-- Indicador de autoguardado (oculto por defecto) -->
                 <div id="draft-save-indicator" style="display:none; align-items:center; gap:6px;
                      font-size:12px; color:#555; padding:4px 8px; background:#f8f8f8;
                      border:1px solid #ddd; border-radius:4px; margin-bottom:8px;">
                   <span id="draft-save-text">Guardado localmente</span>
                 </div>

                 <!-- Paso 1 -->
                 <div class="wizard-step" id="step-1">
                     <h4>Paso 1: Estado de la Gestión</h4>
                     <div class="form-group">
                       <label for="estadoGestion">1. Estado Gestión:</label>
                       <select class="form-control" id="estadoGestion" name="estadoGestion" required>
                         <option value="" disabled selected>Seleccione una opción</option>
                         <?php if ($modalidad === 'implementacion_auditoria'): ?>
                           <option value="implementado_auditado">Implementado y Auditado</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php elseif ($modalidad === 'solo_implementacion'): ?>
                           <option value="solo_implementado">Solo Implementado</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php elseif ($modalidad === 'retiro'): ?>
                           <option value="solo_retirado">Solo Retirado</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php elseif ($modalidad === 'solo_auditoria'): ?>
                           <option value="solo_auditoria">Solo Auditoría</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php elseif ($modalidad === 'implementacion_por_etapas'): ?>
                           <option value="gestion_material">Gestionar materiales (por etapa)</option>
                           <option value="pendiente">Pendiente</option>
                           <option value="cancelado">Cancelado</option>
                         <?php endif; ?>
                       </select>
                     </div>
                     <div class="form-group" id="motivoContainer" style="display:none;">
                       <label for="motivo">2. Motivo:</label>
                       <select class="form-control" id="motivo" name="motivo">
                         <option value="" disabled selected>Seleccione una opción</option>
                       </select>
                     </div>
                     <div class="form-group" id="otroTipoExhibicionContainer" style="display:none;">
                       <label for="otroTipoExhibicionDetalle">Especifica el tipo de exhibición: <span class="text-danger">*</span></label>
                       <input type="text" class="form-control" id="otroTipoExhibicionDetalle"
                              name="otroTipoExhibicionDetalle"
                              placeholder="Ej: exhibidor de competencia, góndola especial...">
                     </div>
                     <br>
                    <button type="button" class="btn btn-primary" id="btnNext1" >Siguiente &raquo;</button>
                    <a href="index_pruebas.php">&laquo; Volver al inicio</a>
                 </div>

                 <!-- Paso 2 -->
                 <div class="wizard-step" id="step-2">
                     <h4 id="tituloPaso2">Paso 2</h4>
                     <div id="materialesContainer" style="display:none;">
                        <label>Materiales y Valores:</label><br>
                        <?php
                        if (count($materiales) === 1 && isset($materiales[0]['material']) && $materiales[0]['material'] === '-') {
                            echo "<p id='no-materiales-msg'>No hay materiales cargados. Use el botón <strong>Agregar Material</strong> para añadir nuevos materiales.</p>";
                        } elseif (!empty($materiales)) {
                            foreach ($materiales as $m) {
                                $idFQ       = $m['id'];
                                $matName    = ucfirst(mb_strtolower($m['material_label'], 'UTF-8'));
                                $valorProp  = $m['valor_propuesto'];
                                $valorAct   = $m['valor'];
                                $fechaImp   = $m['fechaVisita'];
                                $refImage   = $m['ref_image'] ?? '';
                                $imgTag = "";
                                if (!empty($refImage)) {
                                    $imgTag = "<img src='$refImage' style='max-width:50px; max-height:50px; cursor:pointer;' onclick=\"verImagenGrande('$refImage')\" title='Ver imagen completa'>";
                                }

                                if ($modalidad === 'implementacion_por_etapas') {
                                    $etapaActual = $m['etapa_material'] ?? '';
                                    $etapaLabels = ['armado' => 'Armado', 'entregado' => 'Entregado', 'implementado' => 'Implementado', 'retirado' => 'Retirado'];
                                    $fotosPorEtapaHtml = renderFotosPorEtapaHtml($m['fotos_por_etapa']);
                                    $apoyosHtml        = renderApoyosHtml($idFQ, $ejecutores, $apoyosPorFQ);

                                    $valorActNum  = (int)$valorAct;
                                    $valorPropNum = (int)$valorProp;
                                    // Parcial = implementado pero todavía por debajo del propuesto: sigue editable/pendiente.
                                    $esParcial  = ($etapaActual === 'implementado' && $valorActNum < $valorPropNum);
                                    $esTerminal = ($etapaActual === 'retirado')
                                               || ($etapaActual === 'implementado' && !$esParcial);

                                    if ($esTerminal) {
                                        $fechaF = $fechaImp ? date('d-m-Y H:i', strtotime($fechaImp)) : '';
                                        echo "
                                        <div class='form-group'>
                                          <label>{$matName} {$imgTag} (Propuesto: {$valorProp}) — <span class='label label-success'>{$etapaLabels[$etapaActual]}</span></label>
                                          <p class='form-control-static'>Valor: {$valorAct}</p>
                                          <p class='form-control-static'>Fecha: {$fechaF}</p>
                                          <p class='form-control-static'>Observación: {$m['observacion']}</p>
                                          {$fotosPorEtapaHtml}
                                        </div>";
                                    } else {
                                        $etapaLabelActual    = $etapaActual !== '' ? $etapaLabels[$etapaActual] : 'Sin acción';
                                        $fotosPorEtapaCounts = array_map('count', $m['fotos_por_etapa']);
                                        $fotosPorEtapaJson   = htmlspecialchars(json_encode($fotosPorEtapaCounts), ENT_QUOTES, 'UTF-8');
                                        $parcialFlag         = $esParcial ? '1' : '0';
                                        $faltan              = max(0, $valorPropNum - $valorActNum);
                                        $selArm  = ($etapaActual === 'armado')      ? ' selected' : '';
                                        $selEnt  = ($etapaActual === 'entregado')   ? ' selected' : '';
                                        $selImpl = ($etapaActual === 'implementado')? ' selected' : '';

                                        $headerLabel = $esParcial
                                          ? "{$matName} {$imgTag} (Propuesto: {$valorProp}) — <span class='label label-warning'>Implementado parcial: {$valorActNum} de {$valorPropNum}</span>"
                                          : "{$matName} {$imgTag} (Propuesto: {$valorProp}) — Etapa actual: <span class='label label-default'>{$etapaLabelActual}</span>";

                                        echo "
                                        <div class='form-group'>
                                          <label>{$headerLabel}</label>
                                          <select class='form-control etapa-material-select'
                                                  data-id-material='{$idFQ}'
                                                  data-etapa-original='{$etapaActual}'
                                                  data-parcial='{$parcialFlag}'
                                                  data-valor-actual='{$valorActNum}'
                                                  data-valor-propuesto='{$valorPropNum}'
                                                  data-fotos-por-etapa='{$fotosPorEtapaJson}'
                                                  name='etapaMaterial[{$idFQ}]'>
                                            <option value=''>Sin acción (no tocar)</option>
                                            <option value='armado'{$selArm}>Armado</option>
                                            <option value='entregado'{$selEnt}>Entregado</option>
                                            <option value='implementado'{$selImpl}>Implementado</option>
                                            <option value='retirado'>Retirado</option>
                                          </select>
                                        </div>";

                                        if ($esParcial) {
                                            $fechaF = $fechaImp ? date('d-m-Y H:i', strtotime($fechaImp)) : '';
                                            echo "
                                            <div class='alert alert-warning' style='margin-left:20px;'>
                                              <strong>Implementado:</strong> {$valorActNum} de {$valorPropNum} — <strong>faltan {$faltan}</strong>.
                                              <br><small>Última gestión: {$fechaF}</small>
                                            </div>";
                                        }

                                        if ($fotosPorEtapaHtml !== '') {
                                            echo "
                                            <div class='form-group' style='padding-left:20px;'>
                                              <small class='text-muted'><i class='fa fa-info-circle'></i> Fotos ya registradas para este material:</small>
                                              {$fotosPorEtapaHtml}
                                            </div>";
                                        }

                                        // Para parcial: la sección queda visible y habilitada desde el inicio (para sumar más).
                                        $sectionStyle    = $esParcial ? 'display:block; padding-left:20px;' : 'display:none; padding-left:20px;';
                                        $disabledAttr    = $esParcial ? '' : 'disabled';
                                        $valorGroupStyle = $esParcial ? 'display:block;' : 'display:none;';

                                        echo "
                                        <div class='etapa-material-section'
                                             id='etapa_material_section_{$idFQ}'
                                             style='{$sectionStyle}'>
                                          <div class='form-group etapa-valor-group' id='etapa_valor_group_{$idFQ}' style='{$valorGroupStyle}'>
                                            <label>Cantidad (en esta visita):</label>
                                            <input type='number'
                                                   class='form-control valor-implementado'
                                                   name='valor[{$idFQ}]'
                                                   min='1'
                                                   placeholder='Cantidad implementada en esta visita'
                                                   data-valor-propuesto='{$valorPropNum}'
                                                   {$disabledAttr}>
                                          </div>
                                          <div class='form-group motivo-parcial-group' id='motivo_parcial_group_{$idFQ}' style='display:none;'>
                                            <label>Motivo de implementación parcial:</label>
                                            <select class='form-control motivo-parcial' name='motivoParcial[{$idFQ}]' {$disabledAttr}>
                                              <option value=''>Seleccione un motivo</option>
                                              <option value='No permitieron implementar todo'>No permitieron implementar todo</option>
                                              <option value='Material pendiente queda en bodega'>Material pendiente queda en bodega</option>
                                            </select>
                                          </div>
                                          <div class='form-group'>
                                            <label>Origen de la foto:</label>
                                            <select class='photo-source form-control'
                                                    data-target-input='fotos_input_{$idFQ}'
                                                    {$disabledAttr}>
                                              <option value='gallery' selected>Elegir de la Galería</option>
                                              <option value='camera'>Tomar Foto</option>
                                            </select>
                                          </div>
                                          <button type='button'
                                                  class='btn btn-xs btn-default etapa-adjuntar-otra-btn'
                                                  id='btn_adjuntar_otra_{$idFQ}'
                                                  data-id-material='{$idFQ}'
                                                  style='display:none; margin-bottom:8px;'>
                                            <i class='fa fa-camera'></i> Adjuntar otra foto (opcional)
                                          </button>
                                          <div class='form-group' id='foto_upload_wrap_{$idFQ}'>
                                            <label>Fotos (hasta 10):</label>
                                            <input type='file'
                                                   accept='image/*'
                                                   name='fotos[{$idFQ}][]'
                                                   multiple
                                                   class='form-control file-input'
                                                   {$disabledAttr}
                                                   id='fotos_input_{$idFQ}'>
                                            <div id='previewContainer_{$idFQ}'></div>
                                          </div>
                                          {$apoyosHtml}
                                          <div class='form-group'>
                                             <label>Observación:</label>
                                            <textarea class='form-control'
                                                      name='observacion[{$idFQ}]'
                                                      {$disabledAttr}
                                                      placeholder='Observación...'></textarea>
                                          </div>
                                        </div>";
                                    }
                                    continue;
                                }

                                if ($valorAct !== '0' && $valorAct !== null && $valorAct !== '') {
                                    $fechaF = date('d-m-Y H:i', strtotime($fechaImp));
                                    echo "
                                    <div class='form-group'>
                                      <label>{$matName} {$imgTag} (Propuesto: {$valorProp})</label>
                                      <p class='form-control-static'>Valor Implementado: {$valorAct}</p>
                                      <p class='form-control-static'>Fecha: {$fechaF}</p>
                                      <p class='form-control-static'>Observación: {$m['observacion']}</p>
                                    </div>";
                                } else {
                                    echo "
                                    <div class='form-group'>
                                      <div class='checkbox'>
                                        <label>
                                          <input type='checkbox'
                                                 class='implementa-material'
                                                 data-id-material='{$idFQ}'>
                                          {$actionLabel} {$matName} {$imgTag} (Valor Propuesto: {$valorProp})
                                        </label>
                                      </div>
                                    </div>";

                                    echo "
                                    <div class='implementa-section'
                                         id='implementa_section_{$idFQ}'
                                         style='display:none; padding-left:20px;'>
                                      <div class='form-group'>
                                        <label>Valor {$actionLabel}:</label>
                                        <input type='number'
                                               class='form-control valor-implementado'
                                               name='valor[{$idFQ}]'
                                               placeholder='Ingrese el valor'
                                               data-valor-propuesto='{$valorProp}'
                                               disabled>
                                      </div>
                                      <div class='form-group'>
                                        <label>Origen de la foto:</label>
                                        <select class='photo-source form-control'
                                                data-target-input='fotos_input_{$idFQ}'
                                                disabled>
                                          <option value='gallery' selected>Elegir de la Galería</option>
                                          <option value='camera'>Tomar Foto</option>
                                        </select>
                                      </div>
                                      <div class='form-group'>
                                        <label>Fotos (hasta 10):</label>
                                        <input type='file'
                                               accept='image/*'
                                               name='fotos[{$idFQ}][]'
                                               multiple
                                               class='form-control file-input'
                                               disabled
                                               id='fotos_input_{$idFQ}'>
                                        <div id='previewContainer_{$idFQ}'></div>
                                      </div>
                                      <div class='form-group'>
                                         <label>Observación {$sectionLabel}:</label>
                                        <textarea class='form-control'
                                                  name='observacion[{$idFQ}]'
                                                  placeholder='Observación...'></textarea>
                                      </div>
                                    </div>";

                                    echo "
                                    <div class='no-implementa-section'
                                         id='no_implementa_section_{$idFQ}'
                                         style='display:block; padding-left:20px;'>
                                      <div class='form-group'>
                                        <label>Motivo de NO {$sectionLabel}:</label>
                                        <select class='form-control' name='motivoSelect[{$idFQ}]'>
                                          <option value='No, no permitieron'>No, no permitieron</option>
                                          <option value='No, hay otro tipo de exhibicion'>No, hay otro tipo de exhibicion</option>
                                          <option value='No, sin productos'>No, sin productos</option>
                                          <option value='No, no ha llegado el material'>No, no ha llegado el material</option>
                                          <option value='Sala en remodelación'>Sala en remodelación</option>
                                          <option value='No permite por robo'>No permite por robo</option>
                                          <option value='Sin bajada de la central'>Sin bajada de la central</option>
                                          <option value='Ya hay mueble adicional'>Ya hay mueble adicional</option>
                                          <option value='No llegó el material de implementación completo'>No llegó el material de implementación completo</option>
                                          <option value='El mueble no se encuentra en la sala'>El mueble no se encuentra en la sala</option>
                                          <option value=' Material entregado'> Material entregado</option>
                                        </select>
                                      </div>
                                      <div class='form-group'>
                                        <label>Detalle adicional:</label>
                                        <textarea class='form-control'
                                                  name='motivoNoImplementado[{$idFQ}]'
                                                  placeholder='Explique brevemente...'></textarea>
                                      </div>
                                    </div>";
                                }
                            }
                        } else {
                            echo "<p>No se encontraron materiales para este local/campaña.</p>";
                        }
                        ?>
                    </div>
                     <button type="button" class="btn btn-success" id="btnAgregarMaterial" style="display:none;">Agregar Material</button>
                     
                     <div class="form-group" id="comentarioContainer" style="display:none;">
                       <label for="comentario">Comentarios Generales:</label>
                       <textarea class="form-control" id="comentario" name="comentario"
                                 placeholder="Ingrese sus comentarios" required ></textarea>
                     </div>

                    <!-- Evidencias específicas/genéricas -->
                    <div class="form-group" id="fotoLocalCerradoContainer" style="display: none;">
                        <label for="fotoLocalCerrado">Subir foto de fachada (Local Cerrado):</label>
                        <input type="file" class="form-control" id="fotoLocalCerrado" name="fotoLocalCerrado" accept="image/*">
                        <div id="evPreview_fotoLocalCerrado" style="display:none; margin-top:8px;"></div>
                    </div>
                    <div class="form-group" id="fotoLocalNoExisteContainer" style="display: none;">
                        <label for="fotoLocalNoExiste">Subir foto del lugar (Local no existe):</label>
                        <input type="file" class="form-control" id="fotoLocalNoExiste" name="fotoLocalNoExiste" accept="image/*">
                        <div id="evPreview_fotoLocalNoExiste" style="display:none; margin-top:8px;"></div>
                    </div>
                    <div class="form-group" id="fotoMuebleNoSalaContainer" style="display: none;">
                      <label for="fotoMuebleNoSala">Subir foto del mueble (no está en la sala):</label>
                      <input type="file"
                             class="form-control"
                             id="fotoMuebleNoSala"
                             name="fotoMuebleNoSala"
                             accept="image/*">
                      <div id="evPreview_fotoMuebleNoSala" style="display:none; margin-top:8px;"></div>
                    </div>
                    <div class="form-group" id="fotoPendienteGenericaContainer" style="display: none;">
                      <label for="fotoPendienteGenerica">Subir foto (Evidencia estado Pendiente):</label>
                      <input type="file" class="form-control" id="fotoPendienteGenerica" name="fotoPendienteGenerica" accept="image/*">
                      <div id="evPreview_fotoPendienteGenerica" style="display:none; margin-top:8px;"></div>
                    </div>
                    <div class="form-group" id="fotoCanceladoGenericaContainer" style="display: none;">
                      <label for="fotoCanceladoGenerica">Subir foto (Evidencia estado Cancelado):</label>
                      <input type="file" class="form-control" id="fotoCanceladoGenerica" name="fotoCanceladoGenerica" accept="image/*">
                      <div id="evPreview_fotoCanceladoGenerica" style="display:none; margin-top:8px;"></div>
                    </div>

                     <br>
                     <button type="button" class="btn btn-default" id="btnBack1">&laquo; Anterior</button>
                     <?php if ($encuestaPendiente): ?>
                         <button type="button" class="btn btn-primary" id="btnNext2">&raquo; Siguiente</button>
                     <?php else: ?>
                         <button type="button" class="btn btn-success" id="btnFinalizar">Finalizar Gestión</button>
                     <?php endif; ?>
                 </div>

                 <!-- Paso 3 -->
                 <?php if ($encuestaPendiente): ?>
                 <div class="wizard-step" id="step-3">
                    <h4>Paso 3: Responder la Encuesta</h4>
                    <?php if (!empty($preguntas)): ?>
                        <?php foreach ($preguntas as $preg): 
                            $q_id            = intval($preg['id_form_question']);
                            $questionText    = htmlspecialchars($preg['question_text'], ENT_QUOTES, 'UTF-8');
                            $tipo            = intval($preg['id_question_type']);
                            $dependencyOption= isset($preg['id_dependency_option']) ? intval($preg['id_dependency_option']) : 0;
                            $isValued        = isset($preg['is_valued']) ? intval($preg['is_valued']) : 0;
                            $isRequired      = intval($preg['is_required']); 
                            $conditionalClass = "";
                            $conditionalAttr  = "";
                            if ($dependencyOption > 0) {
                                $parentId = getParentQuestionId($conn, $dependencyOption);
                                if ($parentId) {
                                    $conditionalClass = " conditional-question";
                                    $conditionalAttr  = ' data-dependency-option="' . $dependencyOption . '" data-parent-question="' . $parentId . '" style="display:none;"';
                                }
                            }
                        ?>
                            <div class="form-group question-container<?php echo $conditionalClass; ?>"
                                 data-question-id="<?php echo $q_id; ?>"
                                 data-question-type="<?php echo (int)$tipo; ?>"
                                 data-question-text="<?php echo $questionText; ?>"
                                 data-required="<?php echo $isRequired; ?>"
                                 <?php echo $conditionalAttr; ?>>
                                <label>
                                    <?php echo $questionText; ?>
                                    <span style="font-style: italic; color: #999;"> <?php echo ($isRequired==1 ? "*obl" : "*opc"); ?></span>
                                </label>
                                <?php
                                if ($tipo === 1 || $tipo === 2) {
                                    $opciones = obtenerOpciones($conn, $q_id);
                                    if (!empty($opciones)) {
                                        foreach ($opciones as $opcion) {
                                            $optId   = $opcion['id'];
                                            $optText = $opcion['text'];
                                            $refImg  = $opcion['reference_image'];
                                            $fullUrl = (!empty($refImg)) ? 'https://visibility.cl/' . ltrim($refImg, '/') : '';
                                            echo "<div style='margin-bottom:5px;'>";
                                            echo "  <label style='margin-right:10px;'>";
                                            echo "    <input type='radio' name='respuesta[$q_id]' value='$optId' " . ($isRequired==1 ? "required" : "") . ($isValued===1 ? " data-valued='1'" : "") . "> $optText";
                                            echo "  </label>";
                                            if ($isValued === 1) {
                                                echo " <input type='number' name='valorRespuesta[$q_id][$optId]' placeholder='Valor' style='width:70px; margin-left:10px;' class='valor-alternativa'>";
                                            }
                                            if (!empty($refImg)) {
                                                echo "  <img src='$fullUrl' alt='Ref' style='max-width:50px; max-height:50px; cursor:pointer;' onclick=\"verImagenGrande('$fullUrl')\" title='Haz clic para agrandar'>";
                                            }
                                            echo "</div>";
                                        }
                                    } else {
                                        echo "<p>No hay opciones disponibles para esta pregunta.</p>";
                                    }
                                } elseif ($tipo === 3) {
                                    $opciones = obtenerOpciones($conn, $q_id);
                                    if (!empty($opciones)) {
                                        foreach ($opciones as $opcion) {
                                            $optId   = $opcion['id'];
                                            $optText = $opcion['text'];
                                            $refImg  = $opcion['reference_image'];
                                            $fullUrl = (!empty($refImg)) ? 'https://visibility.cl/' . ltrim($refImg, '/') : '';
                                            echo "<div style='margin-bottom:5px;'>";
                                            echo "  <label style='margin-right:10px;'>";
                                            echo "    <input type='checkbox' name='respuesta[$q_id][]' value='$optId'" . ($isValued===1 ? " data-valued='1'" : "") . "> $optText";
                                            echo "  </label>";
                                            if ($isValued === 1) {
                                                echo " <input type='number' name='valorRespuesta[$q_id][$optId]' placeholder='Valor' style='width:70px; margin-left:10px;' class='valor-alternativa'>";
                                            }
                                            if (!empty($refImg)) {
                                                echo "  <img src='$fullUrl' alt='Ref' style='max-width:50px; max-height:50px; cursor:pointer;' onclick=\"verImagenGrande('$fullUrl')\" title='Haz clic para agrandar'>";
                                            }
                                            echo "</div>";
                                        }
                                    } else {
                                        echo "<p>No hay opciones disponibles para esta pregunta.</p>";
                                    }
                                } elseif ($tipo === 4) {
                                    echo "<input type='text' class='form-control' name='respuesta[$q_id]' " . ($isRequired==1 ? "required" : "") . ">";
                                } elseif ($tipo === 5) {
                                    echo "<input type='number' class='form-control' name='respuesta[$q_id]' " . ($isRequired==1 ? "required" : "") . ">";
                                } elseif ($tipo === 6) {
                                    echo "<input type='date' class='form-control' name='respuesta[$q_id]' value='' " . ($isRequired==1 ? "required" : "") . ">";
                                } elseif ($tipo === 7) {
                                    ?>
                                    <div class="form-group">
                                        <input type="file" id="fotoPregunta_<?php echo $q_id; ?>" accept="image/*">
                                        <div class="mc-file-actions">
                                          <button type="button" class="mc-btn mc-btn--gallery" onclick="chooseFromGallery(<?php echo $q_id; ?>)">
                                            <i class="fa fa-image"></i> Elegir desde galería
                                          </button>
                                          <button type="button" class="mc-btn mc-btn--camera" onclick="takeWithCamera(<?php echo $q_id; ?>)">
                                            <i class="fa fa-camera"></i> Tomar foto
                                          </button>
                                        </div>
                                        <div id="previewFoto_<?php echo $q_id; ?>" style="margin-top:10px;"></div>
                                        <input type="hidden" id="flagFoto_<?php echo $q_id; ?>" value="" data-question="7" data-idquestion="<?php echo $q_id; ?>">
                                    </div>
                                    <?php
                                } else {
                                    echo "<input type='text' class='form-control' name='respuesta[$q_id]' " . ($isRequired==1 ? "required" : "") . ">";
                                }
                                ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>No hay preguntas pendientes.</p>
                    <?php endif; ?>
                    <button type="button" class="btn btn-default" id="btnBack2">&laquo; Anterior</button>
                    <button type="button" class="btn btn-success" id="btnFinalizar">Finalizar Gestión</button>
                 </div>
                 <?php endif; ?>

                 <!-- Inputs ocultos fijos -->
                 <input type="hidden" name="idCampana" value="<?php echo $idCampana; ?>">
                 <input type="hidden" name="nombreCampana" value="<?php echo htmlspecialchars($nombreCampanaDB, ENT_QUOTES, 'UTF-8'); ?>">
                 <input type="hidden" name="idLocal" value="<?php echo $idLocal; ?>">
                 <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                 <input type="hidden" name="latitudLocal" value="<?php echo $latitud; ?>">
                 <input type="hidden" name="longitudLocal" value="<?php echo $longitud; ?>">
                 <input type="hidden" name="division_id" value="<?php echo $idDivision; ?>">
             </form>
          </div>
       </div>
    </div>

    <div class="footer clearfix">
       <div class="footer-inner">
          2024 &copy; Visibility 2 por Mentecreativa.
       </div>
       <div class="footer-items">
          <span class="go-top"><i class='fa fa-chevron-up'></i></span>
       </div>
    </div>

    <!-- MODAL IMG -->
    <div class="modal fade" id="modalImagenGrande" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document" style="max-width:90vw;">
        <div class="modal-content">
          <div class="modal-body" style="text-align:center;">
            <img id="imagenAmpliada" src="" class="modal-img" alt="Imagen Referencia">
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Agregar Material -->
    <div class="modal fade" id="modalAgregarMaterial" tabindex="-1" role="dialog">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form id="formAgregarMaterial">
            <div class="modal-header">
              <h5 class="modal-title">Agregar Material</h5>
            </div>
            <div class="modal-body">
              <div class="form-group">
                <label for="nombreMaterial">Seleccione Material:</label>
                <select class="form-control" id="nombreMaterial" name="nombreMaterial" required>
                  <option value="" disabled selected>-- Elegir --</option>
                    <?php
                    $materialesCatalogo = dbFetchAllAssoc(
                        $conn,
                        "SELECT id, nombre, ref_image FROM material WHERE id_division = ? ORDER BY nombre",
                        "i",
                        [$idDivision]
                    );
                    
                    foreach ($materialesCatalogo as $rowM) {
                        $matName  = htmlspecialchars($rowM['nombre'], ENT_QUOTES, 'UTF-8');
                        $refImage = htmlspecialchars($rowM['ref_image'], ENT_QUOTES, 'UTF-8');
                        echo '<option value="'.$matName.'" data-ref="'.$refImage.'">'.$matName.'</option>';
                    }
                    ?>
                </select>
              </div>
              <div class="form-group" id="refImageContainer" style="display:none;">
                  <label>Imagen de Referencia:</label>
                  <img id="refPreview" src="" alt="Imagen de Referencia" style="max-width:200px; max-height:200px; margin-top:10px;">
              </div>
              <div class="form-group">
                <label for="valorImplementado">Valor a <?php echo htmlspecialchars($actionLabel, ENT_QUOTES,'UTF-8'); ?> (0 a 9):</label>
                <input type="number" class="form-control" id="valorImplementado" name="valorImplementado" min="0" max="9" required>
              </div>
              <input type="hidden" name="idCampana" value="<?php echo $idCampana; ?>">
              <input type="hidden" name="idLocal" value="<?php echo $idLocal; ?>">
              <input type="hidden" name="division_id" value="<?php echo $idDivision; ?>">
              
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary">Agregar</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Overlays -->
    <div id="loadingOverlay" style="
        display: none;
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); z-index: 9999; text-align: center;">
      <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
          <div class="spinner-border text-light" role="status">
            <span class="sr-only">Cargando..</span>
          </div>
          <p class="text-light mt-2">Procesando, por favor espere...</p>
      </div>
    </div>

    <!-- Geolocal blocker -->
    <div id="geoBlocker" class="geo-blocker">
      <div class="geo-card">
        <h4>Necesitamos tu ubicación</h4>
        <p>Para gestionar este local debes <strong>activar el GPS</strong> y <strong>conceder el permiso de ubicación</strong>.</p>
        <button id="geoRetry" class="btn btn-primary" type="button">Intentar nuevamente</button>
        <p id="geoErrorMsg" class="small text-muted"></p>
      </div>
    </div>

    <div id="mcToasts" class="mc-toasts" aria-live="polite" aria-atomic="true"></div>

    <div class="modal fade" id="mcConfirmModal" tabindex="-1" role="dialog" aria-labelledby="mcConfirmTitle">
      <div class="modal-dialog" role="document" style="max-width:420px;">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
              <span aria-hidden="true">&times;</span>
            </button>
            <h4 class="modal-title" id="mcConfirmTitle">Confirmar</h4>
          </div>
          <div class="modal-body" id="mcConfirmMessage">
            ¿Seguro que desea continuar?
          </div>
          <div class="modal-footer">
            <button type="button" id="mcConfirmCancel" class="btn btn-default" data-dismiss="modal">Cancelar</button>
            <button type="button" id="mcConfirmOk" class="btn btn-danger">Sí, eliminar</button>
          </div>
        </div>
      </div>
    </div>

    <!-- jQuery & Bootstrap -->
<script src="assets/plugins/jquery/jquery-3.6.0.min.js"></script>

    <script src="assets/plugins/bootstrap/js/bootstrap.min.js"></script>
    <script src="assets/plugins/select2/select2.min.js"></script>
    <script src="assets/plugins/select2/select2_locale_es.js"></script>

    <!-- Helpers Geolocalización -->
    <script src="assets/js/gestion_geo.js"></script>

    <!-- Offline + UI helpers -->
    
    
    <script src="assets/js/gestion_offline.js"></script>

    <!-- Lógica principal -->
    <script src="assets/js/gestion_form.js"></script>
    
    <!-- Google Maps API  -->
    <script async defer
      src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap&libraries=geometry"
      onerror="alert('Error al cargar Google Maps. Verifica tu conexión o la clave de API.')">
    </script>
    

    
</body>
</html>
<?php
$conn->close();
?>
