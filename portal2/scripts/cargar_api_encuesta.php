<?php
set_time_limit(0);
ini_set('memory_limit', '2048M');

require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
@mysqli_set_charset($conn, 'utf8mb4');

$API_URL = "https://visibility.cl/visibility2/portal/RESTful/api_encuesta_tradicional.php?id_division=14&id_subdivision=3";

$json = file_get_contents($API_URL);
if ($json === false) {
    die("❌ No se pudo consumir la API\n");
}

$data = json_decode($json, true);
if (!is_array($data) || empty($data)) {
    die("⚠️ API sin datos\n");
}

$sql = "
        INSERT INTO db_encuesta_unificada (
            fuente_origen,
            archivo_origen,
            division,
            subdivision,
            id_campana,
            nombre_campana,
            nombreCanal,
            nombreDistrito,
            codigo_local,
            cuenta,
            nombre_local,
            fecha_respuesta_dt,
            fecha_respuesta,
            comuna,
            region,
            nombreZona,
            usuario,
            pregunta,
            respuesta,
            valor,
            tipo_pregunta,
            hash_registro
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("❌ Error prepare: {$conn->error}\n");
}

$rows = 0;

foreach ($data as $row) {

    $fechaRaw = str_replace('/', '-', trim($row['fecha_respuesta']));
    $fechaDT  = date('Y-m-d H:i:s', strtotime($fechaRaw));
    $fechaD   = date('Y-m-d', strtotime($fechaRaw));

    $precio = is_numeric($row['precio']) ? (float)$row['precio'] : null;

    // Asignación
    $fuente          = 'API';
    $archivo         = 'api_encuesta';
    $division        = $row['division'] ?? null;
    $subdivision     = $row['subdivision'] ?? null;    
    $idCampana       = (int)$row['idCampana'];
    $nombreCampana   = $row['nombreCampana'] ?? null;    
    $nombreCanal     = $row['nombreCanal'];
    $nombreDistrito  = $row['nombreDistrito'];
    $codigoLocal     = $row['codigo_local'];
    $cuenta          = $row['cuenta'];
    $nombreLocal     = $row['nombre_local'];
    $comuna          = $row['comuna'];
    $region          = $row['region'];
    $nombreZona      = $row['nombreZona'];
    $usuario         = $row['nombreCompleto'];
    $pregunta        = $row['pregunta'];
    $respuesta       = $row['respuesta'];
    $tipoPregunta    = (int)$row['tipo'];

    // 🔐 Hash idempotente
    $hash = md5(
        $idCampana . '|' .
        $codigoLocal . '|' .
        $pregunta . '|' .
        $fechaDT . '|' .
        $respuesta
    );

    $stmt->bind_param(
            "ssssissssssssssssssdis",
            $fuente,
            $archivo,
            $division,
            $subdivision,
            $idCampana,
            $nombreCampana,
            $nombreCanal,
            $nombreDistrito,
            $codigoLocal,
            $cuenta,
            $nombreLocal,
            $fechaDT,
            $fechaD,
            $comuna,
            $region,
            $nombreZona,
            $usuario,
            $pregunta,
            $respuesta,
            $precio,
            $tipoPregunta,
            $hash
    );

    if (!$stmt->execute()) {
        // Ignoramos duplicados por hash
        if ($conn->errno != 1062) {
            echo "❌ Error insert API: {$stmt->error}\n";
        }
        continue;
    }

    $rows++;
}

$stmt->close();

echo "✅ $rows filas insertadas desde API\n";
