<?php
set_time_limit(0);
ini_set('memory_limit', '2048M');

require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
@mysqli_set_charset($conn, 'utf8mb4');

// carpeta donde están los CSV
$DIR = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/repositorio/encuestas_csv';

// validación básica
if (!is_dir($DIR)) {
    die("❌ Directorio no existe: $DIR");
}

echo "📂 Leyendo CSV desde: $DIR\n";

$files = glob($DIR . '/*.csv');

if (empty($files)) {
    die("⚠️ No se encontraron CSV para procesar.\n");
}

foreach ($files as $filePath) {
    $fileName = basename($filePath);
    echo "\n➡️ Procesando archivo: $fileName\n";

    if (($handle = fopen($filePath, 'r')) === false) {
        echo "❌ No se pudo abrir $fileName\n";
        continue;
    }

    // leer encabezados
    $headers = fgetcsv($handle, 0, ';');
    if (!$headers) {
        echo "❌ CSV vacío: $fileName\n";
        fclose($handle);
        continue;
    }

    // normalizar encabezados
    $headers = array_map('trim', $headers);

    $sql = "
        INSERT INTO db_encuesta_unificada (
            fuente_origen,
            archivo_origen,
            id_campana,
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
            tipo_pregunta
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "❌ Error prepare: {$conn->error}\n";
        fclose($handle);
        continue;
    }

    $rows = 0;

    while (($data = fgetcsv($handle, 0, ';')) !== false) {
        $row = array_combine($headers, $data);

        // limpieza básica
        $fechaRaw = str_replace('/', '-', trim($row['fecha_respuesta']));
        $fechaDT  = date('Y-m-d H:i:s', strtotime($fechaRaw));
        $fechaD   = date('Y-m-d', strtotime($fechaRaw));

        $precio = is_numeric($row['precio']) ? (float)$row['precio'] : null;

        $stmt->bind_param(
            "ssissssssssssssdi",
            $fuente,         // fuente_origen
            $archivo,        // archivo_origen
            $idCampana,
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
            $tipoPregunta
        );

        // asignación
        $fuente          = 'CSV';
        $archivo         = $fileName;
        $idCampana       = (int)$row['idCampana'];
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

        if (!$stmt->execute()) {
            echo "❌ Error insert: {$stmt->error}\n";
            continue;
        }

        $rows++;
    }

    fclose($handle);
    $stmt->close();

    echo "✅ $rows filas insertadas desde $fileName\n";
}
