<?php
declare(strict_types=1);

// ----------------------------------------------------------------------------
// Config inicial
// ----------------------------------------------------------------------------
while (ob_get_level()) { ob_end_clean(); }

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('memory_limit', '1024M');
ini_set('zlib.output_compression', 'Off');
set_time_limit(0);
ignore_user_abort(true);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (function_exists('mysqli_set_charset')) {
    mysqli_set_charset($conn, 'utf8mb4');
}

// ----------------------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------------------
function responderErrorIframe(string $mensaje, int $status = 400): void
{
    while (ob_get_level()) { ob_end_clean(); }

    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');

    $jsonMsg = json_encode($mensaje, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    echo "<script>window.parent.postMessage({type:'rbDownloadError',message:$jsonMsg}, '*');</script>";
    exit;
}

function parseFecha(string $valor): ?DateTimeImmutable
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $valor);
    $errors = DateTimeImmutable::getLastErrors();

    if ($dt === false) {
        return null;
    }

    if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
        return null;
    }

    if ($dt->format('Y-m-d') !== $valor) {
        return null;
    }

    return $dt;
}

// ----------------------------------------------------------------------------
// Validación
// ----------------------------------------------------------------------------
$fechaDesdeRaw = trim((string)($_POST['fecha_desde'] ?? ''));
$fechaHastaRaw = trim((string)($_POST['fecha_hasta'] ?? ''));
$downloadToken = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($_POST['download_token'] ?? ''));

$fechaDesde = parseFecha($fechaDesdeRaw);
$fechaHasta = parseFecha($fechaHastaRaw);

if (!$fechaDesde || !$fechaHasta) {
    responderErrorIframe('Debes ingresar fechas válidas.');
}

if ($fechaHasta < $fechaDesde) {
    responderErrorIframe('La fecha hasta no puede ser menor que la fecha desde.');
}

// Máximo 3 meses
if ($fechaHasta >= $fechaDesde->modify('+3 months')) {
    responderErrorIframe('El rango máximo permitido es de 3 meses por descarga.');
}

// Para no romper índice en created_at, usamos rango directo y no DATE(created_at)
$desdeTs = $fechaDesde->format('Y-m-d 00:00:00');
$hastaExclusivaTs = $fechaHasta->modify('+1 day')->format('Y-m-d 00:00:00');


// ----------------------------------------------------------------------------
$sql = <<<'SQL'
WITH preguntas_mapeadas AS (
    SELECT 'POP_EXTERIOR' AS nombre_columna, 'Selecciona qué Material POP REDBULL está presente en el exterior del PDV' AS pregunta_norm
    UNION ALL
    SELECT 'POP_EXTERIOR' AS nombre_columna, '¿Local tiene POP Exterior?' AS pregunta_norm
    UNION ALL
    SELECT 'POP_INTERIOR' AS nombre_columna, '¿Local tiene POP interior?' AS pregunta_norm
    UNION ALL
    SELECT 'PRESENCIA_COOLER_CCU' AS nombre_columna, '¿El local tiene cooler de bebidas analcohólicas CCU? (Pepsi, Bilz y Pap, etc.)' AS pregunta_norm
    UNION ALL
    SELECT 'PUERTAS_COOLER_CCU' AS nombre_columna, '¿Cuántas puertas hay de cooler CCU?' AS pregunta_norm
    UNION ALL
    SELECT 'PRESENCIA_ROCKSTAR_EN_CCU' AS nombre_columna, '¿Hay productos Rockstar en el cooler CCU?' AS pregunta_norm
    UNION ALL
    SELECT 'RED_BULL_EN_COOLER_CCU' AS nombre_columna, 'Indique productos Red Bull presentes, precios y número de caras: - (Selección y relleno de casilla) -3-' AS pregunta_norm
    UNION ALL
    SELECT 'RED_BULL_EN_COOLER_CCU' AS nombre_columna, 'Indique productos Red Bull presentes, precios y número de caras: - (Selección y relleno de casilla) -2-' AS pregunta_norm
    UNION ALL
    SELECT 'RED_BULL_EN_COOLER_CCU' AS nombre_columna, 'Indique productos Red Bull presentes, Ingrese precio de cada una que seleccione - (Selección y relleno de casilla)' AS pregunta_norm
    UNION ALL
    SELECT 'RED_BULL_EN_COOLER_CCU' AS nombre_columna, '2. Indique productos Red Bull presentes, precios y número de caras: - (Selección y relleno de casilla) -4-' AS pregunta_norm
    UNION ALL
    SELECT 'RED_BULL_EN_COOLER_CCU' AS nombre_columna, 'Seleccione Productos Red Bull Presente en Cooler CCU' AS pregunta_norm
    UNION ALL
    SELECT 'PRESENCIA_COOLER_GENERICO' AS nombre_columna, '¿El PDV cuenta con cooler genérico?' AS pregunta_norm
    UNION ALL
    SELECT 'PRESENCIA_COOLER_RED_BULL' AS nombre_columna, '¿Existe cooler Red Bull en el local?' AS pregunta_norm
    UNION ALL
    SELECT 'COOLER_RED_BULL_CONTAMINADO' AS nombre_columna, '¿El cooler Red Bull se encuentra contaminado?' AS pregunta_norm
    UNION ALL
    SELECT 'COOLER_RED_BULL_REVESTIDO' AS nombre_columna, '¿El cooler Red Bull se encuentra revestido con una cabecera?' AS pregunta_norm
    UNION ALL
    SELECT 'CABECERA_RED_BULL_CONTAMINADA' AS nombre_columna, '¿La cabecera está contaminada con otros productos?' AS pregunta_norm
    UNION ALL
    SELECT 'PRESENCIA_COOLER_MONSTER' AS nombre_columna, '¿El local tiene cooler de Monster?' AS pregunta_norm
    UNION ALL
    SELECT 'PRESENCIA_COOLER_SCORE' AS nombre_columna, '¿El local tiene cooler de Score?' AS pregunta_norm
    UNION ALL
    SELECT 'PRESENCIA_COOLER_COMPARTIDO' AS nombre_columna, '¿El local tiene cooler compartido de CCU/Red Bull?' AS pregunta_norm
    UNION ALL
    SELECT 'COOLER_COMPARTIDO_3_BANDEJAS_CUMPLE' AS nombre_columna, '¿COOLER COMPARTIDO CCU/RED BULL CUMPLE CON LAS 3 BANDEJAS RED BULL' AS pregunta_norm
    UNION ALL
    SELECT 'COOLER_COMPARTIDO_CON_RED_BULL' AS nombre_columna, '¿COOLER COMPARTIDO CC/RED BULL TIENE PRODUCTOS RED BULL?' AS pregunta_norm
    UNION ALL
    SELECT 'CHECKOUT' AS nombre_columna, '¿El local cuenta con Energy Checkout?' AS pregunta_norm
    UNION ALL
    SELECT 'CHECKOUT_CONTAMINADO' AS nombre_columna, '¿El Cooler del Energy Checkout se encuentra contaminado?' AS pregunta_norm
),
respuestas_mapeadas AS (
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250' AS nombre_columna, 'RED BULL 250ML REGULAR' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250' AS nombre_columna, 'RED BULL ED 250ML (REGULAR)' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250' AS nombre_columna, 'RED BULL ED 250ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355' AS nombre_columna, 'RED BULL 355ML REGULAR' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355' AS nombre_columna, 'RED BULL ED 355ML (REGULAR)' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355' AS nombre_columna, 'RED BULL ED 355ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473' AS nombre_columna, 'RED BULL ED 473ML (REGULAR)' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473' AS nombre_columna, 'RED BULL 473ML REGULAR' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473' AS nombre_columna, 'RED BULL ED 473ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250' AS nombre_columna, 'RED BULL 250ML SUGAR FREE' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250' AS nombre_columna, 'RED BULL SUGAR FREE 250ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250' AS nombre_columna, 'RED BULL SF 250ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355' AS nombre_columna, 'RED BULL 355ML SUGAR FREE' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355' AS nombre_columna, 'RED BULL SUGAR FREE 355ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355' AS nombre_columna, 'RED BULL SF 355ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473' AS nombre_columna, 'RED BULL 473ML SUGAR FREE' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473' AS nombre_columna, 'RED BULL SUGAR FREE 473ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473' AS nombre_columna, 'RED BULL SF 473ML' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO' AS nombre_columna, 'RED BULL ZERO' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN' AS nombre_columna, 'RED BULL GREEN' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN' AS nombre_columna, 'RED BULL GREEN EDITION' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED' AS nombre_columna, 'RED BULL RED EDITION' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW' AS nombre_columna, 'RED BULL YELLOW EDITION' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE' AS nombre_columna, 'RED BULL BLUE EDITION' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE' AS nombre_columna, 'RED BULL BLUE' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO' AS nombre_columna, 'RED BULL SPRING' AS respuesta_norm
    UNION ALL
    SELECT 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO' AS nombre_columna, 'RED BULL SUMMER DURAZNO' AS respuesta_norm
),
base AS (
    SELECT
        f.tipo AS tipo,
        de.nombre AS division,
        sd.nombre AS subdivision,
        f.id AS idCampana,
        can.nombre_canal AS nombreCanal,
        di.nombre_distrito AS nombreDistrito,
        f.nombre AS nombreCampana,
        l.codigo AS codigo_local,
        l.nombre AS nombre_local,
        cu.nombre AS cuenta,
        ca.nombre AS cadena,
        c.comuna AS comuna,
        r.region AS region,
        z.nombre_zona AS nombreZona,
        DATE(fqr.created_at) AS fecha_respuesta,
        CONCAT(u.nombre, ' ', u.apellido) AS nombreCompleto,
        REGEXP_REPLACE(TRIM(REPLACE(fp.question_text, CHAR(9), ' ')), '[[:space:]]+', ' ') AS pregunta_norm,
        UPPER(REGEXP_REPLACE(TRIM(REPLACE(fqr.answer_text, CHAR(9), ' ')), '[[:space:]]+', ' ')) AS respuesta_norm,
        fqr.answer_text AS respuesta,
        fqr.valor AS precio
    FROM formulario f
    JOIN form_questions fp           ON fp.id_formulario = f.id
    JOIN form_question_responses fqr ON fqr.id_form_question = fp.id
    LEFT JOIN division_empresa de    ON de.id = f.id_division
    LEFT JOIN subdivision sd         ON sd.id = f.id_subdivision
    JOIN usuario u                   ON u.id = fqr.id_usuario
    JOIN local l                     ON l.id = fqr.id_local
    JOIN canal can                   ON can.id = l.id_canal
    JOIN cuenta cu                   ON cu.id = l.id_cuenta
    JOIN cadena ca                   ON ca.id = l.id_cadena
    JOIN distrito di                 ON di.id = l.id_distrito
    JOIN comuna c                    ON c.id = l.id_comuna
    JOIN zona z                      ON z.id = l.id_zona
    JOIN region r                    ON r.id = c.id_region
    WHERE f.id_division = 14
      AND f.id_subdivision = 1
      AND fqr.created_at >= ?
      AND fqr.created_at < ?
),
raw AS (
    SELECT
        b.tipo,
        b.division,
        b.subdivision,
        b.idCampana,
        b.nombreCanal,
        b.nombreDistrito,
        b.nombreCampana,
        b.codigo_local,
        b.nombre_local,
        b.cuenta,
        b.cadena,
        b.comuna,
        b.region,
        b.nombreZona,
        b.fecha_respuesta,
        b.nombreCompleto,

        MAX(CASE WHEN pm.nombre_columna = 'POP_EXTERIOR' THEN b.respuesta END) AS `POP_EXTERIOR`,
        MAX(CASE WHEN pm.nombre_columna = 'POP_INTERIOR' THEN b.respuesta END) AS `POP_INTERIOR`,
        MAX(CASE WHEN pm.nombre_columna = 'PRESENCIA_COOLER_CCU' THEN b.respuesta END) AS `PRESENCIA_COOLER_CCU`,
        MAX(CASE WHEN pm.nombre_columna = 'PUERTAS_COOLER_CCU' THEN b.respuesta END) AS `PUERTAS_COOLER_CCU`,
        MAX(CASE WHEN pm.nombre_columna = 'PRESENCIA_ROCKSTAR_EN_CCU' THEN b.respuesta END) AS `PRESENCIA_ROCKSTAR_EN_CCU`,
        MAX(CASE WHEN pm.nombre_columna = 'RED_BULL_EN_COOLER_CCU' THEN b.respuesta END) AS `RED_BULL_EN_COOLER_CCU`,
        MAX(CASE WHEN pm.nombre_columna = 'PRESENCIA_COOLER_GENERICO' THEN b.respuesta END) AS `PRESENCIA_COOLER_GENERICO`,
        MAX(CASE WHEN pm.nombre_columna = 'PRESENCIA_COOLER_RED_BULL' THEN b.respuesta END) AS `PRESENCIA_COOLER_RED_BULL`,
        MAX(CASE WHEN pm.nombre_columna = 'COOLER_RED_BULL_CONTAMINADO' THEN b.respuesta END) AS `COOLER_RED_BULL_CONTAMINADO`,
        MAX(CASE WHEN pm.nombre_columna = 'COOLER_RED_BULL_REVESTIDO' THEN b.respuesta END) AS `COOLER_RED_BULL_REVESTIDO`,
        MAX(CASE WHEN pm.nombre_columna = 'CABECERA_RED_BULL_CONTAMINADA' THEN b.respuesta END) AS `CABECERA_RED_BULL_CONTAMINADA`,
        MAX(CASE WHEN pm.nombre_columna = 'PRESENCIA_COOLER_MONSTER' THEN b.respuesta END) AS `PRESENCIA_COOLER_MONSTER`,
        MAX(CASE WHEN pm.nombre_columna = 'PRESENCIA_COOLER_SCORE' THEN b.respuesta END) AS `PRESENCIA_COOLER_SCORE`,
        MAX(CASE WHEN pm.nombre_columna = 'PRESENCIA_COOLER_COMPARTIDO' THEN b.respuesta END) AS `PRESENCIA_COOLER_COMPARTIDO`,
        MAX(CASE WHEN pm.nombre_columna = 'COOLER_COMPARTIDO_3_BANDEJAS_CUMPLE' THEN b.respuesta END) AS `COOLER_COMPARTIDO_3_BANDEJAS_CUMPLE`,
        MAX(CASE WHEN pm.nombre_columna = 'COOLER_COMPARTIDO_CON_RED_BULL' THEN b.respuesta END) AS `COOLER_COMPARTIDO_CON_RED_BULL`,
        MAX(CASE WHEN pm.nombre_columna = 'CHECKOUT' THEN b.respuesta END) AS `CHECKOUT`,
        MAX(CASE WHEN pm.nombre_columna = 'CHECKOUT_CONTAMINADO' THEN b.respuesta END) AS `CHECKOUT_CONTAMINADO`,

        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO' THEN b.respuesta END) AS `ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO`,
        MAX(CASE WHEN rm.nombre_columna = 'ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO' THEN b.precio END) AS `PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO`
    FROM base b
    LEFT JOIN preguntas_mapeadas pm
      ON pm.pregunta_norm = b.pregunta_norm
    LEFT JOIN respuestas_mapeadas rm
      ON rm.respuesta_norm = b.respuesta_norm
    WHERE pm.nombre_columna IS NOT NULL
       OR rm.nombre_columna IS NOT NULL
    GROUP BY
        b.tipo,
        b.division,
        b.subdivision,
        b.idCampana,
        b.nombreCanal,
        b.nombreDistrito,
        b.nombreCampana,
        b.codigo_local,
        b.nombre_local,
        b.cuenta,
        b.cadena,
        b.comuna,
        b.region,
        b.nombreZona,
        b.fecha_respuesta,
        b.nombreCompleto
)
SELECT
    raw.tipo,
    raw.division,
    raw.subdivision,
    raw.idCampana,
    raw.nombreCanal,
    raw.nombreDistrito,
    raw.nombreCampana,
    raw.codigo_local,
    raw.nombre_local,
    raw.cuenta,
    raw.cadena,
    raw.comuna,
    raw.region,
    raw.nombreZona,
    raw.fecha_respuesta,
    raw.nombreCompleto,

    CASE
        WHEN raw.POP_EXTERIOR IS NULL OR TRIM(raw.POP_EXTERIOR) = '' OR UPPER(TRIM(raw.POP_EXTERIOR)) = 'NO' THEN 0
        ELSE 1
    END AS POP_EXTERIOR,

    CASE
        WHEN raw.POP_INTERIOR IS NULL OR TRIM(raw.POP_INTERIOR) = '' OR UPPER(TRIM(raw.POP_INTERIOR)) = 'NO' THEN 0
        ELSE 1
    END AS POP_INTERIOR,

    CASE
        WHEN raw.PRESENCIA_COOLER_CCU IS NULL OR TRIM(raw.PRESENCIA_COOLER_CCU) = '' OR UPPER(TRIM(raw.PRESENCIA_COOLER_CCU)) = 'NO' THEN 0
        ELSE 1
    END AS PRESENCIA_COOLER_CCU,

    raw.PUERTAS_COOLER_CCU AS PUERTAS_COOLER_CCU,

    CASE
        WHEN raw.PRESENCIA_ROCKSTAR_EN_CCU IS NULL OR TRIM(raw.PRESENCIA_ROCKSTAR_EN_CCU) = '' OR UPPER(TRIM(raw.PRESENCIA_ROCKSTAR_EN_CCU)) = 'NO' THEN 0
        ELSE 1
    END AS PRESENCIA_ROCKSTAR_EN_CCU,

    CASE
        WHEN raw.RED_BULL_EN_COOLER_CCU IS NULL OR TRIM(raw.RED_BULL_EN_COOLER_CCU) = '' THEN 0
        ELSE 1
    END AS RED_BULL_EN_COOLER_CCU,

    CASE
        WHEN raw.PRESENCIA_COOLER_GENERICO IS NULL OR TRIM(raw.PRESENCIA_COOLER_GENERICO) = '' OR UPPER(TRIM(raw.PRESENCIA_COOLER_GENERICO)) = 'NO' THEN 0
        ELSE 1
    END AS PRESENCIA_COOLER_GENERICO,

    CASE
        WHEN raw.PRESENCIA_COOLER_RED_BULL IS NULL OR TRIM(raw.PRESENCIA_COOLER_RED_BULL) = '' OR UPPER(TRIM(raw.PRESENCIA_COOLER_RED_BULL)) = 'NO' THEN 0
        ELSE 1
    END AS PRESENCIA_COOLER_RED_BULL,

    CASE
        WHEN raw.COOLER_RED_BULL_CONTAMINADO IS NULL OR TRIM(raw.COOLER_RED_BULL_CONTAMINADO) = '' OR UPPER(TRIM(raw.COOLER_RED_BULL_CONTAMINADO)) = 'NO' THEN 0
        ELSE 1
    END AS COOLER_RED_BULL_CONTAMINADO,

    CASE
        WHEN raw.COOLER_RED_BULL_REVESTIDO IS NULL OR TRIM(raw.COOLER_RED_BULL_REVESTIDO) = '' OR UPPER(TRIM(raw.COOLER_RED_BULL_REVESTIDO)) = 'NO' THEN 0
        ELSE 1
    END AS COOLER_RED_BULL_REVESTIDO,

    CASE
        WHEN raw.CABECERA_RED_BULL_CONTAMINADA IS NULL OR TRIM(raw.CABECERA_RED_BULL_CONTAMINADA) = '' OR UPPER(TRIM(raw.CABECERA_RED_BULL_CONTAMINADA)) = 'NO' THEN 0
        ELSE 1
    END AS CABECERA_RED_BULL_CONTAMINADA,

    CASE
        WHEN raw.PRESENCIA_COOLER_MONSTER IS NULL OR TRIM(raw.PRESENCIA_COOLER_MONSTER) = '' OR UPPER(TRIM(raw.PRESENCIA_COOLER_MONSTER)) = 'NO' THEN 0
        ELSE 1
    END AS PRESENCIA_COOLER_MONSTER,

    CASE
        WHEN raw.PRESENCIA_COOLER_SCORE IS NULL OR TRIM(raw.PRESENCIA_COOLER_SCORE) = '' OR UPPER(TRIM(raw.PRESENCIA_COOLER_SCORE)) = 'NO' THEN 0
        ELSE 1
    END AS PRESENCIA_COOLER_SCORE,

    CASE
        WHEN raw.PRESENCIA_COOLER_COMPARTIDO IS NULL OR TRIM(raw.PRESENCIA_COOLER_COMPARTIDO) = '' OR UPPER(TRIM(raw.PRESENCIA_COOLER_COMPARTIDO)) = 'NO' THEN 0
        ELSE 1
    END AS PRESENCIA_COOLER_COMPARTIDO,

    CASE
        WHEN raw.`COOLER_COMPARTIDO_3_BANDEJAS_CUMPLE` IS NULL OR TRIM(raw.`COOLER_COMPARTIDO_3_BANDEJAS_CUMPLE`) = '' OR UPPER(TRIM(raw.`COOLER_COMPARTIDO_3_BANDEJAS_CUMPLE`)) = 'NO' THEN 0
        ELSE 1
    END AS `COOLER_COMPARTIDO_3_BANDEJAS_CUMPLE`,

    CASE
        WHEN raw.COOLER_COMPARTIDO_CON_RED_BULL IS NULL OR TRIM(raw.COOLER_COMPARTIDO_CON_RED_BULL) = '' OR UPPER(TRIM(raw.COOLER_COMPARTIDO_CON_RED_BULL)) = 'NO' THEN 0
        ELSE 1
    END AS COOLER_COMPARTIDO_CON_RED_BULL,

    CASE
        WHEN raw.CHECKOUT IS NULL OR TRIM(raw.CHECKOUT) = '' OR UPPER(TRIM(raw.CHECKOUT)) = 'NO' THEN 0
        ELSE 1
    END AS CHECKOUT,

    CASE
        WHEN raw.CHECKOUT_CONTAMINADO IS NULL OR TRIM(raw.CHECKOUT_CONTAMINADO) = '' OR UPPER(TRIM(raw.CHECKOUT_CONTAMINADO)) = 'NO' THEN 0
        ELSE 1
    END AS CHECKOUT_CONTAMINADO,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250 IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355 IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473 IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250 IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355 IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473 IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO,

    CASE
        WHEN raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO IS NULL OR TRIM(raw.ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO) = '' THEN 0
        ELSE 1
    END AS ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO,

    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_250,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_355,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ED_473,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_250,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_355,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_SF_473,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_ZERO,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_GREEN,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_RED,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_YELLOW,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_BLUE,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_POMELO,
    raw.PRECIO_ESTA_ESTE_PRODUCTO_EN_EL_LOCAL_DURAZNO
FROM raw
ORDER BY raw.codigo_local, raw.fecha_respuesta;
SQL;

// ----------------------------------------------------------------------------
// Ejecución
// ----------------------------------------------------------------------------
try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $desdeTs, $hastaExclusivaTs);
    $stmt->execute();

    $result = $stmt->get_result();
    if (!$result) {
        responderErrorIframe('No fue posible obtener el resultado de la consulta.', 500);
    }

    if ($downloadToken !== '') {
        setcookie('fileDownloadToken', $downloadToken, 0, '/');
    }

    $filename = sprintf(
        'RB_Tradicional_%s_%s.csv',
        $fechaDesde->format('Ymd'),
        $fechaHasta->format('Ymd')
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Accel-Buffering: no');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        responderErrorIframe('No fue posible abrir el flujo de salida.', 500);
    }

    // BOM para Excel
    fwrite($out, "\xEF\xBB\xBF");

    // Encabezados automáticos
    $fields = $result->fetch_fields();
    $headers = [];
    foreach ($fields as $field) {
        $headers[] = $field->name;
    }

    // CSV con separador ; para Excel regional
    fputcsv($out, $headers, ';');

    $i = 0;
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, $row, ';');
        $i++;

        if (($i % 500) === 0) {
            fflush($out);
        }
    }

    fclose($out);
    $result->free();
    $stmt->close();
    exit;

} catch (Throwable $e) {
    error_log('Error descargar_rb_tradicional_csv.php: ' . $e->getMessage());
    responderErrorIframe('Ocurrió un error al generar el archivo.', 500);
}