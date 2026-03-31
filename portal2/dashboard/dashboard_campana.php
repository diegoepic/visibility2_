<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($conn) || $conn->connect_error) {
    die('Error BD: ' . $conn->connect_error);
}

// 🔹 Validar ID campaña
$idCampana = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($idCampana <= 0) {
    die('ID de campaña inválido');
}

// 🔹 Validar división (opcional pero recomendable)
$division = isset($_GET['division']) ? intval($_GET['division']) : 0;

// 🔹 Seguridad extra: validar que campaña pertenece a empresa sesión
$idEmpresa = intval($_SESSION['empresa_id'] ?? 0);

$sqlValida = "
    SELECT id, nombre
    FROM formulario
    WHERE id = ?
      AND id_empresa = ?
      AND estado IN (1,3)
    LIMIT 1
";

$stmtVal = $conn->prepare($sqlValida);
$stmtVal->bind_param("ii", $idCampana, $idEmpresa);
$stmtVal->execute();
$resVal = $stmtVal->get_result();
$campana = $resVal->fetch_assoc();
$stmtVal->close();

if (!$campana) {
    die('Campaña no válida o no pertenece a su empresa.');
}

$nombreCampana = $campana['nombre'];

$sqlHeader = "
SELECT 
    MAX(fq.fechaVisita) AS ultimaGestion
FROM formularioQuestion fq
WHERE fq.id_formulario = ?
";

$stmt = $conn->prepare($sqlHeader);
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$res = $stmt->get_result();
$headerData = $res->fetch_assoc();
$stmt->close();

$ultimaGestion = $headerData['ultimaGestion'] ?? null;

$fechaActualizacion = $ultimaGestion 
    ? date('d/m/Y H:i', strtotime($ultimaGestion)) 
    : 'Sin gestión registrada';

$sqlIndicadores = "
SELECT 
    f.id,
    f.nombre,
    CASE f.estado 
        WHEN 1 THEN 'Activo'
        WHEN 3 THEN 'Finalizado'
        ELSE 'Otro'
    END AS estado,
    DATE(f.fechaInicio) AS fechaInicio,
    DATE(f.fechaTermino) AS fechaTermino,
    e.nombre AS nombre_empresa,

    COUNT(DISTINCT fq.id_usuario) AS usuarios,
    COUNT(DISTINCT r.id) AS regiones,
    COUNT(DISTINCT c.id) AS comunas,

    COUNT(DISTINCT fq.id_local) AS totalLocales,

    COUNT(DISTINCT CASE
        WHEN fq.countVisita > 0
        THEN fq.id_local
    END) AS cantidadVisitados,
    
    COUNT(DISTINCT CASE
        WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','completado')
             AND CAST(NULLIF(fq.valor,'') AS DECIMAL(10,2)) > 0
        THEN fq.id_local
    END) AS cantidadImplementados,
    
    COUNT(DISTINCT CASE
        WHEN fq.countVisita > 0
             AND (
                 CAST(NULLIF(fq.valor,'') AS DECIMAL(10,2)) = 0
                 OR fq.observacion LIKE '%no_permitieron%'
                 OR fq.observacion LIKE '%sin_material%'
                 OR fq.observacion LIKE '%local_cerrado%'
             )
        THEN fq.id_local
    END) AS noEjecutado

FROM formulario f
JOIN empresa e ON e.id = f.id_empresa
JOIN formularioQuestion fq ON fq.id_formulario = f.id
JOIN local l ON l.id = fq.id_local
JOIN comuna c ON c.id = l.id_comuna
JOIN region r ON r.id = c.id_region

WHERE f.id = ?
GROUP BY 
    f.id,
    f.nombre,
    f.estado,
    f.fechaInicio,
    f.fechaTermino,
    e.nombre
";

$stmt = $conn->prepare($sqlIndicadores);
$stmt->bind_param("i", $idCampana);
$stmt->execute();
$result = $stmt->get_result();
$indicadores = $result->fetch_assoc();
$stmt->close();

$total = (int)$indicadores['totalLocales'];
$visitados = (int)$indicadores['cantidadVisitados'];
$implementados = (int)$indicadores['cantidadImplementados'];
$noImplementados = max(0, $visitados - $implementados);

$sqlMotivosNoImplementados = "
SELECT
    SUM(CASE WHEN motivo = 'No permitieron' THEN 1 ELSE 0 END) AS no_permitieron,
    SUM(CASE WHEN motivo = 'Sin material' THEN 1 ELSE 0 END) AS sin_material,
    SUM(CASE WHEN motivo = 'Local cerrado / no existe' THEN 1 ELSE 0 END) AS local_cerrado,
    SUM(CASE WHEN motivo = 'Otros' THEN 1 ELSE 0 END) AS otros
FROM (
    SELECT
        fq.id_local,
        CASE
            WHEN MAX(
                CASE
                    WHEN LOWER(COALESCE(fq.observacion, '')) LIKE '%no_permitieron%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%no permitieron%'
                    THEN 1 ELSE 0
                END
            ) = 1
            THEN 'No permitieron'

            WHEN MAX(
                CASE
                    WHEN LOWER(COALESCE(fq.observacion, '')) LIKE '%sin_material%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin material%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin_producto%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin producto%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin_productos%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin productos%'
                    THEN 1 ELSE 0
                END
            ) = 1
            THEN 'Sin material'

            WHEN MAX(
                CASE
                    WHEN LOWER(COALESCE(fq.observacion, '')) LIKE '%local_cerrado%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%local cerrado%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%local_no_existe%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%local no existe%'
                    THEN 1 ELSE 0
                END
            ) = 1
            THEN 'Local cerrado / no existe'

            ELSE 'Otros'
        END AS motivo
    FROM formularioQuestion fq
    WHERE fq.id_formulario = ?
      AND fq.countVisita > 0
    GROUP BY fq.id_local
    HAVING MAX(
        CASE
            WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','completado')
             AND CAST(NULLIF(TRIM(fq.valor), '') AS DECIMAL(10,2)) > 0
            THEN 1 ELSE 0
        END
    ) = 0
) t
";

$stmtMot = $conn->prepare($sqlMotivosNoImplementados);
$stmtMot->bind_param("i", $idCampana);
$stmtMot->execute();
$resMot = $stmtMot->get_result();
$motivosNoImp = $resMot->fetch_assoc();
$stmtMot->close();

$noPermitieron       = (int)($motivosNoImp['no_permitieron'] ?? 0);
$sinMaterial         = (int)($motivosNoImp['sin_material'] ?? 0);
$localCerrado        = (int)($motivosNoImp['local_cerrado'] ?? 0);
$otrosNoImplementados = (int)($motivosNoImp['otros'] ?? 0);

$noEjecutado = $noImplementados;

$pendientes = $total - $visitados;
$noVisitados = max(0, $total - $visitados);

$visitadoRatio = $total > 0 ? round(($visitados * 100) / $total) : 0;
$implementacionRatio = $total > 0 ? round(($implementados * 100) / $total) : 0;
$pendientesRatio = $total > 0 ? round(($pendientes * 100) / $total) : 0;

$colorVisitado = $visitadoRatio >= 80 ? '#2E7D32' : '#C62828';


// Pendientes por visitar (countVisita = 0)
$sqlPendientes = "
SELECT
  COUNT(DISTINCT CASE WHEN fq.countVisita = 0 THEN fq.id_local END) AS total_pendientes
FROM formularioQuestion fq
WHERE fq.id_formulario = ?
";

$stmtP = $conn->prepare($sqlPendientes);
$stmtP->bind_param('i', $idCampana);
$stmtP->execute();
$resP = $stmtP->get_result();
$p = $resP->fetch_assoc();
$stmtP->close();

$totalPendientes = (int)($p['total_pendientes'] ?? 0);

$enProceso = $totalPendientes;


$sqlRegionImplementacion = "
    SELECT
        region,
        SUM(implementado) AS implementados,
        SUM(no_implementado) AS no_implementados
    FROM (
        SELECT
            COALESCE(NULLIF(TRIM(r.region), ''), 'Sin región') AS region,
            fq.id_local,

            MAX(
                CASE
                    WHEN fq.countVisita > 0 THEN 1
                    ELSE 0
                END
            ) AS visitado,

            MAX(
                CASE
                    WHEN fq.pregunta IN ('implementado_auditado', 'solo_implementado', 'completado')
                     AND CAST(NULLIF(TRIM(fq.valor), '') AS DECIMAL(10,2)) > 0
                    THEN 1
                    ELSE 0
                END
            ) AS implementado,

            CASE
                WHEN MAX(
                        CASE
                            WHEN fq.pregunta IN ('implementado_auditado', 'solo_implementado', 'completado')
                             AND CAST(NULLIF(TRIM(fq.valor), '') AS DECIMAL(10,2)) > 0
                            THEN 1
                            ELSE 0
                        END
                    ) = 1
                THEN 0
                ELSE 1
            END AS no_implementado

        FROM formularioQuestion fq
        LEFT JOIN local l   ON l.id = fq.id_local
        LEFT JOIN comuna co ON co.id = l.id_comuna
        LEFT JOIN region r  ON r.id = co.id_region
        WHERE fq.id_formulario = ?
        GROUP BY COALESCE(NULLIF(TRIM(r.region), ''), 'Sin región'), fq.id_local
    ) t
    WHERE 1=1
    GROUP BY region
    ORDER BY region ASC
";

$stmtRegion = $conn->prepare($sqlRegionImplementacion);
$stmtRegion->bind_param("i", $idCampana);
$stmtRegion->execute();
$resRegion = $stmtRegion->get_result();

$labelsRegion = [];
$dataImplementadosRegion = [];
$dataNoImplementadosRegion = [];

while ($row = $resRegion->fetch_assoc()) {
    $labelsRegion[] = $row['region'];
    $dataImplementadosRegion[] = (int)$row['implementados'];
    $dataNoImplementadosRegion[] = (int)$row['no_implementados'];
}

$stmtRegion->close();

$sqlMatrizLocales = "
SELECT
    t.codigo_local,
    t.nombre_local,
    t.direccion,
    t.comuna,
    t.region,
    t.usuario,
    t.estado_visita,
    t.estado_implementacion,
    t.motivo,
    t.observacion
FROM (
    SELECT
        fq.id_local,
        COALESCE(NULLIF(TRIM(l.codigo), ''), '-') AS codigo_local,
        COALESCE(NULLIF(TRIM(l.nombre), ''), '-') AS nombre_local,
        COALESCE(NULLIF(TRIM(l.direccion), ''), '-') AS direccion,
        COALESCE(NULLIF(TRIM(c.comuna), ''), '-') AS comuna,
        COALESCE(NULLIF(TRIM(r.region), ''), '-') AS region,

        COALESCE(
            NULLIF(
                GROUP_CONCAT(
                    DISTINCT NULLIF(TRIM(u.usuario), '')
                    ORDER BY u.usuario ASC
                    SEPARATOR ' | '
                ),
                ''
            ),
            '-'
        ) AS usuario,

        CASE
            WHEN MAX(CASE WHEN fq.countVisita > 0 THEN 1 ELSE 0 END) = 1
            THEN 'VISITADO'
            ELSE 'NO VISITADO'
        END AS estado_visita,

        CASE
            WHEN MAX(CASE WHEN fq.countVisita > 0 THEN 1 ELSE 0 END) = 0
            THEN 'NO IMPLEMENTADO'
            WHEN MAX(
                CASE
                    WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','completado')
                     AND CAST(NULLIF(TRIM(fq.valor), '') AS DECIMAL(10,2)) > 0
                    THEN 1 ELSE 0
                END
            ) = 1
            THEN 'IMPLEMENTADO'
            ELSE 'NO IMPLEMENTADO'
        END AS estado_implementacion,

        CASE
            WHEN MAX(CASE WHEN fq.countVisita > 0 THEN 1 ELSE 0 END) = 0
            THEN 'Pendiente de visita'

            WHEN MAX(
                CASE
                    WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','completado')
                     AND CAST(NULLIF(TRIM(fq.valor), '') AS DECIMAL(10,2)) > 0
                    THEN 1 ELSE 0
                END
            ) = 1
            THEN 'Implementado correctamente'

            WHEN MAX(
                CASE
                    WHEN LOWER(COALESCE(fq.observacion, '')) LIKE '%no_permitieron%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%no permitieron%'
                    THEN 1 ELSE 0
                END
            ) = 1
            THEN 'No permitieron'

            WHEN MAX(
                CASE
                    WHEN LOWER(COALESCE(fq.observacion, '')) LIKE '%sin_material%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin material%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin_producto%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin producto%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin_productos%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%sin productos%'
                    THEN 1 ELSE 0
                END
            ) = 1
            THEN 'Sin material / sin productos'

            WHEN MAX(
                CASE
                    WHEN LOWER(COALESCE(fq.observacion, '')) LIKE '%local_cerrado%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%local cerrado%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%local_no_existe%'
                      OR LOWER(COALESCE(fq.observacion, '')) LIKE '%local no existe%'
                    THEN 1 ELSE 0
                END
            ) = 1
            THEN 'Local cerrado / no existe'

            ELSE 'Sin motivo registrado'
        END AS motivo,

        COALESCE(
            NULLIF(
                GROUP_CONCAT(
                    DISTINCT NULLIF(
                        TRIM(
                            CASE
                                WHEN LOCATE(' - ', COALESCE(fq.observacion, '')) > 0
                                 AND LOCATE('Foto Mueble:', COALESCE(fq.observacion, '')) > LOCATE(' - ', COALESCE(fq.observacion, ''))
                                THEN TRIM(
                                    REPLACE(
                                        REPLACE(
                                            SUBSTRING(
                                                fq.observacion,
                                                LOCATE(' - ', fq.observacion) + 3,
                                                LOCATE('Foto Mueble:', fq.observacion) - (LOCATE(' - ', fq.observacion) + 3)
                                            ),
                                            '\r', ' '
                                        ),
                                        '\n', ' '
                                    )
                                )
                                ELSE TRIM(fq.observacion)
                            END
                        ),
                        ''
                    )
                    ORDER BY fq.observacion ASC
                    SEPARATOR ' | '
                ),
                ''
            ),
            '-'
        ) AS observacion

    FROM formularioQuestion fq
    INNER JOIN local l   ON l.id = fq.id_local
    INNER JOIN comuna c  ON c.id = l.id_comuna
    INNER JOIN region r  ON r.id = c.id_region
    LEFT JOIN usuario u  ON u.id = fq.id_usuario
    WHERE fq.id_formulario = ?
    GROUP BY
        fq.id_local,
        l.codigo,
        l.nombre,
        l.direccion,
        c.comuna,
        r.region
) t
ORDER BY
    t.region ASC,
    t.comuna ASC,
    t.nombre_local ASC
";

$stmtMatriz = $conn->prepare($sqlMatrizLocales);
$stmtMatriz->bind_param("i", $idCampana);
$stmtMatriz->execute();
$resMatriz = $stmtMatriz->get_result();

$matrizLocales = [];
while ($row = $resMatriz->fetch_assoc()) {
    $matrizLocales[] = $row;
}
$stmtMatriz->close();

if (!function_exists('fixUrlDashboardFoto')) {
    function fixUrlDashboardFoto($url, $base_url) {
        $url = trim((string)$url);
        if ($url === '') return '';

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $prefixes = [
            '/visibility2/app/',
            'visibility2/app/',
            '../app/',
            'app/'
        ];

        foreach ($prefixes as $p) {
            if (strpos($url, $p) === 0) {
                $url = substr($url, strlen($p));
                break;
            }
        }

        return rtrim($base_url, '/') . '/' . ltrim($url, '/');
    }
}

$base_url_fotos = 'https://visibility.cl/visibility2/app/';

$sqlImagenesLocales = "
    SELECT
        l.id AS local_id,
        l.codigo AS local_codigo,
        l.nombre AS local_nombre,
        l.direccion AS local_direccion,
        co.comuna,
        r.region,
        c.nombre AS cadena_nombre,
        fq.material,
        fq.fechaVisita,
        fv.id AS foto_id,
        fv.url
    FROM formularioQuestion fq
    INNER JOIN fotoVisita fv
        ON fv.id_formularioQuestion = fq.id
    INNER JOIN local l
        ON l.id = fq.id_local
    LEFT JOIN comuna co
        ON co.id = l.id_comuna
    LEFT JOIN region r
        ON r.id = co.id_region
    LEFT JOIN cadena c
        ON c.id = l.id_cadena
    WHERE fq.id_formulario = ?
      AND fq.fechaVisita IS NOT NULL
      AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
      AND fv.url IS NOT NULL
      AND TRIM(fv.url) <> ''
    ORDER BY
        r.region ASC,
        co.comuna ASC,
        l.nombre ASC,
        fq.fechaVisita DESC,
        fv.id DESC
";

$stmtImg = $conn->prepare($sqlImagenesLocales);
$stmtImg->bind_param("i", $idCampana);
$stmtImg->execute();
$resImg = $stmtImg->get_result();

$imagenesPorLocal = [];

while ($row = $resImg->fetch_assoc()) {
    $localId = (int)$row['local_id'];

    if (!isset($imagenesPorLocal[$localId])) {
        $imagenesPorLocal[$localId] = [
            'local_id'        => $localId,
            'local_codigo'    => $row['local_codigo'] ?? '-',
            'local_nombre'    => $row['local_nombre'] ?? '-',
            'local_direccion' => $row['local_direccion'] ?? '-',
            'comuna'          => $row['comuna'] ?? '-',
            'region'          => $row['region'] ?? '-',
            'cadena_nombre'   => $row['cadena_nombre'] ?? '-',
            'fecha_visita'    => $row['fechaVisita'] ?? null,
            'fotos'           => []
        ];
    }

    $urlFinal = fixUrlDashboardFoto($row['url'] ?? '', $base_url_fotos);
    if ($urlFinal === '') {
        continue;
    }

    $imagenesPorLocal[$localId]['fotos'][] = [
        'foto_id'  => (int)$row['foto_id'],
        'url'      => $urlFinal,
        'material' => trim((string)($row['material'] ?? ''))
    ];
}

$stmtImg->close();

$imagenesPorLocal = array_values($imagenesPorLocal);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Mentecreativa</title>
<link rel="stylesheet" href="/visibility2/portal/css/dashboard_styles.css">
<link rel="stylesheet" href="/visibility2/portal/css/dashboard_graficos.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">    
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">
<style>

.kpi-horizontal{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(120px, 1fr));
    gap:16px;
    margin-top:20px;
}

@media (max-width: 1200px){
    .kpi-horizontal{
        grid-template-columns:repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 900px){
    .kpi-horizontal{
        grid-template-columns:repeat(2, minmax(0, 1fr));
    }

    .kpi-card{
        padding:16px;
    }

    .kpi-valor{
        font-size:24px;
    }
}

@media (max-width: 576px){
    .kpi-horizontal{
        grid-template-columns:1fr;
    }

    .kpi-card{
        border-radius:18px;
        padding:14px 15px;
        gap:12px;
    }

    .kpi-icon{
        width:46px;
        height:46px;
        min-width:46px;
        font-size:18px;
        border-radius:14px;
    }

    .kpi-titulo{
        font-size:10px;
    }

    .kpi-valor{
        font-size:22px;
    }
}

.matriz{
    background:#fff;
    border-radius:24px;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
    padding:24px;
    margin-top:24px;
    border:1px solid rgba(0,0,0,.04);
}

.matriz-filtros{
    display:grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap:14px;
    margin:18px 0;
}

.matriz-filtro-item{
    display:flex;
    flex-direction:column;
    gap:6px;
}

.matriz-filtro-item label{
    font-size:12px;
    font-weight:700;
    color:#475569;
    margin:0;
}

.matriz-input,
.matriz-select{
    height:42px;
    border:1px solid #dbe2ea;
    border-radius:12px;
    padding:0 14px;
    font-size:13px;
    color:#334155;
    outline:none;
    background:#fff;
}

.matriz-input:focus,
.matriz-select:focus{
    border-color:#94C23D;
    box-shadow:0 0 0 3px rgba(148,194,61,.15);
}

.matriz-tabla-wrap{
    width:100%;
    overflow:auto;
}

.matriz-tabla{
    width:100% !important;
    font-size:13px;
    border-collapse:collapse !important;
}

.matriz-tabla thead th{
    background:#0f2747;
    color:#fff;
    font-size:12px;
    font-weight:700;
    text-transform:uppercase;
    white-space:nowrap;
}

.matriz-tabla tbody td{
    vertical-align:middle;
}

.estado-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:120px;
    padding:7px 12px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.3px;
    text-transform:uppercase;
}

.estado-ok{
    background:rgba(148,194,61,.16);
    color:#5d7d17;
}

.estado-pendiente{
    background:rgba(208,208,208,.35);
    color:#5f6368;
}

.estado-no{
    background:rgba(176,176,176,.22);
    color:#4b5563;
}

/* DataTables */
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_length{
    display:none;
}

.dataTables_wrapper .dataTables_info{
    font-size:12px;
    color:#64748b;
    padding-top:14px;
}

.matriz-toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin:8px 0 14px 0;
    gap:12px;
}

.matriz-toolbar-spacer{
    flex:1;
}

#matrizExportButtons{
    display:flex;
    justify-content:flex-end;
    align-items:center;
}

#matrizExportButtons .dt-buttons{
    display:flex;
    gap:8px;
}

#matrizExportButtons .btn-exportar{
    background:#0f2747 !important;
    border:1px solid #0f2747 !important;
    color:#fff !important;
    border-radius:12px !important;
    padding:8px 14px !important;
    font-size:12px !important;
    font-weight:700 !important;
    box-shadow:0 6px 18px rgba(15,39,71,.12);
}

#matrizExportButtons .btn-exportar:hover{
    background:#16345d !important;
    border-color:#16345d !important;
    color:#fff !important;
}

.dataTables_wrapper .row:last-child{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-top:14px;
}

.dataTables_wrapper .dataTables_paginate{
    padding-top:8px;
}

.dataTables_wrapper .dataTables_paginate .pagination{
    display:flex !important;
    flex-direction:row !important;
    justify-content:flex-end;
    align-items:center;
    gap:8px;
    list-style:none !important;
    padding-left:0 !important;
    margin:0 !important;
}

.dataTables_wrapper .dataTables_paginate .pagination li{
    list-style:none !important;
    margin:0 !important;
}

.dataTables_wrapper .dataTables_paginate .page-link{
    border:none !important;
    background:#f3f6fa !important;
    color:#334155 !important;
    border-radius:10px !important;
    min-width:38px;
    height:38px;
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:700;
    box-shadow:none !important;
}

.dataTables_wrapper .dataTables_paginate .page-item.active .page-link{
    background:#0f2747 !important;
    color:#fff !important;
}

.dataTables_wrapper .dataTables_paginate .page-item.disabled .page-link{
    background:#eef2f7 !important;
    color:#9aa4b2 !important;
}

.dataTables_wrapper .dataTables_paginate .page-link:hover{
    background:#e7edf5 !important;
    color:#0f2747 !important;
}

@media (max-width: 992px){
    .matriz-filtros{
        grid-template-columns:1fr;
    }

    .matriz-toolbar{
        flex-direction:column;
        align-items:stretch;
    }

    #matrizExportButtons{
        justify-content:stretch;
    }

    #matrizExportButtons .dt-buttons{
        width:100%;
    }

    #matrizExportButtons .btn-exportar{
        width:100%;
    }

    .dataTables_wrapper .row:last-child{
        flex-direction:column;
        align-items:flex-start;
    }
}
:root{
    --shadow: 0 12px 35px rgba(15, 39, 71, 0.12), 0 4px 12px rgba(15, 39, 71, 0.06);
}

.module-card {
    background: #ffffff;
    border-radius: 24px;
    box-shadow: var(--shadow);
    padding: 28px;
    border: 1px solid rgba(255, 255, 255, .7);
}

.cabecera-pro{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:24px;
    flex-wrap:wrap;
}

.cabecera-info{
    display:flex;
    align-items:center;
    gap:18px;
    min-width:0;
    flex:1;
}

.cabecera-acciones{
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.btn-export{
    display:inline-flex;
    align-items:center;
    gap:8px;
    border:none;
    border-radius:14px;
    padding:11px 16px;
    font-size:13px;
    font-weight:700;
    cursor:pointer;
    transition:all .2s ease;
    box-shadow:0 10px 24px rgba(15, 39, 71, 0.10);
}

.btn-export i{
    font-size:14px;
}

.btn-export-pdf{
    background:#9b1c1c;
    color:#fff;
}

.btn-export-pdf:hover{
    background:#7f1717;
    transform:translateY(-1px);
}

.btn-export-ppt{
    background:#c75b12;
    color:#fff;
}

.btn-export-ppt:hover{
    background:#a9480f;
    transform:translateY(-1px);
}

@media (max-width: 992px){
    .cabecera-pro{
        align-items:flex-start;
    }

    .cabecera-acciones{
        width:100%;
        justify-content:flex-start;
    }
}

.imagenes{
    background:#fff;
    border-radius:24px;
    box-shadow:var(--shadow);
    padding:24px;
    margin-top:24px;
    border:1px solid rgba(0,0,0,.04);
}

.imagenes-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));
    gap:18px;
    margin-top:18px;
}

.imagen-card{
    background:#fff;
    border:1px solid #edf0f4;
    border-radius:20px;
    padding:16px;
    box-shadow:0 8px 24px rgba(15, 39, 71, 0.06);
}

.imagen-card-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:8px;
}

.imagen-card-codigo{
    font-size:12px;
    font-weight:800;
    color:#0f2747;
    letter-spacing:.4px;
}

.imagen-card-total{
    font-size:11px;
    font-weight:700;
    color:#6b7280;
    background:#f3f6fa;
    border-radius:999px;
    padding:6px 10px;
}

.imagen-card-nombre{
    font-size:15px;
    font-weight:800;
    color:#1f2937;
    margin-bottom:6px;
    line-height:1.25;
}

.imagen-card-meta{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
    font-size:12px;
    color:#64748b;
    margin-bottom:8px;
}

.imagen-card-direccion{
    font-size:12px;
    color:#475569;
    min-height:34px;
    margin-bottom:12px;
}

.imagen-principal-btn,
.imagen-thumb-btn{
    border:none;
    background:transparent;
    padding:0;
    cursor:pointer;
}

.imagen-principal{
    width:100%;
    height:220px;
    object-fit:cover;
    border-radius:16px;
    display:block;
    border:1px solid #edf0f4;
}

.imagen-thumbs{
    display:grid;
    grid-template-columns:repeat(4, 1fr);
    gap:8px;
    margin-top:10px;
}

.imagen-thumb{
    width:100%;
    height:64px;
    object-fit:cover;
    border-radius:10px;
    border:1px solid #edf0f4;
    display:block;
}

.imagen-thumb-more{
    height:64px;
    border-radius:10px;
    background:#0f2747;
    color:#fff;
    font-size:14px;
    font-weight:800;
    display:flex;
    align-items:center;
    justify-content:center;
}

.imagen-card-footer{
    margin-top:12px;
    font-size:12px;
    color:#475569;
    display:grid;
    gap:4px;
}

.imagenes-vacio{
    margin-top:18px;
    padding:22px;
    border-radius:16px;
    background:#f8fafc;
    color:#64748b;
    font-weight:600;
    text-align:center;
}

.modal-imagenes{
    position:fixed;
    inset:0;
    display:none;
    z-index:9999;
}

.modal-imagenes.open{
    display:block;
}

.modal-imagenes-backdrop{
    position:absolute;
    inset:0;
    background:rgba(15, 23, 42, .72);
}

.modal-imagenes-content{
    position:relative;
    z-index:2;
    width:min(1100px, calc(100vw - 32px));
    max-height:calc(100vh - 32px);
    overflow:auto;
    margin:16px auto;
    background:#fff;
    border-radius:24px;
    padding:20px;
    box-shadow:0 24px 60px rgba(0,0,0,.25);
}

.modal-imagenes-close{
    position:absolute;
    top:14px;
    right:16px;
    width:40px;
    height:40px;
    border:none;
    border-radius:50%;
    background:#0f2747;
    color:#fff;
    font-size:24px;
    cursor:pointer;
}

.modal-imagenes-titulo{
    font-size:18px;
    font-weight:800;
    color:#0f2747;
    margin-bottom:16px;
    padding-right:52px;
}

.modal-imagenes-main-wrap{
    display:grid;
    grid-template-columns:56px 1fr 56px;
    gap:12px;
    align-items:center;
}

.modal-imagenes-main{
    background:#f8fafc;
    border-radius:18px;
    padding:12px;
    text-align:center;
}

.modal-imagenes-main img{
    max-width:100%;
    max-height:68vh;
    border-radius:14px;
}

.modal-imagen-material{
    margin-top:10px;
    font-size:13px;
    font-weight:700;
    color:#475569;
}

.modal-nav{
    border:none;
    height:48px;
    border-radius:14px;
    background:#0f2747;
    color:#fff;
    font-size:22px;
    cursor:pointer;
}

.modal-imagenes-thumbs{
    display:grid;
    grid-template-columns:repeat(auto-fit, minmax(90px, 1fr));
    gap:10px;
    margin-top:16px;
}

.modal-imagenes-thumbs button{
    border:none;
    background:transparent;
    padding:0;
    cursor:pointer;
}

.modal-imagenes-thumbs img{
    width:100%;
    height:78px;
    object-fit:cover;
    border-radius:12px;
    border:2px solid transparent;
    display:block;
}

.modal-imagenes-thumbs button.active img{
    border-color:#94C23D;
}

@media (max-width: 768px){
    .modal-imagenes-main-wrap{
        grid-template-columns:1fr;
    }

    .modal-nav{
        width:100%;
    }

    .imagen-principal{
        height:180px;
    }
}
</style>	
</head>
<body>

<div class="container">
    <div class="module-card">
<div id="bloqueSuperiorExport">       
    <!-- CABECERA -->
<div class="cabecera-pro">

    <div class="cabecera-info">
        <div class="cabecera-logo">
            <img src="/visibility2/portal/images/logo/Logo_MENTE CREATIVA-03.png" alt="Logo">
        </div>

        <div class="cabecera-texto">
            <div class="cabecera-titulo-principal">
                <?php echo strtoupper($nombreCampana); ?>
            </div>

            <div class="cabecera-subtitulo">
                DASHBOARD EJECUCIÓN
            </div>

            <div class="cabecera-actualizacion">
                Información actualizada al <?php echo $fechaActualizacion; ?>
            </div>
        </div>
    </div>

    <div class="cabecera-acciones no-export">
        <button type="button" class="btn-export btn-export-pdf" id="btnExportPDF">
            <i class="fas fa-file-pdf"></i>
            Exportar PDF
        </button>

        <button type="button" class="btn-export btn-export-ppt" id="btnExportPPT">
            <i class="fas fa-file-powerpoint"></i>
            Exportar PPT
        </button>
    </div>

</div>

        <!-- KPI -->
<div class="kpi-horizontal">
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-store"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">SALAS PROGRAMADAS</div>
                    <div class="kpi-valor" ><?php echo number_format($total); ?></div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-route"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">SALAS VISITADAS</div>
                    <div class="kpi-valor"><?php echo number_format($visitados); ?></div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">SALAS EJECUTADAS</div>
                    <div class="kpi-valor"><?php echo number_format($implementados); ?></div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">SALAS PENDIENTES</div>
                    <div class="kpi-valor"><?php echo number_format($pendientes); ?></div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">% VISITADAS</div>
                    <div class="kpi-valor" style="color:<?php echo $colorVisitado; ?>"><?php echo $visitadoRatio; ?>%</div>
                </div>
            </div>
        
            <div class="kpi-card">
                <div class="kpi-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="kpi-info">
                    <div class="kpi-titulo">% EJECUTADAS</div>
                    <div class="kpi-valor" style="color:<?php echo $colorVisitado; ?>"><?php echo $implementacionRatio; ?>%</div>
                </div>
            </div>
        
        </div>

<!-- KPI + GRÁFICOS -->
<div class="bloque-superior">

    <div class="graficos">

        <!-- 1 -->
        <div class="grafico">
            <div class="grafico-titulo">
                ESTADO DE LAS SALAS PROGRAMADAS: 
                <strong><?php echo $total; ?></strong>
                <div class="grafico-subtitulo">
                    (Estado del total de locales planificados)
                </div>                
            </div>

            <div class="grafico-layout-vertical">
                <div class="grafico-chart">
                    <canvas id="graficoVisitados"></canvas>
                </div>
                <div class="grafico-leyenda" id="leyendaVisitados"></div>
            </div>
        </div>

        <!-- 2 -->
        <div class="grafico">
            <div class="grafico-titulo">
                ESTADO DE LOS LOCALES PENDIENTES POR VISITAR: 
                <strong><?php echo $totalPendientes; ?></strong>
                <div class="grafico-subtitulo">
                    (Total de locales pendientes por ser visitados)
                </div>                  
            </div>

            <div class="grafico-layout-vertical">
                <div class="grafico-chart">
                    <canvas id="graficoPendientes"></canvas>
                </div>
                <div class="grafico-leyenda" id="leyendaPendientes"></div>
            </div>
        </div>

        <!-- 3 -->
        <div class="grafico">
            <div class="grafico-titulo">
                ESTADO DE LOS LOCALES VISITADOS: 
                <strong><?php echo $visitados; ?></strong>
                <div class="grafico-subtitulo">
                    (Estado del total de locales implementados y no implementados)
                </div>                  
            </div>

            <div class="grafico-layout-vertical">
                <div class="grafico-chart">
                    <canvas id="graficoImplementados"></canvas>
                </div>
                <div class="grafico-leyenda" id="leyendaImplementados"></div>
            </div>
        </div>

        <!-- 4 -->
        <div class="grafico">
            <div class="grafico-titulo">
                ESTADO DE LOS LOCALES QUE NO PUDIERON SER IMPLEMENTADOS: 
                <strong><?php echo $noEjecutado; ?></strong>
                <div class="grafico-subtitulo">
                    (Distribución por motivo del total de locales visitados no implementados)
                </div>
            </div>

            <div class="grafico-layout-vertical">
                <div class="grafico-chart">
                    <canvas id="graficoNoImplementados"></canvas>
                </div>
                <div class="grafico-leyenda" id="leyendaNoImplementados"></div>
            </div>
        </div>

    </div>
</div>

    <!-- MATERIALES -->
<div class="materiales">

		<div class="grafico-titulo">
			ESTADO DE LOCALES VISITADOS POR REGION
		</div>

		<div class="materiales-chart">
			<canvas id="graficoMateriales"></canvas>
		</div>

	</div>

</div> 

<div id="bloqueMatrizExport">
<!-- MATRIZ -->
<div class="matriz">

    <div class="grafico-titulo">
        DETALLE DE LOCALES
        <div class="grafico-subtitulo">
            (Estado consolidado por sala programada)
        </div>
    </div>
    
    <div class="matriz-toolbar">
        <div class="matriz-toolbar-spacer"></div>
        <div id="matrizExportButtons"></div>
    </div>

    <div class="matriz-filtros">
        <div class="matriz-filtro-item">
            <label for="filtroBusqueda">Buscar</label>
            <input type="text" id="filtroBusqueda" class="matriz-input" placeholder="Código, local, dirección, comuna, usuario, observación...">
        </div>

        <div class="matriz-filtro-item">
            <label for="filtroRegion">Región</label>
            <select id="filtroRegion" class="matriz-select">
                <option value="">Todas</option>
            </select>
        </div>

        <div class="matriz-filtro-item">
            <label for="filtroVisita">Estado visita</label>
            <select id="filtroVisita" class="matriz-select">
                <option value="">Todos</option>
                <option value="VISITADO">VISITADO</option>
                <option value="NO VISITADO">NO VISITADO</option>
            </select>
        </div>

        <div class="matriz-filtro-item">
            <label for="filtroImplementacion">Estado implementación</label>
            <select id="filtroImplementacion" class="matriz-select">
                <option value="">Todos</option>
                <option value="IMPLEMENTADO">IMPLEMENTADO</option>
                <option value="NO IMPLEMENTADO">NO IMPLEMENTADO</option>
            </select>
        </div>
    </div>

    <div class="matriz-tabla-wrap">
        <table id="tablaMatrizLocales" class="table table-striped table-bordered matriz-tabla" style="width:100%">
            <thead>
                <tr>
                    <th>Código local</th>
                    <th>Nombre local</th>
                    <th>Dirección</th>
                    <th>Comuna</th>
                    <th>Región</th>
                    <th>Usuario</th>
                    <th>Estado visita</th>
                    <th>Estado implementación</th>
                    <th>Motivo</th>
                    <th>Observación</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($matrizLocales)): ?>
                    <?php foreach ($matrizLocales as $fila): ?>
                        <?php
                            $visitaClass = ($fila['estado_visita'] === 'VISITADO') ? 'estado-ok' : 'estado-pendiente';
                            $implClass   = ($fila['estado_implementacion'] === 'IMPLEMENTADO') ? 'estado-ok' : 'estado-no';
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fila['codigo_local']); ?></td>
                            <td><?php echo htmlspecialchars($fila['nombre_local']); ?></td>
                            <td><?php echo htmlspecialchars($fila['direccion']); ?></td>
                            <td><?php echo htmlspecialchars($fila['comuna']); ?></td>
                            <td><?php echo htmlspecialchars($fila['region']); ?></td>
                            <td><?php echo htmlspecialchars($fila['usuario']); ?></td>
                            <td>
                                <span class="estado-badge <?php echo $visitaClass; ?>">
                                    <?php echo htmlspecialchars($fila['estado_visita']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="estado-badge <?php echo $implClass; ?>">
                                    <?php echo htmlspecialchars($fila['estado_implementacion']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($fila['motivo']); ?></td>
                            <td><?php echo htmlspecialchars($fila['observacion']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</div>

<!-- IMÁGENES -->
<div class="imagenes" id="bloqueImagenesExport">

    <div class="grafico-titulo">
        IMAGENES POR LOCAL
        <div class="grafico-subtitulo">
            (Imágenes registradas en la visita)
        </div>
    </div>

    <?php if (!empty($imagenesPorLocal)): ?>
        <div class="imagenes-grid">
            <?php foreach ($imagenesPorLocal as $index => $local): ?>
                <?php
                    $fotoPrincipal = $local['fotos'][0]['url'] ?? '';
                    $fotosJson = htmlspecialchars(json_encode($local['fotos'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                    $fechaVisitaTxt = !empty($local['fecha_visita']) ? date('d/m/Y H:i', strtotime($local['fecha_visita'])) : '-';
                    $totalFotos = count($local['fotos']);
                ?>
                <div class="imagen-card">
                    <div class="imagen-card-header">
                        <div class="imagen-card-codigo"><?php echo htmlspecialchars($local['local_codigo']); ?></div>
                        <div class="imagen-card-total"><?php echo $totalFotos; ?> foto(s)</div>
                    </div>

                    <div class="imagen-card-nombre">
                        <?php echo htmlspecialchars($local['local_nombre']); ?>
                    </div>

                    <div class="imagen-card-meta">
                        <span><?php echo htmlspecialchars($local['comuna']); ?></span>
                        <span>•</span>
                        <span><?php echo htmlspecialchars($local['region']); ?></span>
                    </div>

                    <div class="imagen-card-direccion">
                        <?php echo htmlspecialchars($local['local_direccion']); ?>
                    </div>

                    <button
                        type="button"
                        class="imagen-principal-btn"
                        onclick="abrirGaleriaLocal(this)"
                        data-local="<?php echo htmlspecialchars($local['local_nombre']); ?>"
                        data-fotos="<?php echo $fotosJson; ?>"
                    >
                        <img
                            src="<?php echo htmlspecialchars($fotoPrincipal); ?>"
                            alt="<?php echo htmlspecialchars($local['local_nombre']); ?>"
                            class="imagen-principal"
                            loading="lazy"
                        >
                    </button>

                    <div class="imagen-thumbs">
                        <?php foreach (array_slice($local['fotos'], 0, 4) as $thumb): ?>
                            <button
                                type="button"
                                class="imagen-thumb-btn"
                                onclick="abrirGaleriaLocal(this)"
                                data-local="<?php echo htmlspecialchars($local['local_nombre']); ?>"
                                data-fotos="<?php echo $fotosJson; ?>"
                            >
                                <img
                                    src="<?php echo htmlspecialchars($thumb['url']); ?>"
                                    alt="Miniatura"
                                    class="imagen-thumb"
                                    loading="lazy"
                                >
                            </button>
                        <?php endforeach; ?>

                        <?php if ($totalFotos > 4): ?>
                            <button
                                type="button"
                                class="imagen-thumb-btn imagen-thumb-more"
                                onclick="abrirGaleriaLocal(this)"
                                data-local="<?php echo htmlspecialchars($local['local_nombre']); ?>"
                                data-fotos="<?php echo $fotosJson; ?>"
                            >
                                +<?php echo ($totalFotos - 4); ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="imagen-card-footer">
                        <div><strong>Cadena:</strong> <?php echo htmlspecialchars($local['cadena_nombre']); ?></div>
                        <div><strong>Fecha visita:</strong> <?php echo htmlspecialchars($fechaVisitaTxt); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="imagenes-vacio">
            No se encontraron imágenes asociadas a esta campaña.
        </div>
    <?php endif; ?>

</div>
</div>
</div>

<div class="modal-imagenes" id="modalImagenesLocal">
    <div class="modal-imagenes-backdrop" onclick="cerrarGaleriaLocal()"></div>

    <div class="modal-imagenes-content">
        <button type="button" class="modal-imagenes-close" onclick="cerrarGaleriaLocal()">
            &times;
        </button>

        <div class="modal-imagenes-titulo" id="modalImagenesTitulo"></div>

        <div class="modal-imagenes-main-wrap">
            <button type="button" class="modal-nav prev" onclick="moverGaleria(-1)">&#10094;</button>

            <div class="modal-imagenes-main">
                <img id="modalImagenActual" src="" alt="Imagen local">
                <div class="modal-imagen-material" id="modalImagenMaterial"></div>
            </div>

            <button type="button" class="modal-nav next" onclick="moverGaleria(1)">&#10095;</button>
        </div>

        <div class="modal-imagenes-thumbs" id="modalImagenesThumbs"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pptxgenjs@3.12.0/dist/pptxgen.bundle.js"></script>


<script>
const EXPORT_ASSETS = {
    portada1: '/visibility2/portal/images/export/portada_1.png',
    portada2: '/visibility2/portal/images/export/portada_2.png',
    marco: '/visibility2/portal/images/export/marco.png'
};
</script>

<script>
async function urlToDataUrl(url) {
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error('No se pudo cargar la imagen: ' + url);
    }

    const blob = await response.blob();

    return await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
}
</script>

<script>
function agregarImagenCompletaEnSlide(slide, dataUrl) {
    slide.addImage({
        data: dataUrl,
        x: 0,
        y: 0,
        w: 13.333,
        h: 7.5
    });
}

function agregarMarcoEnSlide(slide, marcoDataUrl) {
    slide.addImage({
        data: marcoDataUrl,
        x: 0,
        y: 0,
        w: 13.333,
        h: 7.5
    });
}

function agregarCanvasConMarcoEnSlide(pptx, canvas, marcoDataUrl) {
    const slide = pptx.addSlide();
    slide.background = { color: 'F4F6F9' };

    const maxW = 11.9;
    const maxH = 6.2;

    const ratio = canvas.width / canvas.height;

    let w = maxW;
    let h = w / ratio;

    if (h > maxH) {
        h = maxH;
        w = h * ratio;
    }

    const x = (13.333 - w) / 2;
    const y = (7.5 - h) / 2;

    slide.addImage({
        data: canvas.toDataURL('image/png'),
        x: x,
        y: y,
        w: w,
        h: h
    });

    if (marcoDataUrl) {
        agregarMarcoEnSlide(slide, marcoDataUrl);
    }
}
</script>

<script>
function agregarImagenCompletaPDF(pdf, dataUrl) {
    const pageW = 210;
    const pageH = 297;

    const img = new Image();
    img.src = dataUrl;

    return new Promise((resolve) => {
        img.onload = function () {
            const ratio = img.width / img.height;

            let w = pageW;
            let h = w / ratio;

            if (h > pageH) {
                h = pageH;
                w = h * ratio;
            }

            const x = (pageW - w) / 2;
            const y = (pageH - h) / 2;

            pdf.setFillColor(255, 255, 255);
            pdf.rect(0, 0, pageW, pageH, 'F');
            pdf.addImage(dataUrl, 'PNG', x, y, w, h, undefined, 'FAST');
            resolve();
        };
    });
}

function agregarMarcoPDF(pdf, marcoDataUrl) {
    pdf.addImage(marcoDataUrl, 'PNG', 0, 0, 210, 297, undefined, 'FAST');
}
</script>

<script>
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function limpiarNombreArchivo(texto) {
    return (texto || 'dashboard')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9_-]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .toLowerCase();
}

function obtenerElementoExport() {
    return document.getElementById('dashboardExport')
        || document.querySelector('.module-card')
        || document.body;
}

async function capturarElementoPorNodo(elemento, ocultarNoExport = true) {
    if (!elemento) return null;

    return await html2canvas(elemento, {
        scale: 2,
        useCORS: true,
        allowTaint: false,
        backgroundColor: '#f4f6f9',
        windowWidth: elemento.scrollWidth,
        windowHeight: elemento.scrollHeight,
        scrollX: 0,
        scrollY: -window.scrollY,
        onclone: function(clonedDoc) {
            if (!ocultarNoExport) return;
            clonedDoc.querySelectorAll('.no-export').forEach(el => {
                el.style.display = 'none';
            });
        }
    });
}

async function capturarElemento(elementId) {
    const elemento = document.getElementById(elementId);
    return await capturarElementoPorNodo(elemento, true);
}

function agregarCanvasEnSlides(pptx, canvas, overlapPx = 24) {
    const slideWidth = 13.333;
    const slideHeight = 7.5;

    const canvasWidth = canvas.width;
    const canvasHeight = canvas.height;

    const slideHeightPx = Math.floor(canvasWidth * (slideHeight / slideWidth));
    let renderedHeightPx = 0;

    while (renderedHeightPx < canvasHeight) {
        const sliceHeightPx = Math.min(slideHeightPx, canvasHeight - renderedHeightPx);

        const slideCanvas = document.createElement('canvas');
        slideCanvas.width = canvasWidth;
        slideCanvas.height = sliceHeightPx;

        const slideCtx = slideCanvas.getContext('2d');
        slideCtx.drawImage(
            canvas,
            0, renderedHeightPx, canvasWidth, sliceHeightPx,
            0, 0, canvasWidth, sliceHeightPx
        );

        const imgData = slideCanvas.toDataURL('image/png');
        const imgHeightInches = (sliceHeightPx * slideWidth) / canvasWidth;

        const slide = pptx.addSlide();
        slide.background = { color: 'F4F6F9' };
        slide.addImage({
            data: imgData,
            x: 0,
            y: 0,
            w: slideWidth,
            h: imgHeightInches
        });

        if (renderedHeightPx + sliceHeightPx >= canvasHeight) {
            break;
        }

        renderedHeightPx += Math.max(1, sliceHeightPx - overlapPx);
    }
}

function agregarCanvasAjustadoEnUnaSlide(pptx, canvas) {
    const slide = pptx.addSlide();
    slide.background = { color: 'F4F6F9' };

    const maxW = 12.9;
    const maxH = 6.8;

    const ratio = canvas.width / canvas.height;

    let w = maxW;
    let h = w / ratio;

    if (h > maxH) {
        h = maxH;
        w = h * ratio;
    }

    const x = (13.333 - w) / 2;
    const y = (7.5 - h) / 2;

    slide.addImage({
        data: canvas.toDataURL('image/png'),
        x: x,
        y: y,
        w: w,
        h: h
    });
}

async function exportarDashboardPDF() {
    const btn = document.getElementById('btnExportPDF');
    if (!btn) return;

    const textoOriginal = btn.innerHTML;

    try {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando PDF...';

        const [portada1DataUrl, portada2DataUrl, marcoDataUrl] = await Promise.all([
            urlToDataUrl(EXPORT_ASSETS.portada1),
            urlToDataUrl(EXPORT_ASSETS.portada2),
            urlToDataUrl(EXPORT_ASSETS.marco)
        ]);

        const contenedor = obtenerElementoExport();
        const canvas = await capturarElementoPorNodo(contenedor, true);
        if (!canvas) {
            throw new Error('No se pudo capturar el dashboard');
        }

        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');

        // Página 1 portada
        await agregarImagenCompletaPDF(pdf, portada1DataUrl);
        
        pdf.addPage();
        await agregarImagenCompletaPDF(pdf, portada2DataUrl);

        const pageWidthMm = 210;
        const pageHeightMm = 297;

        const canvasWidth = canvas.width;
        const canvasHeight = canvas.height;

        const margenMm = 10;
        const contenidoWidthMm = 190;
        const contenidoHeightMm = 277;

        const pageHeightPx = Math.floor(canvasWidth * (contenidoHeightMm / contenidoWidthMm));
        let renderedHeightPx = 0;

        while (renderedHeightPx < canvasHeight) {
            const sliceHeightPx = Math.min(pageHeightPx, canvasHeight - renderedHeightPx);

            const pageCanvas = document.createElement('canvas');
            pageCanvas.width = canvasWidth;
            pageCanvas.height = sliceHeightPx;

            const pageCtx = pageCanvas.getContext('2d');
            pageCtx.drawImage(
                canvas,
                0, renderedHeightPx, canvasWidth, sliceHeightPx,
                0, 0, canvasWidth, sliceHeightPx
            );

            const imgData = pageCanvas.toDataURL('image/png');
            const imgHeightMm = (sliceHeightPx * contenidoWidthMm) / canvasWidth;

            pdf.addPage();

            // contenido
            pdf.addImage(
                imgData,
                'PNG',
                margenMm,
                margenMm,
                contenidoWidthMm,
                imgHeightMm,
                undefined,
                'FAST'
            );

            // marco encima
            agregarMarcoPDF(pdf, marcoDataUrl);

            renderedHeightPx += sliceHeightPx;
        }

        const nombre = limpiarNombreArchivo('<?= addslashes($nombreCampana ?? "dashboard") ?>');
        pdf.save(nombre + '_dashboard.pdf');

    } catch (error) {
        console.error(error);
        alert('Ocurrió un error al generar el PDF.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
    }
}

async function exportarDashboardPPT() {
    const btn = document.getElementById('btnExportPPT');
    if (!btn) return;

    const textoOriginal = btn.innerHTML;

    let tabla = null;
    let paginaOriginal = 0;

    try {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando PPT...';

        const [portada1DataUrl, portada2DataUrl, marcoDataUrl] = await Promise.all([
            urlToDataUrl(EXPORT_ASSETS.portada1),
            urlToDataUrl(EXPORT_ASSETS.portada2),
            urlToDataUrl(EXPORT_ASSETS.marco)
        ]);

        const pptx = new PptxGenJS();
        pptx.layout = 'LAYOUT_WIDE';
        pptx.author = 'ChatGPT';
        pptx.company = 'Mente Creativa';
        pptx.subject = 'Dashboard Ejecución';
        pptx.title = '<?= addslashes($nombreCampana ?? "Dashboard") ?>';
        pptx.lang = 'es-CL';

        // Slide 1: portada 1
        const slide1 = pptx.addSlide();
        agregarImagenCompletaEnSlide(slide1, portada1DataUrl);

        // Slide 2: portada 2
        const slide2 = pptx.addSlide();
        agregarImagenCompletaEnSlide(slide2, portada2DataUrl);

        // Bloque superior
        const canvasSuperior = await capturarElemento('bloqueSuperiorExport');
        if (canvasSuperior) {
            agregarCanvasConMarcoEnSlide(pptx, canvasSuperior, marcoDataUrl);
        }

        // Matriz página por página
        if ($.fn.DataTable && $.fn.DataTable.isDataTable('#tablaMatrizLocales')) {
            tabla = $('#tablaMatrizLocales').DataTable();
            paginaOriginal = tabla.page.info().page;

            const totalPaginas = tabla.page.info().pages;

            for (let p = 0; p < totalPaginas; p++) {
                tabla.page(p).draw('page');
                await sleep(400);

                const canvasMatriz = await capturarElemento('bloqueMatrizExport');
                if (canvasMatriz) {
                    agregarCanvasConMarcoEnSlide(pptx, canvasMatriz, marcoDataUrl);
                }
            }

            tabla.page(paginaOriginal).draw('page');
            await sleep(150);
        } else {
            const canvasMatriz = await capturarElemento('bloqueMatrizExport');
            if (canvasMatriz) {
                agregarCanvasConMarcoEnSlide(pptx, canvasMatriz, marcoDataUrl);
            }
        }

        const nombre = limpiarNombreArchivo('<?= addslashes($nombreCampana ?? "dashboard") ?>');
        await pptx.writeFile({ fileName: nombre + '_dashboard.pptx' });

    } catch (error) {
        console.error(error);
        alert('Ocurrió un error al generar el PPT.');
    } finally {
        if (tabla) {
            tabla.page(paginaOriginal).draw('page');
        }
        btn.disabled = false;
        btn.innerHTML = textoOriginal;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const btnPDF = document.getElementById('btnExportPDF');
    const btnPPT = document.getElementById('btnExportPPT');

    if (btnPDF) {
        btnPDF.addEventListener('click', exportarDashboardPDF);
    }

    if (btnPPT) {
        btnPPT.addEventListener('click', exportarDashboardPPT);
    }
});
</script>

<!--LEYENDAS-->
<script>
function crearLeyenda(idContenedor, labels, colores) {
    const contenedor = document.getElementById(idContenedor);
    contenedor.innerHTML = '';

    labels.forEach((label, index) => {
        const item = document.createElement('div');
        item.className = 'leyenda-item';

        const dot = document.createElement('span');
        dot.className = 'leyenda-dot';
        dot.style.backgroundColor = colores[index];

        const texto = document.createElement('span');
        texto.textContent = label;

        item.appendChild(dot);
        item.appendChild(texto);
        contenedor.appendChild(item);
    });
}
</script>

<!--VISITADOS-->
<script>
const labelsVisitados = ['Visitados', 'No visitados'];
const coloresVisitados = ['#94C23D', '#D0D0D0'];

const visitados = <?= (int)$visitados ?>;
const noVisitados = <?= (int)$noVisitados ?>;
const totalReal = visitados + noVisitados; // asegura consistencia del donut

const ctxVisitados = document.getElementById('graficoVisitados').getContext('2d');

new Chart(ctxVisitados, {
  type: 'doughnut',
  data: {
    labels: labelsVisitados,
    datasets: [{
      data: [visitados, noVisitados],
      backgroundColor: coloresVisitados,
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '60%',
    plugins: {
      legend: { display: false },
      datalabels: {
        color: '#fff',
        font: { weight: 'bold', size: 13 },
        formatter: (value) => {
          if (!totalReal) return '';
          const porcentaje = Math.round((value * 100) / totalReal);
          return porcentaje + '%';
        }
      },
      tooltip: {
        callbacks: {
          label: (ctx) => {
            const value = ctx.raw ?? 0;
            const porcentaje = totalReal ? Math.round((value * 100) / totalReal) : 0;
            return `${ctx.label}: ${value} (${porcentaje}%)`;
          }
        }
      }
    }
  },
  plugins: [ChartDataLabels]
});

crearLeyenda('leyendaVisitados', labelsVisitados, coloresVisitados);
</script>

<!--NO VISITADOS-->
<script>
const labelsPendientes = ['En proceso'];
const coloresPendientes = ['#D0D0D0'];

const cantPend = <?= (int)$enProceso ?>; // cantidad real (ej: 17)

// Si hay pendientes, el donut debe ser 100%. Si no, 0.
const dataPendPorc = (cantPend > 0) ? [100] : [0];

const ctxPendientes = document.getElementById('graficoPendientes').getContext('2d');

new Chart(ctxPendientes, {
  type: 'doughnut',
  data: {
    labels: labelsPendientes,
    datasets: [{
      data: dataPendPorc,
      backgroundColor: coloresPendientes,
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '60%',
    plugins: {
      legend: { display: false },
      datalabels: {
        color: '#fff',
        font: { weight: 'bold', size: 13 },
        // Si no hay pendientes, no mostramos etiqueta
        formatter: (value) => (cantPend > 0 ? value + '%' : '')
      },
      tooltip: {
        callbacks: {
          label: () => `En proceso: ${cantPend}`
        }
      }
    }
  },
  plugins: [ChartDataLabels]
});

// Leyenda (solo si hay pendientes, si quieres siempre visible quita el if)
if (cantPend > 0) {
  crearLeyenda('leyendaPendientes', labelsPendientes, coloresPendientes);
} else {
  document.getElementById('leyendaPendientes').innerHTML = '<span style="color:#777;">Sin pendientes</span>';
}
</script>

<!--IMPLEMENTADOS-->
<script>
const labelsImplementados = ['Implementado', 'No implementado'];
const coloresImplementados = ['#94C23D', '#D0D0D0'];

const implementados = <?= (int)$implementados ?>;
const noImplementados = <?= (int)$noImplementados ?>;

const totalVisitados = implementados + noImplementados;

// Si no hay visitados, dibujamos un gris vacío
const dataFinal = totalVisitados > 0 
    ? [implementados, noImplementados] 
    : [1];

const labelsFinal = totalVisitados > 0 
    ? labelsImplementados 
    : ['Sin datos'];

const coloresFinal = totalVisitados > 0 
    ? coloresImplementados 
    : ['#E0E0E0'];

const ctx = document.getElementById('graficoImplementados');

if (ctx) {
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: labelsFinal,
      datasets: [{
        data: dataFinal,
        backgroundColor: coloresFinal,
        borderWidth: 0
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '60%',
      plugins: {
        legend: { display: false },
        datalabels: {
          color: '#fff',
          font: { weight: 'bold', size: 13 },
          formatter: (value) => {
            if (!totalVisitados) return '';
            const porcentaje = Math.round((value * 100) / totalVisitados);
            return porcentaje + '%';
          }
        },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              if (!totalVisitados) return 'Sin datos';
              const value = ctx.raw ?? 0;
              const porcentaje = Math.round((value * 100) / totalVisitados);
              return `${ctx.label}: ${value} (${porcentaje}%)`;
            }
          }
        }
      }
    },
    plugins: [ChartDataLabels]
  });

  if (totalVisitados > 0) {
    crearLeyenda('leyendaImplementados', labelsImplementados, coloresImplementados);
  } else {
    document.getElementById('leyendaImplementados').innerHTML = 
      '<span style="color:#777;">Sin locales visitados</span>';
  }
}
</script>

<script>
const labelsNoImplementados = [
  'No permitieron',
  'Sin material',
  'Local cerrado / no existe',
  'Otros'
];

const coloresNoImplementados = [
  '#7E7E7E',
  '#BDBDBD',
  '#9E9E9E',
  '#E0E0E0'
];

const noPermitieron = <?= (int)$noPermitieron ?>;
const sinMaterial = <?= (int)$sinMaterial ?>;
const localCerrado = <?= (int)$localCerrado ?>;
const otrosNoImplementados = <?= (int)$otrosNoImplementados ?>;

const totalNoImplementados = noPermitieron + sinMaterial + localCerrado + otrosNoImplementados;

const canvasNoImplementados = document.getElementById('graficoNoImplementados');

if (canvasNoImplementados) {
    const dataFinalNoImp = totalNoImplementados > 0
        ? [noPermitieron, sinMaterial, localCerrado, otrosNoImplementados]
        : [1];

    const labelsFinalNoImp = totalNoImplementados > 0
        ? labelsNoImplementados
        : ['Sin datos'];

    const coloresFinalNoImp = totalNoImplementados > 0
        ? coloresNoImplementados
        : ['#E0E0E0'];

    new Chart(canvasNoImplementados, {
        type: 'doughnut',
        data: {
            labels: labelsFinalNoImp,
            datasets: [{
                data: dataFinalNoImp,
                backgroundColor: coloresFinalNoImp,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: { display: false },
                datalabels: {
                    color: '#ffffff',
                    font: { weight: 'bold', size: 13 },
                    formatter: (value) => {
                        if (!totalNoImplementados) return '';
                        if (value === 0) return '';
                        const porcentaje = Math.round((value * 100) / totalNoImplementados);
                        return porcentaje + '%';
                    }
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            if (!totalNoImplementados) return 'Sin datos';
                            const value = ctx.raw ?? 0;
                            const porcentaje = Math.round((value * 100) / totalNoImplementados);
                            return `${ctx.label}: ${value} (${porcentaje}%)`;
                        }
                    }
                }
            }
        },
        plugins: [ChartDataLabels]
    });

    if (totalNoImplementados > 0) {
        crearLeyenda(
            'leyendaNoImplementados',
            labelsNoImplementados,
            coloresNoImplementados
        );
    } else {
        document.getElementById('leyendaNoImplementados').innerHTML =
            '<span style="color:#777;">Sin locales no implementados</span>';
    }
}
</script>

<script>
const labelsRegion = <?= json_encode($labelsRegion, JSON_UNESCAPED_UNICODE) ?>;
const dataImplementadosRegion = <?= json_encode($dataImplementadosRegion) ?>;
const dataNoImplementadosRegion = <?= json_encode($dataNoImplementadosRegion) ?>;

const canvasMateriales = document.getElementById('graficoMateriales');

if (canvasMateriales) {
    const hayDatosRegion = labelsRegion.length > 0;

    new Chart(canvasMateriales, {
        type: 'bar',
        data: {
            labels: hayDatosRegion ? labelsRegion : ['Sin datos'],
            datasets: [
                {
                    label: 'Implementados',
                    data: hayDatosRegion ? dataImplementadosRegion : [0],
                    backgroundColor: '#94C23D',
                    borderRadius: 6,
                    barThickness: 26
                },
                {
                    label: 'No implementados',
                    data: hayDatosRegion ? dataNoImplementadosRegion : [0],
                    backgroundColor: '#D0D0D0',
                    borderRadius: 6,
                    barThickness: 26
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        color: '#555',
                        font: {
                            size: 12,
                            weight: '600'
                        }
                    }
                },
                datalabels: {
                    color: '#555',
                    anchor: 'end',
                    align: 'top',
                    offset: 2,
                    font: {
                        weight: 'bold',
                        size: 11
                    },
                    formatter: (value) => value > 0 ? value : ''
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `${context.dataset.label}: ${context.raw}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: false,
                    ticks: {
                        color: '#555',
                        font: {
                            size: 11
                        },
                        maxRotation: 0,
                        minRotation: 0
                    },
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        color: '#555',
                        font: {
                            size: 11
                        }
                    },
                    grid: {
                        color: '#eee'
                    }
                }
            }
        },
        plugins: [ChartDataLabels]
    });
}
</script>

<script>
$(document).ready(function () {
    const tabla = $('#tablaMatrizLocales').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        ordering: true,
        searching: true,
        info: true,
        autoWidth: false,
        responsive: false,
        scrollX: true,
        dom: 'Brtip',
        buttons: [
            {
                extend: 'excelHtml5',
                text: '<i class="fas fa-file-excel mr-1"></i> Exportar',
                className: 'btn-exportar',
                title: 'detalle_locales_dashboard',
                exportOptions: {
                    columns: [0,1,2,3,4,5,6,7,8,9],
                    modifier: {
                        search: 'applied',
                        order: 'applied'
                    }
                }
            }
        ],
        language: {
            decimal: "",
            emptyTable: "No hay datos disponibles en la tabla",
            info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
            infoEmpty: "Mostrando 0 a 0 de 0 registros",
            infoFiltered: "(filtrado de _MAX_ registros totales)",
            thousands: ".",
            lengthMenu: "Mostrar _MENU_ registros",
            loadingRecords: "Cargando...",
            processing: "Procesando...",
            search: "Buscar:",
            zeroRecords: "No se encontraron resultados",
            paginate: {
                first: "Primero",
                last: "Último",
                next: "Siguiente",
                previous: "Anterior"
            },
            aria: {
                sortAscending: ": activar para ordenar ascendente",
                sortDescending: ": activar para ordenar descendente"
            }
        },
        columnDefs: [
            { targets: [0, 3, 4, 6, 7], className: 'text-center' }
        ],
        order: [[4, 'asc'], [3, 'asc'], [1, 'asc']]
    });

    tabla.buttons().container().appendTo('#matrizExportButtons');

    $('#filtroBusqueda').on('keyup input', function () {
        tabla.search(this.value).draw();
    });

    $('#filtroRegion').on('change', function () {
        const valor = $.fn.dataTable.util.escapeRegex($(this).val());
        tabla.column(4).search(valor ? '^' + valor + '$' : '', true, false).draw();
    });

    $('#filtroVisita').on('change', function () {
        const valor = $.fn.dataTable.util.escapeRegex($(this).val());
        tabla.column(6).search(valor ? valor : '', true, false).draw();
    });

    $('#filtroImplementacion').on('change', function () {
        const valor = $.fn.dataTable.util.escapeRegex($(this).val());
        tabla.column(7).search(valor ? valor : '', true, false).draw();
    });

    const regiones = new Set();

    tabla.column(4).data().each(function (value) {
        const limpio = $('<div>').html(value).text().trim();
        if (limpio !== '') {
            regiones.add(limpio);
        }
    });

    Array.from(regiones)
        .sort((a, b) => a.localeCompare(b, 'es'))
        .forEach(function (region) {
            $('#filtroRegion').append(`<option value="${region}">${region}</option>`);
        });
});
</script>

<script>
let galeriaLocalActual = [];
let galeriaIndexActual = 0;

function abrirGaleriaLocal(btn) {
    try {
        const titulo = btn.getAttribute('data-local') || 'Galería';
        const fotos = JSON.parse(btn.getAttribute('data-fotos') || '[]');

        if (!Array.isArray(fotos) || fotos.length === 0) return;

        galeriaLocalActual = fotos;
        galeriaIndexActual = 0;

        document.getElementById('modalImagenesTitulo').textContent = titulo;
        document.getElementById('modalImagenesLocal').classList.add('open');

        renderGaleriaLocal();
    } catch (e) {
        console.error(e);
    }
}

function cerrarGaleriaLocal() {
    document.getElementById('modalImagenesLocal').classList.remove('open');
    galeriaLocalActual = [];
    galeriaIndexActual = 0;
}

function moverGaleria(direccion) {
    if (!galeriaLocalActual.length) return;

    galeriaIndexActual += direccion;

    if (galeriaIndexActual < 0) {
        galeriaIndexActual = galeriaLocalActual.length - 1;
    }

    if (galeriaIndexActual >= galeriaLocalActual.length) {
        galeriaIndexActual = 0;
    }

    renderGaleriaLocal();
}

function irImagenGaleria(index) {
    galeriaIndexActual = index;
    renderGaleriaLocal();
}

function renderGaleriaLocal() {
    if (!galeriaLocalActual.length) return;

    const actual = galeriaLocalActual[galeriaIndexActual];
    const img = document.getElementById('modalImagenActual');
    const material = document.getElementById('modalImagenMaterial');
    const thumbs = document.getElementById('modalImagenesThumbs');

    img.src = actual.url || '';
    material.textContent = actual.material ? ('Material: ' + actual.material) : 'Sin material informado';

    thumbs.innerHTML = galeriaLocalActual.map((foto, i) => `
        <button type="button" class="${i === galeriaIndexActual ? 'active' : ''}" onclick="irImagenGaleria(${i})">
            <img src="${foto.url}" alt="Miniatura ${i + 1}">
        </button>
    `).join('');
}

document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('modalImagenesLocal');
    if (!modal || !modal.classList.contains('open')) return;

    if (e.key === 'Escape') cerrarGaleriaLocal();
    if (e.key === 'ArrowLeft') moverGaleria(-1);
    if (e.key === 'ArrowRight') moverGaleria(1);
});
</script>
</body>
</html>
