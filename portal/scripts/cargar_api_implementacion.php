<?php
require __DIR__ . '/../vendor/autoload.php';

/* =========================
   CONFIG API (igual a Power Query)
========================= */

$apiBase = 'https://visibility.cl/visibility2/portal/RESTful/api_material.php';

$idDivision     = 6;
$idSubdivisions = [6];
$years          = [2025, 2026];

// Construcción URL
$apiUrl = $apiBase
    . '?idDivision=' . $idDivision
    . '&idSubdivisions=' . implode(',', $idSubdivisions)
    . '&years=' . implode(',', $years);

/* =========================
   CONEXIÓN DB
========================= */

$db = new mysqli(
    'localhost',
    'visibility',
    'xyPz8e/rgaC2',
    'visibility_visibility2'
);

if ($db->connect_error) {
    die('Error DB: ' . $db->connect_error);
}

$db->set_charset('utf8mb4');

/* =========================
   LLAMADA API
========================= */

$response = file_get_contents($apiUrl);

if ($response === false) {
    echo $apiUrl;
}

$data = json_decode($response, true);

if (!is_array($data)) {
    die('Respuesta API inválida');
}

/* =========================
   PREPARE SQL
========================= */

$stmt = $db->prepare("
    INSERT INTO db_implementacion_unificada (
        tipo,
        id_local,
        codigo_local,
        nombre_local,
        direccion_local,
        comuna,
        region,
        nombreCanal,
        subcanal,
        cadena,
        cuenta,
        nombreZona,
        nombreDistrito,
        nombre_campana,
        material,
        fecha_inicio,
        fecha_termino,
        fecha_visita,
        hora_visita,
        fecha_propuesta,
        valor_propuesto,
        valor,
        estado,
        pregunta,
        observacion,
        gestionado_por,
        nombreCompleto,
        nombre_usuario,
        fuente_origen,
        fecha_carga,
        hash_registro
    ) VALUES (
        ?,?,?,?,?,?,
        ?,?,?,?,?,?,
        ?,?,
        ?,?,?,?,?,?,
        ?,?,?,?,?,?,
        'API', NOW(), ?
    )
    ON DUPLICATE KEY UPDATE
        estado = VALUES(estado),
        valor = VALUES(valor),
        observacion = VALUES(observacion),
        fecha_carga = NOW()
");

/* =========================
   PROCESO REGISTROS
========================= */

foreach ($data as $row) {

    // Fechas
    $fechaInicio    = !empty($row['fechaInicio'])    ? date('Y-m-d', strtotime($row['fechaInicio']))    : null;
    $fechaTermino   = !empty($row['fechaTermino'])   ? date('Y-m-d', strtotime($row['fechaTermino']))   : null;
    $fechaVisita    = !empty($row['fechaVisita'])    ? date('Y-m-d', strtotime($row['fechaVisita']))    : null;
    $horaVisita     = !empty($row['horaVisita'])     ? $row['horaVisita']                                : null;
    $fechaPropuesta = !empty($row['fechaPropuesta']) ? date('Y-m-d', strtotime($row['fechaPropuesta'])) : null;

    // Hash único (anti duplicados)
    $hash = md5(
        $row['codigo_local']
        . $row['nombreCampaña']
        . $fechaVisita
        . ($row['pregunta'] ?? '')
    );

    $stmt->bind_param(
        'iisssssssssssssssssddsssssss',
        $row['tipo'],
        $row['idLocal'],
        $row['codigo_local'],
        $row['nombre_local'],
        $row['direccion_local'],
        $row['comuna_local'],
        $row['region_local'],
        $row['nombreCanal'],
        $row['subcanal'],
        $row['cadena'],
        $row['cuenta'],
        $row['nombreZona'],
        $row['nombreDistrito'],
        $row['nombreCampaña'],
        $row['material'],
        $fechaInicio,
        $fechaTermino,
        $fechaVisita,
        $horaVisita,
        $fechaPropuesta,
        $row['valor_propuesto'],
        $row['valor'],
        $row['estado'],
        $row['pregunta'],
        $row['observacion'],
        $row['gestionado_por'],
        $row['nombreCompleto'],
        $row['nombre'],
        $hash
    );

    if (!$stmt->execute()) {
        echo "Error SQL: {$stmt->error}\n";
    }
}

$stmt->close();
$db->close();

echo "Carga API implementación finalizada correctamente";
