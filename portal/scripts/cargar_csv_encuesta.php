<?php
set_time_limit(0);
ini_set('memory_limit', '2048M');

require $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
@mysqli_set_charset($conn, 'utf8mb4');

// ================= CONFIG =================
$DIR = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/repositorio/encuestas_csv';

// ================= VALIDACIONES =================
if (!is_dir($DIR)) {
    die("❌ Directorio no existe: $DIR\n");
}

echo "📂 Leyendo CSV desde: $DIR\n";

// Buscar TODOS los CSV (los 7 actuales y los futuros)
$files = glob($DIR . '/*.csv');

if (empty($files)) {
    die("⚠️ No se encontraron CSV para procesar.\n");
}

// ================= LOOP ARCHIVOS =================
foreach ($files as $filePath) {
    $fileName = basename($filePath);
    echo "\n➡️ Procesando archivo: $fileName\n";

    if (($handle = fopen($filePath, 'r')) === false) {
        echo "❌ No se pudo abrir $fileName\n";
        continue;
    }

    // -------- Leer encabezados --------
    $headers = fgetcsv($handle, 0, ';');
    if (!$headers) {
        echo "❌ CSV vacío: $fileName\n";
        fclose($handle);
        continue;
    }

    $headers = array_map('trim', $headers);

    // -------- SQL PREPARED --------
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
            tipo_pregunta,
            hash_registro
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "❌ Error prepare: {$conn->error}\n";
        fclose($handle);
        continue;
    }

    $rows = 0;
    $skipped = 0;

    // ================= LOOP FILAS =================
    while (($data = fgetcsv($handle, 0, ';')) !== false) {

        // Validar columnas
        if (count($headers) !== count($data)) {
            $skipped++;
            continue;
        }

        $row = array_combine($headers, $data);

        // -------- Fechas --------
        $fechaRaw = str_replace('/', '-', trim($row['fecha_respuesta'] ?? ''));
        $ts = strtotime($fechaRaw);

        if (!$ts) {
            $skipped++;
            continue;
        }

        $fechaDT = date('Y-m-d H:i:s', $ts);
        $fechaD  = date('Y-m-d', $ts);

        // -------- Precio --------
        $precio = (isset($row['precio']) && is_numeric($row['precio']))
            ? (float)$row['precio']
            : null;

        // -------- Asignación --------
        $fuente         = 'CSV';
        $archivo        = $fileName;
        $idCampana      = (int)($row['idCampana'] ?? 0);
        $nombreCanal    = $row['nombreCanal']    ?? null;
        $nombreDistrito = $row['nombreDistrito'] ?? null;
        $codigoLocal    = $row['codigo_local']    ?? null;
        $cuenta         = $row['cuenta']          ?? null;
        $nombreLocal    = $row['nombre_local']    ?? null;
        $comuna         = $row['comuna']           ?? null;
        $region         = $row['region']           ?? null;
        $nombreZona     = $row['nombreZona']       ?? null;
        $usuario        = $row['nombreCompleto']   ?? null;
        $pregunta       = $row['pregunta']         ?? null;
        $respuesta      = $row['respuesta']        ?? null;
        $tipoPregunta   = (int)($row['tipo'] ?? 0);

        // -------- HASH ÚNICO --------
        $hash = md5(
            $idCampana . '|' .
            $codigoLocal . '|' .
            $pregunta . '|' .
            $fechaDT . '|' .
            $respuesta
        );

        // -------- Bind --------
        $stmt->bind_param(
        "ssisssssssssssssdis",
        $fuente,
        $archivo,
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
        $tipoPregunta,
        $hash
    );

        // -------- Execute --------
        if (!$stmt->execute()) {
            // 1062 = duplicate key (hash)
            if ($conn->errno != 1062) {
                echo "❌ Error insert ($fileName): {$stmt->error}\n";
            }
            continue;
        }

        $rows++;
    }

    fclose($handle);
    $stmt->close();

    echo "✅ $rows filas insertadas desde $fileName";
    if ($skipped > 0) {
        echo " | ⚠️ $skipped filas omitidas";
    }
    echo "\n";
}

echo "\n🎉 Proceso CSV finalizado correctamente.\n";
