<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// 1) Funciones auxiliares
// -----------------------------------------------------------------------------
function fixUrl(string $url, string $base_url): string {
    if (preg_match('#^https?://#i', $url)) return $url;
    $url = ltrim($url, '/');
    $url = preg_replace('#^(visibility2/app/|app/)#i', '', $url);
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

$division_id   = intval($_SESSION['division_id'] ?? 0);
$division      = isset($_GET['division']) ? intval($_GET['division']) : $division_id;
$formulario_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$view          = $_GET['view'] ?? 'implementacion';
$start_date    = trim($_GET['start_date'] ?? '');
$end_date      = trim($_GET['end_date'] ?? '');
$user_id       = intval($_GET['user_id'] ?? 0);
$material_id   = intval($_GET['material_id'] ?? 0);
$local_code    = trim($_GET['local_code'] ?? '');
$base_url      = "https://visibility.cl/visibility2/app/";

// -----------------------------------------------------------------------------
// 3) Divisiones
// -----------------------------------------------------------------------------
$divisiones = [];
$resDiv = $conn->query("SELECT id,nombre FROM division_empresa WHERE estado=1 ORDER BY nombre");
while ($r = $resDiv->fetch_assoc()) $divisiones[] = $r;


$params = [];
$types  = "";

// Filtros directos desde GET
$id_division     = intval($_GET['division'] ?? 0);
$id_subdivision  = intval($_GET['id_subdivision'] ?? 0);
$tipo_gestion    = intval($_GET['tipo_gestion'] ?? 0);
$id_campania     = intval($_GET['id'] ?? 0);

// -----------------------------------------------------------------------------
// 6) Consulta principal
// -----------------------------------------------------------------------------
if ($view === 'implementacion') {
    $sql = "
        SELECT
            MIN(fv.id) AS foto_id,
            GROUP_CONCAT(fv.url SEPARATOR '||') AS urls,
            fq.material,
            fq.fechaVisita,
            f.nombre AS campaña_nombre,
            l.codigo AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            co.comuna AS comuna_nombre,
            c.nombre AS cadena_nombre,
            ct.nombre AS cuenta_nombre,
            u.usuario
        FROM formularioQuestion fq
        JOIN formulario f ON f.id = fq.id_formulario
        JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        JOIN local l ON l.id = fq.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        JOIN cadena c ON c.id = l.id_cadena
        JOIN cuenta ct ON ct.id = l.id_cuenta
        JOIN usuario u ON u.id = fv.id_usuario
        WHERE f.estado = 1
          AND fq.fechaVisita IS NOT NULL
    ";
} else {
    $sql = "
        SELECT
            MIN(fqr.id) AS foto_id,
            GROUP_CONCAT(fqr.answer_text SEPARATOR '||') AS urls,
            fqr.created_at AS fechaSubida,
            fq.question_text AS pregunta,
            f.nombre AS campaña_nombre,
            l.codigo AS local_codigo,
            l.nombre AS local_nombre,
            l.direccion AS local_direccion,
            co.comuna AS comuna_nombre,
            c.nombre AS cadena_nombre,
            ct.nombre AS cuenta_nombre,
            u.usuario
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        JOIN formulario f ON f.id = fq.id_formulario
        JOIN local l ON l.id = fqr.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        JOIN cadena c ON c.id = l.id_cadena
        JOIN cuenta ct ON ct.id = l.id_cuenta
        JOIN usuario u ON u.id = fqr.id_usuario
        WHERE f.estado = 1
          AND fq.id_question_type = 7
          AND fqr.id_local <> 0
    ";
}

// -----------------------------------------------------------------------------
// 7) Aplicar filtros dinámicos según selects JSON
// -----------------------------------------------------------------------------

// División
if ($id_division > 0) {
    $sql   .= " AND f.id_division = ? ";
    $types .= "i";
    $params[] = $id_division;
}

// Subdivisión
if ($id_subdivision > 0) {
    $sql   .= " AND f.id_subdivision = ? ";
    $types .= "i";
    $params[] = $id_subdivision;
}

// Tipo de gestión
if ($tipo_gestion > 0) {
    $sql   .= " AND f.tipo = ? ";
    $types .= "i";
    $params[] = $tipo_gestion;
}

// Campaña (formulario)
if ($id_campania > 0) {
    $sql   .= " AND f.id = ? ";
    $types .= "i";
    $params[] = $id_campania;
}

// Fechas
if ($start_date !== '') {
    $field = ($view === 'implementacion' ? 'fq.fechaVisita' : 'fqr.created_at');
    $sql   .= " AND DATE({$field}) >= ? ";
    $types .= "s";
    $params[] = $start_date;
}
if ($end_date !== '') {
    $field = ($view === 'implementacion' ? 'fq.fechaVisita' : 'fqr.created_at');
    $sql   .= " AND DATE({$field}) <= ? ";
    $types .= "s";
    $params[] = $end_date;
}

// -----------------------------------------------------------------------------
// 8) Agrupar y ordenar
// -----------------------------------------------------------------------------
$sql .= "
    GROUP BY " . ($view === 'implementacion'
        ? "u.id, l.id, f.nombre, fq.material, fq.fechaVisita"
        : "fqr.id_usuario, fqr.id_local, fqr.id_form_question"
    ) . "
    ORDER BY " . ($view === 'implementacion' ? 'fq.fechaVisita' : 'fqr.created_at') . " DESC
";

// -----------------------------------------------------------------------------
// 8) Ejecutar consulta solo si hay filtros activos
// -----------------------------------------------------------------------------
$data = [];

// ✅ Condición: solo ejecutar si hay al menos un filtro definido
if ($id_division > 0 || $id_subdivision > 0 || $tipo_gestion > 0 || $id_campania > 0 || $start_date !== '' || $end_date !== '') {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $rawUrls = explode('||', $row['urls']);
        $fixed   = array_map(fn($u) => fixUrl($u, $base_url), $rawUrls);
        $row['photos']       = $fixed;
        $row['photos_count'] = count($fixed);
        $row['thumbnail']    = $fixed[0] ?? null;
        $data[] = $row;
    }

    $stmt->close();
} else {
    // 👇 Sin filtros → no ejecutar consulta, dejar data vacía
    $data = [];
}
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
#loader-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(3px);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 2000;
}

#loader-overlay.active {
  display: flex;
}

#loader-overlay .loader-content {
  text-align: center;
} 

/* ===== Estilo general de los filtros ===== */
.filtros-container {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem 1.5rem; /* Espaciado entre filtros */
}

.filtro-item {
  display: flex;
  flex-direction: column;
  min-width: 180px;
}

.filtro-item label {
  font-weight: 600;
  font-size: 0.85rem;
  margin-bottom: 4px;
  color: #444;
}

.filtro-item select,
.filtro-item input[type="date"] {
  min-width: 180px;
  max-width: 220px;
  height: 38px;
  padding: 6px 10px;
}

.btn-filtrar {
  align-self: flex-end;
  height: 38px;
  margin-top: 1.6rem; /* Alinear con los selects */
}

/* Responsivo */
@media (max-width: 768px) {
  .filtro-item select,
  .filtro-item input[type="date"] {
    width: 100%;
  }
  .btn-filtrar {
    width: 100%;
  }
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

<!-- 🧭 FILTROS -->
<form method="GET" class="form-inline flex-wrap align-items-end mb-4 filtros-container">
  <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">

  <!-- 🔹 División -->
  <?php if ($division_id === 1): ?>
    <div class="form-group filtro-item">
      <label for="divisionSelect">División:</label>
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

  <!-- 🔹 Subdivisión -->
  <div class="form-group filtro-item">
    <label for="id_subdivision">Subdivisión:</label>
    <?php $val_subdivision = intval($_GET['id_subdivision'] ?? 0); ?>
    <select id="id_subdivision" name="id_subdivision" class="form-control" disabled>
      <option value="0" selected>Todas</option>
    </select>
  </div>

  <!-- 🔹 Tipo de gestión -->
  <?php $tipoCampana = intval($_GET['tipo_gestion'] ?? 0); ?>
  <div class="form-group filtro-item">
    <label for="tipo_gestion">Tipo de gestión:</label>
    <select id="tipo_gestion" name="tipo_gestion" class="form-control">
      <option value="0" <?= ($tipoCampana==0)?'selected':'' ?>>TODAS</option>
      <option value="1" <?= ($tipoCampana==1)?'selected':'' ?>>CAMPAÑA</option>
      <option value="3" <?= ($tipoCampana==3)?'selected':'' ?>>RUTA</option>
    </select>
  </div>

  <!-- 🔹 Campaña -->
  <?php $val_campana = intval($_GET['id'] ?? 0); ?>
  <div class="form-group filtro-item">
    <label for="campaignSelect">Campaña:</label>
    <select id="campaignSelect" name="id" class="form-control" disabled>
      <option value="0" selected>-- Todas --</option>
    </select>
  </div>

  <!-- 🔹 Fechas -->
  <div class="form-group filtro-item">
    <label for="start_date">Desde:</label>
    <input type="date" id="start_date" name="start_date" class="form-control" 
           value="<?=htmlspecialchars($start_date)?>">
  </div>

  <div class="form-group filtro-item">
    <label for="end_date">Hasta:</label>
    <input type="date" id="end_date" name="end_date" class="form-control" 
           value="<?=htmlspecialchars($end_date)?>">
  </div>

  <!-- 🔹 Botón -->
  <div class="form-group filtro-item">
    <button type="submit" class="btn btn-primary btn-filtrar">
      <i class="fas fa-filter"></i> Filtrar
    </button>
  </div>
</form>

<!-- 🔽 Descargar ZIP -->
<form id="zipForm" method="POST" action="download_zip.php" style="display:none">
  <input type="hidden" name="jsonFotos" id="jsonFotos">
</form>

<button id="btnDownloadSelected" class="btn btn-secondary mb-3">
  <i class="fas fa-download"></i> Descargar seleccionadas
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

<!-- 🌀 Loader general -->
<div id="loader-overlay">
  <div class="loader-content">
    <div class="spinner-border text-light" role="status">
      <span class="sr-only">Cargando...</span>
    </div>
    <p class="mt-3 text-white">Cargando datos, por favor espera...</p>
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

  $(function(){
  const $f = $('form.form-inline.mb-3');

  $f.find('select:not(#divisionSelect, #id_subdivision, #campaignSelect), input[type="date"]').on('change', function(){
    // No se hace submit automático, solo podrías agregar aquí lógica opcional
    console.log('Filtro cambiado:', this.name, this.value);
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
  
// ============================================
// 🧩 SUBDIVISIONES DINÁMICAS (con loader seguro)
// ============================================
document.addEventListener('DOMContentLoaded', function () {
  const $division = document.getElementById('divisionSelect');
  const $subdivision = document.getElementById('id_subdivision');
  const loader = document.getElementById('loader-overlay');

  // ✅ Valores actuales del filtro (si vienen desde $_GET)
  const val_division = <?= isset($division) ? intval($division) : 0 ?>;
  const val_subdivision = <?= isset($_GET['id_subdivision']) ? intval($_GET['id_subdivision']) : 0 ?>;

  // 🔧 Helpers para manejar el select
  function resetSelect(select, text) {
    select.innerHTML = `<option value="0">${text}</option>`;
  }

  function setOptions(select, items) {
    select.innerHTML = '';
    items.forEach(it => {
      const opt = document.createElement('option');
      opt.value = it.id;
      opt.textContent = it.nombre;
      select.appendChild(opt);
    });
  }

  // 🚀 Cargar subdivisiones cuando cambia la división
  if ($division) {
    $division.addEventListener('change', function () {
      const idDivision = parseInt(this.value, 10) || 0;

      // 🔹 Si no hay división válida → limpiar y desactivar
      if (idDivision <= 0) {
        resetSelect($subdivision, 'Sin subdivisión');
        $subdivision.disabled = true;
        return;
      }

      // 🌀 Mostrar loader y bloquear select
      loader.classList.add('active');
      $subdivision.disabled = true;
      resetSelect($subdivision, 'Cargando...');

      // 🔄 Fetch JSON dinámico
      fetch(`../mod_cargar/cargar_subdivisiones.php?id_division=${idDivision}`, { credentials: 'same-origin' })
        .then(r => {
          if (!r.ok) throw new Error('Error HTTP ' + r.status);
          return r.json();
        })
        .then(data => {
          let items = [];

          if (data.ok && Array.isArray(data.subdivisiones) && data.subdivisiones.length > 0) {
            // ✅ Si hay subdivisiones → solo "Todas" + lista
            items = [{ id: 0, nombre: 'Todas' }, ...data.subdivisiones];
          } else {
            // ⚠️ Si no hay subdivisiones → solo "Sin Subdivisión"
            items = [{ id: -1, nombre: 'Sin Subdivisión' }];
          }

          setOptions($subdivision, items);
          $subdivision.disabled = false;

          // 🔁 Restaurar selección previa si existe
          if (val_subdivision !== 0 && $subdivision.querySelector(`option[value="${val_subdivision}"]`)) {
            $subdivision.value = val_subdivision;
          }

          console.log('🔹 Subdivisiones cargadas:', items);
        })
        .catch(err => {
          console.error('⚠️ Error cargando subdivisiones:', err);
          resetSelect($subdivision, 'Error al cargar');
          $subdivision.disabled = false;
        })
        .finally(() => {
          // ✅ Siempre apagar loader al final
          loader.classList.remove('active');
        });
    });

    // ✅ Si ya hay división seleccionada, cargar subdivisiones al entrar
    if (val_division > 0) {
      $division.value = val_division;
      $division.dispatchEvent(new Event('change'));
    }
  }
});

/* ============================================
   🧩 CARGAR CAMPAÑAS DINÁMICAS
============================================ */
document.addEventListener('DOMContentLoaded', function () {
  const loader        = document.getElementById('loader-overlay');
  const $division     = document.getElementById('divisionSelect');
  const $subdivision  = document.getElementById('id_subdivision');
  const $tipoGestion  = document.getElementById('tipo_gestion');
  const $campana      = document.getElementById('campaignSelect');

  const val_campana = <?= isset($_GET['id']) ? intval($_GET['id']) : 0 ?>;

  function resetSelect(select, text) {
    select.innerHTML = `<option value="0">${text}</option>`;
  }
  function setOptions(select, items) {
    select.innerHTML = '';
    items.forEach(it => {
      const opt = document.createElement('option');
      opt.value = it.id;
      opt.textContent = it.nombre;
      select.appendChild(opt);
    });
  }

  // 🧠 Función principal: cargar campañas según filtros
  function cargarCampanas() {
    const idDivision    = parseInt($division?.value || 0, 10);
    const idSubdivision = parseInt($subdivision?.value || 0, 10);
    const tipoGestion   = parseInt($tipoGestion?.value || 0, 10);

    if (!idDivision) {
      resetSelect($campana, '-- Todas --');
      $campana.disabled = true;
      return;
    }

    loader.classList.add('active');
    $campana.disabled = true;
    resetSelect($campana, 'Cargando...');

    const url = `../mod_cargar/cargar_campanas3.php?id_division=${idDivision}&id_subdivision=${idSubdivision}&tipo_gestion=${tipoGestion}`;
    fetch(url, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        const items = (data.ok && Array.isArray(data.campanas))
          ? [{ id: 0, nombre: '-- Todas --' }, ...data.campanas]
          : [{ id: 0, nombre: '-- Sin campañas --' }];
        setOptions($campana, items);
        $campana.disabled = false;

        if (val_campana !== 0 && $campana.querySelector(`option[value="${val_campana}"]`)) {
          $campana.value = val_campana;
        }
      })
      .catch(err => {
        console.error('⚠️ Error cargando campañas:', err);
        resetSelect($campana, 'Error al cargar');
      })
      .finally(() => {
        loader.classList.remove('active');
      });
  }

  // 🚀 Disparadores: cualquier cambio en filtros relacionados recarga campañas
  [$division, $subdivision, $tipoGestion].forEach(el => {
    if (el) el.addEventListener('change', cargarCampanas);
  });

  // ✅ Cargar campañas iniciales si ya hay división
  if (parseInt($division?.value || 0) > 0) {
    cargarCampanas();
  }
});

/* ============================================
   🧭 Loader Global + Subdivisiones Dinámicas
============================================ */
document.addEventListener('DOMContentLoaded', function () {
  const loader        = document.getElementById('loader-overlay');
  const $division     = document.getElementById('divisionSelect');
  const $subdivision  = document.getElementById('id_subdivision');
  const $tipoGestion  = document.getElementById('tipo_gestion');

  // ✅ Valores actuales del filtro
  const val_division    = <?= isset($division) ? intval($division) : 0 ?>;
  const val_subdivision = <?= isset($_GET['id_subdivision']) ? intval($_GET['id_subdivision']) : 0 ?>;

  // 🔧 Funciones auxiliares
  function resetSelect(select, text) {
    select.innerHTML = `<option value="0">${text}</option>`;
  }
  function setOptions(select, items) {
    select.innerHTML = '';
    items.forEach(it => {
      const opt = document.createElement('option');
      opt.value = it.id;
      opt.textContent = it.nombre;
      select.appendChild(opt);
    });
  }

  // 🚀 Cargar subdivisiones al cambiar la división
  if ($division) {
    $division.addEventListener('change', function () {
      const idDivision = parseInt(this.value, 10) || 0;

      if (idDivision <= 0) {
        resetSelect($subdivision, 'Sin subdivisión');
        $subdivision.disabled = true;
        return;
      }

      loader.classList.add('active');
      $subdivision.disabled = true;
      resetSelect($subdivision, 'Cargando...');

      fetch(`../mod_cargar/cargar_subdivisiones.php?id_division=${idDivision}`, { credentials: 'same-origin' })
        .then(r => {
          if (!r.ok) throw new Error('Error HTTP ' + r.status);
          return r.json();
        })
        .then(data => {
          let items = [];

          if (data.ok && Array.isArray(data.subdivisiones) && data.subdivisiones.length > 0) {
            items = [{ id: 0, nombre: 'Todas' }, ...data.subdivisiones];
          } else {
            items = [{ id: -1, nombre: 'Sin Subdivisión' }];
          }

          setOptions($subdivision, items);
          $subdivision.disabled = false;

          if (val_subdivision !== 0 && $subdivision.querySelector(`option[value="${val_subdivision}"]`)) {
            $subdivision.value = val_subdivision;
          }

          console.log('🔹 Subdivisiones cargadas:', items);
        })
        .catch(err => {
          console.error('⚠️ Error cargando subdivisiones:', err);
          resetSelect($subdivision, 'Error al cargar');
          $subdivision.disabled = false;
        })
        .finally(() => {
          loader.classList.remove('active');
        });
    });

    // ✅ Cargar subdivisiones iniciales si ya hay división seleccionada
    if (val_division > 0) {
      $division.value = val_division;
      $division.dispatchEvent(new Event('change'));
    }
  }

  // 🧩 Evitar que el loader se quede pegado al cambiar tipo_gestion
  if ($tipoGestion) {
    $tipoGestion.addEventListener('change', function () {
      // No mostramos el loader aquí, solo podríamos hacer un submit manual si lo deseas
      console.log('Tipo de gestión cambiado:', this.value);
    });
  }

  // 🧭 Loader en formularios normales
  const mainForm = document.querySelector('form.form-inline.mb-3');
  if (mainForm) {
    mainForm.addEventListener('submit', () => {
      loader.classList.add('active');
    });
  }

  window.addEventListener('load', () => {
    loader.classList.remove('active');
  });

  // ⚠️ Mostrar loader sólo en filtros que realmente hacen submit
  const selects = document.querySelectorAll('select:not(#divisionSelect, #id_subdivision, #tipo_gestion)');
  selects.forEach(sel => {
    sel.addEventListener('change', () => loader.classList.add('active'));
  });
});
 
</script>
</body>
</html>
