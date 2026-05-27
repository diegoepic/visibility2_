<?php
// ui_dashboard.php
// 1) Iniciar sesión y ajustes iniciales
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/_session_guard.php';
require_once $_SERVER['DOCUMENT_ROOT'
] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'
] . '/visibility2/portal/modulos/session_data.php';

$empresa_id  = $_SESSION['empresa_id'] ?? null;
$division_id = $_SESSION['division_id'] ?? null;
$perfilUser  = $_SESSION['perfil_nombre'] ?? null;



// 3) Obtener el nombre de la empresa
$stmt_empresa = $conn->prepare("SELECT nombre FROM empresa WHERE id = ?");
$stmt_empresa->bind_param("i", $empresa_id);
$stmt_empresa->execute();
$stmt_empresa->bind_result($nombre_empresa);
if (!$stmt_empresa->fetch()) {
    $stmt_empresa->close();
    die("Empresa no encontrada o error al obtenerla.");
}
$stmt_empresa->close();

// Determinar si es Mentecreativa
$es_mentecreativa = false;
$nombre_empresa_limpio = mb_strtolower(trim($nombre_empresa), 'UTF-8');
if ($nombre_empresa_limpio === 'mentecreativa') {
    $es_mentecreativa = true;
}



if (isset($_GET['estado'
])) {
    $estado_seleccionado = intval($_GET['estado'
  ]);
} else {
  // Mentecreativa arranca en 'En curso (1)', resto en 'En proceso (2)'
    $estado_seleccionado = $es_mentecreativa ? 1 : 2;
}
// 4) Obtener listado de empresas (si es Mentecreativa) para el filtro
if ($es_mentecreativa) {
    $stmt_all_empresas = $conn->prepare("SELECT id, nombre FROM empresa ORDER BY nombre ASC");
    if ($stmt_all_empresas) {
        $stmt_all_empresas->execute();
        $res_all = $stmt_all_empresas->get_result();
        $empresas_all = $res_all->fetch_all(MYSQLI_ASSOC);
        $stmt_all_empresas->close();
  } else {
        $empresas_all = [];
  }
} else {
  // Si no es Mentecreativa, sólo su empresa
    $empresas_all = [
    ['id' => $empresa_id, 'nombre' => $nombre_empresa
    ]
  ];
}
// 5) Leer filtros GET (empresa y división)
$empresa_seleccionada  = $empresa_id;
if ($es_mentecreativa && isset($_GET['empresa'
])) {
    $empresa_seleccionada = intval($_GET['empresa'
  ]);
}
$division_seleccionada = $division_id;
if (isset($_GET['division'
])) {
    $division_seleccionada = intval($_GET['division'
  ]);
}
// 6) Armar la parte de la cláusula WHERE para filtrar
$filtros_sql = "";
$parametros  = [];
$tipos_param = "";

// Filtrar por empresa
if ($es_mentecreativa) {
    if ($empresa_seleccionada > 0) {
        $filtros_sql .= " AND f.id_empresa = ?";
        $parametros[] = $empresa_seleccionada;
        $tipos_param .= "i";
  }
} else {
    $filtros_sql .= " AND f.id_empresa = ?";
    $parametros[] = $empresa_id;
    $tipos_param .= "i";
}
// Filtrar por división
if ($division_seleccionada > 0) {
    $filtros_sql .= " AND f.id_division = ?";
    $parametros[] = $division_seleccionada;
    $tipos_param .= "i";
}
// Filtrar por estado si es 1 o 3
if ($estado_seleccionado > 0) {
    $filtros_sql   .= " AND f.estado = ?";
    $parametros[]   = $estado_seleccionado;
    $tipos_param   .= "i";
}
// ----------------------------------------------------------------------------
// 7) Consulta para campañas IPT (tipo=3)
// ----------------------------------------------------------------------------
$sql_ipt = "
    SELECT
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.modalidad AS modalidad,
        f.reference_image AS reference_image,

        CASE
            WHEN f.fechaInicio IS NULL OR CAST(f.fechaInicio AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(f.fechaInicio)
        END AS fechaInicio,

        CASE
            WHEN f.fechaTermino IS NULL OR CAST(f.fechaTermino AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
            ELSE DATE(f.fechaTermino)
        END AS fechaTermino,

        e.nombre AS nombre_empresa,
        COUNT(DISTINCT fq.id_local) AS locales_programados,

        COUNT(DISTINCT CASE 
            WHEN fq.fechaVisita IS NOT NULL
                 AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
            THEN CONCAT(l.codigo, CAST(fq.fechaVisita AS CHAR(19)))
        END) AS locales_visitados,

        COUNT(DISTINCT CASE
            WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
            THEN CONCAT(l.codigo, CAST(fq.fechaVisita AS CHAR(19)))
        END) AS locales_implementados,

        ROUND(
            (
                COUNT(DISTINCT CASE
                    WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','en proceso','cancelado','solo_retirado')
                    THEN CONCAT(l.codigo, CAST(fq.fechaVisita AS CHAR(19)))
                END)
                /
                COUNT(DISTINCT fq.id_local)
            ) * 100
        ) AS porcentaje_visitado,

        ROUND(
            (
                COUNT(DISTINCT CASE
                    WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
                    THEN CONCAT(l.codigo, CAST(fq.fechaVisita AS CHAR(19)))
                END)
                /
                COUNT(DISTINCT fq.id_local)
            ) * 100
        ) AS porcentaje_completado,

        f.estado
    FROM formulario f
    INNER JOIN empresa e ON e.id = f.id_empresa
    INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
    INNER JOIN local l ON l.id = fq.id_local
    WHERE f.tipo = 3
      AND fq.id_usuario != 50
      $filtros_sql
    GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre, f.estado
    ORDER BY f.fechaInicio DESC
";
// ----------------------------------------------------------------------------
// 8) Consulta para campañas Programadas (tipo=1)
// ----------------------------------------------------------------------------
$sql_prog = "
SELECT
    f.id AS id_campana,
    f.nombre AS nombre_campana,
    f.modalidad AS modalidad,
    f.reference_image AS reference_image,

    CASE
        WHEN f.fechaInicio IS NULL OR CAST(f.fechaInicio AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
        ELSE DATE(f.fechaInicio)
    END AS fechaInicio,

    CASE
        WHEN f.fechaTermino IS NULL OR CAST(f.fechaTermino AS CHAR(19)) = '0000-00-00 00:00:00' THEN NULL
        ELSE DATE(f.fechaTermino)
    END AS fechaTermino,

    e.nombre AS nombre_empresa,

    CASE
        WHEN f.modalidad = 'solo_auditoria'
            THEN COUNT(fq.id_local)
        ELSE
            COUNT(DISTINCT fq.id_local)
    END AS locales_programados,

    CASE 
        WHEN f.modalidad = 'solo_auditoria'
            THEN COUNT(
                CASE
                    WHEN fq.fechaVisita IS NOT NULL
                         AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
                    THEN fq.id
                END
            )
        ELSE COUNT(DISTINCT
                CASE
                    WHEN fq.fechaVisita IS NOT NULL
                         AND CAST(fq.fechaVisita AS CHAR(19)) <> '0000-00-00 00:00:00'
                    THEN l.codigo
                END
            )
    END AS locales_visitados,

    CASE 
        WHEN f.modalidad = 'solo_auditoria'
            THEN COUNT(
                CASE
                    WHEN fq.pregunta IN ('solo_auditoria','implementado_auditado','solo_retirado')
                    THEN fq.id
                END
            )
        ELSE COUNT(DISTINCT
                CASE
                    WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
                    THEN l.codigo
                END
            )
    END AS locales_implementados,

    CASE
        WHEN f.modalidad = 'solo_auditoria'
            THEN ROUND(
                COUNT(
                    CASE WHEN fq.pregunta IN (
                        'solo_auditoria', 'en proceso','cancelado','solo_retirado','implementado_auditado'
                    )
                    THEN fq.id END
                )
                /
                COUNT(fq.id_local)
                * 100
            )
        ELSE ROUND(
            COUNT(DISTINCT
                CASE WHEN fq.pregunta IN (
                    'implementado_auditado','solo_implementado','solo_auditoria','solo_retirado','en proceso','cancelado'
                )
                THEN l.codigo END
            )
            /
            COUNT(DISTINCT fq.id_local)
            * 100
        )
    END AS porcentaje_visitado,

    CASE
        WHEN f.modalidad = 'solo_auditoria'
            THEN ROUND(
                COUNT(
                    CASE WHEN fq.pregunta IN ('solo_auditoria','implementado_auditado','solo_retirado')
                    THEN fq.id END
                )
                /
                COUNT(fq.id_local)
                * 100
            )
        ELSE ROUND(
            COUNT(DISTINCT 
                CASE WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
                THEN l.codigo END
            )
            /
            COUNT(DISTINCT fq.id_local)
            * 100
        )
    END AS porcentaje_completado,

    f.estado
FROM formulario f
INNER JOIN empresa e ON e.id = f.id_empresa
INNER JOIN formularioQuestion fq ON fq.id_formulario = f.id
INNER JOIN local l ON l.id = fq.id_local
WHERE f.tipo = 1
  $filtros_sql
GROUP BY f.id, f.nombre, f.fechaInicio, f.fechaTermino, e.nombre, f.estado
ORDER BY f.fechaInicio DESC
";
// ----------------------------------------------------------------------------
// 9) Consulta para campañas Complementarias (tipo=2)
// ----------------------------------------------------------------------------
$sql_comp = "
    SELECT
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.estado
    FROM formulario f
    WHERE f.tipo = 2
      {$filtros_sql
}
    ORDER BY f.nombre ASC
";
// 10) Ejecutar consultas
// IPT
$stmt_ipt = $conn->prepare($sql_ipt);
if (!$stmt_ipt) {
    die("Error en consulta IPT: " . htmlspecialchars($conn->error));
}
if (!empty($parametros)) {
    $stmt_ipt->bind_param($tipos_param, ...$parametros);
}
$stmt_ipt->execute();
$result_ipt = $stmt_ipt->get_result();
$stmt_ipt->close();

// Programadas
$stmt_prog = $conn->prepare($sql_prog);
if (!$stmt_prog) {
    die("Error en consulta Programadas: " . htmlspecialchars($conn->error));
}
if (!empty($parametros)) {
    $stmt_prog->bind_param($tipos_param, ...$parametros);
}
$stmt_prog->execute();
$result_prog = $stmt_prog->get_result();
$stmt_prog->close();

// Complementarias
$stmt_comp = $conn->prepare($sql_comp);
if (!$stmt_comp) {
    die("Error en consulta Complementarias: " . htmlspecialchars($conn->error));
}
if (!empty($parametros)) {
    $stmt_comp->bind_param($tipos_param, ...$parametros);
}
$stmt_comp->execute();
$result_comp = $stmt_comp->get_result();
$stmt_comp->close();

// Convertir Complementarias en array PHP
$compCampanas = [];
while ($row = $result_comp->fetch_assoc()) {
    $compCampanas[] = [
        'id_campana'     => (int)$row['id_campana'
    ],
        'nombre_campana' => htmlspecialchars($row['nombre_campana'
    ], ENT_QUOTES, 'UTF-8'),
        'estado'         => htmlspecialchars($row['estado'
    ], ENT_QUOTES, 'UTF-8')
  ];
}
// Detectar si la campaña Estatus de Vehículo (ID 138) es visible para esta empresa
$tiene_estatus_vehiculo = false;
foreach ($compCampanas as $_cc) {
    if ((int)$_cc['id_campana'] === 138) { $tiene_estatus_vehiculo = true; break; }
}
// Obtener divisiones para el filtro
if ($es_mentecreativa && $empresa_seleccionada > 0) {
    $divisiones = obtenerDivisionesPorEmpresa($empresa_seleccionada);
} elseif (!$es_mentecreativa) {
    $divisiones = obtenerDivisionesPorEmpresa($empresa_id);
} else {
    $divisiones = [];
}

function calcularKpisCampanasResumen(array $rows): array
{
    $totalCampanas = count($rows);
    $totalProgramados = 0;
    $totalVisitados = 0;
    $totalEjecutados = 0;

    foreach ($rows as $row) {
        $totalProgramados += (int)($row['locales_programados'] ?? 0);
        $totalVisitados += (int)($row['locales_visitados'] ?? 0);
        $totalEjecutados += (int)($row['locales_implementados'] ?? 0);
    }

    $pctVisitado = $totalProgramados > 0
        ? round(($totalVisitados / $totalProgramados) * 100)
        : 0;

    $pctEjecutado = $totalProgramados > 0
        ? round(($totalEjecutados / $totalProgramados) * 100)
        : 0;

    return [
        'total_campanas' => $totalCampanas,
        'programados'    => $totalProgramados,
        'visitados'      => $totalVisitados,
        'ejecutados'     => $totalEjecutados,
        'pct_visitado'   => $pctVisitado,
        'pct_ejecutado'  => $pctEjecutado,
    ];
}

$campanasProgramadas = [];
if ($result_prog instanceof mysqli_result) {
    while ($row = $result_prog->fetch_assoc()) {
        $campanasProgramadas[] = $row;
    }
}

$rutasPlanificadas = [];
if ($result_ipt instanceof mysqli_result) {
    while ($row = $result_ipt->fetch_assoc()) {
        $rutasPlanificadas[] = $row;
    }
}

$kpiProgramadas = calcularKpisCampanasResumen($campanasProgramadas);
$kpiRutas = calcularKpisCampanasResumen($rutasPlanificadas);

$estadoKpiLabel = ((int)$estado_seleccionado === 3) ? 'Finalizadas' : 'Activas';

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Visibility 2 | Dashboard</title>
  <!-- CSS principales -->
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&amp;display=fallback">
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <link rel="stylesheet" href="dist/css/stylesUI.css">
  <style>

/* ---- CONTENEDOR DE CAMPAÑAS ---- */
.card-body .row {
    display: flex;
    flex-wrap: wrap;
}

/* ---- COLUMNAS DE CARDS (campaign-item) ---- */
.campaign-item,
.campaign-item-ipt,
.col-12.col-sm-6.col-md-4.d-flex.align-items-stretch {
    display: flex;
    flex-direction: column;
    margin-bottom: 20px;
}

/* ---- CARD PRINCIPAL ---- */
.card.card-widget.widget-user {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 420px; /* Altura mínima consistente */
}

/* ---- HEADER DE LA CARD ---- */
.widget-user .widget-user-header {
    position: relative;
    padding: 6px 0px 28px 0px; 
    min-height: 140px; 
    height: 140px;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    overflow: visible;
}

/* ---- TÍTULO DE CAMPAÑA (nombre) ---- */
.widget-user .widget-user-username {
    display: block;
    width: calc(100% - 60px); /* Dejar espacio para botón descarga */
    margin: 20px;
    padding-right: 10px;
    font-size: 80%;
    font-weight: 700;
    line-height: 1.3;
    max-height: 36px; /* Máximo 2 líneas */
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* ---- DESCRIPCIÓN (estado y fechas) ---- */
.widget-user .widget-user-desc {
    margin-top: 4px;
    font-size: 80%;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ---- IMAGEN DE REFERENCIA ---- */
.widget-user .widget-user-image {
    position: absolute;
    top: auto !important;
    bottom: -35px; /* Posición desde abajo del header */
    left: 50%;
    transform: translateX(-50%);
    margin: 0;
    z-index: 10;
}

.widget-user .widget-user-image img,
.widget-user .widget-user-image .reference-img {
    width: 80px !important;
    height: 80px !important;
    min-width: 80px;
    min-height: 80px;
    max-width: 80px;
    max-height: 80px;
    object-fit: cover;
    object-position: center;
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    background-color: #f5f5f5;
}

/* ---- FOOTER DE LA CARD ---- */
.widget-user .card-footer {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding-top: 50px !important; /* Espacio para la imagen que sobresale */
    padding-bottom: 15px;
}

/* ---- INDICADORES (Programados/Visitados/Ejecutados) ---- */
.widget-user .card-footer > .row:first-child {
    margin-bottom: 10px;
}

.widget-user .description-block {
    margin: 5px 0;
    padding: 5px 0;
}

.widget-user .description-header {
    font-size: 80%;
    font-weight: 700;
    margin: 0;
}

.widget-user .description-text {
    font-size: 10px !important;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    display: block;
}

/* ---- BOTONES DE ACCIÓN ---- */
.widget-user .btn-app {
    margin: 3px auto;
    min-width: 70px;
    max-width: 85px;
    padding: 8px 5px;
    font-size: 11px;
}

.widget-user .btn-app i {
    font-size: 16px;
}

/* ---- PORCENTAJES ---- */
.widget-user .inner {
    padding: 8px 5px;
}

.widget-user .inner h3 {
    margin: 0;
}

.widget-user .inner p {
    margin: 5px 0 0 0;
    font-size: 11px !important;
    line-height: 1.2;
}

/* ---- CHECKBOX SELECCIÓN ---- */
.widget-user-header input[type="checkbox"] {
    position: absolute !important;
    top: 10px !important;
    left: 10px !important;
    margin: 0 !important;
    width: 18px;
    height: 18px;
    cursor: pointer;
    z-index: 15;
}

/* ---- BOTÓN DESCARGA EXCEL ---- */
/* Contenedor del botón excel */
/* Solo el contenedor del botón arriba a la derecha */
.widget-user-header .dropdown.dl-compact,
.widget-user-header .download-link {
    position: absolute !important;
    top: 8px !important;
    right: 10px !important;
    z-index: 20;
}

/* Los items del menú deben quedar normales */
.widget-user-header .download-excel-trigger,
.widget-user-header .download-distribucion-trigger {
    position: static !important;
    top: auto !important;
    right: auto !important;
    display: block;
}

/* Ícono excel */
.widget-user-header .dropdown.dl-compact img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

/* Menú */
.widget-user-header .dropdown-menu {
    min-width: 180px;
    margin-top: 6px;
    z-index: 1050;
}

/* Items del menú */
.widget-user-header .dropdown-menu .dropdown-item {
    white-space: nowrap;
    font-size: 80%;
    padding: 0px 12px;
}

/* Este link no debe heredar posicionamiento raro */
.widget-user-header .download-excel-trigger {
    position: static !important;
    display: block;
}

/* ============================================
   RESPONSIVE
   ============================================ */

/* Tablets */
@media (max-width: 991.98px) {
    .widget-user .widget-user-header {
        min-height: 130px;
        height: 130px;
    }
    
    .widget-user .widget-user-username {
        font-size: 13px;
        max-height: 34px;
    }
    
    .widget-user .widget-user-image img,
    .widget-user .widget-user-image .reference-img {
        width: 70px !important;
        height: 70px !important;
        min-width: 70px;
        min-height: 70px;
        max-width: 70px;
        max-height: 70px;
    }
    
    .widget-user .card-footer {
        padding-top: 45px !important;
    }
    
    .card.card-widget.widget-user {
        min-height: 400px;
    }
}

/* Móviles */
@media (max-width: 767.98px) {
    .campaign-item,
    .campaign-item-ipt,
    .col-12.col-sm-6.col-md-4.d-flex.align-items-stretch {
        max-width: 100%;
        flex: 0 0 100%;
    }
    
    .widget-user .widget-user-header {
        min-height: 120px;
        height: 120px;
        padding: 12px 15px 45px 15px;
    }
    
    .widget-user .widget-user-username {
        font-size: 14px;
        width: calc(100% - 50px);
        max-height: 40px;
    }
    
    .widget-user .widget-user-desc {
        font-size: 80%;
    }
    
    .widget-user .widget-user-image {
        bottom: -30px;
    }
    
    .widget-user .widget-user-image img,
    .widget-user .widget-user-image .reference-img {
        width: 65px !important;
        height: 65px !important;
        min-width: 65px;
        min-height: 65px;
        max-width: 65px;
        max-height: 65px;
    }
    
    .widget-user .card-footer {
        padding-top: 40px !important;
    }
    
    .widget-user .btn-app {
        min-width: 60px;
        padding: 6px 4px;
        font-size: 10px;
    }
    
    .widget-user .btn-app i {
        font-size: 14px;
    }
    
    .card.card-widget.widget-user {
        min-height: 380px;
    }
    
    .widget-user-header input[type="checkbox"] {
        width: 16px;
        height: 16px;
    }
}

/* Móviles pequeños */
@media (max-width: 575.98px) {
    .widget-user .widget-user-header {
        min-height: 115px;
        height: auto;
        min-height: 115px;
    }
    
    .widget-user .description-header {
        font-size: 1rem;
    }
    
    .widget-user .inner p {
        font-size: 10px !important;
    }
    
    .widget-user .inner h3 b {
        font-size: 16px !important;
    }
}

/* ============================================
   ACTIVIDADES COMPLEMENTARIAS
   (Cards más simples sin porcentajes)
   ============================================ */
.card-body .row > .col-12.col-sm-6.col-md-4:not(.campaign-item):not(.campaign-item-ipt) .widget-user {
    min-height: 320px;
}

/* ============================================
   UTILIDADES ADICIONALES
   ============================================ */

/* Efecto hover suave */
.card.card-widget.widget-user {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card.card-widget.widget-user:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

/* Imagen zoom */
.widget-user-image img.zoom {
    cursor: pointer;
    transition: transform 0.2s ease;
}



/* Fix para d-flex align-items-stretch en Bootstrap */
.row.d-flex > [class*="col-"] {
    display: flex;
}

/* Asegurar que todas las cards en una fila tengan misma altura */
.card-body > .container > .row {
    align-items: stretch;
}

.card-body > .container > .row > [class*="col-"] {
    display: flex;
    flex-direction: column;
}

.card-body > .container > .row > [class*="col-"] > .card {
    flex: 1;
}
.widget-user .widget-user-image { pointer-events: none; }
.widget-user .widget-user-image img { pointer-events: auto; }

.mr-2{
    font-size:80%;
}
.t2{
    height:10%;
}
.t1{
    font-size:80%
}
.font-weight-bold {
    font-size: 80%;
}
.custom-control-label{
    font-size: 80%;    
}

.download-overlay{
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  backdrop-filter: blur(2px);
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.download-overlay.d-none{
  display: none !important;
}

.download-overlay__box{
  width: 100%;
  max-width: 380px;
  background: #ffffff;
  border-radius: 18px;
  padding: 28px 24px;
  box-shadow: 0 18px 45px rgba(0,0,0,.18);
  text-align: center;
}

.download-overlay__spinner{
  width: 52px;
  height: 52px;
  margin: 0 auto 16px auto;
  border: 4px solid #dbeafe;
  border-top: 4px solid #16a34a;
  border-radius: 50%;
  animation: giroOverlay 0.9s linear infinite;
}

.download-overlay__title{
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 8px;
}

.download-overlay__text{
  font-size: 14px;
  color: #475569;
  line-height: 1.45;
}

@keyframes giroOverlay{
  0%{ transform: rotate(0deg); }
  100%{ transform: rotate(360deg); }
}

/* =========================================================
   VISUAL MODERNA PARA WIDGETS SUPERIORES
   Mantiene lógica AdminLTE: expandir / contraer
========================================================= */

.modern-module-card {
    border: 0 !important;
    border-radius: 26px !important;
    background: rgba(255, 255, 255, 0.58) !important;
    box-shadow:
        0 20px 50px rgba(70, 95, 140, .10),
        inset 0 1px 0 rgba(255,255,255,.72) !important;
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    overflow: hidden;
    margin-bottom: 22px;
}

.modern-module-card > .card-header {
    border: 0 !important;
    background: transparent !important;
    padding: 22px 26px !important;
}

.modern-widget-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
}

.modern-widget-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 260px;
}

.modern-widget-icon {
    width: 52px;
    height: 52px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        linear-gradient(145deg, rgba(255,255,255,.95), rgba(226,239,255,.88));
    color: #4f86ff;
    font-size: 20px;
    box-shadow:
        0 12px 26px rgba(70, 110, 180, .13),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.modern-widget-title {
    margin: 0;
    font-size: 22px;
    line-height: 1.1;
    font-weight: 900;
    color: #15315d;
    letter-spacing: .2px;
}

.modern-widget-subtitle {
    margin: 5px 0 0 0;
    font-size: 13px;
    color: #7a8ba7;
    font-weight: 600;
}

.modern-widget-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.modern-search-box {
    height: 44px;
    min-width: 270px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 14px;
    border-radius: 15px;
    border: 1px solid rgba(185, 202, 230, .72);
    background: rgba(255,255,255,.82);
    box-shadow:
        0 10px 22px rgba(70, 95, 140, .07),
        inset 0 1px 0 rgba(255,255,255,.8);
}

.modern-search-box i {
    color: #5d79a8;
    font-size: 14px;
}

.modern-search-box input {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    color: #28446f;
    font-size: 13px;
    font-weight: 600;
}

.modern-check-label {
    height: 44px;
    margin: 0;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    color: #20395f;
    font-size: 13px;
    font-weight: 750;
    cursor: pointer;
    white-space: nowrap;
}

.modern-check-label input {
    width: 17px;
    height: 17px;
    accent-color: #5e8cff;
}

.modern-download-btn {
    height: 44px;
    border: 0;
    border-radius: 15px;
    padding: 0 18px;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    background: linear-gradient(135deg, #75a5ff, #4d7eff);
    color: #fff !important;
    font-size: 13px;
    font-weight: 850;
    box-shadow:
        0 13px 26px rgba(77, 126, 255, .30),
        inset 0 1px 0 rgba(255,255,255,.35);
    transition: transform .18s ease, box-shadow .18s ease;
}

.modern-download-btn:hover {
    transform: translateY(-2px);
    box-shadow:
        0 17px 32px rgba(77, 126, 255, .38),
        inset 0 1px 0 rgba(255,255,255,.35);
}

.modern-collapse-btn {
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 50%;
    background: rgba(255,255,255,.76);
    color: #6a7d99;
    box-shadow:
        0 10px 22px rgba(70, 95, 140, .09),
        inset 0 1px 0 rgba(255,255,255,.8);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .18s ease;
}

.modern-collapse-btn:hover {
    color: #315f9d;
    transform: translateY(-2px);
    background: rgba(255,255,255,.95);
}

.modern-module-card > .card-body {
    background: transparent !important;
    padding-top: 10px;
}

/* Filtro superior División / Estado */
.modern-filter-shell {
    margin: 18px auto 18px auto;
    padding: 16px 22px;
    border-radius: 22px;
    background: rgba(255,255,255,.55);
    border: 1px solid rgba(255,255,255,.7);
    box-shadow:
        0 16px 38px rgba(70, 95, 140, .08),
        inset 0 1px 0 rgba(255,255,255,.72);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
}

.modern-filter-form {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 18px;
    flex-wrap: wrap;
    margin: 0;
}

.modern-filter-field {
    display: flex;
    align-items: center;
    gap: 9px;
}

.modern-filter-field label {
    margin: 0;
    font-size: 13px;
    font-weight: 800;
    color: #213c66;
}

.modern-filter-field select {
    height: 42px;
    min-width: 170px;
    border-radius: 14px;
    border: 1px solid rgba(185, 202, 230, .78);
    background: rgba(255,255,255,.86);
    color: #28446f;
    padding: 0 14px;
    font-size: 13px;
    font-weight: 650;
    box-shadow:
        0 8px 18px rgba(70, 95, 140, .06),
        inset 0 1px 0 rgba(255,255,255,.8);
}

@media (max-width: 992px) {
    .modern-widget-header {
        align-items: flex-start;
    }

    .modern-widget-actions {
        width: 100%;
    }

    .modern-search-box {
        flex: 1;
        min-width: 220px;
    }
}

@media (max-width: 576px) {
    .modern-module-card > .card-header {
        padding: 18px !important;
    }

    .modern-widget-left {
        min-width: 100%;
    }

    .modern-widget-title {
        font-size: 19px;
    }

    .modern-search-box,
    .modern-download-btn,
    .modern-check-label {
        width: 100%;
        justify-content: center;
    }

    .modern-filter-field {
        width: 100%;
        flex-direction: column;
        align-items: flex-start;
    }

    .modern-filter-field select {
        width: 100%;
    }
}

/* =========================================================
   CARD MODERNA PARA CAMPAÑAS / RUTAS
========================================================= */

/* =========================================================
   CARD MODERNA PARA CAMPAÑAS PLANIFICADAS
========================================================= */

.modern-plan-card {
    position: relative;
    width: 100%;
    min-height: 430px;
    border: 0 !important;
    border-radius: 28px !important;
    overflow: hidden;
    background: rgba(255, 255, 255, .78);
    box-shadow:
        0 24px 50px rgba(70, 95, 140, .12),
        inset 0 1px 0 rgba(255,255,255,.78);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    transition: transform .20s ease, box-shadow .20s ease;
}

.modern-plan-card:hover {
    transform: translateY(-4px);
    box-shadow:
        0 30px 60px rgba(70, 95, 140, .17),
        inset 0 1px 0 rgba(255,255,255,.82);
}

.modern-plan-top {
    position: relative;
    min-height: 175px;
    padding: 20px 18px 72px 18px;
    background:
        radial-gradient(circle at 50% 0%, rgba(255,255,255,.30), rgba(255,255,255,0) 38%),
        linear-gradient(135deg, #25b6c9 0%, #249fc7 45%, #4b82ff 100%);
    color: #fff;
    text-align: center;
}

.modern-plan-top::after {
    content: "";
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at 20% 30%, rgba(255,255,255,.15), rgba(255,255,255,0) 34%),
        radial-gradient(circle at 80% 30%, rgba(255,255,255,.10), rgba(255,255,255,0) 36%);
    pointer-events: none;
}

.modern-plan-check {
    position: absolute;
    top: 15px;
    left: 15px;
    z-index: 5;
}

.modern-plan-check input {
    width: 19px;
    height: 19px;
    cursor: pointer;
    accent-color: #ffffff;
}

.modern-plan-tools {
    position: absolute;
    top: 13px;
    right: 13px;
    z-index: 20;
}

.modern-plan-excel-btn {
    width: 46px;
    height: 40px;
    border: 0;
    border-radius: 13px;
    background: rgba(35, 170, 75, .96);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow:
        0 10px 20px rgba(20, 100, 38, .24),
        inset 0 1px 0 rgba(255,255,255,.24);
}

.modern-plan-excel-btn:hover {
    background: rgba(29, 150, 65, 1);
    color: #fff;
}

.modern-plan-title {
    position: relative;
    z-index: 2;
    margin: 14px auto 10px auto;
    max-width: calc(100% - 76px);
    min-height: 46px;
    font-size: 15px;
    line-height: 1.28;
    font-weight: 900;
    letter-spacing: .25px;
    text-transform: uppercase;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-shadow: 0 1px 10px rgba(0,0,0,.12);
}

.modern-plan-status {
    position: relative;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 6px 14px;
    border-radius: 999px;
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.26);
    font-size: 11px;
    font-weight: 850;
    letter-spacing: .75px;
    text-transform: uppercase;
    margin-bottom: 9px;
}

.modern-plan-dates {
    position: relative;
    z-index: 2;
    font-size: 12px;
    font-weight: 700;
    opacity: .96;
}

.modern-plan-avatar {
    position: absolute;
    left: 50%;
    bottom: -42px;
    transform: translateX(-50%);
    width: 92px;
    height: 92px;
    border-radius: 50%;
    overflow: hidden;
    background: #fff;
    border: 5px solid rgba(255,255,255,.96);
    box-shadow:
        0 15px 35px rgba(40, 70, 120, .22),
        inset 0 1px 0 rgba(255,255,255,.85);
    z-index: 10;
}

.modern-plan-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    cursor: pointer;
}

.modern-plan-body {
    padding: 60px 18px 20px 18px;
}

.modern-plan-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin-bottom: 18px;
}

.modern-stat-box {
    background: rgba(247, 250, 255, .92);
    border: 1px solid rgba(220,230,245,.95);
    border-radius: 18px;
    padding: 13px 7px;
    text-align: center;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.82),
        0 10px 22px rgba(70, 95, 140, .05);
}

.modern-stat-number {
    font-size: 22px;
    font-weight: 950;
    color: #16325c;
    line-height: 1;
    margin-bottom: 6px;
}

.modern-stat-label {
    font-size: 10px;
    font-weight: 850;
    color: #7c8da8;
    text-transform: uppercase;
    letter-spacing: .65px;
    line-height: 1.2;
}

.modern-plan-actions {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}

.modern-action-btn {
    min-height: 70px;
    border-radius: 18px;
    border: 1px solid rgba(215,225,240,.95);
    background: rgba(255,255,255,.88);
    color: #304a73 !important;
    text-decoration: none !important;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 7px;
    box-shadow:
        0 10px 24px rgba(70, 95, 140, .07),
        inset 0 1px 0 rgba(255,255,255,.80);
    transition: all .18s ease;
}

.modern-action-btn i {
    font-size: 18px;
    color: #557fcb;
}

.modern-action-btn span {
    font-size: 10px;
    font-weight: 850;
    text-transform: uppercase;
    letter-spacing: .5px;
}

.modern-action-btn:hover {
    transform: translateY(-2px);
    background: rgba(245,249,255,.98);
    box-shadow:
        0 14px 28px rgba(70, 95, 140, .12),
        inset 0 1px 0 rgba(255,255,255,.84);
}

.modern-plan-footer {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.modern-kpi-box {
    background: linear-gradient(180deg, rgba(248,252,255,.98), rgba(239,246,255,.94));
    border: 1px solid rgba(220,230,245,.95);
    border-radius: 18px;
    padding: 14px 10px;
    text-align: center;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.82),
        0 10px 22px rgba(70, 95, 140, .05);
}

.modern-kpi-value {
    font-size: 21px;
    font-weight: 950;
    color: #79b700;
    line-height: 1;
    margin-bottom: 7px;
}

.modern-kpi-label {
    font-size: 10px;
    font-weight: 850;
    color: #6f819e;
    text-transform: uppercase;
    line-height: 1.25;
    letter-spacing: .45px;
}

.modern-plan-progress {
    position: absolute;
    top: 62px;
    right: 14px;
    width: 120px;
    display: none;
    background: rgba(255,255,255,.35);
    border-radius: 999px;
    overflow: hidden;
    z-index: 30;
}

.modern-plan-card .dropdown-menu {
    border: 0;
    border-radius: 16px;
    box-shadow: 0 18px 38px rgba(30, 60, 110, .18);
    padding: 8px;
}

.modern-plan-card .dropdown-item {
    border-radius: 11px;
    font-size: 12px;
    font-weight: 800;
    padding: 9px 12px;
}

.modern-plan-card .dropdown-item:hover {
    background: #eef5ff;
    color: #194675;
}

@media (max-width: 576px) {
    .modern-plan-title {
        font-size: 13px;
    }

    .modern-stat-number,
    .modern-kpi-value {
        font-size: 19px;
    }

    .modern-plan-actions {
        gap: 8px;
    }

    .modern-action-btn {
        min-height: 64px;
    }
}

/* =========================================================
   MODAL MODERNO - IMAGEN DE REFERENCIA
========================================================= */

.modern-ref-modal .modal-backdrop {
    backdrop-filter: blur(10px);
}

.modern-ref-dialog {
    max-width: min(720px, 94vw);
}

.modern-ref-content {
    border: 0;
    border-radius: 28px;
    overflow: hidden;
    background:
        linear-gradient(180deg, rgba(255,255,255,.98), rgba(245,249,255,.96));
    box-shadow:
        0 30px 90px rgba(20, 45, 90, .28),
        inset 0 1px 0 rgba(255,255,255,.82);
}

.modern-ref-header {
    padding: 20px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(205, 218, 238, .75);
    background:
        radial-gradient(circle at 20% 0%, rgba(95, 160, 255, .12), transparent 35%),
        rgba(255,255,255,.84);
}

.modern-ref-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.modern-ref-icon {
    width: 48px;
    height: 48px;
    border-radius: 17px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d83ff;
    font-size: 18px;
    box-shadow:
        0 12px 24px rgba(70, 110, 180, .12),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.modern-ref-title {
    margin: 0;
    font-size: 19px;
    font-weight: 900;
    color: #15315d;
    line-height: 1.1;
}

.modern-ref-subtitle {
    margin: 5px 0 0 0;
    font-size: 12px;
    font-weight: 600;
    color: #7b8ca7;
}

.modern-ref-close {
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 50%;
    background: rgba(240,246,255,.95);
    color: #536782;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    box-shadow:
        0 10px 22px rgba(70, 95, 140, .08),
        inset 0 1px 0 rgba(255,255,255,.9);
    transition: all .18s ease;
}

.modern-ref-close:hover {
    background: #e8f1ff;
    color: #1d4f91;
    transform: scale(1.05);
}

.modern-ref-body {
    padding: 22px;
    text-align: center;
}

.modern-ref-image-frame {
    width: 100%;
    max-height: 68vh;
    min-height: 340px;
    border-radius: 24px;
    overflow: hidden;
    background:
        linear-gradient(135deg, rgba(248,251,255,.98), rgba(238,245,255,.96));
    border: 1px solid rgba(210, 225, 245, .85);
    box-shadow:
        0 18px 45px rgba(50, 80, 130, .12),
        inset 0 1px 0 rgba(255,255,255,.82);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 14px;
}

.modern-ref-image-frame img {
    max-width: 100%;
    max-height: calc(68vh - 28px);
    object-fit: contain;
    border-radius: 18px;
    display: block;
    box-shadow: 0 12px 30px rgba(30, 55, 100, .14);
}

.modern-ref-change-btn {
    margin-top: 18px;
    min-height: 46px;
    border: 0;
    border-radius: 15px;
    padding: 0 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: linear-gradient(135deg, #75a5ff, #4d7eff);
    color: #fff;
    font-size: 13px;
    font-weight: 850;
    box-shadow:
        0 14px 28px rgba(77, 126, 255, .30),
        inset 0 1px 0 rgba(255,255,255,.35);
    transition: transform .18s ease, box-shadow .18s ease;
}

.modern-ref-change-btn:hover {
    transform: translateY(-2px);
    box-shadow:
        0 18px 34px rgba(77, 126, 255, .38),
        inset 0 1px 0 rgba(255,255,255,.35);
}

.modern-ref-upload-form {
    margin-top: 18px;
    padding: 16px;
    border-radius: 20px;
    background: rgba(247,250,255,.92);
    border: 1px solid rgba(215,225,240,.95);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
}

.modern-ref-file-box {
    min-height: 88px;
    border-radius: 18px;
    border: 1px dashed rgba(90, 130, 190, .55);
    background: rgba(255,255,255,.72);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: #365d92;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    margin: 0 0 14px 0;
    transition: all .18s ease;
}

.modern-ref-file-box:hover {
    background: rgba(240,247,255,.96);
    border-color: rgba(77, 126, 255, .70);
}

.modern-ref-file-box i {
    font-size: 24px;
    color: #5d8dff;
}

.modern-ref-file-box input {
    display: none;
}

.modern-ref-save-btn {
    width: 100%;
    height: 46px;
    border: 0;
    border-radius: 15px;
    background: linear-gradient(135deg, #50c878, #22a85a);
    color: #fff;
    font-size: 13px;
    font-weight: 850;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow:
        0 14px 28px rgba(34, 168, 90, .24),
        inset 0 1px 0 rgba(255,255,255,.35);
    transition: transform .18s ease, box-shadow .18s ease;
}

.modern-ref-save-btn:hover {
    transform: translateY(-2px);
    box-shadow:
        0 18px 34px rgba(34, 168, 90, .32),
        inset 0 1px 0 rgba(255,255,255,.35);
}

@media (max-width: 576px) {
    .modern-ref-dialog {
        max-width: 96vw;
    }

    .modern-ref-header {
        padding: 16px;
    }

    .modern-ref-body {
        padding: 16px;
    }

    .modern-ref-image-frame {
        min-height: 260px;
        max-height: 62vh;
    }

    .modern-ref-image-frame img {
        max-height: calc(62vh - 28px);
    }

    .modern-ref-title {
        font-size: 16px;
    }
}
/* =========================================================
   EMPTY STATE MODERNO
========================================================= */

.modern-empty-state {
    width: 100%;
    min-height: 130px;
    border-radius: 24px;
    background:
        radial-gradient(circle at 12% 20%, rgba(95, 160, 255, .10), transparent 34%),
        linear-gradient(180deg, rgba(255,255,255,.86), rgba(245,249,255,.78));
    border: 1px solid rgba(215, 228, 246, .95);
    box-shadow:
        0 20px 45px rgba(70, 95, 140, .10),
        inset 0 1px 0 rgba(255,255,255,.86);
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 24px 28px;
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
}

.modern-empty-icon {
    width: 58px;
    height: 58px;
    min-width: 58px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d83ff;
    font-size: 22px;
    box-shadow:
        0 12px 24px rgba(70, 110, 180, .12),
        inset 0 1px 0 rgba(255,255,255,.9);
}

.modern-empty-content h4 {
    margin: 0;
    font-size: 18px;
    font-weight: 900;
    color: #15315d;
    line-height: 1.2;
}

.modern-empty-content p {
    margin: 6px 0 0 0;
    font-size: 13px;
    font-weight: 600;
    color: #7a8ba7;
}

@media (max-width: 576px) {
    .modern-empty-state {
        flex-direction: column;
        text-align: center;
        padding: 24px 18px;
    }
}

/* =========================================================
   VARIANTE MODERNA PARA RUTAS PLANIFICADAS / IPT
========================================================= */

.modern-route-card .modern-plan-top {
    background:
        radial-gradient(circle at 50% 0%, rgba(255,255,255,.30), rgba(255,255,255,0) 38%),
        linear-gradient(135deg, #22b8b6 0%, #1f9fc9 45%, #4b82ff 100%);
}

.modern-route-card .modern-action-btn i {
    color: #287dbd;
}

.modern-route-card .modern-plan-status {
    background: rgba(255,255,255,.18);
    border-color: rgba(255,255,255,.28);
}

.modern-route-card .modern-kpi-value {
    color: #74b816;
}

/* =========================================================
   VARIANTE MODERNA PARA ACTIVIDADES COMPLEMENTARIAS
========================================================= */

.modern-complementary-card .modern-plan-top {
    background:
        radial-gradient(circle at 50% 0%, rgba(255,255,255,.30), rgba(255,255,255,0) 38%),
        linear-gradient(135deg, #6bc6a4 0%, #37b6bd 45%, #4b82ff 100%);
}

.modern-complementary-card .modern-plan-status {
    background: rgba(255,255,255,.18);
    border-color: rgba(255,255,255,.28);
}

.modern-complementary-card .modern-action-btn i {
    color: #2d8fbf;
}

.modern-complementary-stats {
    grid-template-columns: repeat(2, 1fr);
}

.modern-complementary-actions {
    grid-template-columns: repeat(2, 1fr);
}

.modern-complementary-card .modern-plan-avatar {
    background: #ffffff;
}

.modern-complementary-card .modern-plan-avatar img {
    object-fit: contain;
    padding: 8px;
}

/* =========================================================
   MODAL MODERNO PARA INFORMES
========================================================= */

.modern-report-dialog {
    max-width: 95vw !important;
    width: 95vw !important;
    height: 95vh;
    margin: 2.5vh auto;
}

.modern-report-content {
    height: 95vh;
    border: 0;
    border-radius: 26px;
    overflow: hidden;
    background: rgba(255,255,255,.98);
    box-shadow:
        0 34px 95px rgba(20, 45, 90, .30),
        inset 0 1px 0 rgba(255,255,255,.82);
}

.modern-report-header {
    height: 64px;
    min-height: 64px;
    padding: 10px 16px 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background:
        radial-gradient(circle at 12% 0%, rgba(95, 160, 255, .14), transparent 36%),
        rgba(248, 251, 255, .96);
    border-bottom: 1px solid rgba(205, 218, 238, .78);
}

.modern-report-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.modern-report-icon {
    width: 42px;
    height: 42px;
    min-width: 42px;
    border-radius: 15px;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d83ff;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow:
        0 10px 22px rgba(70, 110, 180, .12),
        inset 0 1px 0 rgba(255,255,255,.88);
}

.modern-report-title {
    margin: 0;
    max-width: 70vw;
    font-size: 15px;
    font-weight: 900;
    color: #15315d;
    line-height: 1.15;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.modern-report-subtitle {
    margin: 3px 0 0 0;
    font-size: 11px;
    font-weight: 600;
    color: #7b8ca7;
}

.modern-report-close {
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 50%;
    background: rgba(240,246,255,.95);
    color: #536782;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    box-shadow:
        0 10px 22px rgba(70, 95, 140, .08),
        inset 0 1px 0 rgba(255,255,255,.9);
    transition: all .18s ease;
}

.modern-report-close:hover {
    background: #e8f1ff;
    color: #1d4f91;
    transform: scale(1.05);
}

.modern-report-body {
    height: calc(95vh - 64px);
    background: #ffffff;
}

#modalReportFrame {
    width: 100%;
    height: 100%;
    border: 0;
    display: block;
    background: #ffffff;
}

body.modal-open {
    overflow: hidden;
}

@media (max-width: 768px) {
    .modern-report-dialog {
        max-width: 98vw !important;
        width: 98vw !important;
        height: 96vh;
        margin: 2vh auto;
    }

    .modern-report-content {
        height: 96vh;
        border-radius: 18px;
    }

    .modern-report-body {
        height: calc(96vh - 64px);
    }
}

/* =========================================================
   KPI RESUMEN SUPERIOR POR WIDGET
========================================================= */

.modern-summary-kpis {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin: 0 0 26px 0;
}

.modern-summary-card {
    position: relative;
    overflow: hidden;
    min-height: 118px;
    border-radius: 24px;
    padding: 20px;
    background:
        radial-gradient(circle at 12% 0%, rgba(95, 160, 255, .12), transparent 34%),
        linear-gradient(180deg, rgba(255,255,255,.88), rgba(245,249,255,.78));
    border: 1px solid rgba(215, 228, 246, .95);
    box-shadow:
        0 20px 45px rgba(70, 95, 140, .10),
        inset 0 1px 0 rgba(255,255,255,.88);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    display: flex;
    align-items: center;
    gap: 16px;
}

.modern-summary-card::after {
    content: "";
    position: absolute;
    width: 120px;
    height: 120px;
    right: -45px;
    top: -45px;
    border-radius: 50%;
    background: rgba(90, 142, 255, .08);
}

.modern-summary-icon {
    width: 58px;
    height: 58px;
    min-width: 58px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(145deg, #f7fbff, #dcecff);
    color: #4d83ff;
    font-size: 22px;
    box-shadow:
        0 12px 24px rgba(70, 110, 180, .12),
        inset 0 1px 0 rgba(255,255,255,.9);
    position: relative;
    z-index: 2;
}

.modern-summary-info {
    position: relative;
    z-index: 2;
    min-width: 0;
}

.modern-summary-label {
    margin: 0 0 6px 0;
    font-size: 12px;
    font-weight: 850;
    color: #71829f;
    text-transform: uppercase;
    letter-spacing: .7px;
}

.modern-summary-value {
    margin: 0;
    font-size: 30px;
    line-height: 1;
    font-weight: 950;
    color: #15315d;
}

.modern-summary-help {
    display: block;
    margin-top: 7px;
    font-size: 12px;
    font-weight: 650;
    color: #7a8ba7;
}

.modern-summary-card.success .modern-summary-icon {
    color: #72af10;
    background: linear-gradient(145deg, #fbfff3, #eaf7d1);
}

.modern-summary-card.success::after {
    background: rgba(130, 190, 30, .10);
}

.modern-summary-card.route .modern-summary-icon {
    color: #1f9fc9;
    background: linear-gradient(145deg, #f4fdff, #d9f3fb);
}

.modern-summary-card.route::after {
    background: rgba(31, 159, 201, .10);
}

@media (max-width: 992px) {
    .modern-summary-kpis {
        grid-template-columns: 1fr;
    }
}

/* ── Grid de semanas — Modal Estatus Vehículo ── */
.ev-week-card { border:1px solid #dee2e6; border-radius:8px; padding:10px; background:#fff; }
.ev-week-header { font-size:13px; margin-bottom:8px; }
.ev-week-days { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px; }
.ev-day-label { display:inline-flex; flex-direction:column; align-items:center; padding:5px 9px; border:1px solid #dee2e6; border-radius:6px; cursor:pointer; user-select:none; min-width:40px; transition:all .15s; }
.ev-day-label.active  { background:#eafbea; border-color:#217346; }
.ev-day-label.feriado { background:#f8d7da; border-color:#dc3545; opacity:.75; }
.ev-day-check { display:none; }
.ev-day-name  { font-size:10px; font-weight:700; }
.ev-day-num   { font-size:15px; font-weight:700; }
.ev-week-preview { font-size:11px; margin-top:4px; min-height:16px; }
  </style>
</head>
<body>

<!-- ===================== BLOQUE: Filtros ===================== -->
<div class="container-fluid modern-filter-shell">
  <form method="GET" action="ui_dashboard.php" id="filterForm" class="modern-filter-form">

    <?php if ($division_id === 1): ?>
      <div class="modern-filter-field">
        <label for="division_filter">División:</label>
        <select name="division" id="division_filter">
          <option value="0" <?= $division_seleccionada===0 ? 'selected':'' ?>>Todas</option>
          <?php foreach ($divisiones as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $division_seleccionada===$d['id'] ? 'selected':'' ?>>
              <?= htmlspecialchars($d['nombre'], ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="modern-filter-field">
        <label for="estado_filter">Estado:</label>
        <select name="estado" id="estado_filter">
          <option value="1" <?= $estado_seleccionado===1 ? 'selected':'' ?>>En curso</option>
          <option value="3" <?= $estado_seleccionado===3 ? 'selected':'' ?>>Finalizadas</option>
        </select>
      </div>

    <?php else: ?>

      <input type="hidden" name="division" value="<?= $division_id ?>">

      <div class="modern-filter-field">
        <label for="estado_filter">Estado:</label>
        <select name="estado" id="estado_filter">
          <option value="1" <?= $estado_seleccionado===1 ? 'selected':'' ?>>En proceso</option>
          <option value="3" <?= $estado_seleccionado===3 ? 'selected':'' ?>>Finalizadas</option>
        </select>
      </div>

    <?php endif; ?>

  </form>
</div>

<!-- ===================== BLOQUE: CAMPAÑAS PROGRAMADAS (tipo=1) ===================== -->
<div class="card card-widget mt-4 modern-module-card">
<div class="card-header">
  <div class="modern-widget-header">

    <div class="modern-widget-left">
      <div class="modern-widget-icon">
        <i class="far fa-calendar-check"></i>
      </div>
      <div>
        <h2 class="modern-widget-title">Campañas planificadas</h2>
        <p class="modern-widget-subtitle">Última actividad cargada</p>
      </div>
    </div>

    <div class="modern-widget-actions">
      <div class="modern-search-box">
        <i class="fas fa-search"></i>
        <input
          type="text"
          id="searchInput"
          placeholder="Buscar campaña..."
        >
      </div>

      <label class="modern-check-label" for="selectAllCheckbox">
        <input
          type="checkbox"
          id="selectAllCheckbox"
        >
        <span>Seleccionar todos</span>
      </label>

      <button
        id="bulkDownloadBtn"
        class="modern-download-btn"
        type="button"
      >
        <i class="fas fa-download"></i>
        <span>Descarga masiva</span>
      </button>

      <button
        type="button"
        class="modern-collapse-btn"
        data-card-widget="collapse"
        title="Expandir / contraer"
      >
        <i class="fas fa-minus"></i>
      </button>
    </div>

  </div>
</div>
  <div class="card-body">
    <div class="container mt-4">
<div class="modern-summary-kpis">

  <div class="modern-summary-card">
    <div class="modern-summary-icon">
      <i class="far fa-calendar-check"></i>
    </div>
    <div class="modern-summary-info">
      <p class="modern-summary-label">Total campañas</p>
      <h3 class="modern-summary-value">
        <?= number_format((int)$kpiProgramadas['total_campanas'], 0, ',', '.'); ?>
      </h3>
      <span class="modern-summary-help">
        <?= htmlspecialchars($estadoKpiLabel, ENT_QUOTES, 'UTF-8'); ?>
      </span>
    </div>
  </div>

  <div class="modern-summary-card">
    <div class="modern-summary-icon">
      <i class="fas fa-store"></i>
    </div>
    <div class="modern-summary-info">
      <p class="modern-summary-label">% visita total</p>
      <h3 class="modern-summary-value">
        <?= number_format((int)$kpiProgramadas['pct_visitado'], 0, ',', '.'); ?>%
      </h3>
      <span class="modern-summary-help">
        <?= number_format((int)$kpiProgramadas['visitados'], 0, ',', '.'); ?>
        /
        <?= number_format((int)$kpiProgramadas['programados'], 0, ',', '.'); ?>
        salas
      </span>
    </div>
  </div>

  <div class="modern-summary-card success">
    <div class="modern-summary-icon">
      <i class="fas fa-check-circle"></i>
    </div>
    <div class="modern-summary-info">
      <p class="modern-summary-label">% ejecución total</p>
      <h3 class="modern-summary-value">
        <?= number_format((int)$kpiProgramadas['pct_ejecutado'], 0, ',', '.'); ?>%
      </h3>
      <span class="modern-summary-help">
        <?= number_format((int)$kpiProgramadas['ejecutados'], 0, ',', '.'); ?>
        /
        <?= number_format((int)$kpiProgramadas['programados'], 0, ',', '.'); ?>
        salas
      </span>
    </div>
  </div>

</div>
      <div class="row" id="campaignsContainer">
        <?php if (!empty($campanasProgramadas)): ?>
            <?php foreach ($campanasProgramadas as $rowP): ?>
            <?php
              switch($rowP['estado'
                ]){
                                case 1: $estado_desc='EN CURSO';     break;
                                case 2: $estado_desc='EN PROCESO';   break;
                                case 3: $estado_desc='FINALIZADO';   break;
                                case 4: $estado_desc='CANCELADO';    break;
                                default:$estado_desc='DESCONOCIDO';  break;
                }
                              $campana_upper = htmlspecialchars(mb_strtoupper($rowP['nombre_campana'
                ],'UTF-8'));
                              $locales_programados   = $rowP['locales_programados'
                ];
                              $locales_visitados     = $rowP['locales_visitados'
                ];
                              $locales_implementados = $rowP['locales_implementados'
                ];
                              $porc_visitados        = $rowP['porcentaje_visitado'
                ];
                              $porc_completados      = $rowP['porcentaje_completado'
                ];
                            ?>
            <!-- 4) Cada “.campaign-item” es la columna que engloba la card -->
<div class="col-12 col-sm-6 col-md-4 align-items-stretch campaign-item">
  <div class="modern-plan-card">

    <div class="modern-plan-top">

      <!-- Checkbox selección masiva -->
      <div class="modern-plan-check">
        <input type="checkbox"
               id="chk-prog<?php echo (int)$rowP['id_campana']; ?>"
               class="campaign-bulk-checkbox"
               value="<?php echo (int)$rowP['id_campana']; ?>"
               data-modalidad="<?php echo htmlspecialchars($rowP['modalidad'], ENT_QUOTES, 'UTF-8'); ?>">
      </div>

      <!-- Dropdown descarga Excel -->
      <div class="modern-plan-tools">
        <div class="dropdown dl-compact">
          <button class="modern-plan-excel-btn dropdown-toggle"
                  type="button"
                  data-toggle="dropdown"
                  aria-expanded="false"
                  title="Descargar Excel">
            <i class="fas fa-file-excel"></i>
          </button>

          <div class="dropdown-menu dropdown-menu-right" style="z-index:1060;">
            <a class="dropdown-item download-excel-trigger"
               href="#"
               data-id="<?= (int)$rowP['id_campana']; ?>"
               data-modalidad="<?= htmlspecialchars($rowP['modalidad'], ENT_QUOTES, 'UTF-8'); ?>">
               DESCARGAR DATA
            </a>

            <div class="dropdown-divider"></div>

            <a class="dropdown-item download-distribucion-trigger"
               href="#"
               data-id="<?= (int)$rowP['id_campana']; ?>"
               data-modalidad="<?= htmlspecialchars($rowP['modalidad'], ENT_QUOTES, 'UTF-8'); ?>">
               DESCARGAR DISTRIBUCIÓN
            </a>
          </div>
        </div>
      </div>

      <!-- Barra de progreso descarga -->
      <div class="progress modern-plan-progress">
        <div class="progress-bar progress-bar-striped progress-bar-animated"
             role="progressbar"
             style="width:0%;"
             aria-valuemin="0"
             aria-valuemax="100">0%
        </div>
      </div>

      <!-- Título campaña -->
      <div class="modern-plan-title campaign-name">
        <?php echo $campana_upper; ?>
      </div>

      <!-- Estado -->
      <div class="modern-plan-status">
        <?php echo htmlspecialchars($estado_desc, ENT_QUOTES, 'UTF-8'); ?>
      </div>

      <!-- Fechas -->
      <div class="modern-plan-dates">
        <?php echo htmlspecialchars($rowP['fechaInicio'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        al
        <?php echo htmlspecialchars($rowP['fechaTermino'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
      </div>

      <!-- Imagen referencia -->
      <div class="modern-plan-avatar">
        <img
          id="refImg-<?php echo (int)$rowP['id_campana']; ?>"
          class="reference-img zoom"
          src="<?php echo htmlspecialchars($rowP['reference_image'] ?: 'dist/img/visibility2Logo.png', ENT_QUOTES, 'UTF-8'); ?>"
          data-camp="<?php echo (int)$rowP['id_campana']; ?>"
          alt="Imagen de referencia">
      </div>

    </div>

    <div class="modern-plan-body">

      <!-- Indicadores -->
      <div class="modern-plan-stats">
        <div class="modern-stat-box">
          <div class="modern-stat-number"><?php echo (int)$locales_programados; ?></div>
          <div class="modern-stat-label">Programados</div>
        </div>

        <div class="modern-stat-box">
          <div class="modern-stat-number"><?php echo (int)$locales_visitados; ?></div>
          <div class="modern-stat-label">Visitados</div>
        </div>

        <div class="modern-stat-box">
          <div class="modern-stat-number"><?php echo (int)$locales_implementados; ?></div>
          <div class="modern-stat-label">Ejecutados</div>
        </div>
      </div>

      <!-- Botones acción -->
      <div class="modern-plan-actions">
        <a href="/visibility2/portal/modulos/mod_formulario/mapa_campana.php?id=<?php echo (int)$rowP['id_campana']; ?>"
           class="modern-action-btn js-open-viewer-modal"
           data-viewer-title="Mapa en línea - <?php echo htmlspecialchars($rowP['nombre_campana'], ENT_QUOTES, 'UTF-8'); ?>"
           data-viewer-subtitle="Vista integrada del mapa de gestión"
           title="Ver mapa en línea">
          <i class="fas fa-map-marker-alt"></i>
          <span>En línea</span>
        </a>

        <a href="modulos/mod_galeria/mod_galeria.php?id=<?php echo (int)$rowP['id_campana']; ?>"
           class="modern-action-btn js-open-viewer-modal"
           data-viewer-title="Galería - <?php echo htmlspecialchars($rowP['nombre_campana'], ENT_QUOTES, 'UTF-8'); ?>"
           data-viewer-subtitle="Vista integrada de imágenes"
           title="Ver galería">
          <i class="fas fa-image"></i>
          <span>Galería</span>
        </a>

        <a href="dashboard/dashboard_campana.php?id=<?php echo (int)$rowP['id_campana']; ?>&division=<?php echo (int)$division_seleccionada; ?>"
           class="modern-action-btn js-open-viewer-modal"
           data-report-title="<?php echo htmlspecialchars($rowP['nombre_campana'], ENT_QUOTES, 'UTF-8'); ?>"
           data-viewer-title="Informe - NOMBRE"
           data-viewer-subtitle="Vista integrada del reporte">
          <i class="fas fa-bars"></i>
          <span>Informe</span>
        </a>
      </div>

      <!-- Porcentajes -->
      <div class="modern-plan-footer">
        <div class="modern-kpi-box">
          <div class="modern-kpi-value">
            <?php echo (int)$porc_visitados; ?>%
          </div>
          <div class="modern-kpi-label">
            Salas visitadas
          </div>
        </div>

        <div class="modern-kpi-box">
          <div class="modern-kpi-value">
            <?php echo (int)$porc_completados; ?>%
          </div>
          <div class="modern-kpi-label">
            Salas ejecutadas
          </div>
        </div>
      </div>

    </div>

  </div>
</div>
<?php endforeach; ?>
        <?php else: ?>
        <div class="col-12">
          <div class="modern-empty-state">
            <div class="modern-empty-icon">
              <i class="fas fa-route"></i>
            </div>
        
            <div class="modern-empty-content">
              <h4>No hay campañas planificadas disponibles</h4>
              <p>No se encontraron campañas planificadas para los filtros seleccionados.</p>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>


<!-- ===================== BLOQUE: CAMPAÑAS IPT (tipo=3) ===================== -->
<div class="card card-widget mt-4 modern-module-card">
<div class="card-header">
  <div class="modern-widget-header">

    <div class="modern-widget-left">
      <div class="modern-widget-icon">
        <i class="fas fa-route"></i>
      </div>
      <div>
        <h2 class="modern-widget-title">Rutas planificadas</h2>
        <p class="modern-widget-subtitle">Última actividad IPT cargada</p>
      </div>
    </div>

    <div class="modern-widget-actions">
      <div class="modern-search-box">
        <i class="fas fa-search"></i>
        <input
          type="text"
          id="searchInput-ipt"
          placeholder="Buscar ruta..."
        >
      </div>

      <label class="modern-check-label" for="selectAllCheckboxIPT">
        <input
          type="checkbox"
          id="selectAllCheckboxIPT"
        >
        <span>Seleccionar todos</span>
      </label>

      <button
        id="bulkDownloadBtnIPT"
        class="modern-download-btn"
        type="button"
      >
        <i class="fas fa-download"></i>
        <span>Descarga masiva</span>
      </button>

      <button
        type="button"
        class="modern-collapse-btn"
        data-card-widget="collapse"
        title="Expandir / contraer"
      >
        <i class="fas fa-minus"></i>
      </button>
    </div>

  </div>
</div>
<div class="card-body">
  <div class="container mt-4">
<div class="modern-summary-kpis">

  <div class="modern-summary-card route">
    <div class="modern-summary-icon">
      <i class="fas fa-route"></i>
    </div>
    <div class="modern-summary-info">
      <p class="modern-summary-label">Total rutas</p>
      <h3 class="modern-summary-value">
        <?= number_format((int)$kpiRutas['total_campanas'], 0, ',', '.'); ?>
      </h3>
      <span class="modern-summary-help">
        <?= htmlspecialchars($estadoKpiLabel, ENT_QUOTES, 'UTF-8'); ?>
      </span>
    </div>
  </div>

  <div class="modern-summary-card route">
    <div class="modern-summary-icon">
      <i class="fas fa-map-marker-alt"></i>
    </div>
    <div class="modern-summary-info">
      <p class="modern-summary-label">% visita total</p>
      <h3 class="modern-summary-value">
        <?= number_format((int)$kpiRutas['pct_visitado'], 0, ',', '.'); ?>%
      </h3>
      <span class="modern-summary-help">
        <?= number_format((int)$kpiRutas['visitados'], 0, ',', '.'); ?>
        /
        <?= number_format((int)$kpiRutas['programados'], 0, ',', '.'); ?>
        salas
      </span>
    </div>
  </div>

  <div class="modern-summary-card success">
    <div class="modern-summary-icon">
      <i class="fas fa-check-circle"></i>
    </div>
    <div class="modern-summary-info">
      <p class="modern-summary-label">% ejecución total</p>
      <h3 class="modern-summary-value">
        <?= number_format((int)$kpiRutas['pct_ejecutado'], 0, ',', '.'); ?>%
      </h3>
      <span class="modern-summary-help">
        <?= number_format((int)$kpiRutas['ejecutados'], 0, ',', '.'); ?>
        /
        <?= number_format((int)$kpiRutas['programados'], 0, ',', '.'); ?>
        salas
      </span>
    </div>
  </div>

</div>      
    <div class="row">

<?php if (!empty($rutasPlanificadas)): ?>
  <?php foreach ($rutasPlanificadas as $rowIpt): ?>
          <?php
            switch ($rowIpt['estado']) {
              case 1: $ed = 'EN CURSO'; break;
              case 2: $ed = 'EN PROCESO'; break;
              case 3: $ed = 'FINALIZADO'; break;
              case 4: $ed = 'CANCELADO'; break;
              default: $ed = 'DESCONOCIDO'; break;
            }

            $prog  = $rowIpt['locales_programados'];
            $visit = $rowIpt['locales_visitados'];
            $exec  = $rowIpt['locales_implementados'];
            $pctV  = $rowIpt['porcentaje_visitado'];
            $pctC  = $rowIpt['porcentaje_completado'];

            $nombreRutaUpper = htmlspecialchars(
              mb_strtoupper($rowIpt['nombre_campana'], 'UTF-8'),
              ENT_QUOTES,
              'UTF-8'
            );

            $modalidadRuta = htmlspecialchars(
              $rowIpt['modalidad'] ?? '',
              ENT_QUOTES,
              'UTF-8'
            );

            $imagenRuta = htmlspecialchars(
              $rowIpt['reference_image'] ?: 'dist/img/visibility2Logo.png',
              ENT_QUOTES,
              'UTF-8'
            );
          ?>

          <div class="col-12 col-sm-6 col-md-4 align-items-stretch campaign-item-ipt">
            <div class="modern-plan-card modern-route-card">

              <div class="modern-plan-top">

                <!-- Checkbox selección masiva IPT -->
                <div class="modern-plan-check">
                  <input type="checkbox"
                         id="chk-ipt<?= (int)$rowIpt['id_campana']; ?>"
                         value="<?= (int)$rowIpt['id_campana']; ?>"
                         data-modalidad="<?= $modalidadRuta; ?>">
                </div>

                <!-- Dropdown descarga Excel -->
                <div class="modern-plan-tools">
                  <div class="dropdown dl-compact">
                    <button class="modern-plan-excel-btn dropdown-toggle"
                            type="button"
                            data-toggle="dropdown"
                            aria-expanded="false"
                            title="Descargar Excel">
                      <i class="fas fa-file-excel"></i>
                    </button>

                    <div class="dropdown-menu dropdown-menu-right" style="z-index:1060;">
                      <a class="dropdown-item download-excel-trigger"
                         href="#"
                         data-id="<?= (int)$rowIpt['id_campana']; ?>"
                         data-modalidad="<?= $modalidadRuta; ?>">
                        DESCARGAR DATA
                      </a>
                    </div>
                  </div>
                </div>

                <!-- Barra progreso descarga -->
                <div class="progress modern-plan-progress">
                  <div class="progress-bar progress-bar-striped progress-bar-animated"
                       role="progressbar"
                       style="width:0%;"
                       aria-valuemin="0"
                       aria-valuemax="100">0%
                  </div>
                </div>

                <!-- Título ruta -->
                <div class="modern-plan-title campaign-name-ipt">
                  <?= $nombreRutaUpper; ?>
                </div>

                <!-- Estado -->
                <div class="modern-plan-status">
                  <?= htmlspecialchars($ed, ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <!-- Fechas -->
                <div class="modern-plan-dates">
                  <?= htmlspecialchars($rowIpt['fechaInicio'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                  al
                  <?= htmlspecialchars($rowIpt['fechaTermino'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </div>

                <!-- Imagen referencia -->
                <div class="modern-plan-avatar">
                  <img
                    id="refImg-<?= (int)$rowIpt['id_campana']; ?>"
                    class="reference-img zoom"
                    src="<?= $imagenRuta; ?>"
                    data-camp="<?= (int)$rowIpt['id_campana']; ?>"
                    alt="Imagen de referencia">
                </div>

              </div>

              <div class="modern-plan-body">

                <!-- Indicadores -->
                <div class="modern-plan-stats">
                  <div class="modern-stat-box">
                    <div class="modern-stat-number"><?= (int)$prog; ?></div>
                    <div class="modern-stat-label">Programados</div>
                  </div>

                  <div class="modern-stat-box">
                    <div class="modern-stat-number"><?= (int)$visit; ?></div>
                    <div class="modern-stat-label">Visitados</div>
                  </div>

                  <div class="modern-stat-box">
                    <div class="modern-stat-number"><?= (int)$exec; ?></div>
                    <div class="modern-stat-label">Ejecutados</div>
                  </div>
                </div>

                <!-- Botones acción -->
                <div class="modern-plan-actions">
                    <a href="/visibility2/portal/modulos/mod_formulario/mapa_campana.php?id=<?= (int)$rowIpt['id_campana']; ?>"
                       class="modern-action-btn js-open-viewer-modal"
                       data-viewer-title="Mapa en línea - <?= htmlspecialchars($rowIpt['nombre_campana'], ENT_QUOTES, 'UTF-8'); ?>"
                       data-viewer-subtitle="Vista integrada del mapa de gestión"
                       title="Ver mapa en línea">
                      <i class="fas fa-map-marker-alt"></i>
                      <span>En línea</span>
                    </a>

                    <a href="modulos/mod_galeria/mod_galeria.php?id=<?= (int)$rowIpt['id_campana']; ?>"
                       class="modern-action-btn js-open-viewer-modal"
                       data-viewer-title="Galería - <?= htmlspecialchars($rowIpt['nombre_campana'], ENT_QUOTES, 'UTF-8'); ?>"
                       data-viewer-subtitle="Vista integrada de imágenes"
                       title="Ver galería">
                      <i class="fas fa-image"></i>
                      <span>Galería</span>
                    </a>

                    <a href="UI_informe.php?id=<?= (int)$rowIpt['id_campana']; ?>&division=<?= (int)$division_seleccionada; ?>"
                       class="modern-action-btn js-open-viewer-modal"
                       data-report-title="<?= htmlspecialchars($rowIpt['nombre_campana'], ENT_QUOTES, 'UTF-8'); ?>"
                       data-viewer-title="Informe - NOMBRE"
                       data-viewer-subtitle="Vista integrada del reporte">
                      <i class="fas fa-bars"></i>
                      <span>Informe</span>
                    </a>
                </div>

                <!-- Porcentajes -->
                <div class="modern-plan-footer">
                  <div class="modern-kpi-box">
                    <div class="modern-kpi-value">
                      <?= (int)$pctV; ?>%
                    </div>
                    <div class="modern-kpi-label">
                      Salas visitadas
                    </div>
                  </div>

                  <div class="modern-kpi-box">
                    <div class="modern-kpi-value">
                      <?= (int)$pctC; ?>%
                    </div>
                    <div class="modern-kpi-label">
                      Salas ejecutadas
                    </div>
                  </div>
                </div>

              </div>

            </div>
          </div>

<?php endforeach; ?>

      <?php else: ?>

        <div class="col-12">
          <div class="modern-empty-state">
            <div class="modern-empty-icon">
              <i class="fas fa-route"></i>
            </div>

            <div class="modern-empty-content">
              <h4>No hay rutas IPT disponibles</h4>
              <p>No se encontraron rutas planificadas para los filtros seleccionados.</p>
            </div>
          </div>
        </div>

      <?php endif; ?>

    </div>
  </div>
</div>
</div><!-- /.card IPT -->

<!-- ===================== BLOQUE: Actividades Complementarias (tipo=2) ===================== -->
<div class="card card-widget mt-4 modern-module-card">
<div class="card-header">
  <div class="modern-widget-header">

    <div class="modern-widget-left">
      <div class="modern-widget-icon">
        <i class="fas fa-layer-group"></i>
      </div>
      <div>
        <h2 class="modern-widget-title">Actividades complementarias</h2>
        <p class="modern-widget-subtitle">Última actividad complementaria cargada</p>
      </div>
    </div>

    <div class="modern-widget-actions">
      <button
        type="button"
        class="modern-collapse-btn"
        data-card-widget="collapse"
        title="Expandir / contraer"
      >
        <i class="fas fa-minus"></i>
      </button>
    </div>

  </div>
</div>
<div class="card-body">
  <div class="container mt-4">
    <div class="row">

      <?php if (!empty($compCampanas)): ?>
        <?php foreach ($compCampanas as $cc): ?>
          <?php
            $idComp = (int)$cc['id_campana'];
            $nombreCompUpper = mb_strtoupper($cc['nombre_campana'], 'UTF-8');
          ?>

          <div class="col-12 col-sm-6 col-md-4 align-items-stretch campaign-item">
            <div class="modern-plan-card modern-complementary-card">

              <div class="modern-plan-top">

                <!-- Checkbox opcional -->
                <div class="modern-plan-check">
                  <input type="checkbox"
                         id="chk-cc<?= $idComp; ?>"
                         value="<?= $idComp; ?>">
                </div>

                <!-- Dropdown descarga Excel / acceso directo Estatus Vehículo -->
                <div class="modern-plan-tools">
                  <?php if ($idComp === 138): ?>
                    <button class="modern-plan-excel-btn"
                            type="button"
                            data-toggle="modal"
                            data-target="#modalEstatusVehiculo"
                            title="Informe Estatus Vehículo">
                      <i class="fas fa-file-excel"></i>
                    </button>
                  <?php else: ?>
                  <div class="dropdown dl-compact">
                    <button class="modern-plan-excel-btn dropdown-toggle"
                            type="button"
                            data-toggle="dropdown"
                            aria-expanded="false"
                            title="Descargar Excel">
                      <i class="fas fa-file-excel"></i>
                    </button>

                    <div class="dropdown-menu dropdown-menu-right" style="z-index:1060;">
                      <a class="dropdown-item download-link-cc"
                         href="#"
                         data-id="<?= $idComp; ?>">
                        DESCARGAR DATA
                      </a>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>

                <!-- Título actividad -->
                <div class="modern-plan-title">
                  <?= $nombreCompUpper; ?>
                </div>

                <!-- Estado / tipo -->
                <div class="modern-plan-status">
                  ACTIVIDAD COMPLEMENTARIA
                </div>

                <!-- Imagen referencia -->
                <div class="modern-plan-avatar">
                  <img
                    class="reference-img zoom"
                    src="dist/img/visibility2Logo.png"
                    alt="Campaña Complementaria">
                </div>

              </div>

              <div class="modern-plan-body">

                <!-- Indicadores simples -->
                <div class="modern-plan-stats modern-complementary-stats">
                  <div class="modern-stat-box">
                    <div class="modern-stat-number">-</div>
                    <div class="modern-stat-label">Programados</div>
                  </div>

                  <div class="modern-stat-box">
                    <div class="modern-stat-number">-</div>
                    <div class="modern-stat-label">Visitados</div>
                  </div>
                </div>

                <!-- Botones acción -->
                <div class="modern-plan-actions modern-complementary-actions">

                  <a href="/visibility2/portal/modulos/mod_formulario/mapa_campana.php?id=<?= $idComp; ?>"
                     class="modern-action-btn"
                     target="_blank"
                     title="Ver mapa en línea">
                    <i class="fas fa-play"></i>
                    <span>En línea</span>
                  </a>

                <a href="/visibility2/portal/modulos/mod_galeria/mod_galeria_complementarias.php?id=<?= $idComp; ?>"
                   class="modern-action-btn js-open-viewer-modal"
                   data-viewer-title="Galería - <?= htmlspecialchars($cc['nombre_campana'], ENT_QUOTES, 'UTF-8'); ?>"
                   data-viewer-subtitle="Vista integrada de imágenes complementarias"
                   title="Ver galería">
                  <i class="fas fa-image"></i>
                  <span>Galería</span>
                </a>

                </div>

              </div>

            </div>
          </div>

        <?php endforeach; ?>

      <?php else: ?>

        <div class="col-12">
          <div class="modern-empty-state">
            <div class="modern-empty-icon">
              <i class="fas fa-layer-group"></i>
            </div>

            <div class="modern-empty-content">
              <h4>No hay actividades complementarias</h4>
              <p>No se encontraron actividades complementarias para los filtros seleccionados.</p>
            </div>
          </div>
        </div>

      <?php endif; ?>

    </div>
  </div>
</div>
</div><!-- /.card COMPLEMENTARIAS -->

<?php if ($tiene_estatus_vehiculo): ?>
<!-- Modal Informe Estatus Vehículo -->
<div class="modal fade" id="modalEstatusVehiculo" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">

      <div class="modal-header" style="background:#217346; color:#fff;">
        <h5 class="modal-title"><i class="fas fa-truck mr-2"></i>Informe Estatus Vehículo</h5>
        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body">
        <p class="small text-muted mb-3">
          Selecciona el rango de fechas, el modo de análisis y las semanas que aplican para generar el Excel de cumplimiento.
        </p>

        <!-- Rango de fechas -->
        <div class="form-row mb-3">
          <div class="col">
            <label class="font-weight-bold small">Fecha inicio</label>
            <input type="date" id="evFechaDesde" class="form-control form-control-sm">
          </div>
          <div class="col">
            <label class="font-weight-bold small">Fecha término</label>
            <input type="date" id="evFechaHasta" class="form-control form-control-sm">
          </div>
        </div>

        <!-- Modo de análisis -->
        <div class="form-group mb-3">
          <label class="font-weight-bold small mb-1">Modo de análisis</label>
          <div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="evModo" id="evModoClasico" value="clasico" checked>
              <label class="form-check-label small" for="evModoClasico">
                <strong>Fin de semana</strong> — viernes tarde  + lunes mañana
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="evModo" id="evModoDiario" value="diario">
              <label class="form-check-label small" for="evModoDiario">
                <strong>Diario</strong> — 2 subidas por día hábil (inicio y término de jornada)
              </label>
            </div>
          </div>
        </div>

        <!-- Grid de semanas (se genera por JS) -->
        <div id="evWeekGrid" class="row g-2 mb-3"></div>

        <!-- Totalizador -->
        <div class="alert alert-light border py-2 px-3 mb-0" id="evTotalAlert" style="display:none;">
          Total subidas esperadas: <strong id="evTotalCount">0</strong>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
        <form id="evReportForm"
              method="POST"
              action="informes/descargar_excel_estatus_vehiculo.php"
              target="_blank">
          <input type="hidden" name="start_date" id="evInputStart">
          <input type="hidden" name="end_date"   id="evInputEnd">
          <input type="hidden" name="expected_days" id="evInputExpected">
          <input type="hidden" name="modo" id="evInputModo">
          <button type="submit" class="btn btn-success btn-sm" id="evSubmitBtn" disabled>
            <i class="fas fa-file-excel mr-1"></i>Generar Excel
          </button>
        </form>
      </div>

    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal de filtro de fechas -->
<div class="modal fade" id="modalFechaFiltro" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Filtrar por rango de fechas</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <form id="formFechaFiltro">
          <div class="form-group">
            <label for="fechaDesde">Desde:</label>
            <input type="date" id="fechaDesde" name="start_date" class="form-control" >
          </div>
          <div class="form-group">
            <label for="fechaHasta">Hasta:</label>
            <input type="date" id="fechaHasta" name="end_date" class="form-control" >
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-primary" id="btnAplicarFiltro">Aplicar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade modern-ref-modal" id="modalRefImage" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modern-ref-dialog" role="document">
    <div class="modal-content modern-ref-content">

      <div class="modern-ref-header">
        <div class="modern-ref-title-wrap">
          <div class="modern-ref-icon">
            <i class="fas fa-image"></i>
          </div>
          <div>
            <h5 class="modern-ref-title">Imagen de referencia</h5>
            <p class="modern-ref-subtitle">Vista previa del material asociado</p>
          </div>
        </div>

        <button type="button" class="modern-ref-close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modern-ref-body">

        <div class="modern-ref-image-frame">
          <img id="modalRefImgTag" src="" alt="Referencia">
        </div>

        <?php if (strtolower($perfilUser) == 'editor' || strtolower($perfilUser) == 'coordinador'): ?>
          <button id="btnChangeRef" class="modern-ref-change-btn" type="button">
            <i class="fas fa-camera"></i>
            <span>Cambiar foto de referencia</span>
          </button>
        <?php endif; ?>

        <form id="formChangeRef" enctype="multipart/form-data" class="modern-ref-upload-form" style="display:none;">
          <label class="modern-ref-file-box">
            <i class="fas fa-cloud-upload-alt"></i>
            <span>Seleccionar nueva imagen</span>
            <input type="file" name="new_ref" accept="image/*" required>
          </label>

          <button type="submit" class="modern-ref-save-btn">
            <i class="fas fa-save"></i>
            <span>Guardar imagen</span>
          </button>
        </form>

      </div>

    </div>
  </div>
</div>

<!-- Modal: selección de descarga Excel -->
<div class="modal fade" id="modalDescargaExcel" tabindex="-1" aria-labelledby="modalDescargaExcelLabel" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="modalDescargaExcelLabel">Opciones de descarga</h6>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div id="excelFotosImplementacionGroup" class="mb-3">
          <p class="mb-2 font-weight-bold" id="excelFotosImplementacionTitle">Fotos de implementación</p>
          <div class="custom-control custom-radio mb-2">
            <input type="radio" id="excelPhotosMaterialCon" name="excelPhotosMaterialOption" value="1" class="custom-control-input" checked>
            <label class="custom-control-label" for="excelPhotosMaterialCon" id="excelFotosImplementacionLabelCon">Con fotos de implementación</label>
          </div>
          <div class="custom-control custom-radio">
            <input type="radio" id="excelPhotosMaterialSin" name="excelPhotosMaterialOption" value="0" class="custom-control-input">
            <label class="custom-control-label" for="excelPhotosMaterialSin" id="excelFotosImplementacionLabelSin">Sin fotos de implementación</label>
          </div>
        </div>

        <div id="excelFotosEncuestaGroup">
          <p class="mb-2 font-weight-bold" id="excelFotosEncuestaTitle">Fotos de encuesta</p>
          <div class="custom-control custom-radio mb-2">
            <input type="radio" id="excelPhotosEncuestaCon" name="excelPhotosEncuestaOption" value="1" class="custom-control-input" checked>
            <label class="custom-control-label" for="excelPhotosEncuestaCon" id="excelFotosEncuestaLabelCon">Con fotos de encuesta</label>
          </div>
          <div class="custom-control custom-radio">
            <input type="radio" id="excelPhotosEncuestaSin" name="excelPhotosEncuestaOption" value="0" class="custom-control-input">
            <label class="custom-control-label" for="excelPhotosEncuestaSin" id="excelFotosEncuestaLabelSin">Sin fotos de encuesta</label>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success btn-sm" id="btnDescargarExcelConfirm">
          <i class="fas fa-download mr-1"></i> Descargar archivos
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade modern-report-modal" id="modalReportViewer" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modern-report-dialog" role="document">
    <div class="modal-content modern-report-content">

      <div class="modern-report-header">
        <div class="modern-report-title-wrap">
          <div class="modern-report-icon">
            <i class="fas fa-chart-bar"></i>
          </div>

          <div>
            <h5 id="modalReportTitle" class="modern-report-title">Informe</h5>
            <p class="modern-report-subtitle">Vista integrada del reporte</p>
          </div>
        </div>

        <button type="button" class="modern-report-close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modern-report-body">
        <iframe
          id="modalReportFrame"
          src=""
          frameborder="0"
          allowfullscreen>
        </iframe>
      </div>

    </div>
  </div>
</div>

<!-- Overlay de descarga -->
<div id="downloadOverlay" class="download-overlay d-none">
  <div class="download-overlay__box">
    <div class="download-overlay__spinner"></div>
    <div class="download-overlay__title">Preparando archivos</div>
    <div class="download-overlay__text">
      Estamos generando la descarga. Esto puede tardar unos segundos.
    </div>
  </div>
</div>

<!-- JS -->
<script src="plugins/jquery/jquery.min.js"></script>
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="dist/js/adminlte.min.js"></script>

<script>
$(document).on('click', '.js-open-report-modal', function (e) {
  e.preventDefault();

  const url = $(this).attr('href');
  const title = $(this).data('report-title') || $(this).attr('title') || 'Informe';

  if (!url || url === '#') {
    return;
  }

  $('#modalReportTitle').text(title);
  $('#modalReportFrame').attr('src', url);
  $('#modalReportViewer').modal('show');
});

$('#modalReportViewer').on('hidden.bs.modal', function () {
  $('#modalReportFrame').attr('src', '');
});
</script>

<script>
$('#selectAllCheckbox').on('change', function() {
  const marcado = this.checked;
  $("input[id^='chk-prog']").prop('checked', marcado);
});

$('#selectAllCheckboxIPT').on('change', function() {
  const marcado = this.checked;
  $("input[id^='chk-ipt']").prop('checked', marcado);
});
</script>

<script>
// Maneja tanto descarga como vista en línea
$(document).on('click', '.inline-link', function (e) {
  e.preventDefault();
  const id = $(this).data('id');
  $('#modalFechaFiltro')
    .data({ tipo: 'complementaria', id: id, mode: 'inline' })
    .modal('show');
});

// Excel de complementarias: abre modal con modo=excel
$(document).on('click', '.download-link-cc', function (e) {
  e.preventDefault();
  const id = $(this).data('id');
  $('#modalFechaFiltro')
    .data({ tipo: 'complementaria', id: id, mode: 'excel' })
    .modal('show');
});


$('#btnAplicarFiltro').off('click').on('click', function () {
  const modal = $('#modalFechaFiltro');
  const meta  = modal.data() || {};
  const tipo  = meta.tipo;                 // 'complementaria' (y futuro: otros)
  const id    = meta.id;                   // id campaña
  const mode  = meta.mode;                 // 'inline' | 'excel'

  const start = modal.find('#fechaDesde').val();
  const end   = modal.find('#fechaHasta').val();

  const base = (tipo === 'complementaria')
    ? 'informes/descargar_excel_IW.php'
    : 'informes/descargar_excel.php';     // por si reutilizas el modal

  const today = (() => {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}`;
  })();

  let url = `${base}?id=${encodeURIComponent(id)}`;

  if (!start && !end) {
    // Excepción #1: histórico (sin fechas)
    // url ya armado solo con id
  } else if (start && !end) {
    // Excepción #2: desde start hasta hoy
    url += `&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(today)}`;
  } else if (!start && end) {
    alert('Si indicas "Hasta", debes indicar "Desde".');
    return;
  } else {
    // Ambos presentes: validar orden
    if (start > end) {
      alert('La fecha "Desde" no puede ser mayor que "Hasta".');
      return;
    }
    url += `&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`;
  }

  if (mode === 'inline') url += '&inline=1';

  modal.modal('hide');
  window.open(url, '_blank');
});




// Filtros de formulario
$('#empresa_filter').on('change', function(){ $('#filterForm').submit();
});
  $('#division_filter, #estado_filter')
    .on('change', function(){ $('#filterForm').submit();
});

$(document).on('click','.reference-img',function(){
  var src=$(this).attr('src'),
      camp=$(this).data('camp');
  $('#modalRefImgTag').attr('src',src);
  $('#modalRefImage').data('camp',camp).modal('show');
/*  var canEdit=<?=($perfil_nombre=="editor"&&$empresa_id===103&&$division_id===1?1: 0)?>; */
  $('#btnChangeRef').toggle(canEdit);
  $('#formChangeRef').hide();
});
$('#btnChangeRef').click(function(){
  $('#formChangeRef').show();
}); 

$('#formChangeRef').submit(function(e){
  e.preventDefault();
  var camp=$('#modalRefImage').data('camp'),
      fd=new FormData(this);
  fd.append('id',camp);
  $.ajax({
    url:'update_reference_image.php',
    method:'POST',
    data:fd,
    processData: false,
    contentType: false,
    success:function(res){
      var j=JSON.parse(res);
      if(j.ok){
        $('#refImg-' + camp).attr('src',j.url);
        $('#modalRefImgTag').attr('src',j.url);
        alert('Referencia actualizada');
      } else alert('Error: '+j.error);
      $('#formChangeRef').hide();
    },
    error:function(){alert('Error subida'); $('#formChangeRef').hide();
    }
  });
});


</script>

<script>
function generarTokenDescarga() {
  return 'dl_' + Date.now() + '_' + Math.random().toString(36).slice(2);
}

function obtenerCookie(nombre) {
  const valor = `; ${document.cookie}`;
  const partes = valor.split(`; ${nombre}=`);
  if (partes.length === 2) {
    return partes.pop().split(';').shift();
  }
  return null;
}

function limpiarCookie(nombre) {
  document.cookie = `${nombre}=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
}

function esperarDescargaReal(token, onComplete, timeoutMs = 120000) {
  const cookieName = 'fileDownloadToken';
  const start = Date.now();

  const timer = setInterval(() => {
    const valor = obtenerCookie(cookieName);

    if (valor === token) {
      clearInterval(timer);
      limpiarCookie(cookieName);
      if (typeof onComplete === 'function') {
        onComplete();
      }
      return;
    }

    if (Date.now() - start > timeoutMs) {
      clearInterval(timer);
      if (typeof onComplete === 'function') {
        onComplete();
      }
    }
  }, 500);
}
</script>

<script>
function descargarEnIframe(url, iframeId) {
  let iframe = document.getElementById(iframeId);

  if (!iframe) {
    iframe = document.createElement('iframe');
    iframe.id = iframeId;
    iframe.style.display = 'none';
    document.body.appendChild(iframe);
  }

  iframe.src = url;
}
</script>

<script>
let campanaDescargaExcel = null;
let campanaModalidadExcel = '';
let modoDescargaExcel = 'single';
let campanasSeleccionadasExcel = [];
let descargaEnCurso = false;
let overlayTimerMinimo = null;
let overlayVisibleDesde = 0;

const normalizarModalidad = (modalidad = '') => (modalidad || '').toLowerCase().trim();

const mostrarOverlayDescarga = () => {
  descargaEnCurso = true;
  overlayVisibleDesde = Date.now();
  $('#downloadOverlay').removeClass('d-none');

  // respaldo máximo
  setTimeout(() => {
    if (descargaEnCurso) {
      ocultarOverlayDescarga();
    }
  }, 10000);
};

const ocultarOverlayDescarga = () => {
  descargaEnCurso = false;
  $('#downloadOverlay').addClass('d-none');

  if (overlayTimerMinimo) {
    clearTimeout(overlayTimerMinimo);
    overlayTimerMinimo = null;
  }
};

const ocultarOverlayConMinimo = (minMs = 900) => {
  const transcurrido = Date.now() - overlayVisibleDesde;
  const restante = Math.max(0, minMs - transcurrido);

  if (overlayTimerMinimo) {
    clearTimeout(overlayTimerMinimo);
  }

  overlayTimerMinimo = setTimeout(() => {
    ocultarOverlayDescarga();
  }, restante);
};

const modalidadTieneDetalle = (modalidad) => {
  const m = normalizarModalidad(modalidad);
  return ['solo_implementacion', 'implementacion_auditoria', 'retiro', 'entrega'].includes(m);
};

const modalidadTieneEncuesta = (modalidad) => {
  const m = normalizarModalidad(modalidad);
  return ['solo_auditoria', 'implementacion_auditoria'].includes(m);
};

const actualizarModalDescargaExcel = (modalidades) => {
  const modosEntrada = Array.isArray(modalidades) ? modalidades : [modalidades];
  const modos = modosEntrada.map(normalizarModalidad).filter(Boolean);

  const $grupoImpl = $('#excelFotosImplementacionGroup');
  const $grupoEncuesta = $('#excelFotosEncuestaGroup');

  const setTextosImpl = (textoBase) => {
    const capitalizado = textoBase.charAt(0).toUpperCase() + textoBase.slice(1);
    $('#excelFotosImplementacionTitle').text(capitalizado);
    $('#excelFotosImplementacionLabelCon').text(`Con ${textoBase}`);
    $('#excelFotosImplementacionLabelSin').text(`Sin ${textoBase}`);
  };

  const setTextosEncuesta = () => {
    $('#excelFotosEncuestaTitle').text('Fotos de encuesta');
    $('#excelFotosEncuestaLabelCon').text('Con fotos de encuesta');
    $('#excelFotosEncuestaLabelSin').text('Sin fotos de encuesta');
  };

  const hasImpl = modos.length === 0 || modos.some(m => modalidadTieneDetalle(m));
  const hasEncuesta = modos.some(m => modalidadTieneEncuesta(m));
  const soloRetiro = modos.length > 0 && modos.every(m => m === 'retiro');

  $grupoImpl.toggle(hasImpl);
  $grupoEncuesta.toggle(hasEncuesta);

  setTextosImpl(soloRetiro ? 'fotos de retiro' : 'fotos de implementación');
  setTextosEncuesta();

  $('#excelPhotosMaterialCon').prop('checked', true);
  $('#excelPhotosEncuestaCon').prop('checked', true);
};

$(document).on('click', '.download-excel-trigger', function (e) {
  e.preventDefault();

  campanaDescargaExcel = $(this).data('id') || null;
  campanaModalidadExcel = $(this).data('modalidad') || '';
  modoDescargaExcel = 'single';
  campanasSeleccionadasExcel = campanaDescargaExcel ? [campanaDescargaExcel] : [];

  actualizarModalDescargaExcel([campanaModalidadExcel]);
  $('#modalDescargaExcel').modal('show');
});

const prepararDescargaMasiva = (checkboxSelector) => {
  const seleccionados = Array.from(document.querySelectorAll(checkboxSelector));

  if (seleccionados.length === 0) {
    alert('Por favor, selecciona al menos una campaña.');
    return;
  }

  campanaDescargaExcel = null;
  campanaModalidadExcel = '';
  modoDescargaExcel = 'bulk';
  campanasSeleccionadasExcel = seleccionados.map(cb => cb.value);

  const modalidades = seleccionados.map(cb => cb.dataset.modalidad || '');
  actualizarModalDescargaExcel(modalidades);

  $('#modalDescargaExcel').modal('show');
};

$('#bulkDownloadBtn').on('click', function (e) {
  e.preventDefault();
  prepararDescargaMasiva('.campaign-bulk-checkbox:checked');
});

$('#selectAllCheckbox').on('change', function () {
  const checked = $(this).is(':checked');
  $('.campaign-bulk-checkbox').prop('checked', checked);
});

$(document).on('change', '.campaign-bulk-checkbox', function () {
  const total = $('.campaign-bulk-checkbox').length;
  const checked = $('.campaign-bulk-checkbox:checked').length;

  $('#selectAllCheckbox').prop('checked', total > 0 && total === checked);
});

$('#btnDescargarExcelConfirm').on('click', function () {
  const params = new URLSearchParams();

  if (modoDescargaExcel === 'bulk') {
    if (campanasSeleccionadasExcel.length === 0) return;
    params.set('ids', campanasSeleccionadasExcel.join(','));
  } else {
    if (!campanaDescargaExcel) return;
    params.set('id', campanaDescargaExcel);
  }

  let fotosMaterial = '0';
  let fotosEncuesta = '0';

  if ($('#excelFotosImplementacionGroup').is(':visible')) {
    fotosMaterial = $("input[name='excelPhotosMaterialOption']:checked").val() || '1';
    params.set('fotos', fotosMaterial);
  }

  if ($('#excelFotosEncuestaGroup').is(':visible')) {
    fotosEncuesta = $("input[name='excelPhotosEncuestaOption']:checked").val() || '1';
    params.set('fotos_encuesta', fotosEncuesta);
  }

  $('#modalDescargaExcel').modal('hide');
  mostrarOverlayDescarga();

  if (modoDescargaExcel === 'bulk') {
    const token = generarTokenDescarga();
    params.set('download_token', token);

    const urlBulk = `/visibility2/portal/informes/descarga_excel_masivo.php?${params.toString()}`;

    esperarDescargaReal(token, function () {
      ocultarOverlayDescarga();
    });

    descargarEnIframe(urlBulk, 'downloadFrameBulk');
    return;
  }

  const modalidad = normalizarModalidad(campanaModalidadExcel);
  const descargarDetalle = modalidadTieneDetalle(modalidad);
  const descargarEncuesta = modalidadTieneEncuesta(modalidad);

  // Si solo hay detalle
  if (descargarDetalle && !descargarEncuesta) {
    const paramsDetalle = new URLSearchParams(params.toString());
    const tokenDetalle = generarTokenDescarga();

    paramsDetalle.set('fotos', fotosMaterial);
    paramsDetalle.set('download_token', tokenDetalle);

    const urlDetalle = `/visibility2/portal/informes/descargar_excel_detalle.php?${paramsDetalle.toString()}`;

    esperarDescargaReal(tokenDetalle, function () {
      ocultarOverlayDescarga();
    });

    descargarEnIframe(urlDetalle, 'downloadFrameDetalle');
    return;
  }

  // Si solo hay encuesta
  if (!descargarDetalle && descargarEncuesta) {
    const paramsEncuesta = new URLSearchParams(params.toString());
    const tokenEncuesta = generarTokenDescarga();

    paramsEncuesta.set('fotos_encuesta', fotosEncuesta);
    paramsEncuesta.set('download_token', tokenEncuesta);

    const urlEncuesta = `/visibility2/portal/informes/descargar_encuesta_csv.php?${paramsEncuesta.toString()}`;

    esperarDescargaReal(tokenEncuesta, function () {
      ocultarOverlayDescarga();
    });

    descargarEnIframe(urlEncuesta, 'downloadFrameEncuesta');
    return;
  }

  // Si hay detalle + encuesta (implementacion_auditoria)
  if (descargarDetalle && descargarEncuesta) {
    const paramsDetalle = new URLSearchParams(params.toString());
    const tokenDetalle = generarTokenDescarga();

    paramsDetalle.set('fotos', fotosMaterial);
    paramsDetalle.set('download_token', tokenDetalle);

    const urlDetalle = `/visibility2/portal/informes/descargar_excel_detalle.php?${paramsDetalle.toString()}`;

    esperarDescargaReal(tokenDetalle, function () {
      const paramsEncuesta = new URLSearchParams(params.toString());
      const tokenEncuesta = generarTokenDescarga();

      paramsEncuesta.set('fotos_encuesta', fotosEncuesta);
      paramsEncuesta.set('download_token', tokenEncuesta);

      const urlEncuesta = `/visibility2/portal/informes/descargar_encuesta_csv.php?${paramsEncuesta.toString()}`;

      esperarDescargaReal(tokenEncuesta, function () {
        ocultarOverlayDescarga();
      });

      descargarEnIframe(urlEncuesta, 'downloadFrameEncuesta');
    });

    descargarEnIframe(urlDetalle, 'downloadFrameDetalle');
    return;
  }

  ocultarOverlayDescarga();
});
</script>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const configs = [
    { inputId: 'searchInput',    cardSel: '.campaign-item',     titleSel: '.campaign-name'     },
    { inputId: 'searchInput-ipt', cardSel: '.campaign-item-ipt', titleSel: '.campaign-name-ipt' }
  ];

  configs.forEach(({inputId, cardSel, titleSel}) => {
    const input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('input', () => {
      const filter = input.value.trim().toUpperCase();
      document.querySelectorAll(cardSel).forEach(card => {
        // 1) Filtrado normal
        const titleEl = card.querySelector(titleSel);
        const matches = titleEl && titleEl.textContent.trim().toUpperCase().includes(filter);
        card.style.display = matches ? '' : 'none';

        // 2) Checkbox dentro de la tarjeta
        const chk = card.querySelector("input[type='checkbox']");
        if (chk) {
          if (!matches) {
            chk.checked = false;   // desmarcarlo
            chk.disabled = true;   // y deshabilitarlo
          } else {
            chk.disabled = false;  // volver a habilitar
          }
        }
      });
    });
  });
});

</script>

<script>
function descargarEnIframe(url) {
  let iframe = document.getElementById('downloadFrame');

  if (!iframe) {
    iframe = document.createElement('iframe');
    iframe.id = 'downloadFrame';
    iframe.style.display = 'none';
    document.body.appendChild(iframe);
  }

  iframe.src = url;
}

function generarTokenDescarga() {
  return 'dl_' + Date.now() + '_' + Math.random().toString(36).slice(2);
}

function obtenerCookie(nombre) {
  const valor = `; ${document.cookie}`;
  const partes = valor.split(`; ${nombre}=`);
  if (partes.length === 2) {
    return partes.pop().split(';').shift();
  }
  return null;
}

function limpiarCookie(nombre) {
  document.cookie = `${nombre}=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
}

function esperarDescargaReal(token, onComplete, timeoutMs = 120000) {
  const cookieName = 'fileDownloadToken';
  const start = Date.now();

  const timer = setInterval(() => {
    const valor = obtenerCookie(cookieName);

    if (valor === token) {
      clearInterval(timer);
      limpiarCookie(cookieName);
      if (typeof onComplete === 'function') {
        onComplete();
      }
      return;
    }

    if (Date.now() - start > timeoutMs) {
      clearInterval(timer);
      if (typeof onComplete === 'function') {
        onComplete();
      }
    }
  }, 500);
}

$(document).on('click', '.download-distribucion-trigger, .download-legacy-trigger', function(e) {
  e.preventDefault();

  const id = $(this).data('id');
  const modalidad = $(this).data('modalidad') || '';
  const token = generarTokenDescarga();

  let url = '';

  if ($(this).hasClass('download-distribucion-trigger')) {
    url = '/visibility2/portal/informes/descargar_distribucion.php?id='
      + encodeURIComponent(id)
      + '&modalidad=' + encodeURIComponent(modalidad)
      + '&download_token=' + encodeURIComponent(token);
  } else if ($(this).hasClass('download-legacy-trigger')) {
    url = '/visibility2/portal/informes/descargar_excel_legacy.php?id='
      + encodeURIComponent(id)
      + '&modalidad=' + encodeURIComponent(modalidad)
      + '&download_token=' + encodeURIComponent(token);
  }

  if (!url) return;

  mostrarOverlayDescarga();

  esperarDescargaReal(token, function () {
    ocultarOverlayDescarga();
  });

  descargarEnIframe(url);
});
</script>

<script>
$(document).on('click', '.js-open-viewer-modal', function (e) {
  e.preventDefault();

  const url = $(this).attr('href');
  const title = $(this).data('viewer-title') || $(this).attr('title') || 'Vista integrada';
  const subtitle = $(this).data('viewer-subtitle') || 'Vista integrada del contenido';

  if (!url || url === '#') {
    return;
  }

  $('#modalReportTitle').text(title);
  $('.modern-report-subtitle').text(subtitle);
  $('#modalReportFrame').attr('src', url);
  $('#modalReportViewer').modal('show');
});

$('#modalReportViewer').on('hidden.bs.modal', function () {
  $('#modalReportFrame').attr('src', '');
});
</script>

<?php if ($tiene_estatus_vehiculo): ?>
<script>
/* ── Informe Estatus Vehículo — lógica del modal (dashboard) ── */
(function () {
  var EV_FERIADOS  = <?= json_encode($feriados ?? []) ?>;
  var EV_DAY_SHORT = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
  var EV_MONTHS    = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

  function evFmtShort(iso) {
    var d = new Date(iso + 'T12:00:00');
    return d.getDate() + '/' + EV_MONTHS[d.getMonth()];
  }

  function evGetModo() {
    var r = document.querySelector('input[name="evModo"]:checked');
    return r ? r.value : 'clasico';
  }

  function evConsecutiveBlocks(sorted) {
    if (!sorted.length) return [];
    var blocks = [], cur = [sorted[0]];
    for (var i = 1; i < sorted.length; i++) {
      var prev = new Date(sorted[i-1]+'T12:00:00');
      var next = new Date(sorted[i]+'T12:00:00');
      var diff = (next - prev) / 86400000;
      if (diff <= 3) { cur.push(sorted[i]); }
      else { blocks.push(cur); cur = [sorted[i]]; }
    }
    blocks.push(cur);
    return blocks;
  }

  function evCalcExpected(card) {
    var checked = Array.from(card.querySelectorAll('.ev-day-check:checked'))
                       .map(function(c){ return c.dataset.date; }).sort();
    if (!checked.length) return [];
    var modo = evGetModo();
    var result = [], seen = {};
    function push(date, tipo) {
      var k = date+'|'+tipo;
      if (!seen[k]) { seen[k] = true; result.push({date: date, tipo: tipo}); }
    }
    if (modo === 'diario') {
      checked.forEach(function(date) { push(date, 'inicio'); push(date, 'termino'); });
    } else {
      var blocks = evConsecutiveBlocks(checked);
      blocks.forEach(function(block) {
        push(block[0], 'inicio');
        push(block[block.length-1], 'termino');
      });
    }
    return result;
  }

  function evUpdatePreview(card) {
    var preview  = card.querySelector('.ev-week-preview');
    var expected = evCalcExpected(card);
    var modo     = evGetModo();
    if (!expected.length) {
      preview.innerHTML = '<span class="text-danger">⚠ Sin días hábiles — no aplica</span>';
    } else if (modo === 'diario') {
      var dias = expected.filter(function(e){ return e.tipo === 'inicio'; });
      var lbl  = dias.map(function(e){
        var d = new Date(e.date+'T12:00:00');
        return '<strong>'+EV_DAY_SHORT[d.getDay()]+' '+evFmtShort(e.date)+'</strong>';
      }).join(' · ');
      preview.innerHTML = '<span style="color:#217346">→ '+lbl+' — 2 subidas c/día = <strong>'+expected.length+'</strong> total</span>';
    } else {
      var TIPO_LBL = {inicio:'mañana', termino:'tarde'};
      var parts = expected.map(function(e){
        var d = new Date(e.date+'T12:00:00');
        return '<strong>'+EV_DAY_SHORT[d.getDay()]+' '+evFmtShort(e.date)+'</strong> '+TIPO_LBL[e.tipo];
      });
      preview.innerHTML = '<span style="color:#217346">→ '+parts.join(' &nbsp;·&nbsp; ')+'</span>';
    }
    evUpdateTotal();
  }

  function evUpdateTotal() {
    var all = Array.from(document.querySelectorAll('#evWeekGrid .ev-week-card'));
    var total = 0;
    all.forEach(function(card) { total += evCalcExpected(card).length; });
    document.getElementById('evTotalCount').textContent = total;
    var alert = document.getElementById('evTotalAlert');
    alert.style.display = total > 0 ? '' : 'none';
    document.getElementById('evSubmitBtn').disabled = (total === 0);
  }

  function evIsoWeek(d) {
    var tmp = new Date(d.getTime());
    tmp.setHours(12,0,0,0);
    tmp.setDate(tmp.getDate() + 4 - (tmp.getDay()||7));
    var jan1 = new Date(tmp.getFullYear(),0,1);
    return tmp.getFullYear() + '-W' + String(Math.ceil(((tmp-jan1)/86400000+1)/7)).padStart(2,'0');
  }

  function evBuildGrid() {
    var desde = document.getElementById('evFechaDesde').value;
    var hasta = document.getElementById('evFechaHasta').value;
    var grid  = document.getElementById('evWeekGrid');
    grid.innerHTML = '';
    if (!desde || !hasta || desde > hasta) { evUpdateTotal(); return; }

    var weeks = {}, weekOrder = [];
    var cur = new Date(desde+'T12:00:00');
    var end = new Date(hasta+'T12:00:00');
    while (cur <= end) {
      var dow = cur.getDay();
      if (dow >= 1 && dow <= 5) {
        var iso = cur.toISOString().slice(0,10);
        var wk  = evIsoWeek(cur);
        if (!weeks[wk]) { weeks[wk] = []; weekOrder.push(wk); }
        weeks[wk].push({ iso: iso, dow: dow, esFeriado: EV_FERIADOS.indexOf(iso) >= 0 });
      }
      cur.setDate(cur.getDate()+1);
    }

    weekOrder.forEach(function(wk) {
      var days = weeks[wk];
      var col  = document.createElement('div');
      col.className = 'col-12 col-sm-6 col-lg-4 mb-2';

      var card = document.createElement('div');
      card.className = 'ev-week-card';

      var header = document.createElement('div');
      header.className = 'ev-week-header font-weight-bold';
      header.textContent = 'Semana ' + wk;
      card.appendChild(header);

      var daysDiv = document.createElement('div');
      daysDiv.className = 'ev-week-days';

      days.forEach(function(day) {
        var lbl = document.createElement('label');
        lbl.className = 'ev-day-label' + (day.esFeriado ? ' feriado' : ' active');
        lbl.title = day.esFeriado ? 'Feriado' : day.iso;

        var chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.className = 'ev-day-check';
        chk.dataset.date = day.iso;
        if (!day.esFeriado) chk.checked = true;

        var dname = document.createElement('span');
        dname.className = 'ev-day-name';
        dname.textContent = EV_DAY_SHORT[day.dow];

        var dnum = document.createElement('span');
        dnum.className = 'ev-day-num';
        dnum.textContent = parseInt(day.iso.slice(8), 10);

        lbl.appendChild(chk);
        lbl.appendChild(dname);
        lbl.appendChild(dnum);

        chk.addEventListener('change', function() {
          lbl.classList.toggle('active', this.checked);
          evUpdatePreview(card);
        });
        daysDiv.appendChild(lbl);
      });
      card.appendChild(daysDiv);

      var preview = document.createElement('div');
      preview.className = 'ev-week-preview';
      card.appendChild(preview);
      col.appendChild(card);
      grid.appendChild(col);
      evUpdatePreview(card);
    });
    evUpdateTotal();
  }

  document.getElementById('evFechaDesde').addEventListener('change', evBuildGrid);
  document.getElementById('evFechaHasta').addEventListener('change', evBuildGrid);

  document.querySelectorAll('input[name="evModo"]').forEach(function(r) {
    r.addEventListener('change', function() {
      document.querySelectorAll('#evWeekGrid .ev-week-card').forEach(function(card) {
        evUpdatePreview(card);
      });
    });
  });

  document.getElementById('evReportForm').addEventListener('submit', function(e) {
    var allExpected = [];
    document.querySelectorAll('#evWeekGrid .ev-week-card').forEach(function(card) {
      evCalcExpected(card).forEach(function(entry) { allExpected.push(entry); });
    });
    if (!allExpected.length) { e.preventDefault(); return; }
    document.getElementById('evInputStart').value    = document.getElementById('evFechaDesde').value;
    document.getElementById('evInputEnd').value      = document.getElementById('evFechaHasta').value;
    document.getElementById('evInputExpected').value = JSON.stringify(allExpected);
    document.getElementById('evInputModo').value     = evGetModo();
  });

  $('#modalEstatusVehiculo').on('show.bs.modal', function() {
    document.getElementById('evWeekGrid').innerHTML = '';
    document.getElementById('evTotalAlert').style.display = 'none';
    document.getElementById('evSubmitBtn').disabled = true;
    document.getElementById('evFechaDesde').value = '';
    document.getElementById('evFechaHasta').value = '';
    document.getElementById('evModoClasico').checked = true;
  });
})();
</script>
<?php endif; ?>

</body>
</html>