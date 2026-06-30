<?php
include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

function failDownload($message, $statusCode = 400)
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function getDateParam($key)
{
    $value = isset($_GET[$key]) ? trim((string)$_GET[$key]) : '';
    if ($value === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        failDownload($key . ' debe tener formato YYYY-MM-DD.');
    }

    return $value;
}

$division = isset($_GET['id_division']) ? (int)$_GET['id_division'] : 0;
$fechaInicio = getDateParam('fecha_inicio');
$fechaFin = getDateParam('fecha_fin');

if ($division <= 0) {
    failDownload('Debe seleccionar una division.');
}

if ($fechaInicio === '' || $fechaFin === '') {
    failDownload('Debe seleccionar fecha inicio y fecha fin.');
}

if ($fechaFin < $fechaInicio) {
    failDownload('La fecha fin no puede ser menor que la fecha inicio.');
}

$sql = <<<'SQL'
WITH base AS (
    SELECT
        gv.id AS id_movimiento,
        gv.id_formulario,
        gv.id_formularioQuestion,
        gv.id_local,
        gv.fecha_visita,
        gv.created_at,

        de.nombre AS division,
        em.nombre AS categoria,
        f.nombre AS campana,
        l.codigo AS codigo,
        DATE(f.fechaInicio) AS fechaCreacion,
        DATE(f.fechaTermino) AS fechaImplementacion,
        DATE(f.fechaTermino) AS fechaFinImplementacion,
        DATE(f.fechaInicio) AS fechaInicio,
        DATE(f.fechaTermino) AS fechaTermino,
        DATE(COALESCE(gv.fecha_visita, gv.created_at)) AS fechaVisita,
        DATE(COALESCE(gv.fecha_visita, gv.created_at)) AS fechaVisita2,
        TIME(COALESCE(gv.fecha_visita, gv.created_at)) AS hora,
        cu.nombre AS cuenta,
        ca.nombre AS cadena,
        l.nombre AS local,
        l.direccion AS direccion,
        r.region AS region,
        com.comuna AS comuna,
        can.nombre_canal AS canal,
        UPPER(COALESCE(u.usuario, '')) AS usuario,

        CASE
            WHEN LOWER(TRIM(gv.estado_gestion)) IN ('armado') THEN 'armado'
            WHEN LOWER(TRIM(gv.estado_gestion)) IN ('entregado', 'entrega') THEN 'entregado'
            WHEN LOWER(TRIM(gv.estado_gestion)) IN ('implementado', 'implementacion', 'implementación', 'solo_implementado') THEN 'implementado'
            WHEN LOWER(TRIM(gv.estado_gestion)) IN ('retirado', 'retiro') THEN 'retirado'
            WHEN gv.estado_gestion IS NOT NULL AND TRIM(gv.estado_gestion) <> '' THEN LOWER(TRIM(gv.estado_gestion))
            WHEN LOWER(TRIM(fq.etapa_material)) IN ('armado') THEN 'armado'
            WHEN LOWER(TRIM(fq.etapa_material)) IN ('entregado', 'entrega') THEN 'entregado'
            WHEN LOWER(TRIM(fq.etapa_material)) IN ('implementado', 'implementacion', 'implementación', 'solo_implementado') THEN 'implementado'
            WHEN LOWER(TRIM(fq.etapa_material)) IN ('retirado', 'retiro') THEN 'retirado'
            WHEN fq.etapa_material IS NOT NULL AND TRIM(fq.etapa_material) <> '' THEN LOWER(TRIM(fq.etapa_material))
            WHEN IFNULL(fq.valor, 0) = 0 THEN 'no implementado'
            WHEN LOWER(TRIM(fq.pregunta)) = 'solo_implementado' THEN 'implementado'
            WHEN LOWER(TRIM(fq.pregunta)) = 'no_implementado' THEN 'no implementado'
            ELSE LOWER(TRIM(fq.pregunta))
        END AS etapa_key,

        CASE
            WHEN fq.etapa_material IS NULL OR TRIM(fq.etapa_material) = '' THEN 'SIN ETAPA ACTUAL'
            ELSE UPPER(TRIM(fq.etapa_material))
        END AS etapaActualMaterial,

        UPPER(
            CASE
                WHEN LOWER(TRIM(gv.estado_gestion)) IN ('cancelado', 'cancelada', 'cancelacion', 'cancelación')
                    AND COALESCE(NULLIF(TRIM(gv.observacion), ''), NULLIF(TRIM(fq.observacion), '')) IS NOT NULL
                    THEN TRIM(SUBSTRING_INDEX(COALESCE(NULLIF(TRIM(gv.observacion), ''), NULLIF(TRIM(fq.observacion), '')), '-', 1))
                WHEN gv.motivo_no_implementacion IS NOT NULL AND TRIM(gv.motivo_no_implementacion) <> ''
                    THEN TRIM(gv.motivo_no_implementacion)
                WHEN fq.motivo IS NOT NULL AND TRIM(fq.motivo) <> ''
                    THEN TRIM(fq.motivo)
                WHEN IFNULL(fq.valor, 0) = 0
                    AND (fq.motivo IS NULL OR fq.motivo = '')
                    AND fq.observacion IS NOT NULL
                    THEN TRIM(SUBSTRING_INDEX(fq.observacion, '-', 1))
                ELSE NULL
            END
        ) AS motivo,

        UPPER(
            COALESCE(
                NULLIF(TRIM(fq.material), ''),
                CASE
                    WHEN LOWER(TRIM(gv.estado_gestion)) IN ('cancelado', 'cancelada', 'cancelacion', 'cancelación')
                        OR fq.etapa_material IS NULL
                        OR TRIM(fq.etapa_material) = ''
                    THEN (
                        SELECT fq_mat.material
                        FROM formularioQuestion fq_mat
                        WHERE fq_mat.id_formulario = gv.id_formulario
                          AND fq_mat.id_local = gv.id_local
                          AND fq_mat.material IS NOT NULL
                          AND TRIM(fq_mat.material) <> ''
                        ORDER BY fq_mat.id ASC
                        LIMIT 1
                    )
                    ELSE NULL
                END,
                ''
            )
        ) AS material,
        UPPER(
            COALESCE(
                NULLIF(TRIM(fq.categoria), ''),
                CASE
                    WHEN LOWER(TRIM(gv.estado_gestion)) IN ('cancelado', 'cancelada', 'cancelacion', 'cancelación')
                        OR fq.etapa_material IS NULL
                        OR TRIM(fq.etapa_material) = ''
                    THEN (
                        SELECT fq_mat.categoria
                        FROM formularioQuestion fq_mat
                        WHERE fq_mat.id_formulario = gv.id_formulario
                          AND fq_mat.id_local = gv.id_local
                          AND fq_mat.categoria IS NOT NULL
                          AND TRIM(fq_mat.categoria) <> ''
                        ORDER BY fq_mat.id ASC
                        LIMIT 1
                    )
                    ELSE NULL
                END,
                ''
            )
        ) AS categoriaMat,
        UPPER(
            COALESCE(
                NULLIF(TRIM(fq.marca), ''),
                CASE
                    WHEN LOWER(TRIM(gv.estado_gestion)) IN ('cancelado', 'cancelada', 'cancelacion', 'cancelación')
                        OR fq.etapa_material IS NULL
                        OR TRIM(fq.etapa_material) = ''
                    THEN (
                        SELECT fq_mat.marca
                        FROM formularioQuestion fq_mat
                        WHERE fq_mat.id_formulario = gv.id_formulario
                          AND fq_mat.id_local = gv.id_local
                          AND fq_mat.marca IS NOT NULL
                          AND TRIM(fq_mat.marca) <> ''
                        ORDER BY fq_mat.id ASC
                        LIMIT 1
                    )
                    ELSE NULL
                END,
                ''
            )
        ) AS marcaMat,

        IFNULL(gv.valor_real, 0) AS valorMovimiento,
        IFNULL(fq.valor, 0) AS valorTotalMaterial,
        COALESCE(
            NULLIF(fq.valor_propuesto, ''),
            CASE
                WHEN LOWER(TRIM(gv.estado_gestion)) IN ('cancelado', 'cancelada', 'cancelacion', 'cancelación')
                    OR fq.etapa_material IS NULL
                    OR TRIM(fq.etapa_material) = ''
                THEN (
                    SELECT fq_mat.valor_propuesto
                    FROM formularioQuestion fq_mat
                    WHERE fq_mat.id_formulario = gv.id_formulario
                      AND fq_mat.id_local = gv.id_local
                      AND fq_mat.valor_propuesto IS NOT NULL
                      AND fq_mat.valor_propuesto <> ''
                    ORDER BY fq_mat.id ASC
                    LIMIT 1
                )
                ELSE NULL
            END,
            0
        ) AS valorPropuesto,

        UPPER(
            COALESCE(
                NULLIF(TRIM(gv.observacion), ''),
                NULLIF(TRIM(fq.observacion), ''),
                ''
            )
        ) AS observacion

    FROM gestion_visita gv
    JOIN formulario f ON f.id = gv.id_formulario
    LEFT JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
    JOIN empresa em ON em.id = f.id_empresa
    JOIN local l ON l.id = gv.id_local
    JOIN cuenta cu ON cu.id = l.id_cuenta
    JOIN cadena ca ON ca.id = l.id_cadena
    JOIN canal can ON can.id = l.id_canal
    JOIN comuna com ON com.id = l.id_comuna
    JOIN region r ON r.id = com.id_region
    JOIN division_empresa de ON de.id = f.id_division
    LEFT JOIN usuario u ON u.id = gv.id_usuario

    WHERE f.id_division = ?
      AND DATE(COALESCE(gv.fecha_visita, gv.created_at)) BETWEEN ? AND ?
),

apoyos_raw AS (
    SELECT
        ga.id_formulario,
        ga.id_formularioQuestion,
        CASE
            WHEN LOWER(TRIM(ga.etapa)) IN ('armado') THEN 'armado'
            WHEN LOWER(TRIM(ga.etapa)) IN ('entregado', 'entrega') THEN 'entregado'
            WHEN LOWER(TRIM(ga.etapa)) IN ('implementado', 'implementacion', 'implementación') THEN 'implementado'
            WHEN LOWER(TRIM(ga.etapa)) IN ('retirado', 'retiro') THEN 'retirado'
            ELSE LOWER(TRIM(ga.etapa))
        END AS etapa_key,
        MIN(ga.created_at) AS fecha_apoyo,
        CONCAT(
            UPPER(
                COALESCE(
                    NULLIF(TRIM(CONCAT(COALESCE(uap.nombre, ''), ' ', COALESCE(uap.apellido, ''))), ''),
                    uap.usuario
                )
            ),
            CASE
                WHEN ga.etapa IS NOT NULL AND TRIM(ga.etapa) <> '' THEN CONCAT(' (', UPPER(TRIM(ga.etapa)), ')')
                ELSE ''
            END
        ) AS apoyo_txt
    FROM gestion_apoyo ga
    JOIN usuario uap ON uap.id = ga.id_usuario_apoyo
    GROUP BY
        ga.id_formulario,
        ga.id_formularioQuestion,
        ga.id_usuario_apoyo,
        ga.etapa,
        uap.nombre,
        uap.apellido,
        uap.usuario
),

apoyos AS (
    SELECT
        id_formulario,
        id_formularioQuestion,
        etapa_key,
        GROUP_CONCAT(apoyo_txt ORDER BY fecha_apoyo SEPARATOR ', ') AS apoyo
    FROM apoyos_raw
    GROUP BY id_formulario, id_formularioQuestion, etapa_key
),

fotos_etapa AS (
    SELECT
        fv.id_formulario,
        fv.id_formularioQuestion,
        CASE
            WHEN LOWER(TRIM(fv.kind)) IN ('armado') THEN 'armado'
            WHEN LOWER(TRIM(fv.kind)) IN ('entregado', 'entrega') THEN 'entregado'
            WHEN LOWER(TRIM(fv.kind)) IN ('implementado', 'implementacion', 'implementación') THEN 'implementado'
            WHEN LOWER(TRIM(fv.kind)) IN ('retirado', 'retiro') THEN 'retirado'
            ELSE LOWER(TRIM(fv.kind))
        END AS etapa_key,
        GROUP_CONCAT(
            CASE
                WHEN fv.url IS NULL OR TRIM(fv.url) = '' THEN NULL
                WHEN TRIM(fv.url) REGEXP '^https?://' THEN TRIM(fv.url)
                ELSE CONCAT(
                    'https://www.visibility.cl/visibility2/app/',
                    TRIM(LEADING '/' FROM REPLACE(TRIM(fv.url), '\\', '/'))
                )
            END
            ORDER BY fv.id
            SEPARATOR '; '
        ) AS url
    FROM fotoVisita fv
    WHERE fv.kind IS NOT NULL
      AND TRIM(fv.kind) <> ''
    GROUP BY
        fv.id_formulario,
        fv.id_formularioQuestion,
        CASE
            WHEN LOWER(TRIM(fv.kind)) IN ('armado') THEN 'armado'
            WHEN LOWER(TRIM(fv.kind)) IN ('entregado', 'entrega') THEN 'entregado'
            WHEN LOWER(TRIM(fv.kind)) IN ('implementado', 'implementacion', 'implementación') THEN 'implementado'
            WHEN LOWER(TRIM(fv.kind)) IN ('retirado', 'retiro') THEN 'retirado'
            ELSE LOWER(TRIM(fv.kind))
        END
),

fotos_general AS (
    SELECT
        fv.id_formulario,
        fv.id_formularioQuestion,
        GROUP_CONCAT(
            CASE
                WHEN fv.url IS NULL OR TRIM(fv.url) = '' THEN NULL
                WHEN TRIM(fv.url) REGEXP '^https?://' THEN TRIM(fv.url)
                ELSE CONCAT(
                    'https://www.visibility.cl/visibility2/app/',
                    TRIM(LEADING '/' FROM REPLACE(TRIM(fv.url), '\\', '/'))
                )
            END
            ORDER BY fv.id
            SEPARATOR '; '
        ) AS url
    FROM fotoVisita fv
    GROUP BY fv.id_formulario, fv.id_formularioQuestion
)

SELECT
    ROW_NUMBER() OVER (
        ORDER BY
            b.division,
            b.codigo,
            b.material,
            b.fechaVisita,
            b.hora,
            b.id_movimiento
    ) AS correlativo,
    b.id_movimiento,
    b.division,
    b.categoria,
    b.campana,
    b.codigo,
    b.fechaCreacion,
    b.fechaImplementacion,
    b.fechaFinImplementacion,
    b.fechaInicio,
    b.fechaTermino,
    b.fechaVisita,
    b.fechaVisita2,
    b.hora,
    b.cuenta,
    b.cadena,
    b.local,
    b.direccion,
    b.region,
    b.comuna,
    b.canal,
    b.usuario,
    IFNULL(ap.apoyo, 'SIN APOYO') AS apoyo,
    CASE b.etapa_key
        WHEN 'armado' THEN 'ARMADO'
        WHEN 'entregado' THEN 'ENTREGADO'
        WHEN 'implementado' THEN 'IMPLEMENTADO'
        WHEN 'retirado' THEN 'RETIRADO'
        WHEN 'solo_implementado' THEN 'IMPLEMENTADO'
        WHEN 'no_implementado' THEN 'NO IMPLEMENTADO'
        WHEN 'no implementado' THEN 'NO IMPLEMENTADO'
        ELSE UPPER(REPLACE(b.etapa_key, '_', ' '))
    END AS etapa,
    b.etapaActualMaterial,
    b.motivo,
    b.material,
    b.categoriaMat,
    b.marcaMat,
    b.valorMovimiento,
    b.valorTotalMaterial,
    b.valorPropuesto,
    b.observacion,
    COALESCE(foto_etapa.url, foto_general.url, '') AS url
FROM base b
LEFT JOIN apoyos ap
    ON ap.id_formulario = b.id_formulario
    AND ap.id_formularioQuestion = b.id_formularioQuestion
    AND ap.etapa_key = b.etapa_key
LEFT JOIN fotos_etapa foto_etapa
    ON foto_etapa.id_formulario = b.id_formulario
    AND foto_etapa.id_formularioQuestion = b.id_formularioQuestion
    AND foto_etapa.etapa_key = b.etapa_key
LEFT JOIN fotos_general foto_general
    ON foto_general.id_formulario = b.id_formulario
    AND foto_general.id_formularioQuestion = b.id_formularioQuestion
ORDER BY
    b.codigo,
    b.material,
    b.fechaVisita,
    b.hora,
    b.id_movimiento
SQL;

$fechaInicioSql = mysqli_real_escape_string($conn, $fechaInicio);
$fechaFinSql = mysqli_real_escape_string($conn, $fechaFin);
$sql = preg_replace('/f\.id_division = \?/', 'f.id_division = ' . $division, $sql, 1);
$sql = preg_replace("/BETWEEN \? AND \?/", "BETWEEN '{$fechaInicioSql}' AND '{$fechaFinSql}'", $sql, 1);

$result = $conn->query($sql);
if (!$result) {
    failDownload('Error ejecutando consulta: ' . $conn->error, 500);
}

$filename = 'data_adicionales_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'wb');
fputs($output, "\xEF\xBB\xBF");

$headersWritten = false;
while ($row = $result->fetch_assoc()) {
    if (!$headersWritten) {
        fputcsv($output, array_keys($row), ';');
        $headersWritten = true;
    }
    fputcsv($output, $row, ';');
}

if (!$headersWritten) {
    fputcsv($output, ['Sin datos'], ';');
}

fclose($output);
exit;
