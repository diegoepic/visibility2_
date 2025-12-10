<?php
// mod_local/fetch_locales.php

header('Content-Type: application/json');
require_once '../db.php';

// Leer los filtros básicos

$empresa_id = isset($_GET['empresa_id']) && is_numeric($_GET['empresa_id']) ? intval($_GET['empresa_id']) : null;
$region_id  = isset($_GET['region_id'])  && is_numeric($_GET['region_id'])  ? intval($_GET['region_id'])  : null;
$comuna_id  = isset($_GET['comuna_id'])  && is_numeric($_GET['comuna_id'])  ? intval($_GET['comuna_id'])  : null;
$canal_id   = isset($_GET['canal_id'])   && is_numeric($_GET['canal_id'])   ? intval($_GET['canal_id'])   : null;
$nombre     = isset($_GET['nombre'])     ? trim($_GET['nombre']) : '';
$subcanal_id = isset($_GET['subcanal_id']) && is_numeric($_GET['subcanal_id']) ? intval($_GET['subcanal_id']) : null;
$division_id = isset($_GET['division_id']) && is_numeric($_GET['division_id']) ? intval($_GET['division_id']) : null;
$codigo     = isset($_GET['codigo']) ? trim($_GET['codigo']) : '';
$id_local     = isset($_GET['id_local']) ? trim($_GET['id_local']) : '';
$filtros = [];
if ($empresa_id) {
    $filtros['empresa_id'] = $empresa_id;
}
if ($region_id) {
    $filtros['region_id'] = $region_id;
}
if ($comuna_id) {
    $filtros['comuna_id'] = $comuna_id;
}
if ($canal_id) {
    $filtros['canal_id'] = $canal_id;
}
if (!empty($nombre)) {
    $filtros['nombre'] = $nombre;
}
if ($subcanal_id) {
    $filtros['subcanal_id'] = $subcanal_id;
}
if ($division_id) {
    $filtros['division_id'] = $division_id;
}
if (!empty($codigo)) {
    $filtros['codigo'] = $codigo;  // <--- NUEVO
}
if (!empty($id_local)) {
    $filtros['id_local'] = $id_local;  // <--- NUEVO
}

// Leer parámetros de paginación (offset y limit)
$offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? intval($_GET['offset']) : 0;
$limit  = isset($_GET['limit'])  && is_numeric($_GET['limit'])  ? intval($_GET['limit'])  : 50;

// Se asume que la función obtenerLocalesFiltrados ya se ha actualizado para recibir offset y limit
$locales = obtenerLocalesFiltrados($filtros, $offset, $limit);

// Para la paginación, se realiza una segunda consulta para contar el total de registros
function obtenerTotalLocalesFiltrados($filtros = []) {
    global $conn;

    $sql = "SELECT COUNT(*) AS total
            FROM local l
            JOIN cuenta c ON l.id_cuenta = c.id
            JOIN cadena ca ON l.id_cadena = ca.id
            JOIN comuna co ON l.id_comuna = co.id
            JOIN region r ON co.id_region = r.id
            JOIN empresa e ON l.id_empresa = e.id
            LEFT JOIN canal can ON l.id_canal = can.id
            LEFT JOIN subcanal s ON l.id_subcanal = s.id
            LEFT JOIN division_empresa d ON l.id_division = d.id
            WHERE e.activo = 1";

    $params = [];
    $tipos  = '';

    // Filtros (por empresa, región, comuna, canal, subcanal, división)
    if (!empty($filtros['empresa_id'])) {
        $sql .= " AND e.id = ?";
        $params[] = intval($filtros['empresa_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['region_id'])) {
        $sql .= " AND r.id = ?";
        $params[] = intval($filtros['region_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['comuna_id'])) {
        $sql .= " AND co.id = ?";
        $params[] = intval($filtros['comuna_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['canal_id'])) {
        $sql .= " AND can.id = ?";
        $params[] = intval($filtros['canal_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['subcanal_id'])) {
        $sql .= " AND s.id = ?";
        $params[] = intval($filtros['subcanal_id']);
        $tipos   .= 'i';
    }
    if (!empty($filtros['division_id'])) {
        $sql .= " AND d.id = ?";
        $params[] = intval($filtros['division_id']);
        $tipos   .= 'i';
    }

    // NUEVO: filtro por código local
    if (!empty($filtros['codigo'])) {
        $sql .= " AND l.codigo LIKE ?";
        $params[] = '%' . $filtros['codigo'] . '%';
        $tipos   .= 's';
    }
    if (!empty($filtros['id_local'])) {
    $sql .= " AND l.id = ?";
    $params[] = intval($filtros['id_local']);
    $tipos   .= 'i';
    }

    // Filtro por nombre
    if (!empty($filtros['nombre'])) {
        $sql .= " AND l.nombre LIKE ?";
        $params[] = '%' . $filtros['nombre'] . '%';
        $tipos   .= 's';
    }

    if ($stmt = $conn->prepare($sql)) {
        if (!empty($params)) {
            $stmt->bind_param($tipos, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $total  = 0;
        if ($row = $result->fetch_assoc()) {
            $total = intval($row['total']);
        }
        $stmt->close();
        return $total;
    }
    // En caso de error al hacer prepare()
    return 0;
}

$totalRegistros = obtenerTotalLocalesFiltrados($filtros);

$response = [
    'success' => true,
    'data'    => $locales,
    'total'   => $totalRegistros
];

echo json_encode($response);
exit();
?>