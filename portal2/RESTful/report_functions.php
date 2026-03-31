<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// --- Normaliza el parámetro id_subdivision en un array de enteros (>0) ---
function normalizeSubdivisionParam(): array {
    if (!isset($_GET['id_subdivision']) && !isset($_GET['id_subdivision'])) {
        return []; // no viene => todas
    }

    // Puede venir como id_subdivision[] (array), id_subdivision (csv o número), o ambos
    $vals = [];

    // Caso id_subdivision[] => PHP lo entrega como array
    if (isset($_GET['id_subdivision']) && is_array($_GET['id_subdivision'])) {
        $vals = array_merge($vals, $_GET['id_subdivision']);
    }

    // Caso id_subdivision como string/numérico (csv o número suelto)
    if (isset($_GET['id_subdivision']) && !is_array($_GET['id_subdivision'])) {
        $s = trim((string)$_GET['id_subdivision']);
        if ($s !== '') {
            if (strpos($s, ',') !== false) {
                $vals = array_merge($vals, explode(',', $s));
            } else {
                $vals[] = $s;
            }
        }
    }

    // Sanitizar: a int, >0, únicos
    $ints = array_unique(array_filter(array_map('intval', $vals), fn($n) => $n > 0));
    return $ints; // [] => todas
}

function getMaterialPivot($division, array $subdivisions = [], $year = null) {
    global $conn;

    $division   = (int)$division;
    $yearFilter = (is_numeric($year) ? (int)$year : null);

    if ($division <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'ID de división inválido.']);
        exit();
    }

    $yearSql = $yearFilter ? " AND YEAR(f.fechaInicio) = {$yearFilter}" : "";
    $subdivisionSql = "";
    if (!empty($subdivisions)) {
        $in = implode(',', $subdivisions); // ya sanitizados
        $subdivisionSql = " AND f.id_subdivision IN ({$in})";
    }

    $query = "
        SELECT 
            f.tipo                      AS tipo, 
            f.modalidad                 AS modalidad,
            l.id                        AS idLocal,
            can.nombre_canal            AS nombreCanal,
            di.nombre_distrito          AS nombreDistrito,
            zo.nombre_zona              AS nombreZona,            
            l.codigo                    AS codigo_local,
            f.nombre                    AS nombreCampaña,
            f.fechaInicio,
            f.fechaTermino,
            fq.fechaVisita,
            fq.fechaPropuesta,            
            l.nombre                    AS nombre_local,
            l.direccion                 AS direccion_local,
            co.comuna                   AS comuna_local,
            re.region                   AS region_local,
            c.nombre                    AS cuenta,
            ca.nombre                   AS cadena,
            fq.material,
            fq.valor_propuesto,
            fq.valor,
            fq.observacion,
            f.estado,
            CASE
                WHEN fq.pregunta IN ('en proceso', 'cancelado')
                     AND (fq.observacion LIKE '%-%' OR fq.observacion LIKE '%|%')
                THEN
                    TRIM(
                        SUBSTRING_INDEX(
                            REPLACE(fq.observacion, '|', '-'),
                            '-',
                            1
                        )
                    )
                ELSE fq.pregunta
            END AS pregunta,
            u.usuario                   AS gestionado_por,
            CONCAT(u.nombre, ' ', u.apellido) AS nombreCompleto,       
            sc.nombre_subcanal          AS subcanal,
            f.tipo,
            sd.nombre
        FROM formularioQuestion fq
        INNER JOIN local      l  ON l.id   = fq.id_local
        INNER JOIN canal      can ON can.id = l.id_canal
        INNER JOIN distrito   di ON di.id  = l.id_distrito
        INNER JOIN zona       zo ON zo.id = l.id_zona
        INNER JOIN subcanal   sc ON sc.id  = l.id_subcanal
        INNER JOIN comuna     co ON co.id  = l.id_comuna
        INNER JOIN region     re ON re.id  = co.id_region
        INNER JOIN formulario f  ON f.id   = fq.id_formulario
        INNER JOIN subdivision  sd  ON sd.id   = f.id_subdivision        
        INNER JOIN usuario    u  ON u.id   = fq.id_usuario
        INNER JOIN cuenta     c  ON c.id   = l.id_cuenta
        INNER JOIN cadena     ca ON ca.id  = l.id_cadena
        WHERE f.id_division = {$division}
          {$subdivisionSql}
          AND f.estado IN (3,1)
          {$yearSql}
        ORDER BY l.codigo, fq.fechaVisita ASC
    ";

    // Streaming
    $result = mysqli_query($conn, $query, MYSQLI_USE_RESULT);
    header('Content-Type: application/json; charset=UTF-8');

    if ($result) {
        echo '[';
        $first = true;
        while ($row = mysqli_fetch_assoc($result)) {
            if (!$first) echo ',';
            $first = false;
            echo json_encode($row, JSON_UNESCAPED_UNICODE);
            flush();
        }
        echo ']';
        mysqli_free_result($result);
    } else {
        echo json_encode(["error" => mysqli_error($conn)], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// ---- Entrada ----
$division = isset($_GET['id_division']) ? (int)$_GET['id_division'] : 0;
$year     = (isset($_GET['year']) && $_GET['year'] !== '') ? (int)$_GET['year'] : null;
$subs     = normalizeSubdivisionParam();

// Ejecutar
getMaterialPivot($division, $subs, $year);
