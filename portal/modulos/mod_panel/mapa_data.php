<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// --- Validar sesión ---
if (!isset($_SESSION['usuario_id'])) {
  header("Location: ../index.php");
  exit();
}

$id_empresa        = intval($_SESSION['empresa_id']);
$user_division_id  = intval($_SESSION['division_id']);
$isMC              = ($user_division_id === 1);

// --- Filtros ---
$filter_division    = $isMC ? intval($_GET['id_division'] ?? 0) : $user_division_id;
$filter_subdivision = isset($_GET['id_subdivision']) ? intval($_GET['id_subdivision']) : 0;
$filter_estado      = isset($_GET['estado']) ? intval($_GET['estado']) : 1;
$filter_campana     = intval($_GET['id_campana'] ?? 0);
$id_ejecutor        = intval($_GET['id_ejecutor'] ?? 0);
$filter_distrito    = intval($_GET['id_distrito'] ?? 0);
$fecha_desde        = trim($_GET['desde'] ?? '');
$fecha_hasta        = trim($_GET['hasta'] ?? '');
$accionBuscar = isset($_GET['buscar']) ? intval($_GET['buscar']) : 0;

// --- Tipo de gestión dinámico ---
$tipoCampana = isset($_GET['tipo_gestion']) ? intval($_GET['tipo_gestion']) : 0; // 1=Campaña, 3=Ruta, 0=Todas

// --- Divisiones (MC) ---
$divisiones = [];
if ($isMC) {
  $sql_divisiones = "SELECT id, nombre FROM division_empresa WHERE id_empresa = ? AND estado = 1 ORDER BY nombre";
  $stmt = $conn->prepare($sql_divisiones);
  $stmt->bind_param("i", $id_empresa);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $divisiones[] = $r;
  $stmt->close();
}


// --- Distritos ---
$distritos = [];
$sql_distritos = "
  SELECT DISTINCT d.id, d.nombre_distrito
  FROM local l
  INNER JOIN distrito d ON l.id_distrito = d.id
  WHERE l.id_empresa = ?
  ORDER BY d.nombre_distrito
";
$stmt = $conn->prepare($sql_distritos);
$stmt->bind_param("i", $id_empresa);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $distritos[] = $r;
$stmt->close();


// --- Locales ---
$locales = [];
if ($accionBuscar === 1) {
// Construir base SQL
$sql = "
  SELECT DISTINCT
    f.nombre AS Actividad,
    CASE
        WHEN f.modalidad = 'solo_auditoria'
            THEN 'AUDITORIA'
        WHEN f.modalidad = 'solo_implementacion'
            THEN 'IMPLEMENTACION'
        WHEN f.modalidad = 'implementacion_auditoria'
            THEN 'IMPL/AUD'
        ELSE UPPER(f.modalidad)
    END AS modalidad,            
    l.id      AS id_local,
    l.codigo  AS codigo,
    UPPER(l.nombre) AS nombre_local,
    UPPER(l.direccion) AS direccion_local,
    UPPER(c.comuna) AS comuna_local,
    UPPER(r.region) AS region_local,
    UPPER(u.usuario) AS usuario_local,
    l.lat AS latitud,
    l.lng AS longitud,
    DATE(fq.fechaVisita) AS fechaVisita,
    TIME(fq.fechaVisita) AS horaVisita,
    fq.fechaPropuesta AS fechaPropuesta,
    CASE
        WHEN fq.pregunta = 'solo_auditoria'
            THEN 'AUDITORIA'
        WHEN fq.pregunta IN ('solo_implementacion', 'solo_implementado')
            THEN 'IMPLEMENTACION'
        WHEN fq.pregunta = 'implementado_auditado'
            THEN 'IMPL/AUD'
        WHEN fq.pregunta = 'local_no_existe'
            THEN 'LOCAL NO EXISTE'            
        WHEN fq.pregunta IN ('en proceso','cancelado')
            THEN TRIM(SUBSTRING_INDEX(REPLACE(fq.observacion,'|','-'), '-', 1))
        ELSE UPPER(fq.pregunta)
    END AS pregunta,
    fq.countVisita,
    f.nombre AS nombre_campana
  FROM formularioQuestion fq
  INNER JOIN usuario u ON u.id = fq.id_usuario
  INNER JOIN local l ON l.id = fq.id_local
  INNER JOIN comuna c ON c.id = l.id_comuna
  INNER JOIN region r ON r.id = c.id_region
  INNER JOIN formulario f ON f.id = fq.id_formulario
  WHERE f.id_empresa = ?
";

$params = [$id_empresa];
$types  = "i";

// 🔹 Filtrar tipo de gestión (1=Campaña, 3=Ruta)
if (in_array($tipoCampana, [1, 3])) {
  $sql .= " AND f.tipo = ? ";
  $params[] = $tipoCampana;
  $types .= "i";
}

// 🔹 Si se seleccionó campaña
if ($filter_campana > 0) {
  $sql .= " AND f.id = ? ";
  $params[] = $filter_campana;
  $types .= "i";
}

// 🔹 Filtrar ejecutor solo si hay uno seleccionado (>0)
if ($id_ejecutor > 0) {
  $sql .= " AND fq.id_usuario = ? ";
  $params[] = $id_ejecutor;
  $types .= "i";
}

// 🔹 División / Subdivisión
if ($filter_division > 0) {
  $sql .= " AND f.id_division = ? ";
  $params[] = $filter_division;
  $types .= "i";
}
if ($filter_subdivision > 0) {
  $sql .= " AND f.id_subdivision = ? ";
  $params[] = $filter_subdivision;
  $types .= "i";
}

// 🔹 Estado
if (in_array($filter_estado, [1,3])) {
  $sql .= " AND f.estado = ? ";
  $params[] = $filter_estado;
  $types .= "i";
}

// 🔹 Distrito
if ($filter_distrito > 0) {
  $sql .= " AND l.id_distrito = ? ";
  $params[] = $filter_distrito;
  $types .= "i";
}

// 🔹 Fechas (solo si aplica)
if ($fecha_desde !== '') {
  $sql .= " AND fq.fechaPropuesta >= ? ";
  $params[] = $fecha_desde . " 00:00:00";
  $types .= "s";
}
if ($fecha_hasta !== '') {
  $sql .= " AND fq.fechaPropuesta <= ? ";
  $params[] = $fecha_hasta . " 23:59:59";
  $types .= "s";
}

$sql .= " ORDER BY fq.fechaPropuesta DESC, l.nombre ASC";

// Ejecutar
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $locales[] = $row;
$stmt->close();

}

// --- Generar datos del mapa ---
$infoLocales = [];
foreach ($locales as $fila) {
  $idLocal = (int)$fila['id_local'];
  if (!isset($infoLocales[$idLocal])) {
    $infoLocales[$idLocal] = [
      'id_local'        => $idLocal,
      'nombre_campana'    => $fila['nombre_campana'],
      'modalidad'       => $fila['modalidad'],       
      'nombre_local'    => $fila['nombre_local'],
      'direccion_local' => $fila['direccion_local'],
      'comuna_local'    => $fila['comuna_local'],
      'region_local'    => $fila['region_local'],
      'usuario_local'   => $fila['usuario_local'],
      'fechaVisita'     => $fila['fechaVisita'],
      'horaVisita'      => $fila['horaVisita'],
      'pregunta'        => $fila['pregunta'],      
      'latitud'         => is_null($fila['latitud']) ? null : (float)$fila['latitud'],
      'longitud'        => is_null($fila['longitud']) ? null : (float)$fila['longitud'],
      'preguntas'       => []
    ];
  }
  $infoLocales[$idLocal]['preguntas'][] = $fila['pregunta'];
}

// --- Armar coordenadas finales ---
$coordenadas_locales = [];
if ($accionBuscar === 1) {
foreach ($infoLocales as $loc) {
  if ($loc['latitud'] === null || $loc['longitud'] === null) continue;

  $preguntas = $loc['preguntas'];
  $complValues = ['AUDITORIA', 'IMPLEMENTACION', 'IMPL/AUD'];

  if (count(array_intersect($preguntas, $complValues)) > 0) {
    $markerColor = 'green';
  } elseif (count(array_unique($preguntas)) === 1 && $preguntas[0] === '-') {
    $markerColor = 'red';
  } else {
    $markerColor = 'orange';
  }

  $estadoRaw = $preguntas[0] ?? '-';
  if (in_array($estadoRaw, $complValues)) {
    $estadoLegible = 'GESTIONADO';
  } elseif ($estadoRaw === '-' || trim($estadoRaw) === '') {
    $estadoLegible = '-';
  } else {
    $estadoLegible = strtoupper(str_replace('_', ' ', $estadoRaw));
  }

  $coordenadas_locales[] = [
    'idLocal'        => $loc['id_local'],
    'nombre_campana'   => $loc['nombre_campana'],
    'modalidad'      => $loc['modalidad'],     
    'nombre_local'   => $loc['nombre_local'],
    'direccion_local'=> $loc['direccion_local'],
    'comuna_local'   => $loc['comuna_local'],
    'region_local'   => $loc['region_local'],
    'usuario_local'  => $loc['usuario_local'],
    'fechaVisita'    => $loc['fechaVisita'],
    'horaVisita'     => $loc['horaVisita'],
    'pregunta'       => $loc['pregunta'],    
    'latitud'        => $loc['latitud'],
    'longitud'       => $loc['longitud'],
    'markerColor'    => $markerColor,
    'estado'         => $estadoLegible
  ];
}
}

$conn->close();
?>
