<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// 1) Funciones auxiliares
// -----------------------------------------------------------------------------

function fixUrl(string $url, string $base_url): string {
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $url = ltrim($url, '/');
    $url = preg_replace('#^(visibility2/app/|app/)#i', '', $url);
    return rtrim($base_url, '/') . '/' . ltrim($url, '/');
}

function formatearFecha($f): string {
    return $f ? date('d/m/Y H:i:s', strtotime($f)) : '';
}

// -----------------------------------------------------------------------------
// 2) Includes
// -----------------------------------------------------------------------------

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// -----------------------------------------------------------------------------
// 3) Parámetros
// -----------------------------------------------------------------------------

$division_id = intval($_SESSION['division_id'] ?? 0);
$division    = isset($_GET['division']) ? intval($_GET['division']) : $division_id;

$divisionLogin = (int)$_SESSION['division_id'];

$subdivision = intval($_GET['subdivision'] ?? 0);
$region   = intval($_GET['region']   ?? 0);
$zona     = intval($_GET['zona']     ?? 0);
$distrito = intval($_GET['distrito'] ?? 0);
$comuna   = intval($_GET['comuna']   ?? 0);

$usuarioFiltro    = intval($_GET['usuario'] ?? 0);
$jefeVentaFiltro  = intval($_GET['jefe_venta'] ?? 0);

$view       = $_GET['view'] ?? 'implementacion';
$start_date = trim($_GET['start_date'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');

$base_url = "https://visibility.cl/visibility2/app/";

// Fecha por defecto: HOY
if ($start_date === '' && $end_date === '') {
    $today = date('Y-m-d');
    $start_date = $today;
    $end_date   = $today;
}

// -----------------------------------------------------------------------------
// 4) Cargar divisiones (solo para el selector)
// -----------------------------------------------------------------------------

$divisiones = [];
$resDiv = $conn->query("SELECT id,nombre FROM division_empresa WHERE estado=1 ORDER BY nombre");
while ($r = $resDiv->fetch_assoc()) {
    $divisiones[] = $r;
}


if ($subdivision > 0 && $division > 0) {
    $stmtCheck = $conn->prepare("
        SELECT 1
        FROM subdivision
        WHERE id = ?
          AND id_division = ?
        LIMIT 1
    ");
    $stmtCheck->bind_param("ii", $subdivision, $division);
    $stmtCheck->execute();
    $stmtCheck->store_result();

    if ($stmtCheck->num_rows === 0) {
        // Subdivisión inválida → la ignoramos
        $subdivision = 0;
    }

    $stmtCheck->close();
}


// --------------------------------------
// Jefes de venta
// --------------------------------------
$jefesVenta = [];
$sqlJV = "SELECT id, nombre FROM jefe_venta ORDER BY nombre ASC";
$resJV = $conn->query($sqlJV);
while ($jv = $resJV->fetch_assoc()) {
    $jefesVenta[] = $jv;
}


// -----------------------------------------------------------------------------
// 5) Construcción de filtros BASE
// -----------------------------------------------------------------------------

$where  = "1=1";
$params = [];
$types  = "";

// División
if ($division > 0) {
    $where  .= " AND f.id_division = ?";
    $types  .= "i";
    $params[] = $division;
}

if ($subdivision > 0) {
    $where  .= " AND f.id_subdivision = ?";
    $types  .= "i";
    $params[] = $subdivision;
}

// Región (vía comuna)
if ($region > 0) {
    $where  .= " AND r.id = ?";
    $types  .= "i";
    $params[] = $region;
}

// Zona (vía distrito)
if ($zona > 0) {
    $where  .= " AND z.id = ?";
    $types  .= "i";
    $params[] = $zona;
}

// Distrito
if ($distrito > 0) {
    $where  .= " AND d.id = ?";
    $types  .= "i";
    $params[] = $distrito;
}

// Comuna
if ($comuna > 0) {
    $where  .= " AND co.id = ?";
    $types  .= "i";
    $params[] = $comuna;
}

if ($usuarioFiltro > 0) {
    if ($view === 'implementacion') {
        $where .= " AND fv.id_usuario = ?";
    } else {
        $where .= " AND fqr.id_usuario = ?";
    }
    $types .= "i";
    $params[] = $usuarioFiltro;
}

// Jefe de venta
if ($jefeVentaFiltro > 0) {
    $where .= " AND l.id_jefe_venta = ?";
    $types .= "i";
    $params[] = $jefeVentaFiltro;
}

if (!empty($_GET['comuna'])) {
  $where .= " AND l.id_comuna = ?";
  $params[] = $_GET['comuna'];
  $types .= "i";
}

if (!empty($_GET['zona'])) {
  $where .= " AND l.id_zona = ?";
  $params[] = $_GET['zona'];
  $types .= "i";
}

if (!empty($_GET['distrito'])) {
  $where .= " AND l.id_distrito = ?";
  $params[] = $_GET['distrito'];
  $types .= "i";
}

// Fechas
$fieldFecha = ($view === 'implementacion') ? 'fq.fechaVisita' : 'fqr.created_at';

if ($start_date !== '') {
    $where  .= " AND {$fieldFecha} >= ?";
    $types  .= "s";
    $params[] = $start_date . " 00:00:00";
}

if ($end_date !== '') {
    $where  .= " AND {$fieldFecha} <= ?";
    $types  .= "s";
    $params[] = $end_date . " 23:59:59";
}

// -----------------------------------------------------------------------------
// 6) Query principal
// -----------------------------------------------------------------------------

if ($view === 'implementacion') {

    $sql = "
        SELECT
            MIN(fv.id) AS foto_id,
            GROUP_CONCAT(fv.url SEPARATOR '||') AS urls,
            fq.material,
            fq.fechaVisita,
            f.nombre AS campaña_nombre,
            TRIM(SUBSTRING_INDEX(l.codigo, '-', -1)) AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            co.comuna AS comuna_nombre,
            r.region AS region_nombre,
            d.nombre_distrito AS distrito_nombre,
            z.nombre_zona AS zona_nombre,
            c.nombre AS cadena_nombre,
            ct.nombre AS cuenta_nombre,
            u.usuario
        FROM formularioQuestion fq
        JOIN formulario f        ON f.id = fq.id_formulario
        JOIN fotoVisita fv       ON fv.id_formularioQuestion = fq.id
        JOIN local l             ON l.id = fq.id_local
        LEFT JOIN comuna co      ON co.id = l.id_comuna
        LEFT JOIN region r       ON r.id = co.id_region
        LEFT JOIN distrito d     ON d.id = l.id_distrito
        LEFT JOIN zona z         ON z.id = d.id_zona
        LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
        JOIN cadena c            ON c.id = l.id_cadena
        JOIN cuenta ct           ON ct.id = l.id_cuenta
        JOIN usuario u           ON u.id = fv.id_usuario
        WHERE {$where}
          AND fq.fechaVisita IS NOT NULL
        GROUP BY u.id, l.id, fq.material, fq.fechaVisita
        ORDER BY fq.fechaVisita DESC
    ";

} else {

    $sql = "
        SELECT
            MIN(fqr.id) AS foto_id,
            GROUP_CONCAT(fqr.answer_text SEPARATOR '||') AS urls,
            fqr.created_at AS fechaSubida,
            UPPER(fq.question_text) AS pregunta,
            f.nombre AS campaña_nombre,
            TRIM(SUBSTRING_INDEX(l.codigo, '-', -1)) AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            co.comuna AS comuna_nombre,
            r.region AS region_nombre,
            d.nombre_distrito AS distrito_nombre,
            z.nombre_zona AS zona_nombre,
            c.nombre AS cadena_nombre,
            ct.nombre AS cuenta_nombre,
            u.usuario
        FROM form_question_responses fqr
        JOIN form_questions fq    ON fq.id = fqr.id_form_question
        JOIN formulario f         ON f.id = fq.id_formulario
        JOIN local l              ON l.id = fqr.id_local
        LEFT JOIN comuna co       ON co.id = l.id_comuna
        LEFT JOIN region r        ON r.id = co.id_region
        LEFT JOIN distrito d      ON d.id = l.id_distrito
        LEFT JOIN zona z          ON z.id = d.id_zona
        LEFT JOIN jefe_venta jv   ON jv.id = l.id_jefe_venta
        JOIN cadena c             ON c.id = l.id_cadena
        JOIN cuenta ct            ON ct.id = l.id_cuenta
        JOIN usuario u            ON u.id = fqr.id_usuario
        WHERE {$where}
          AND fq.id_question_type = 7
          AND fqr.id_local <> 0
        GROUP BY fqr.id_usuario, fqr.id_local, fqr.id_form_question
        ORDER BY fqr.created_at DESC
    ";
}

// -----------------------------------------------------------------------------
// 7) Ejecutar
// -----------------------------------------------------------------------------

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();

$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $rawUrls = explode('||', $row['urls']);
    $fixed = array_map(fn($u) => fixUrl($u, $base_url), $rawUrls);

    $row['photos']       = $fixed;
    $row['photos_count'] = count($fixed);
    $row['thumbnail']    = $fixed[0] ?? null;

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
  <link rel="stylesheet"
      href="/visibility2/portal/css/mod_galeria.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/css/mod_galeria.css') ?>">
    <link rel="stylesheet"
          href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet"
          href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">   
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <style>
        .select2-container--default .select2-selection--single {
          height: 38px;
          padding: 5px 10px;
        }
        
        .select2-selection__rendered {
          line-height: 28px !important;
        }
        
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
      <label for="subdivisionSelect" class="mr-2 mb-0">Subdivisión:</label>
      <select id="subdivisionSelect" name="subdivision" class="form-control">
        <option value="0">-- Todas --</option>
      </select>
    </div>   

    <?php if ($divisionLogin != 14): ?>
    
      <div class="form-group mr-3 mb-2">
        <label class="mr-2 mb-0">Región:</label>
        <select id="regionSelect" name="region" class="form-control">
          <option value="0">-- Todas --</option>
        </select>
      </div>
      
      <div class="form-group mr-3 mb-2">
        <label class="mr-2 mb-0">Comuna:</label>
        <select id="comunaSelect" name="comuna" class="form-control">
          <option value="0">-- Todas --</option>
        </select>
      </div>
    
    <?php endif; ?>
    
    <div class="form-group mr-3 mb-2">
      <label class="mr-2 mb-0">Zona:</label>
      <select id="zonaSelect" name="zona" class="form-control">
        <option value="0">-- Todas --</option>
      </select>
    </div>
    
    <div class="form-group mr-3 mb-2">
      <label class="mr-2 mb-0">Distrito:</label>
      <select id="distritoSelect" name="distrito" class="form-control">
        <option value="0">-- Todos --</option>
      </select>
    </div>

    <div class="form-group mr-3 mb-2">
      <label class="mr-2 mb-0">Usuario:</label>
      <select id="usuarioSelect" name="usuario" class="form-control">
        <option value="0">-- Todos --</option>
      </select>
    </div>
    
    <div class="form-group mr-3 mb-2">
      <label class="mr-2 mb-0">Jefe de Venta:</label>
      <select name="jefe_venta" id="jefe_ventaSelect" class="form-control">
        <option value="0">-- Todos --</option>
        <?php foreach ($jefesVenta as $jv): ?>
          <option value="<?= $jv['id'] ?>"
            <?= $jefeVentaFiltro == $jv['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($jv['nombre']) ?>
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

  <div class="form-group mb-2">
    <button type="button" id="btnFiltrar" class="btn btn-primary">
      <i class="fas fa-filter"></i> Filtrar
    </button>
  </div>
</form>

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
        <?php else: $i = 1; foreach ($data as $r): 
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
            <td></td>
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
          <th>Campaña</th> 
          <th>Pregunta</th>          
          <th>Cód. Local</th>          
          <th>Local</th>
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
        <?php else: $i = 1; foreach ($data as $r): 
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
            <td></td>
            <td class="custom-img-cell">
              <span class="badge-count"><?= $r['photos_count'] ?></span>
              <img src="<?= htmlspecialchars($r['thumbnail'], ENT_QUOTES) ?>"
                   class="thumbnail img-click"
                   data-urls="<?= htmlspecialchars(implode('||',$r['photos']), ENT_QUOTES) ?>">
            </td>
            <td><?= htmlspecialchars($r['campaña_nombre'], ENT_QUOTES) ?></td>  
            <td><?= htmlspecialchars($r['pregunta'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['local_codigo'], ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['local_nombre'], ENT_QUOTES) ?></td>
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
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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
$(function () {

  $('#usuarioSelect').select2({
    placeholder: "Buscar usuario...",
    allowClear: true,
    width: '200px' // ajusta si quieres
  });
  
  $('#jefe_ventaSelect').select2({
    placeholder: "Buscar jefe de venta...",
    allowClear: true,
    width: '200px' // ajusta si quieres
  });  

});
    
</script>
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

    $(function(){
    
      const $form = $('form.form-inline.mb-3');
    
      // Solo filtrar cuando se presiona el botón
      $('#btnFiltrar').on('click', function () {
        $form.submit();
      });
    
    });

    $(function(){
    
      const $form = $('form.form-inline.mb-3');
      const $btn  = $('#btnFiltrar');
    
      $form.find('select, input[type="date"]').on('change', function(){
        $btn
          .removeClass('btn-primary')
          .addClass('btn-warning')
          .text('Aplicar filtros');
      });
    
      $btn.on('click', function(){
        $form.submit();
      });
    
    });

</script>

<script>
window.GALERIA_FILTROS = {
  division: <?= (int)$division ?>,
  usuario: <?= (int)$usuarioFiltro ?>
  subdivision: <?= (int)$subdivision ?>,
  region: <?= (int)($_GET['region'] ?? 0) ?>,
  comuna: <?= (int)($_GET['comuna'] ?? 0) ?>,
  zona: <?= (int)($_GET['zona'] ?? 0) ?>,
  distrito: <?= (int)($_GET['distrito'] ?? 0) ?>
};
</script>

<script>
$(function () {

  /* ===============================
     Estado inicial desde PHP
  =============================== */
  const F = window.GALERIA_FILTROS || {
    division: 0,
    subdivision: 0,
    region: 0,
    comuna: 0,
    zona: 0,
    distrito: 0
  };

  /* ===============================
     DIVISION → USUARIO
  =============================== */

$(function () {

  const currentDivision = window.GALERIA_FILTROS?.division || 0;
  const currentUsuario  = window.GALERIA_FILTROS?.usuario || 0;

  function loadUsuarios(division, selected) {
    const $user = $('#usuarioSelect');
    $user.html('<option value="0">-- Todos --</option>');

    if (!division || division == 0) return;

    $.getJSON('/visibility2/portal/modulos/mod_galeria/ajax_usuarios.php',
      { division: division },
      function (data) {

        data.forEach(function (u) {
          $user.append(
            $('<option>', {
              value: u.id,
              text: u.nombre_completo,
              selected: u.id == selected
            })
          );
        });

      });
  }

  // Cuando cambia la división
  $('#divisionSelect').on('change', function () {
    loadUsuarios($(this).val(), 0);
  });

  // Carga inicial (cuando viene por GET)
  if (currentDivision > 0) {
    loadUsuarios(currentDivision, currentUsuario);
  }

});


  /* ===============================
     DIVISIÓN → SUBDIVISIÓN
  =============================== */
  function loadSubdivisions(division, selected) {
    const $sub = $('#subdivisionSelect');
    $sub.html('<option value="0">-- Todas --</option>');

    if (!division || division == 0) return;

    $.getJSON('ajax_subdivisiones.php', { division }, function (data) {
      data.forEach(s => {
        $sub.append(
          $('<option>', {
            value: s.id,
            text: s.nombre,
            selected: s.id == selected
          })
        );
      });
    });
  }

  $('#divisionSelect').on('change', function () {
    loadSubdivisions(this.value, 0);
  });

  if (F.division > 0) {
    $('#divisionSelect').val(F.division);
    loadSubdivisions(F.division, F.subdivision);
  }

  /* ===============================
     REGIÓN → COMUNA
  =============================== */
  function loadRegiones() {
    $.getJSON('ajax_regiones.php', function (data) {
      const $r = $('#regionSelect');
      $r.html('<option value="0">-- Todas --</option>');

      data.forEach(r => {
        $r.append(
          $('<option>', {
            value: r.id,
            text: r.nombre,
            selected: r.id == F.region
          })
        );
      });

      if (F.region > 0) {
        loadComunas(F.region, F.comuna);
      }
    });
  }

  function loadComunas(region, selected) {
    const $c = $('#comunaSelect');
    $c.html('<option value="0">-- Todas --</option>');

    if (!region || region == 0) return;

    $.getJSON('ajax_comunas.php', { region }, function (data) {
      data.forEach(c => {
        $c.append(
          $('<option>', {
            value: c.id,
            text: c.nombre,
            selected: c.id == selected
          })
        );
      });
    });
  }

  $('#regionSelect').on('change', function () {
    loadComunas(this.value, 0);
  });

  loadRegiones();

  /* ===============================
     ZONA → DISTRITO
  =============================== */
  function loadZonas() {
    $.getJSON('ajax_zonas.php', function (data) {
      const $z = $('#zonaSelect');
      $z.html('<option value="0">-- Todas --</option>');

      data.forEach(z => {
        $z.append(
          $('<option>', {
            value: z.id,
            text: z.nombre,
            selected: z.id == F.zona
          })
        );
      });

      if (F.zona > 0) {
        loadDistritos(F.zona, F.distrito);
      }
    });
  }

  function loadDistritos(zona, selected) {
    const $d = $('#distritoSelect');
    $d.html('<option value="0">-- Todos --</option>');

    if (!zona || zona == 0) return;

    $.getJSON('ajax_distritos.php', { zona }, function (data) {
      data.forEach(d => {
        $d.append(
          $('<option>', {
            value: d.id,
            text: d.nombre,
            selected: d.id == selected
          })
        );
      });
    });
  }

  $('#zonaSelect').on('change', function () {
    loadDistritos(this.value, 0);
  });

  loadZonas();

});
</script>




</body>
</html>
