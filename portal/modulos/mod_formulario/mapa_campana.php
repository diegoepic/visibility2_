<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// 1) Verificar sesión
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit(); }

// --- helpers ---
function estado_label($s){
  static $map = [
    'implementado_auditado' => 'Implementación + Encuesta',
    'solo_implementado'     => 'Solo implementación',
    'solo_auditoria'        => 'Encuesta (auditoría)',
    'solo_retirado'         => 'Retiro',
    'entregado'             => 'Entrega',
    'en proceso'            => 'En proceso',
    'cancelado'             => 'Cancelado',
  ];
  return $map[$s] ?? $s;
}

// 2) Obtener ID de campaña y empresa
$idCampana  = intval($_GET['id'] ?? 0);
$empresa_id = intval($_SESSION['empresa_id'] ?? 0);
if ($idCampana <= 0 || $empresa_id <= 0) { http_response_code(400); exit('Parámetros inválidos'); }

// 2.a) Filtros y paginación
$filterCodigo   = trim($_GET['filter_codigo']  ?? '');
$filterEstado   = trim($_GET['filter_estado']  ?? '');           // estado agregado o 'sin_datos'
$filterUserId   = isset($_GET['filter_usuario_id']) && ctype_digit($_GET['filter_usuario_id']) ? (int)$_GET['filter_usuario_id'] : 0;
$filterDesdeRaw = trim($_GET['fdesde'] ?? '');
$filterHastaRaw = trim($_GET['fhasta'] ?? '');

$filterDesde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDesdeRaw) ? $filterDesdeRaw . ' 00:00:00' : null;
$filterHasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterHastaRaw) ? $filterHastaRaw . ' 23:59:59' : null;

$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// 2.b) Nombre de campaña (seguridad multiempresa)
$campanaNombre = null;
$stmt = $conn->prepare("
  SELECT COALESCE(f.nombre, CONCAT('Campaña #', f.id)) AS campana
  FROM formulario f
  WHERE f.id = ? AND f.id_empresa = ?
  LIMIT 1
");
$stmt->bind_param("ii", $idCampana, $empresa_id);
$stmt->execute();
$stmt->bind_result($campanaNombre);
$stmt->fetch();
$stmt->close();
if (!$campanaNombre) $campanaNombre = 'Campaña #'.$idCampana;

// 2.c) Opciones de usuario (dropdown): todos los que tienen gestiones en esta campaña
$usuarios = [];
$stU = $conn->prepare("
  SELECT DISTINCT u.id, COALESCE(NULLIF(TRIM(u.usuario),''), CONCAT('user#',u.id)) AS usuario,
         TRIM(CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,''))) AS nombre
  FROM gestion_visita gv
  JOIN usuario u ON u.id = gv.id_usuario
  JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
  WHERE gv.id_formulario = ?
  ORDER BY usuario ASC
");
$stU->bind_param("ii", $empresa_id, $idCampana);
$stU->execute();
$resU = $stU->get_result();
while($r = $resU->fetch_assoc()){ $usuarios[] = $r; }
$stU->close();

// 2.d) Estados disponibles: solo los que EXISTEN en esta campaña (+ 'sin_datos')
$estadosDisponibles = [];
$stE = $conn->prepare("
  SELECT DISTINCT gv.estado_gestion
  FROM gestion_visita gv
  JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
  WHERE gv.id_formulario = ?
    AND gv.estado_gestion IS NOT NULL
    AND TRIM(gv.estado_gestion) <> ''
  ORDER BY gv.estado_gestion ASC
");
$stE->bind_param("ii", $empresa_id, $idCampana);
$stE->execute();
$resE = $stE->get_result();
while($r = $resE->fetch_assoc()){ $estadosDisponibles[] = $r['estado_gestion']; }
$stE->close();
$allowedEstados = array_values(array_unique($estadosDisponibles));
$allowedEstados[] = 'sin_datos';
if ($filterEstado !== '' && !in_array($filterEstado, $allowedEstados, true)) {
  $filterEstado = '';
}

// 3) Construcción de universo base (última gestión + estado agregado)
$baseSql = "
  SELECT
    l.id AS idLocal,
    MIN(l.codigo) AS cod_min,
    MAX(fq.is_priority) AS is_priority,
    last.fecha_visita AS last_fecha,
    CASE
      WHEN agg.has_impl_aud = 1 THEN 'implementado_auditado'
      WHEN agg.has_impl_any = 1 THEN 'solo_implementado'
      WHEN agg.has_audit    = 1 THEN 'solo_auditoria'
      ELSE last.estado_gestion
    END AS estado_agg
  FROM formularioQuestion fq
  JOIN local      l ON l.id = fq.id_local
  JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?
  /* última gestión por local, sin IN(): clave máxima por local */
  LEFT JOIN (
    SELECT gv2.*
    FROM gestion_visita gv2
    JOIN (
      SELECT id_local,
             MAX(CONCAT(DATE_FORMAT(fecha_visita,'%Y%m%d%H%i%s'), LPAD(id,10,'0'))) AS max_key
      FROM gestion_visita
      WHERE id_formulario = ?
      GROUP BY id_local
    ) s ON s.id_local = gv2.id_local
       AND CONCAT(DATE_FORMAT(gv2.fecha_visita,'%Y%m%d%H%i%s'), LPAD(gv2.id,10,'0')) = s.max_key
    WHERE gv2.id_formulario = ?
  ) last ON last.id_local = l.id
  /* agregado de estados por local */
  LEFT JOIN (
    SELECT id_local,
           MAX(estado_gestion = 'implementado_auditado')                        AS has_impl_aud,
           MAX(estado_gestion IN ('solo_implementado','implementado_auditado')) AS has_impl_any,
           MAX(estado_gestion = 'solo_auditoria')                               AS has_audit
    FROM gestion_visita
    WHERE id_formulario = ?
    GROUP BY id_local
  ) agg ON agg.id_local = l.id
  WHERE fq.id_formulario = ?
";

$baseParams = [$empresa_id, $idCampana, $idCampana, $idCampana, $idCampana];

$baseTypes  = "iiiii";

if ($filterCodigo !== '') {
  $baseSql     .= " AND l.codigo LIKE ? ";
  $baseParams[] = "%{$filterCodigo}%";
  $baseTypes   .= "s";
}
if ($filterUserId > 0) {

  $baseSql     .= " AND EXISTS (
    SELECT 1 FROM gestion_visita gx
    WHERE gx.id_formulario = ? AND gx.id_local = l.id AND gx.id_usuario = ?
  )";
  $baseParams[] = $idCampana;  $baseTypes .= "i";
  $baseParams[] = $filterUserId; $baseTypes .= "i";
}

$baseSql .= " GROUP BY l.id ";

// --- Contar aplicando filtros de capa externa (estado / fecha)
$countSql = "SELECT COUNT(*) FROM ( $baseSql ) t WHERE 1=1";
$countParams = $baseParams; $countTypes = $baseTypes;

if ($filterEstado !== '') {
  if ($filterEstado === 'sin_datos') {
    $countSql .= " AND t.last_fecha IS NULL ";
  } else {
    $countSql .= " AND t.estado_agg = ? ";
    $countParams[] = $filterEstado; $countTypes .= "s";
  }
}
if ($filterDesde) { $countSql .= " AND t.last_fecha >= ? "; $countParams[] = $filterDesde; $countTypes .= "s"; }
if ($filterHasta) { $countSql .= " AND t.last_fecha <= ? "; $countParams[] = $filterHasta; $countTypes .= "s"; }

$stCount = $conn->prepare($countSql);
$stCount->bind_param($countTypes, ...$countParams);
$stCount->execute();
$stCount->bind_result($totalRows);
$stCount->fetch();
$stCount->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

// --- IDs para la página
$sqlIds = "
  SELECT idLocal, MAX(is_priority) AS is_priority, MIN(cod_min) AS cod_min
  FROM (
    $baseSql
  ) t
  WHERE 1=1
";
$paramsIds = $baseParams; $typesIds = $baseTypes;

if ($filterEstado !== '') {
  if ($filterEstado === 'sin_datos') {
    $sqlIds .= " AND t.last_fecha IS NULL ";
  } else {
    $sqlIds .= " AND t.estado_agg = ? ";
    $paramsIds[] = $filterEstado; $typesIds .= "s";
  }
}
if ($filterDesde) { $sqlIds .= " AND t.last_fecha >= ? "; $paramsIds[] = $filterDesde; $typesIds .= "s"; }
if ($filterHasta) { $sqlIds .= " AND t.last_fecha <= ? "; $paramsIds[] = $filterHasta; $typesIds .= "s"; }

$sqlIds .= " GROUP BY idLocal ORDER BY MAX(is_priority) DESC, MIN(cod_min) ASC LIMIT ? OFFSET ? ";
$paramsIds[] = $perPage; $paramsIds[] = $offset; $typesIds .= "ii";

$stmtIds = $conn->prepare($sqlIds);
$stmtIds->bind_param($typesIds, ...$paramsIds);
$stmtIds->execute();
$resIds = $stmtIds->get_result();

$ids = [];
$prioById = [];
while ($row = $resIds->fetch_assoc()) {
  $idL = (int)$row['idLocal'];
  $ids[] = $idL;
  $prioById[$idL] = (int)$row['is_priority'];
}
$stmtIds->close();

// 4) Carga de locales de la página (consulta grande)
$locales = [];
if (!empty($ids)) {
  $place = implode(',', array_fill(0, count($ids), '?'));
  $sql = "
  SELECT
    l.id        AS idLocal,
    l.codigo    AS codigoLocal,
    l.nombre    AS nombreLocal,
    l.direccion AS direccionLocal,
    l.lat, l.lng,

    u.usuario AS usuarioGestion,
    DATE_FORMAT(last.fecha_visita,'%d/%m/%Y %H:%i') AS fechaVisita,
    /* Estado agregado por local con prioridad */
    CASE
      WHEN agg.has_impl_aud = 1 THEN 'implementado_auditado'
      WHEN agg.has_impl_any = 1 THEN 'solo_implementado'
      WHEN agg.has_audit    = 1 THEN 'solo_auditoria'
      ELSE last.estado_gestion
    END AS estadoGestion,

    last.lastLat AS lastLat,
    last.lastLng AS lastLng,

    fv.url AS fotoRef,               /* fotoVisita por visita más reciente */
    last.foto_url AS fotoURLGV,      /* evidencia de gv (pendiente/cancelado) */
    fr.encuesta_foto AS encuestaFoto,/* foto de encuesta (solo auditoría) */
    fv_fq.url AS fotoRefFQ,          /* NUEVO: foto fallback desde FQ */

    cnt.visitas_count AS visitasCount,
    cnt.gestiones_count AS gestionesCount

  FROM local l

  /* Última gestión por local */
  LEFT JOIN (
    SELECT gv1.id_local,
           gv1.id_usuario,
           gv1.estado_gestion,
           gv1.visita_id,
           gv1.fecha_visita,
           COALESCE(gv1.latitud, gv1.lat_foto)  AS lastLat,
           COALESCE(gv1.longitud, gv1.lng_foto) AS lastLng,
           gv1.foto_url
    FROM gestion_visita gv1
    JOIN (
      SELECT id_local,
             MAX(CONCAT(DATE_FORMAT(fecha_visita,'%Y%m%d%H%i%s'), LPAD(id,10,'0'))) AS max_key
      FROM gestion_visita
      WHERE id_formulario = ? AND id_local IN ($place)
      GROUP BY id_local
    ) sel
      ON sel.id_local = gv1.id_local
     AND CONCAT(DATE_FORMAT(gv1.fecha_visita,'%Y%m%d%H%i%s'), LPAD(gv1.id,10,'0')) = sel.max_key
    WHERE gv1.id_formulario = ?
  ) last ON last.id_local = l.id

  LEFT JOIN usuario u ON u.id = last.id_usuario

  /* Foto de encuesta (última respuesta con ruta de imagen) vinculada a la visita */
  LEFT JOIN (
    SELECT r.visita_id,
           SUBSTRING_INDEX(
             MAX(CONCAT(DATE_FORMAT(r.created_at,'%Y%m%d%H%i%s'),'|', r.answer_text)),
             '|', -1
           ) AS encuesta_foto
    FROM form_question_responses r
    JOIN form_questions q ON q.id = r.id_form_question
    JOIN formulario f     ON f.id = q.id_formulario AND f.id_empresa = ?
    WHERE q.id_formulario = ?
      AND r.answer_text <> ''
      AND (
        LOWER(r.answer_text) LIKE '%.jpg%'  OR
        LOWER(r.answer_text) LIKE '%.jpeg%' OR
        LOWER(r.answer_text) LIKE '%.png%'  OR
        LOWER(r.answer_text) LIKE '%.gif%'  OR
        LOWER(r.answer_text) LIKE '%.webp%'
      )
    GROUP BY r.visita_id
  ) fr ON fr.visita_id = last.visita_id

  /* Foto de la visita más reciente del local (fotoVisita) */
  LEFT JOIN (
    SELECT fv2.visita_id, MAX(fv2.id) AS max_foto
    FROM fotoVisita fv2
    WHERE fv2.id_formulario = ? AND fv2.id_local IN ($place)
    GROUP BY fv2.visita_id
  ) fmax ON fmax.visita_id = last.visita_id
  LEFT JOIN fotoVisita fv ON fv.id = fmax.max_foto

  /* NUEVO: Foto FQ (fallback para locales sin gestion_visita) */
  LEFT JOIN (
    SELECT fq3.id_local, MAX(fv3.id) AS max_foto_fq
    FROM formularioQuestion fq3
    JOIN fotoVisita fv3
      ON fv3.id_formularioQuestion = fq3.id
     AND fv3.id_formulario        = fq3.id_formulario
     AND fv3.id_local             = fq3.id_local
    WHERE fq3.id_formulario = ?
      AND fv3.id_formulario = ?
    GROUP BY fq3.id_local
  ) fqx ON fqx.id_local = l.id
  LEFT JOIN fotoVisita fv_fq ON fv_fq.id = fqx.max_foto_fq

  /* Estado agregado por local */
  LEFT JOIN (
    SELECT id_local,
           MAX(estado_gestion = 'implementado_auditado')                                   AS has_impl_aud,
           MAX(estado_gestion IN ('solo_implementado','implementado_auditado'))            AS has_impl_any,
           MAX(estado_gestion = 'solo_auditoria')                                          AS has_audit
    FROM gestion_visita
    WHERE id_formulario = ? AND id_local IN ($place)
    GROUP BY id_local
  ) agg ON agg.id_local = l.id

  /* Contadores por local */
  LEFT JOIN (
    SELECT id_local,
           COUNT(DISTINCT visita_id) AS visitas_count,
           COUNT(*)                  AS gestiones_count
    FROM gestion_visita
    WHERE id_formulario = ? AND id_local IN ($place)
    GROUP BY id_local
  ) cnt ON cnt.id_local = l.id

  WHERE l.id IN ($place)
  ORDER BY FIELD(l.id, $place)
  ";

  // Construir parámetros (orden exacto)
  $params = [];
  $types  = "";

  // subquery last.sel (id_formulario + IN ids)
  $params[] = $idCampana; $types .= "i";
  foreach ($ids as $v){ $params[] = $v; $types .= "i"; }
  // last outer WHERE id_formulario
  $params[] = $idCampana; $types .= "i";

  // fr (foto encuesta): empresa + idCampana
  $params[] = $empresa_id; $types .= "i";
  $params[] = $idCampana;  $types .= "i";

  // fmax (id_formulario + IN ids)
  $params[] = $idCampana; $types .= "i";
  foreach ($ids as $v){ $params[] = $v; $types .= "i"; }

  // NUEVO: fqx (foto FQ fallback) -> dos parámetros (campaña y campaña)
  $params[] = $idCampana; $types .= "i";  // fq3.id_formulario
  $params[] = $idCampana; $types .= "i";  // fv3.id_formulario

  // agg (id_formulario + IN ids)
  $params[] = $idCampana; $types .= "i";
  foreach ($ids as $v){ $params[] = $v; $types .= "i"; }

  // cnt (id_formulario + IN ids)
  $params[] = $idCampana; $types .= "i";
  foreach ($ids as $v){ $params[] = $v; $types .= "i"; }

  // WHERE l.id IN ($place)
  foreach ($ids as $v){ $params[] = $v; $types .= "i"; }

  // FIELD order
  foreach ($ids as $v){ $params[] = $v; $types .= "i"; }

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $locales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

$conn->close();

// Reconstruir URLs y defaults (+ fallback por estado/encuesta/evidencia y FQ)
$baseURL = 'https://visibility.cl/visibility2/app/';
foreach ($locales as &$loc) {

  // 1) por defecto, foto de fotoVisita (si existe)
  $raw = $loc['fotoRef'] ?? '';

  // 2) si no hay, según estado usar encuestaFoto o fotoURLGV
  if (!$raw) {
    $estado = $loc['estadoGestion'] ?? '';
    if ($estado === 'solo_auditoria' && !empty($loc['encuestaFoto'])) {
      $raw = $loc['encuestaFoto'];
    } elseif (($estado === 'en proceso' || $estado === 'cancelado') && !empty($loc['fotoURLGV'])) {
      $parts = preg_split('/\s+/', trim($loc['fotoURLGV'])); // tomar primera si vienen varias
      $raw = $parts[0] ?? '';
    }
  }

  // 2.b) NUEVO: si aún no hay foto, tomar la ligada a FQ (fotoRefFQ)
  if (!$raw && !empty($loc['fotoRefFQ'])) {
    $raw = $loc['fotoRefFQ'];
  }

  // 3) normaliza URL o placeholder
  if ($raw) {
    if (preg_match('#^https?://#i', $raw)) {
      $loc['fotoRef'] = $raw;
    } elseif (preg_match('#^/visibility2/app/#', $raw)) {
      $loc['fotoRef'] = 'https://visibility.cl' . $raw;
    } else {
      $loc['fotoRef'] = $baseURL . ltrim($raw,'./');
    }
  } else {
    $loc['fotoRef'] = $baseURL . 'assets/images/placeholder.png';
  }

  // enrich
  $idL = (int)$loc['idLocal'];
  $loc['is_priority']     = (int)($prioById[$idL] ?? 0);
  $loc['usuarioGestion']  = $loc['usuarioGestion'] ?: '—';
  $loc['fechaVisita']     = $loc['fechaVisita']    ?: '—';
  $loc['estadoLabel']     = estado_label($loc['estadoGestion'] ?? '');
  $loc['visitasCount']    = (int)($loc['visitasCount']    ?? 0);
  $loc['gestionesCount']  = (int)($loc['gestionesCount']  ?? 0);
  $loc['lastLat']         = isset($loc['lastLat']) ? (float)$loc['lastLat'] : null;
  $loc['lastLng']         = isset($loc['lastLng']) ? (float)$loc['lastLng'] : null;
}
unset($loc);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mapa — <?= htmlspecialchars($campanaNombre, ENT_QUOTES) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.1/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css"/>
  <style>
    body, html { height:100%; margin:0; }
    #map, #mapGestiones { height:100%; width:100%; position:absolute; top:0; left:0; display:none; }
    #map { display:block; }
    #overlay { position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.85); z-index:2000; display:flex; align-items:center; justify-content:center; font-size:1.2rem; color:#007bff; }
    .sidebar { position:absolute; top:0; left:0; width:320px; height:100%; background:#fff; overflow-y:auto; box-shadow:2px 0 8px rgba(0,0,0,0.15); transition:width .3s; z-index:1000; }
    .sidebar.collapsed { width:0; }
    .sidebar .header { background:#007bff; color:#fff; padding:12px; display:flex; align-items:center; justify-content:space-between; }
    .sidebar .filters { padding:10px; border-bottom:1px solid #e3e3e3; }
    .sidebar table { width:100%; }
    .sidebar tr:hover { background:#f1f1f1; cursor:pointer; }
    .tabs-top { position:absolute; top:10px; left:50%; transform:translateX(-50%); z-index:1500; }
    #btnToggleSidebar { position:absolute; top:10px; left:340px; z-index:1500; }
    .camp-name { font-weight:600; font-size:.95rem; }
    .table-active { background:#fff3cd !important; }
    .form-row > .form-group { margin-right:6px; }
  </style>
</head>
<body>

  <div id="overlay"><i class="fas fa-spinner fa-spin mr-2"></i> Cargando mapa…</div>

  <!-- Sidebar -->
  <div class="sidebar" id="sbar">
    <div class="header">
      <div>
        <div class="camp-name"><?= htmlspecialchars($campanaNombre, ENT_QUOTES) ?></div>
        <small>Campaña #<?= $idCampana ?></small>
      </div>
      <button class="btn btn-sm btn-light" onclick="toggleSidebar()"><i class="fas fa-chevron-left"></i></button>
    </div>

    <div class="filters">
      <form method="get" class="mb-2">
        <input type="hidden" name="id" value="<?= $idCampana ?>">

        <div class="form-row">
          <div class="form-group">
            <input type="text" name="filter_codigo" class="form-control form-control-sm" placeholder="Código"
                   value="<?= htmlspecialchars($filterCodigo, ENT_QUOTES) ?>">
          </div>

          <div class="form-group">
            <select name="filter_usuario_id" class="form-control form-control-sm">
              <option value="">Todos los usuarios</option>
              <?php foreach($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $filterUserId===(int)$u['id']?'selected':'' ?>>
                  <?= htmlspecialchars($u['usuario'], ENT_QUOTES) ?>
                  <?= $u['nombre'] ? ' — '.htmlspecialchars($u['nombre'], ENT_QUOTES) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <select name="filter_estado" class="form-control form-control-sm">
              <option value="">Todos los estados</option>
              <?php
                foreach ($estadosDisponibles as $est) {
                  $sel = ($filterEstado === $est) ? 'selected' : '';
                  echo '<option value="'.htmlspecialchars($est,ENT_QUOTES).'" '.$sel.'>'.htmlspecialchars(estado_label($est),ENT_QUOTES).'</option>';
                }
                $selSD = ($filterEstado === 'sin_datos') ? 'selected' : '';
                echo '<option value="sin_datos" '.$selSD.'>Sin gestiones</option>';
              ?>
            </select>
          </div>
        </div>

        <div class="form-row align-items-center">
          <div class="form-group">
            <input type="date" name="fdesde" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDesdeRaw, ENT_QUOTES) ?>" placeholder="Desde">
          </div>
          <div class="form-group">
            <input type="date" name="fhasta" class="form-control form-control-sm" value="<?= htmlspecialchars($filterHastaRaw, ENT_QUOTES) ?>" placeholder="Hasta">
          </div>

          <div class="form-group">
            <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
          </div>
        </div>
      </form>
    </div>

    <table class="table table-sm mb-0" id="tblLocales">
      <thead><tr><th>Código</th><th>Local</th><th>★</th></tr></thead>
      <tbody>
      <?php foreach($locales as $loc): ?>
        <tr data-id="<?= (int)$loc['idLocal'] ?>">
          <td><?= htmlspecialchars($loc['codigoLocal'] ?? '', ENT_QUOTES) ?></td>
          <td>
            <?= htmlspecialchars($loc['nombreLocal'] ?? '', ENT_QUOTES) ?>
            <br><small class="text-muted">
              <?= htmlspecialchars($loc['estadoLabel'] ?? ($loc['estadoGestion'] ?? '—'), ENT_QUOTES) ?> ·
              V: <?= (int)($loc['visitasCount'] ?? 0) ?> ·
              G: <?= (int)($loc['gestionesCount'] ?? 0) ?>
            </small>
          </td>
          <td><?= !empty($loc['is_priority'])?'★':'' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php
          // reconstruir query base con filtros para paginación
          $q = [
            'id'=>$idCampana,
            'filter_codigo'=>$filterCodigo,
            'filter_usuario_id'=>$filterUserId ?: '',
            'filter_estado'=>$filterEstado,
            'fdesde'=>$filterDesdeRaw,
            'fhasta'=>$filterHastaRaw
          ];
          for($p=1;$p<=$totalPages;$p++):
            $q['page']=$p; $href='?'.http_build_query($q);
        ?>
        <li class="page-item <?= $p===$page?'active':'' ?>">
          <a class="page-link" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
      </ul>
    </nav>
  </div>

  <button id="btnToggleSidebar" class="btn btn-warning btn-sm"><i class="fas fa-bars"></i></button>

  <ul class="nav nav-pills tabs-top">
    <li class="nav-item"><a class="nav-link active" href="#" id="tabLocales">Locales</a></li>
    <li class="nav-item"><a class="nav-link" href="#" id="tabGestiones">Gestiones</a></li>
  </ul>

  <div id="map"></div>
  <div id="mapGestiones"></div>

  <div class="modal fade" id="detalleLocalModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content" id="detalleLocalContent">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Detalle Local</h5>
          <button class="close text-white" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body text-center p-5">
          <i class="fas fa-spinner fa-spin fa-2x"></i>
          <p>Cargando detalle…</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Datos JS -->
  <script>
    const CAMPANA_ID = <?= $idCampana ?>,
          LOCALES = <?= json_encode($locales, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  </script>

  <!-- Dependencias -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.1/js/bootstrap.bundle.min.js"></script>
  <script src="https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js"></script>

  <!-- Lógica JS -->
  <script>
    let mapLocales, mapGestiones, markersLocales={}, markersGestiones={}, clusterer;

    function esc(s){return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/`/g,'&#96;');}
    function haversineMeters(a,b){ if(!a||!b)return null; const R=6371000,dLat=(b.lat-a.lat)*Math.PI/180,dLng=(b.lng-a.lng)*Math.PI/180; const sa=Math.sin(dLat/2)**2+Math.cos(a.lat*Math.PI/180)*Math.cos(b.lat*Math.PI/180)*Math.sin(dLng/2)**2; return Math.round(R*2*Math.atan2(Math.sqrt(sa),Math.sqrt(1-sa))); }

    function initMap(){
      mapLocales = new google.maps.Map(document.getElementById('map'), {center:{lat:-33.45,lng:-70.66},zoom:5,streetViewControl:false,mapTypeControl:false});
      const chileBounds = new google.maps.LatLngBounds({lat:-56,lng:-76},{lat:-17.5,lng:-66}); mapLocales.fitBounds(chileBounds);

      Object.values(LOCALES).forEach(loc=>{
        if (loc.lat == null || loc.lng == null) return;
        const pos={lat:+loc.lat,lng:+loc.lng};
        const icon=`/visibility2/portal/assets/images/marker_${loc.is_priority?'blue':'red'}1.png`;
        const m=new google.maps.Marker({ position:pos, map:mapLocales, icon:{url:icon,scaledSize:new google.maps.Size(30,30)} });

        const last=(loc.lastLat!=null&&loc.lastLng!=null)?{lat:+loc.lastLat,lng:+loc.lastLng}:null;
        const dist= last ? haversineMeters({lat:+loc.lat,lng:+loc.lng}, last) : null;
        const pill = dist==null ? '' : `<span class="badge badge-${dist<=150?'success':'danger'} ml-1">${dist} m</span>`;

        const iw=new google.maps.InfoWindow({content:`
          <div style="max-width:240px">
            <strong>${esc(loc.nombreLocal)}</strong><br>
            <small>${esc(loc.direccionLocal)}</small><br>
            <img src="${esc(loc.fotoRef)}" loading="lazy" decoding="async" style="width:100%;border-radius:4px;margin:8px 0;"><br>
            <small><strong>Estado:</strong> ${esc(loc.estadoLabel ?? loc.estadoGestion ?? '—')} ${pill}</small><br>
            <small><strong>Última:</strong> ${esc(loc.fechaVisita ?? '—')} · ${esc(loc.usuarioGestion ?? '—')}</small><br>
            <small><strong>V/G:</strong> ${+loc.visitasCount || 0} / ${+loc.gestionesCount || 0}</small><br>
            <div class="mt-2 d-flex"><button class="btn btn-sm btn-info" onclick="abrirDetalle(${CAMPANA_ID},${+loc.idLocal})">Detalle</button></div>
          </div>`});
        m.addListener('click',()=>{ iw.open(mapLocales,m); highlightRow(+loc.idLocal); });

        markersLocales[+loc.idLocal]=m;
      });

      clusterer = new markerClusterer.MarkerClusterer({ map: mapLocales, markers: Object.values(markersLocales) });

      mapGestiones = new google.maps.Map(document.getElementById('mapGestiones'), {center:{lat:-33.45,lng:-70.66}, zoom:5, streetViewControl:false, mapTypeControl:false});
      mapGestiones.fitBounds(chileBounds);

      document.getElementById('overlay').style.display='none';
    }

    function highlightRow(localId){
      const $row = $(`#tblLocales tr[data-id="${localId}"]`);
      if(!$row.length) return;
      $('#tblLocales tr').removeClass('table-active');
      $row.addClass('table-active')[0].scrollIntoView({block:'center', behavior:'smooth'});
    }
    $('#tblLocales').on('mouseenter','tr', function(){
      const id = +$(this).data('id'); const m = markersLocales[id]; if(!m) return;
      m.setAnimation(google.maps.Animation.BOUNCE); setTimeout(()=>m.setAnimation(null), 700);
    });

    function toggleSidebar(){ document.getElementById('sbar').classList.toggle('collapsed'); }
    document.getElementById('btnToggleSidebar').addEventListener('click', toggleSidebar);

    $('#tabLocales').click(e=>{ e.preventDefault(); $('#tabLocales').addClass('active'); $('#tabGestiones').removeClass('active'); $('#mapGestiones').hide(); $('#map').show(); });
    $('#tabGestiones').click(e=>{
      e.preventDefault(); $('#tabGestiones').addClass('active'); $('#tabLocales').removeClass('active'); $('#map').hide(); $('#mapGestiones').show();
      if ($.isEmptyObject(markersGestiones)) {
        const firstId = +$('#tblLocales tbody tr:first').data('id'); if (firstId) cargarGestionesDeLocal(firstId);
      }
    });

    $('#tblLocales').on('click','tr', function(){
      const id = +$(this).data('id'); if (!id) return;
      if ($('#tabLocales').hasClass('active')) {
        const m = markersLocales[id]; if (!m) return;
        mapLocales.setZoom(15); mapLocales.panTo(m.getPosition()); google.maps.event.trigger(m,'click');
      } else { cargarGestionesDeLocal(id); }
    });

    function cargarGestionesDeLocal(localId){
      Object.values(markersGestiones).forEach(m=>m.setMap(null)); markersGestiones = {};
      $.getJSON('ajax_gestiones_mapa.php', { campana: CAMPANA_ID, local: localId }, data=>{
        const bounds = new google.maps.LatLngBounds();
        const loc = LOCALES.find(x=> +x.idLocal===+localId) || {};
        data.forEach(g=>{
          if (g.lat == null || g.lng == null) return;
          const pos = { lat:+g.lat, lng:+g.lng }; bounds.extend(pos);
          const marker = new google.maps.Marker({
            position: pos, map: mapGestiones,
            icon: { url: `/visibility2/portal/assets/images/marker_${loc.is_priority ? 'blue' : 'red'}1.png`, scaledSize: new google.maps.Size(30,30) }
          });
          const iw = new google.maps.InfoWindow({ content: `
            <div style="max-width:240px">
              <strong>${esc(loc.nombreLocal ?? '')}</strong><br>
              <small>${esc(loc.direccionLocal ?? '')}</small><br>
              <img src="${esc(loc.fotoRef ?? '')}" loading="lazy" decoding="async" style="width:100%;border-radius:4px;margin:8px 0;"><br>
              <small><strong>Usuario:</strong> ${esc(g.usuario ?? '—')}</small><br>
              <small><strong>Fecha:</strong> ${esc(g.fechaVisita ?? '—')}</small><br>
              <button class="btn btn-sm btn-info mt-2" onclick="abrirDetalle(${CAMPANA_ID},${+localId})">Detalle</button>
            </div>`});
          marker.addListener('click', ()=> iw.open(mapGestiones, marker));
          markersGestiones[g.idFQ || g.id || Math.random()] = marker;
        });
        if (!bounds.isEmpty()) mapGestiones.fitBounds(bounds);
      });
    }

    function abrirDetalle(camp,loc){
      $('#detalleLocalContent').html(`
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title">Detalle Local #${loc}</h5>
          <button class="close text-white" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body text-center p-5">
          <i class="fas fa-spinner fa-spin fa-2x"></i><p>Cargando detalle…</p>
        </div>`);
      $('#detalleLocalModal').modal('show');
      $.get('detalle_local.php',{idCampana:camp,idLocal:loc})
        .done(html=>$('#detalleLocalContent').html(html))
        .fail(()=>$('#detalleLocalContent').html('<div class="alert alert-danger p-3">Error cargando detalle.</div>'));
    }
  </script>

  <!-- Google Maps API -->
  <script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap"></script>
</body>
</html>
