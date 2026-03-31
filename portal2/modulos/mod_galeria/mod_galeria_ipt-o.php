<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// 1) Funciones auxiliares
// -----------------------------------------------------------------------------

// Función auxiliar para pasar parámetros por referencia (necesaria en PHP < 7 para bind_param)
function refValues($arr) {
    if (strnatcmp(phpversion(), '5.3') >= 0) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    }
    return $arr;
}

// Construye la URL absoluta final de la imagen
function fixUrl($url, $base_url) {
    $prefix = "../app/";
    if (substr($url, 0, strlen($prefix)) === $prefix) {
        $url = substr($url, strlen($prefix));
    }
    return $base_url . $url;
}

// Función para formatear fecha
function formatearFecha($f) {
    return $f ? date('d/m/Y H:i:s', strtotime($f)) : '';
}

// -----------------------------------------------------------------------------
// 2) Includes y validaciones iniciales
// -----------------------------------------------------------------------------
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Valor de división del usuario desde la sesión
$division_id = isset($_SESSION['division_id']) ? intval($_SESSION['division_id']) : 0;

// Si se envía un valor por GET, lo usamos; de lo contrario, usamos el valor de sesión.
$division = isset($_GET['division']) ? intval($_GET['division']) : $division_id;

// Obtener el id de distrito desde GET (0 por defecto)
$distrito = isset($_GET['distrito']) ? intval($_GET['distrito']) : 0;


if ($division > 0 && $division != 1) {
    // Para divisiones distintas de 1, verificamos que exista un formulario de tipo 3
    $stmtTipo = $conn->prepare("SELECT id_division FROM formulario WHERE id_division = ? AND tipo = 3 LIMIT 1");
    $stmtTipo->bind_param("i", $division);
    $stmtTipo->execute();
    $stmtTipo->bind_result($idDivision);
    if (!$stmtTipo->fetch()) {
        die("<div class='alert alert-danger'>No se encontró un formulario de tipo 3 para esa división.</div>");
    }
    $stmtTipo->close();
    $tipoForm = 3; // Forzamos el tipo a 3
} else {
    // Si el usuario es de la división 1, omitir la validación y forzar tipo 3
    $tipoForm = 3;
}
// -----------------------------------------------------------------------------
// 3) Parámetros de filtrado y paginación
// -----------------------------------------------------------------------------
$start_date  = isset($_GET['start_date'])  ? trim($_GET['start_date'])  : '';
$end_date    = isset($_GET['end_date'])    ? trim($_GET['end_date'])    : '';
$user_id     = isset($_GET['user_id'])     ? (int)$_GET['user_id']      : 0;
$material_id = isset($_GET['material_id']) ? (int)$_GET['material_id'] : 0;
$distrito    = isset($_GET['distrito']) ? intval($_GET['distrito']) : 0; 
$local_code = isset($_GET['local_code']) ? trim($_GET['local_code']) : '';

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 25;
if ($limit <= 0) { $limit = 25; }
$page  = isset($_GET['page'])  ? intval($_GET['page']) : 1;
if ($page < 1) { $page = 1; }
$offset = ($page - 1) * $limit;

$view = isset($_GET['view']) ? trim($_GET['view']) : 'implementacion';
if ($tipoForm == 2) { $view = 'encuesta'; }

$base_url = "https://visibility.cl/visibility2/app/";

if (empty($start_date) && empty($end_date)) {
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $start_date = $tomorrow;
    $end_date   = $tomorrow;
}

// -----------------------------------------------------------------------------
// 4) Obtener lista de usuarios y materiales para los select de filtros
// -----------------------------------------------------------------------------
$usuarios = [];
if ($tipoForm == 1 || $tipoForm == 3) {
    if ($view === 'implementacion') {
        $sqlUsers = "
          SELECT DISTINCT u.id, u.usuario
          FROM fotoVisita fv
          JOIN usuario u ON u.id = fv.id_usuario
          JOIN formularioQuestion fq ON fq.id = fv.id_formularioQuestion
          JOIN formulario f on f.id = fq.id_formulario
          JOIN local l on  l.id = fq.id_local
          WHERE l.id_division = ?
          ORDER BY u.usuario
        ";
    } else {
        $sqlUsers = "
          SELECT DISTINCT u.id, u.usuario
          FROM form_question_responses fqr
          JOIN usuario u ON u.id = fqr.id_usuario
          JOIN form_questions fq ON fq.id = fqr.id_form_question
          JOIN formulario f on f.id = fq.id_formulario
          JOIN formularioQuestion fqn on fqn.id_formulario = f.id
          JOIN local l on l.id = fqn.id_local
          WHERE l.id_division = ?
            AND fq.id_question_type = 7
            AND fqr.id_local <> 0
        ";
    }
} else {
    $sqlUsers = "
      SELECT DISTINCT u.id, u.usuario
      FROM form_question_responses fqr
      JOIN usuario u ON u.id = fqr.id_usuario
      JOIN form_questions fq ON fq.id = fqr.id_form_question
          JOIN formulario f on f.id = fq.id_formulario
          JOIN formularioQuestion fqn on fqn.id_formulario = f.id
          JOIN local l on l.id = fqn.id_local
          WHERE l.id_division = ?
        AND fq.id_question_type = 7
        AND fqr.id_local = 0
      ORDER BY u.usuario
    ";
}
$stmtUsers = $conn->prepare($sqlUsers);
$stmtUsers->bind_param("i", $division);
$stmtUsers->execute();
$resU = $stmtUsers->get_result();
while ($u = $resU->fetch_assoc()) {
    $usuarios[] = $u;
}
$stmtUsers->close();

$materials = [];
if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {
    // Asegúrate de filtrar los materiales por la división actual
    $sqlMat = "SELECT id, nombre FROM material WHERE id_division = ? ORDER BY nombre";
    $stmtMat = $conn->prepare($sqlMat);
    $stmtMat->bind_param("i", $division);
    $stmtMat->execute();
    $resM = $stmtMat->get_result();
    while ($rowM = $resM->fetch_assoc()){
        $materials[] = $rowM;
    }
    $stmtMat->close();
}

// -----------------------------------------------------------------------------
// 5) Construir la consulta principal para las fotos
// -----------------------------------------------------------------------------
$params = [];
$types  = "i";
// Usamos la variable $division (obtenida de GET o de la sesión)
$params[] = $division;

if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {
    $sql = "
      SELECT
        fv.id AS foto_id,
        fv.url,
        fq.material,
        fq.fechaVisita,
        l.codigo AS local_codigo,
        l.nombre AS local_nombre,
        l.direccion AS local_direccion,
        c.nombre AS cadena_nombre
      FROM formularioQuestion fq
      JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
      JOIN local l ON l.id = fq.id_local
      JOIN cadena c ON c.id = l.id_cadena
      WHERE l.id_division = ?
        AND fq.fechaVisita IS NOT NULL
    ";
    if ($start_date !== '') {
        $sql .= " AND DATE(fq.fechaVisita) >= ? ";
        $types .= "s";
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $sql .= " AND DATE(fq.fechaVisita) <= ? ";
        $types .= "s";
        $params[] = $end_date;
    }
    if ($local_code !== '') {
    $sql .= " AND l.codigo = ? ";
    $types .= "s";
    $params[] = $local_code;
    }
    if ($user_id > 0) {
        $sql .= " AND fv.id_usuario = ? ";
        $types .= "i";
        $params[] = $user_id;
    }
    if ($material_id > 0) {
        $sql .= " AND fq.material = (SELECT nombre FROM material WHERE id = ? AND id_division = ?)";
        $types .= "ii";
        $params[] = $material_id;
        $params[] = $division;
    }
    $sql .= " ORDER BY fq.fechaVisita DESC LIMIT ? OFFSET ? ";
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
} elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'encuesta') {
    $sql = "
      SELECT
        fqr.id AS foto_id,
        fqr.answer_text AS url,
        fqr.created_at AS fechaSubida,
        fq.question_text AS pregunta,
        l.codigo AS local_codigo,
        l.nombre AS local_nombre,
        l.direccion AS local_direccion
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      JOIN local l ON l.id = fqr.id_local
      WHERE l.id_division = ?
        AND fq.id_question_type = 7
        AND fqr.id_local <> 0
    ";
    if ($start_date !== '') {
        $sql .= " AND DATE(fqr.created_at) >= ? ";
        $types .= "s";
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $sql .= " AND DATE(fqr.created_at) <= ? ";
        $types .= "s";
        $params[] = $end_date;
    }
    if ($local_code !== '') {
    $sql .= " AND l.codigo = ? ";
    $types .= "s";
    $params[] = $local_code;
    }
    if ($user_id > 0) {
        $sql .= " AND fqr.id_usuario = ? ";
        $types .= "i";
        $params[] = $user_id;
    }
    $sql .= " ORDER BY fqr.created_at DESC LIMIT ? OFFSET ? ";
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
} else {
    $sql = "
      SELECT
        fqr.id AS foto_id,
        fqr.answer_text AS url,
        fqr.created_at AS fechaSubida,
        fq.question_text AS pregunta,
        'N/A' AS local_codigo,
        'N/A' AS local_nombre,
        'N/A' AS local_direccion
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
          JOIN formulario f on f.id = fq.id_formulario
          JOIN formularioQuestion fqn on fqn.id_formulario = f.id
          JOIN local l on l.id = fqn.id_local
          WHERE l.id_division = ?
        AND fq.id_question_type = 7
        AND fqr.id_local = 0
    ";
    if ($start_date !== '') {
        $sql .= " AND DATE(fqr.created_at) >= ? ";
        $types .= "s";
        $params[] = $start_date;
    }
    if ($end_date !== '') {
        $sql .= " AND DATE(fqr.created_at) <= ? ";
        $types .= "s";
        $params[] = $end_date;
    }
    if ($user_id > 0) {
        $sql .= " AND fqr.id_usuario = ? ";
        $types .= "i";
        $params[] = $user_id;
    }
    $sql .= " ORDER BY fqr.created_at DESC LIMIT ? OFFSET ? ";
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
}

$stmtMain = $conn->prepare($sql);
if (!$stmtMain) {
    die("<div class='alert alert-danger'>Error en la preparación: " . htmlspecialchars($conn->error) . "</div>");
}
call_user_func_array([$stmtMain, 'bind_param'], array_merge([$types], refValues($params)));
$stmtMain->execute();
$result = $stmtMain->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
$stmtMain->close();

// -----------------------------------------------------------------------------
// 6) Calcular total para la paginación
// -----------------------------------------------------------------------------
$countSql   = "";
$countTypes = "i";
$countParams= [$division];

if (($tipoForm == 1 || $tipoForm == 3) && $view === 'implementacion') {
    $countSql = "
      SELECT COUNT(*) AS total
      FROM formularioQuestion fq
      JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
      JOIN local l ON l.id = fq.id_local
      WHERE l.id_division = ?
        AND fq.fechaVisita IS NOT NULL
    ";
    if ($start_date !== '') {
        $countSql .= " AND DATE(fq.fechaVisita) >= ? ";
        $countTypes .= "s";
        $countParams[] = $start_date;
    }
    if ($end_date !== '') {
        $countSql .= " AND DATE(fq.fechaVisita) <= ? ";
        $countTypes .= "s";
        $countParams[] = $end_date;
    }
    if ($user_id > 0) {
        $countSql .= " AND fv.id_usuario = ? ";
        $countTypes .= "i";
        $countParams[] = $user_id;
    }
    if ($material_id > 0) {
        $countSql .= " AND fq.material = (SELECT nombre FROM material WHERE id = ? AND id_division = ?)";
        $countTypes .= "ii";
        $countParams[] = $material_id;
        $countParams[] = $division;
    }
} elseif (($tipoForm == 1 || $tipoForm == 3) && $view === 'encuesta') {
    $countSql = "
      SELECT COUNT(*) AS total
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
          JOIN local l on l.id = fqr.id_local
          WHERE l.id_division = ?
        AND fq.id_question_type = 7
        AND fqr.id_local <> 0
    ";
    if ($start_date !== '') {
        $countSql .= " AND DATE(fqr.created_at) >= ? ";
        $countTypes .= "s";
        $countParams[] = $start_date;
    }
    if ($end_date !== '') {
        $countSql .= " AND DATE(fqr.created_at) <= ? ";
        $countTypes .= "s";
        $countParams[] = $end_date;
    }
    if ($user_id > 0) {
        $countSql .= " AND fqr.id_usuario = ? ";
        $countTypes .= "i";
        $countParams[] = $user_id;
    }
} else {
    $countSql = "
      SELECT COUNT(*) AS total
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
          JOIN local l on l.id = fqr.id_local
          WHERE l.id_division = ?
        AND fq.id_question_type = 7
        AND fqr.id_local = 0
    ";
    if ($start_date !== '') {
        $countSql .= " AND DATE(fqr.created_at) >= ? ";
        $countTypes .= "s";
        $countParams[] = $start_date;
    }
    if ($end_date !== '') {
        $countSql .= " AND DATE(fqr.created_at) <= ? ";
        $countTypes .= "s";
        $countParams[] = $end_date;
    }
    if ($user_id > 0) {
        $countSql .= " AND fqr.id_usuario = ? ";
        $countTypes .= "i";
        $countParams[] = $user_id;
    }
}

$stmtCount = $conn->prepare($countSql);
if (!$stmtCount) {
    die("<div class='alert alert-danger'>Error en la preparación del conteo: " . htmlspecialchars($conn->error) . "</div>");
}
call_user_func_array([$stmtCount, 'bind_param'], array_merge([$countTypes], refValues($countParams)));
$stmtCount->execute();
$stmtCount->bind_result($totalRows);
$stmtCount->fetch();
$stmtCount->close();
$totalPages = ceil($totalRows / $limit);

$divisiones = [];
$sqlDiv = "SELECT id, nombre FROM division_empresa WHERE estado = 1 ORDER BY nombre ASC";
$resDiv = $conn->query($sqlDiv);
while ($rowDiv = $resDiv->fetch_assoc()) {
    $divisiones[] = $rowDiv;
}

// Obtener la lista de distritos
$districts = [];
$sqlDistrict = "SELECT id, nombre_distrito FROM distrito ORDER BY nombre_distrito ASC";
$resDistrict = $conn->query($sqlDistrict);
while ($rowDistrict = $resDistrict->fetch_assoc()) {
    $districts[] = $rowDistrict;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Galería de Campaña</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    .thumbnail {
      width: 100px; 
      height: 100px; 
      border-radius: 5px;
      object-fit: cover;
    }
    .custom-img-cell {
      width: 130px;
    }
    .pagination {
      flex-wrap: wrap;
      justify-content: center; /* centra los elementos */
      gap: 5px; /* espacio entre elementos, compatible en navegadores modernos */
    }
  </style>
</head>
<body class="bg-light">
<div class="container mt-4">
  <h2>Galería de rutas</h2>
  <?php if ($tipoForm == 1 || $tipoForm == 3): ?>
    <ul class="nav nav-tabs mb-3">
      <li class="nav-item">
        <a class="nav-link <?php echo ($view==='implementacion') ? 'active' : ''; ?>"
           href="?division=<?php echo $division; ?>&view=implementacion&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&user_id=<?php echo $user_id; ?>&limit=<?php echo $limit; ?>&page=1">
          Fotos Implementación
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php echo ($view==='encuesta') ? 'active' : ''; ?>"
           href="?division=<?php echo $division; ?>&view=encuesta&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&user_id=<?php echo $user_id; ?>&limit=<?php echo $limit; ?>&page=1">
          Fotos Encuesta
        </a>
      </li>
    </ul>
  <?php endif; ?>

<!-- Filtros -->

<form method="GET" action="mod_galeria_ipt.php" class="mb-3" id="filtrosLocales">
  <div class="form-row align-items-center">
    <!-- Selección de División -->
    <div class="col-auto">
      <?php if ($division_id == 1): ?>
        <label class="mr-2">División:</label>
        <select name="division" class="form-control mr-2" id="divisionSelect">
          <option value="0">-- Todas --</option>
          <?php foreach ($divisiones as $d): ?>
            <option value="<?php echo $d['id']; ?>" <?php if ($division == $d['id']) echo 'selected'; ?>>
              <?php echo htmlspecialchars($d['nombre']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="hidden" name="division" id="divisionSelect" value="<?php echo $division; ?>">
      <?php endif; ?>
    </div>

    <!-- NUEVO: Selección de Distrito -->
    <div class="col-auto">
      <label for="districtSelect" class="mr-2">Distrito:</label>
      <select name="distrito" id="districtSelect" class="form-control mr-2">
        <option value="0">-- Todos --</option>
        <?php foreach ($districts as $dist): ?>
          <option value="<?php echo $dist['id']; ?>" <?php if ($distrito == $dist['id']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($dist['nombre_distrito']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Campo oculto para view -->
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
    <!-- Filtro Fecha Desde -->
    <div class="col-auto">
      <label for="start_date" class="mr-2">Desde:</label>
      <input type="date" name="start_date" id="start_date" class="form-control mr-2" value="<?php echo htmlspecialchars($start_date); ?>">
    </div>
    <!-- Filtro Fecha Hasta -->
    <div class="col-auto">
      <label for="end_date" class="mr-2">Hasta:</label>
      <input type="date" name="end_date" id="end_date" class="form-control mr-2" value="<?php echo htmlspecialchars($end_date); ?>">
    </div>
    <!-- Filtro Usuario -->
    <div class="col-auto">
      <label for="user_id" class="mr-2">Usuario:</label>
      <select name="user_id" id="user_id" class="form-control mr-2">
        <option value="0">-- Todos --</option>
        <?php foreach ($usuarios as $u): ?>
          <option value="<?php echo $u['id']; ?>" <?php if ($user_id == $u['id']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($u['usuario']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <!-- Filtro codigo local -->
    <div class="col-auto">
       <label class="mr-2">Cód. Local:</label>
     <input type="text" name="local_code" class="form-control mr-2" value="<?php echo htmlspecialchars($local_code); ?>">
    </div>
    <!-- Filtro Material (si aplica) -->
    <?php if (($tipoForm == 1 || $tipoForm == 3) && $view==='implementacion'): ?>
      <div class="col-auto">
        <label for="material_id" class="mr-2">Material:</label>
        <select name="material_id" id="material_id" class="form-control mr-2">
          <option value="0">-- Todos --</option>
          <?php foreach ($materials as $m): ?>
            <option value="<?php echo $m['id']; ?>" <?php if ($material_id == $m['id']) echo 'selected'; ?>>
              <?php echo htmlspecialchars($m['nombre']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
  </div>
  <!-- Nueva fila para el botón Filtrar -->
  <div class="form-row mt-2">
    <div class="col-auto">
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </div>
  </div>
</form>


  <!-- Selector de "limit" -->
  <div class="d-flex align-items-center mb-2">
    <label for="limitSelect" class="mr-2">Mostrar:</label>
    <select id="limitSelect" class="form-control" style="width: auto;">
      <option value="10">10</option>
      <option value="25">25</option>
      <option value="50">50</option>
      <option value="100">100</option>
    </select>
    <span class="ml-2">registros por página</span>
  </div>

  <!-- Form para enviar JSON de fotos a descargar (se usará vía AJAX) -->
  <form id="zipForm" method="POST" action="download_zip.php" target="_self" style="display:none;">
    <input type="hidden" name="jsonFotos" id="jsonFotos">
  </form>
  <button type="button" id="btnDownloadSelected" class="btn btn-success mb-3">Descargar seleccionadas</button>

  <!-- Tabla de resultados -->
  <?php if ($view==='implementacion'): ?>
    <table class="table table-bordered table-hover">
      <thead class="thead-light">
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>#</th>
          <th>Imagen</th>
          <th>Cód. Local</th>
          <th>Local</th>
          <th>Dirección</th>
          <th>Material</th>
          <th>Cadena</th>
          <th>Fecha</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($data)===0): ?>
          <tr><td colspan="9" class="text-center">No se encontraron fotos de implementación</td></tr>
        <?php else: ?>
          <?php $i = $offset + 1; foreach ($data as $row): 
                $imgUrl = fixUrl($row['url'], $base_url);
                $fechaVisita = formatearFecha($row['fechaVisita']);
          ?>
          <tr>
            <td>
              <input type="checkbox" class="imgCheckbox"
                     data-id="<?php echo $row['foto_id']; ?>"
                     data-url="<?php echo htmlspecialchars($imgUrl); ?>"
                     data-filename="foto_<?php echo $row['foto_id']; ?>.jpg">
            </td>
            <td><?php echo $i; ?></td>
            <td class="custom-img-cell">
              <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="Foto"
                   class="thumbnail img-click" data-full="<?php echo htmlspecialchars($imgUrl); ?>">
            </td>
            <td><?php echo htmlspecialchars($row['local_codigo']); ?></td>
            <td><?php echo htmlspecialchars($row['local_nombre']); ?></td>
            <td><?php echo htmlspecialchars($row['local_direccion']); ?></td>
            <td><?php echo htmlspecialchars($row['material']); ?></td>
            <td><?php echo htmlspecialchars($row['cadena_nombre']); ?></td>
            <td><?php echo $fechaVisita; ?></td>
          </tr>
          <?php $i++; endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  <?php else: ?>
    <table class="table table-bordered table-hover">
      <thead class="thead-light">
        <tr>
          <th><input type="checkbox" id="selectAll"></th>
          <th>#</th>
          <th>Imagen</th>
          <th>Pregunta</th>
          <th>Cód. Local</th>
          <th>Local</th>
          <th>Dirección</th>
          <th>Fecha Subida</th>
        </tr>
      </thead>
      <tbody>
        <?php if (count($data)===0): ?>
          <tr><td colspan="8" class="text-center">No se encontraron fotos de encuesta</td></tr>
        <?php else: ?>
          <?php $i = $offset + 1; foreach ($data as $row): 
                $imgUrl = fixUrl($row['url'], $base_url);
                $fechaSubida = formatearFecha($row['fechaSubida']);
          ?>
          <tr>
            <td>
              <input type="checkbox" class="imgCheckbox"
                     data-id="<?php echo $row['foto_id']; ?>"
                     data-url="<?php echo htmlspecialchars($imgUrl); ?>"
                     data-filename="foto_<?php echo $row['foto_id']; ?>.jpg">
            </td>
            <td><?php echo $i; ?></td>
            <td class="custom-img-cell">
              <img src="<?php echo htmlspecialchars($imgUrl); ?>" alt="Foto"
                   class="thumbnail img-click" data-full="<?php echo htmlspecialchars($imgUrl); ?>">
            </td>
            <td><?php echo htmlspecialchars($row['pregunta']); ?></td>
            <td><?php echo htmlspecialchars($row['local_codigo']); ?></td>
            <td><?php echo htmlspecialchars($row['local_nombre']); ?></td>
            <td><?php echo htmlspecialchars($row['local_direccion']); ?></td>
            <td><?php echo $fechaSubida; ?></td>
          </tr>
          <?php $i++; endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Paginación -->
  <?php if ($totalPages > 1): ?>
    <nav>
      <ul class="pagination">
        <?php if ($page > 1): ?>
          <li class="page-item">
            <a class="page-link" href="?division=<?php echo $division; ?>&view=<?php echo htmlspecialchars($view); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&user_id=<?php echo $user_id; ?>&limit=<?php echo $limit; ?>&page=<?php echo ($page - 1); ?>">
              Anterior
            </a>
          </li>
        <?php else: ?>
          <li class="page-item disabled"><span class="page-link">Anterior</span></li>
        <?php endif; ?>

        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <?php if ($p == $page): ?>
            <li class="page-item active"><span class="page-link"><?php echo $p; ?></span></li>
          <?php else: ?>
            <li class="page-item">
              <a class="page-link" href="?division=<?php echo $division; ?>&view=<?php echo htmlspecialchars($view); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&user_id=<?php echo $user_id; ?>&limit=<?php echo $limit; ?>&page=<?php echo $p; ?>">
                <?php echo $p; ?>
              </a>
            </li>
          <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <li class="page-item">
            <a class="page-link" href="?division=<?php echo $division; ?>&view=<?php echo htmlspecialchars($view); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&user_id=<?php echo $user_id; ?>&limit=<?php echo $limit; ?>&page=<?php echo ($page + 1); ?>">
              Siguiente
            </a>
          </li>
        <?php else: ?>
          <li class="page-item disabled"><span class="page-link">Siguiente</span></li>
        <?php endif; ?>
      </ul>
    </nav>
  <?php endif; ?>

</div>

<!-- Modal para mostrar imagen en grande -->
<div class="modal fade" id="fullSizeModal" tabindex="-1" role="dialog" aria-labelledby="fullSizeModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-body p-0">
        <img id="fullSizeImg" src="" class="img-fluid" alt="Imagen ampliada">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- jQuery / Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>



<script>
////////////////////////////////////////////////////////////////////////////////
// 1) Mostrar imagen en grande
////////////////////////////////////////////////////////////////////////////////
$(document).on('click', '.thumbnail.img-click', function(){
  var src = $(this).data('full');
  $('#fullSizeImg').attr('src', src);
  $('#fullSizeModal').modal('show');
});

////////////////////////////////////////////////////////////////////////////////
// 2) "Seleccionar todo" en la página actual
////////////////////////////////////////////////////////////////////////////////
$('#selectAll').change(function() {
  var isChecked = $(this).prop('checked');
  $('.imgCheckbox').prop('checked', isChecked);
});

////////////////////////////////////////////////////////////////////////////////
// 3) Descarga AJAX con ZipArchive: recoger solo los checkboxes marcados de la página actual
////////////////////////////////////////////////////////////////////////////////
$('#btnDownloadSelected').click(function() {
  var arrFotos = [];
  $('.imgCheckbox:checked').each(function(){
      var url = $(this).data('url');
      var filename = $(this).data('filename');
      arrFotos.push({ url: url, filename: filename });
  });
  if (arrFotos.length === 0) {
    alert('No has seleccionado fotos.');
    return;
  }
  
  $.ajax({
    url: 'download_zip.php',
    method: 'POST',
    data: { jsonFotos: JSON.stringify(arrFotos) },
    xhrFields: { responseType: 'blob' },
    success: function(data, status, xhr) {
      var disposition = xhr.getResponseHeader('Content-Disposition');
      var filename = "fotos_seleccionadas.zip";
      if (disposition && disposition.indexOf('attachment') !== -1) {
          var matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
          if (matches != null && matches[1]) { 
              filename = matches[1].replace(/['"]/g, '');
          }
      }
      var blob = new Blob([data], { type: 'application/zip' });
      var link = document.createElement('a');
      link.href = window.URL.createObjectURL(blob);
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    },
    error: function(xhr, status, error) {
      alert('Error al descargar el ZIP: ' + error);
    }
  });
});

////////////////////////////////////////////////////////////////////////////////
// 4) Selector de "limit" para cambiar registros por página
////////////////////////////////////////////////////////////////////////////////
$(document).ready(function(){
  var currentLimit = <?php echo $limit; ?>;
  $('#limitSelect').val(currentLimit.toString());

  $('#limitSelect').change(function(){
    var newLimit = $(this).val();
    var url = new URL(window.location.href);
    url.searchParams.set('limit', newLimit);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
  });
});
////////////////////////////////////////////////////////////////////////////////

</script>

</body>
</html>
