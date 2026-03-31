<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// 1) Funciones auxiliares
// -----------------------------------------------------------------------------
function refValues(array $arr) {
    // (ya no lo necesitamos para bind_param con splat)
    return $arr;
}

function fixUrl(string $url, string $base_url): string {
    // 1) Si ya viene con http:// o https://, devolvemos sin tocar.
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    // 2) Quitar slashes iniciales
    $url = ltrim($url, '/');

    // 3) Quitar el prefijo de la aplicación si lo trae (case-insensitive)
    $url = preg_replace('#^(visibility2/app/|app/)#i', '', $url);

    // 4) Devolver la ruta completa
    return rtrim($base_url, '/') . '/' . ltrim($url, '/');
}

function formatearFecha($f): string {
    return $f ? date('d/m/Y H:i:s', strtotime($f)) : '';
}

// -----------------------------------------------------------------------------
// 2) Includes y validaciones
// -----------------------------------------------------------------------------
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Obtener división de sesión o GET
$division_id   = intval($_SESSION['division_id'] ?? 0);
$division      = isset($_GET['division']) ? intval($_GET['division']) : $division_id;

// Obtener campaña (formulario) y otros parámetros
$formulario_id = isset($_GET['id'])       ? intval($_GET['id'])       : 0;
$view          = $_GET['view'] ?? 'implementacion';
$limit         = max(1, intval($_GET['limit']  ?? 25));
$page          = max(1, intval($_GET['page']   ?? 1));
$offset        = ($page - 1) * $limit;
$start_date    = trim($_GET['start_date']   ?? '');
$end_date      = trim($_GET['end_date']     ?? '');
$user_id       = intval($_GET['user_id']    ?? 0);
$material_id   = intval($_GET['material_id']?? 0);
$local_code    = trim($_GET['local_code']   ?? '');

$base_url = "https://visibility.cl/visibility2/app/";

// -----------------------------------------------------------------------------
// 3) Cargar divisiones (solo MC)
// -----------------------------------------------------------------------------
$divisiones = [];
$resDiv = $conn->query("SELECT id,nombre FROM division_empresa WHERE estado=1 ORDER BY nombre");
while ($r = $resDiv->fetch_assoc()) {
    $divisiones[] = $r;
}

// -----------------------------------------------------------------------------
// 4) Cargar campañas tipo=1 según división
// -----------------------------------------------------------------------------
$formularios2 = [];
$sqlF = "SELECT id,nombre FROM formulario WHERE tipo=1 and estado ='1'";
$paramsF = [];
$typesF = "";
if ($division > 0) {
    $sqlF .= " AND id_division=?";
    $typesF = "i";
    $paramsF[] = $division;
}
$sqlF .= " ORDER BY nombre";
$stmtF = $conn->prepare($sqlF);
if ($typesF) {
    $stmtF->bind_param($typesF, ...$paramsF);
}
$stmtF->execute();
$resF = $stmtF->get_result();
while ($f = $resF->fetch_assoc()) {
    $formularios2[] = $f;
}
$stmtF->close();

// -----------------------------------------------------------------------------
// 5) Cargar usuarios y materiales para filtros
// -----------------------------------------------------------------------------
$usuarios = [];
if ($view === 'implementacion') {
    $sqlU = "
      SELECT DISTINCT u.id,u.usuario
      FROM fotoVisita fv
      JOIN usuario u ON u.id=fv.id_usuario
      JOIN formularioQuestion fq ON fq.id=fv.id_formularioQuestion
      WHERE fq.id_formulario IN (
        SELECT id FROM formulario
        WHERE tipo=1 and estado = '1'" . ($division>0?" AND id_division=?":"") . "
      )
      ORDER BY u.usuario
    ";
    $stmtU = $conn->prepare($sqlU);
    if ($division>0) {
        $stmtU->bind_param("i", $division);
    }
} else {
    $sqlU = "
      SELECT DISTINCT u.id,u.usuario
      FROM form_question_responses fqr
      JOIN usuario u ON u.id=fqr.id_usuario
      JOIN form_questions fq ON fq.id=fqr.id_form_question
      WHERE fq.id_formulario IN (
        SELECT id FROM formulario
        WHERE tipo=1 and estado = '1' " . ($division>0?" AND id_division=?":"") . "
      )
        AND fq.id_question_type=7
        AND fqr.id_local<>0
      ORDER BY u.usuario
    ";
    $stmtU = $conn->prepare($sqlU);
    if ($division>0) {
        $stmtU->bind_param("i", $division);
    }
}
$stmtU->execute();
$resU = $stmtU->get_result();
while ($u = $resU->fetch_assoc()) {
    $usuarios[] = $u;
}
$stmtU->close();

$materials = [];
if ($view==='implementacion') {
    $resM = $conn->query("SELECT id,nombre FROM material ORDER BY nombre");
    while ($m = $resM->fetch_assoc()) {
        $materials[] = $m;
    }
}

// -----------------------------------------------------------------------------
// 6) Construir filtro de formulario
// -----------------------------------------------------------------------------
$formFilter = "";
$params = [];
$types = "";
if ($formulario_id>0) {
    $formFilter = "fq.id_formulario=?";
    $types = "i";
    $params[] = $formulario_id;
} else {
    $formFilter = "fq.id_formulario IN (
      SELECT id FROM formulario
      WHERE tipo=1 and estado = '1' " . ($division>0?" AND id_division=?":"") . "
    )";
    if ($division>0) {
        $types = "i";
        $params[] = $division;
    }
}

// -----------------------------------------------------------------------------
// 7) Consulta principal
// -----------------------------------------------------------------------------
if ($view==='implementacion') {
    $sql = "
      SELECT
        MIN(fv.id)         AS foto_id,
        GROUP_CONCAT(fv.url SEPARATOR '||') AS urls,
        fq.material,
        fq.fechaVisita,
        f.nombre           AS campaña_nombre,
        l.codigo           AS local_codigo,
        l.nombre           AS local_nombre,
        l.direccion        AS local_direccion,
        co.comuna          AS comuna_nombre,
        c.nombre           AS cadena_nombre,
        ct.nombre          AS cuenta_nombre,
        u.usuario
      FROM formularioQuestion fq
      JOIN formulario     f  ON f.id = fq.id_formulario
      JOIN fotoVisita     fv ON fv.id_formularioQuestion = fq.id
      JOIN local          l  ON l.id = fq.id_local
      LEFT JOIN comuna    co ON co.id = l.id_comuna
      JOIN cadena         c  ON c.id = l.id_cadena
      JOIN cuenta         ct ON ct.id = l.id_cuenta
      JOIN usuario        u  ON u.id = fv.id_usuario
      WHERE {$formFilter}
        AND fq.fechaVisita IS NOT NULL
    ";
} else {
    $sql = "
      SELECT
        MIN(fqr.id)       AS foto_id,
        GROUP_CONCAT(fqr.answer_text SEPARATOR '||') AS urls,
        fqr.created_at    AS fechaSubida,
        fq.question_text  AS pregunta,
        f.nombre          AS campaña_nombre,
        l.codigo          AS local_codigo,
        l.nombre          AS local_nombre,
        l.direccion       AS local_direccion,
        co.comuna         AS comuna_nombre,
        c.nombre          AS cadena_nombre,
        ct.nombre         AS cuenta_nombre,
        u.usuario
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      JOIN formulario     f   ON f.id = fq.id_formulario
      JOIN local          l   ON l.id = fqr.id_local
      LEFT JOIN comuna    co  ON co.id = l.id_comuna
      JOIN cadena         c   ON c.id = l.id_cadena
      JOIN cuenta         ct  ON ct.id = l.id_cuenta
      JOIN usuario        u   ON u.id = fqr.id_usuario
      WHERE {$formFilter}
        AND fq.id_question_type = 7
        AND fqr.id_local <> 0
    ";
}

// Filtros adicionales
if ($start_date!=='') {
    $field = ($view==='implementacion'?'fq.fechaVisita':'fqr.created_at');
    $sql   .= " AND DATE({$field})>=?";
    $types .= "s";
    $params[] = $start_date;
}
if ($end_date!=='') {
    $field = ($view==='implementacion'?'fq.fechaVisita':'fqr.created_at');
    $sql   .= " AND DATE({$field})<=?";
    $types .= "s";
    $params[] = $end_date;
}
if ($user_id>0) {
    $field = ($view==='implementacion'?'fv.id_usuario':'fqr.id_usuario');
    $sql   .= " AND {$field}=?";
    $types .= "i";
    $params[] = $user_id;
}
if ($local_code!=='') {
    $sql   .= " AND l.codigo=?";
    $types .= "s";
    $params[] = $local_code;
}
if ($view==='implementacion' && $material_id>0) {
    $sql   .= " AND fq.material=(SELECT nombre FROM material WHERE id=?)";
    $types .= "i";
    $params[] = $material_id;
}

$sql .= "
  GROUP BY
    " . ($view==='implementacion'
          ? "u.id, l.id, f.nombre, fq.material, fq.fechaVisita"
          : "fqr.id_usuario, fqr.id_local, fqr.id_form_question"
        ) . "
  ORDER BY " . ($view==='implementacion'?'fq.fechaVisita':'fqr.created_at') . " DESC
  LIMIT ? OFFSET ?
";
$types .= "ii";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($sql);
if ($types!=='') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $rawUrls = explode('||', $row['urls']);
    $fixed   = array_map(function($u) use ($base_url) {
        return fixUrl($u, $base_url);
    }, $rawUrls);
    $row['photos']       = $fixed;
    $row['photos_count'] = count($fixed);
    $row['thumbnail']    = $fixed[0];
    $data[] = $row;
}
$stmt->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Galería Campañas Programadas</title>
  <!--<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css"> -->
      <!-- Bootstrap 4 desde CDN -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.12/css/dataTables.bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.2.2/css/buttons.bootstrap.min.css">    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
  
  <style>
#example thead th,.uppercase{text-transform:uppercase}.thumbnail{width:100px;height:100px;object-fit:cover;border-radius:5px}.custom-img-cell{width:130px;position:relative}.badge-count{position:absolute;top:5px;right:5px;background:rgba(0,0,0,.6);color:#fff;font-size:.8rem;padding:.2rem .4rem;border-radius:50%}.pagination{flex-wrap:wrap;justify-content:center;gap:5px}@media (min-width:1200px){.container,.container-lg,.container-md,.container-sm,.container-xl{max-width:100%!important}}#example thead th{background-color:#4b545c;padding:20px 15px;text-align:left;font-weight:500;font-size:12px;color:#fff}.badge{font-size:.75rem;padding:.4em .6em}.modal-body-scrollable{max-height:70vh;overflow-y:auto}.material-row{margin-bottom:10px}.remove-material-btn{margin-top:32px}thead input{width:100%;padding:3px;box-sizing:border-box}.pagination>li>a,.pagination>li>span{position:relative;float:left;padding:6px 12px;margin-left:-1px;line-height:1.42857143;color:#337ab7;text-decoration:none;background-color:#fff;border:1px solid #ddd}.dt-buttons{float:left;margin-right:10px}.dataTables_filter{float:right}.btn-default{background-color:#f8f9fa;border-color:#ddd;color:#444}.table-responsive{margin-top:2%}.mb-2{margin-top:1%}.bg-success-mc{background:#93c01f;background-image:linear-gradient(to left,#e7f4cb 0,#cbe47b 40%,#a7d13a 75%,#93c01f 100%);color:#fff;padding:15px;border-radius:3px 3px 0 0}div.dataTables_wrapper div.dataTables_length select{margin-left:5px;margin-right:5px}div.dataTables_wrapper div.dataTables_info{padding-top:0!important;margin-left:1%}.btn-secondary{margin-bottom:0.3%;}
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">


  <!-- Nav tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $view==='implementacion'?'active':'' ?>"
         href="?<?= http_build_query(array_merge($_GET,['view'=>'implementacion','page'=>1])) ?>">
        Fotos Implementación
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $view==='encuesta'?'active':'' ?>"
         href="?<?= http_build_query(array_merge($_GET,['view'=>'encuesta','page'=>1])) ?>">
        Fotos Encuesta
      </a>
    </li>
  </ul>

<!-- 🧭 Filtros -->
<form method="GET" class="form-inline flex-wrap mb-3 align-items-end">
  <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">

  <?php if ($division_id === 1): ?>
    <div class="form-group mr-3 mb-2">
      <label for="divisionSelect" class="mr-2 mb-0">División:</label>
      <select id="divisionSelect" name="division" class="form-control">
        <option value="0">-- Todas --</option>
        <?php foreach($divisiones as $d): ?>
          <option value="<?=$d['id']?>" <?=$d['id']==$division?'selected':''?>>
            <?=htmlspecialchars($d['nombre'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php else: ?>
    <input type="hidden" name="division" value="<?=$division_id?>">
  <?php endif; ?>

  <div class="form-group mr-3 mb-2">
    <label for="campaignSelect" class="mr-2 mb-0">Campaña:</label>
    <select id="campaignSelect" name="id" class="form-control">
      <option value="0">-- Todas --</option>
      <?php foreach($formularios2 as $f): ?>
        <option value="<?=$f['id']?>" <?=$f['id']==$formulario_id?'selected':''?>>
          <?=htmlspecialchars($f['nombre'])?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-group mr-3 mb-2">
    <label class="mr-2 mb-0">Desde:</label>
    <input type="date" name="start_date" class="form-control" value="<?=htmlspecialchars($start_date)?>">
  </div>

  <div class="form-group mr-3 mb-2">
    <label class="mr-2 mb-0">Hasta:</label>
    <input type="date" name="end_date" class="form-control" value="<?=htmlspecialchars($end_date)?>">
  </div>

  <div class="form-group mr-3 mb-2">
    <label class="mr-2 mb-0">Usuario:</label>
    <select name="user_id" class="form-control">
      <option value="0">-- Todos --</option>
      <?php foreach($usuarios as $u): ?>
        <option value="<?=$u['id']?>" <?=$u['id']==$user_id?'selected':''?>>
          <?=htmlspecialchars($u['usuario'])?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="form-group mr-3 mb-2">
    <label class="mr-2 mb-0">Cód. Local:</label>
    <input type="text" name="local_code" class="form-control" value="<?=htmlspecialchars($local_code)?>">
  </div>

  <?php if ($view === 'implementacion'): ?>
    <div class="form-group mr-3 mb-2">
      <label class="mr-2 mb-0">Material:</label>
      <select name="material_id" class="form-control">
        <option value="0">-- Todos --</option>
        <?php foreach($materials as $m): ?>
          <option value="<?=$m['id']?>" <?=$m['id']==$material_id?'selected':''?>>
            <?=htmlspecialchars($m['nombre'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>

  <div class="form-group mb-2">
    <button type="submit" class="btn btn-primary">
      <i class="fas fa-filter"></i> Filtrar
    </button>
  </div>
</form>


  <!-- Limit selector -->
  <div class="d-flex align-items-center mb-2">
    <label class="mr-2">Mostrar:</label>
    <select id="limitSelect" class="form-control" style="width:auto">
      <?php foreach([10,25,50,100,200,300,400,500] as $n): ?>
        <option value="<?=$n?>" <?=$n==$limit?'selected':''?>><?=$n?></option>
      <?php endforeach; ?>
    </select>
    <span class="ml-2">registros</span>
  </div>

  <!-- Descargar ZIP -->
  <form id="zipForm" method="POST" action="download_zip.php" style="display:none">
    <input type="hidden" name="jsonFotos" id="jsonFotos">
  </form>
  <button id="btnDownloadSelected" class="btn btn-secondary">
      Descargar seleccionadas
      <i class="fas fa-download"></i>
  </button>

<br>

  <?php if ($view === 'implementacion'): ?>
    <table id="example"
                        class="table table-sm table-bordered table-hover"
                        cellspacing="0"
                        width="100%">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>#</th>
          <th>Imagen</th>
          <th>Campaña</th>          
          <th>Cód. Local</th>
          <th>Local</th>
          <th>Dirección</th>
          <th>Material</th>
          <th>Cadena</th>
          <th>Cuenta</th>
          <th>Usuario</th>
          <th>Fecha</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($data)): ?>
          <tr><td colspan="12" class="text-center">Sin fotos de implementación</td></tr>
        <?php else: $i = $offset + 1; foreach ($data as $r): 
             $usuarioSafe = preg_replace('/[^a-zA-Z0-9]/','_', $r['usuario'] ?? '');

    // 2) dentro: material (implementación) o pregunta (encuesta)
    if ($view === 'implementacion') {
      $inner = $r['material']     ?? '';
    } else {
      $inner = $r['pregunta']     ?? '';
    }
    $innerSafe  = preg_replace('/[^a-zA-Z0-9]/','_', $inner);

    // 3) código de local
    $codigoSafe = preg_replace('/[^a-zA-Z0-9]/','_', $r['local_codigo'] ?? '');

    // 4) fecha formateada
    $fechaField = $view==='implementacion'
                  ? ($r['fechaVisita']  ?? null)
                  : ($r['fechaSubida'] ?? null);
    $fechaSafe  = $fechaField
                  ? date('Ymd_His', strtotime($fechaField))
                  : '';

    // 5) ensamblar prefijo, evitando guiones duplicados al final
    $prefix = trim("{$usuarioSafe}_{$innerSafe}_{$codigoSafe}", '_');
            
            ?>
          <tr>
    <td>
      <input type="checkbox" class="imgCheckbox"
             data-urls="<?= htmlspecialchars(implode('||',$r['photos']), ENT_QUOTES) ?>"
             data-prefix="<?= $prefix ?>">
    </td>
            <td><?= $i++ ?></td>
            <td class="custom-img-cell">
              <span class="badge-count"><?= $r['photos_count'] ?></span>
              <img src="<?= htmlspecialchars($r['thumbnail'], ENT_QUOTES) ?>"
                   class="thumbnail img-click"
                   data-urls="<?= htmlspecialchars(implode('||',$r['photos']), ENT_QUOTES) ?>">
            </td>
            <td><?= htmlspecialchars($r['campaña_nombre'], ENT_QUOTES) ?></td>  
            <td><?= htmlspecialchars($r['local_codigo'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['local_nombre'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['local_direccion'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['material'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['cadena_nombre'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['cuenta_nombre'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['usuario'], ENT_QUOTES) ?></td>
            <td><?= formatearFecha($r['fechaVisita']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  <?php else: /* === pestaña Encuesta === */ ?>
    <table id="example"
                        class="table table-sm table-bordered table-hover"
                        cellspacing="0"
                        width="100%">
      <thead class="thead-light">
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>#</th>
          <th>Imagen</th>
          <th>Pregunta</th>
          <th>Cód. Local</th>
          <th>Local</th>
          <th>Campaña</th>
          <th>Dirección</th>
          <th>Cadena</th>
          <th>Cuenta</th>
          <th>Usuario</th>
          <th>Fecha Subida</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($data)): ?>
          <tr><td colspan="12" class="text-center">Sin fotos de encuesta</td></tr>
        <?php else: $i = $offset + 1; foreach ($data as $r): 
            // 1) usuario
    $usuarioSafe = preg_replace('/[^a-zA-Z0-9]/','_', $r['usuario'] ?? '');

    // 2) dentro: material (implementación) o pregunta (encuesta)
    if ($view === 'implementacion') {
      $inner = $r['material']     ?? '';
    } else {
      $inner = $r['pregunta']     ?? '';
    }
    $innerSafe  = preg_replace('/[^a-zA-Z0-9]/','_', $inner);

    // 3) código de local
    $codigoSafe = preg_replace('/[^a-zA-Z0-9]/','_', $r['local_codigo'] ?? '');

    // 4) fecha formateada
    $fechaField = $view==='implementacion'
                  ? ($r['fechaVisita']  ?? null)
                  : ($r['fechaSubida'] ?? null);
    $fechaSafe  = $fechaField
                  ? date('Ymd_His', strtotime($fechaField))
                  : '';

    // 5) ensamblar prefijo, evitando guiones duplicados al final
    $prefix = trim("{$usuarioSafe}_{$innerSafe}_{$codigoSafe}", '_');
        
        
        
        ?>
          <tr>
   <td>
      <input type="checkbox" class="imgCheckbox"
             data-urls="<?= htmlspecialchars(implode('||',$r['photos']), ENT_QUOTES) ?>"
             data-prefix="<?= $prefix ?>">
    </td>
            <td><?= $i++ ?></td>
            <td class="custom-img-cell">
              <span class="badge-count"><?= $r['photos_count'] ?></span>
              <img src="<?= htmlspecialchars($r['thumbnail'], ENT_QUOTES) ?>"
                   class="thumbnail img-click"
                   data-urls="<?= htmlspecialchars(implode('||',$r['photos']), ENT_QUOTES) ?>">
            </td>
            <td><?= htmlspecialchars($r['pregunta'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['local_codigo'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['local_nombre'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['campaña_nombre'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['local_direccion'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['cadena_nombre'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['cuenta_nombre'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['usuario'], ENT_QUOTES) ?></td>
            <td><?= formatearFecha($r['fechaSubida']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  <?php endif; ?>


</div>

<!-- Modal de visualización -->
<div class="modal fade" id="fullSizeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-body p-0 text-center" id="modalBodyImgs"></div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<!-- Bootstrap 4 desde CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/visibility2/portal/dist/js/jquery.dataTables.min.js"></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/dataTables.buttons.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.colVis.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.html5.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.print.min.js'></script>
<script src='https://cdn.datatables.net/1.10.12/js/dataTables.bootstrap.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.bootstrap.min.js'></script>
<script src='https://cdnjs.cloudflare.com/ajax/libs/jszip/2.5.0/jszip.min.js'></script>
<script src='https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/vfs_fonts.js'></script>
<script src='https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/pdfmake.min.js'></script>
<!-- bs-custom-file-input -->
<script src="../plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>





<script>
    $(document).ready(function () {
	//Only needed for the filename of export files.
	//Normally set in the title tag of your page.
	document.title = "Simple DataTable";
	// Create search inputs in footer
	$("#example tfoot th").each(function () {
		var title = $(this).text();
		$(this).html('<input type="text" placeholder="Buscar ' + title + '" />');
	});
	// DataTable initialisation
	var table = $("#example").DataTable({
		dom: '<"dt-buttons"Bf><"clear">lirtp',
		paging: true,
		autoWidth: true,
		buttons: [
			"colvis",
			"copyHtml5",
			"csvHtml5",
			"excelHtml5",
			"pdfHtml5",
			"print"
		],
		initComplete: function (settings, json) {
			var footer = $("#example tfoot tr");
			$("#example thead").append(footer);
		}
	});

	// Apply the search
	$("#example thead").on("keyup", "input", function () {
		table.column($(this).parent().index())
		.search(this.value)
		.draw();
	});
});
</script>
<script>
  // Mostrar varias fotos
  $(document).on('click','.thumbnail.img-click',function(){
    var base = '<?= $base_url ?>',
        urls = $(this).data('urls').split('||'),
        $b   = $('#modalBodyImgs').empty();
    urls.forEach(function(u){
      var src = u.match(/^https?:\/\//) ? u : base + u.replace(/^\/+/, '');
      $b.append('<img src="'+ src +'" class="img-fluid mb-2" style="max-height:80vh">');
    });
    $('#fullSizeModal').modal('show');
  });

  // Select all
  $('#selectAll').change(function(){
    $('.imgCheckbox').prop('checked', $(this).prop('checked'));
  });

  // Descargar ZIP
  $('#btnDownloadSelected').click(function(){
    var toZip = [];
    $('.imgCheckbox:checked').each(function(){
      var urls   = $(this).data('urls').split('||'),
          prefix = $(this).data('prefix');
      urls.forEach(function(u){
        toZip.push({url: u, filename: prefix + '_' + u.split('/').pop()});
      });
    });
    if (!toZip.length) return alert('Selecciona al menos una foto.');
    $.ajax({
      url: 'download_zip.php',
      method: 'POST',
      data: { jsonFotos: JSON.stringify(toZip) },
      xhrFields: { responseType: 'blob' },
      success(data,_,xhr){
        var fname = 'fotos.zip',
            disp  = xhr.getResponseHeader('Content-Disposition') || '',
            m     = disp.match(/filename=(["']?)([^"'\n]*)/);
        if (m && m[2]) fname = m[2];
        var blob = new Blob([data],{type:'application/zip'}),
            link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fname;
        document.body.appendChild(link);
        link.click();
        link.remove();
      },
      error(_,__,e){ alert('Error al crear ZIP: ' + e); }
    });
  });

  // Cambiar limit
  $('#limitSelect').val('<?= $limit ?>').change(function(){
    var u = new URL(window.location.href);
    u.searchParams.set('limit',$(this).val());
    u.searchParams.set('page',1);
    window.location.href = u;
  });


   $(function(){
    // Tomar la forma de filtros
    const $filterForm = $('form.form-inline.mb-3');

    // Cada vez que cambie un <select> o un datepicker, reenviamos el formulario
    $filterForm.find('select, input[type="date"]').on('change', function(){
      $filterForm.submit();
    });

    // Para el input de código (texto), usamos keyup con debounce de 500 ms
    let debounceTimer;
    $filterForm.find('input[name="local_code"]').on('keyup', function(){
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        $filterForm.submit();
      }, 500);
    });
  });
  
  $(function(){
  const $f = $('form.form-inline.mb-3');

  // cada cambio en selects o datepickers hace submit
  $f.find('select, input[type="date"]').on('change', () => {
    $f.submit();
  });

  // para el filtro de código esperamos 500 ms tras la última tecla
  let debounceTimer;
  $f.find('input[name="local_code"]').on('keyup', function(){
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      $f.submit();
    }, 500);
  });
});
  
</script>
</body>
</html>
