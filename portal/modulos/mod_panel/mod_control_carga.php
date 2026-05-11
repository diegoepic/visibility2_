<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$nombre      = $_SESSION['usuario_nombre'] ?? '';
$apellido    = $_SESSION['usuario_apellido'] ?? '';
$id_division = (int)($_SESSION['division_id'] ?? 0);
$id_empresa  = (int)($_SESSION['empresa_id'] ?? 0);

/* ======================================================
   FILTROS
====================================================== */
$division_usuario = isset($_GET['division_usuario'])
    ? (int)$_GET['division_usuario']
    : $id_division;
    
$subdivision_usuario = isset($_GET['subdivision_usuario'])
    ? (int)$_GET['subdivision_usuario']
    : 0;

$clasificacion_usuario = $_GET['clasificacion_usuario'] ?? 'todos';
// valores esperados: todos, interno, externo

$estado_gestion = $_GET['estado_gestion'] ?? 'todos';
// valores esperados: todos, activa, finalizada

$formulario_estado = $_GET['formulario_estado'] ?? 'activos';
// valores esperados: todos, activos, inactivos

$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

/* ======================================================
   UTILIDADES
====================================================== */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function diasCobertura($ultimaFecha) {
    if (empty($ultimaFecha)) {
        return null;
    }

    $hoy = new DateTime(date('Y-m-d'));
    $fin = new DateTime(date('Y-m-d', strtotime($ultimaFecha)));

    $diff = (int)$hoy->diff($fin)->format('%r%a');

    // Si la fecha aún no vence, contamos también el día actual.
    // Ejemplo: si vence hoy => 1 día de cobertura.
    if ($diff >= 0) {
        return $diff + 1;
    }

    // Si ya venció, mantenemos negativo.
    return $diff;
}

function badgeCobertura($ultimaFecha) {
    $dias = diasCobertura($ultimaFecha);

    if ($dias === null) {
        return '<span class="badge badge-secondary">Sin planificación</span>';
    }

    if ($dias < 0) {
        return '<span class="badge badge-danger">Vencida</span>';
    }

    if ($dias <= 3) {
        return '<span class="badge badge-warning">Crítica</span>';
    }

    if ($dias <= 7) {
        return '<span class="badge badge-info">Baja cobertura</span>';
    }

    return '<span class="badge badge-success">Con cobertura</span>';
}

/* ======================================================
   CARGAR DIVISIONES DE USUARIOS
====================================================== */
$sqlDiv = "
    SELECT DISTINCT d.id, d.nombre
    FROM division_empresa d
    INNER JOIN usuario u 
        ON u.id_division = d.id
    WHERE d.estado = 1
      AND u.id_perfil = 3
      AND u.id_empresa = ?
      AND u.activo = 1
    ORDER BY d.nombre
";

$stmtDiv = $conn->prepare($sqlDiv);
$stmtDiv->bind_param("i", $id_empresa);
$stmtDiv->execute();
$resDiv = $stmtDiv->get_result();

$divisiones = [];
while ($row = $resDiv->fetch_assoc()) {
    $divisiones[] = $row;
}
$stmtDiv->close();

/* ======================================================
   EXPRESIONES DE ESTADO
====================================================== */
$fechaValida = "
    fq.fechaPropuesta IS NOT NULL
    AND CAST(fq.fechaPropuesta AS CHAR(19)) <> '0000-00-00 00:00:00'
    AND CAST(fq.fechaPropuesta AS CHAR(10)) <> '0000-00-00'
";

$gestionFinalizada = "
    (
        COALESCE(fq.countVisita, 0) > 0
        OR fq.pregunta IN (
            'solo_auditoria',
            'solo_implementado',
            'implementado_auditado',
            'completado'
        )
    )
";

$gestionActiva = "
    (
        fq.id IS NOT NULL
        AND NOT $gestionFinalizada
    )
";

$joinFormularioEstado = "";

if ($formulario_estado === 'activos') {
    $joinFormularioEstado = " AND f.estado = 1 ";
} elseif ($formulario_estado === 'inactivos') {
    $joinFormularioEstado = " AND f.estado <> 1 ";
}

/* ======================================================
   QUERY PRINCIPAL
   IMPORTANTE:
   - Filtramos por división del usuario.
   - No filtramos por división del formulario.
   - Así un usuario CPW muestra formularios de otras divisiones.
====================================================== */
$sql = "
    SELECT
        u.id AS id_ejecutor,
        UPPER(u.nombre) AS nombre,
        UPPER(u.apellido) AS apellido,
        UPPER(u.usuario) AS usuario,
        COALESCE(u.clasificacion_usuario, 'sin_clasificacion') AS clasificacion_usuario,

        du.nombre AS division_usuario,
        COALESCE(su.nombre, '') AS subdivision_usuario,

        COUNT(DISTINCT CASE 
            WHEN fq.id IS NOT NULL
             AND f.id IS NOT NULL
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_planificado,

        COUNT(DISTINCT CASE 
            WHEN $gestionActiva
             AND f.id IS NOT NULL
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_activo,

        COUNT(DISTINCT CASE 
            WHEN $gestionFinalizada
             AND f.id IS NOT NULL
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_finalizado,

        COUNT(DISTINCT CASE 
            WHEN $gestionActiva
             AND f.id IS NOT NULL
             AND DATE(fq.fechaPropuesta) < CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_vencido,
        
        COUNT(DISTINCT CASE 
            WHEN $gestionActiva
             AND f.id IS NOT NULL
             AND DATE(fq.fechaPropuesta) = CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_hoy,
        
        COUNT(DISTINCT CASE 
            WHEN $gestionActiva
             AND f.id IS NOT NULL
             AND DATE(fq.fechaPropuesta) > CURDATE()
            THEN CONCAT(fq.id_local, '-', fq.id_formulario)
        END) AS total_futuro,
        
        COUNT(DISTINCT CASE 
            WHEN $gestionActiva
             AND f.id IS NOT NULL
            THEN f.id
        END) AS total_formularios,

        MAX(CASE 
            WHEN fq.id IS NOT NULL
             AND f.id IS NOT NULL
            THEN DATE(fq.fechaPropuesta)
        END) AS ultima_fecha_propuesta,

        MIN(CASE 
            WHEN $gestionActiva
             AND f.id IS NOT NULL
            THEN DATE(fq.fechaPropuesta)
        END) AS primera_fecha_pendiente,

        GROUP_CONCAT(
            DISTINCT CASE
                WHEN f.id IS NOT NULL THEN CONCAT(
                    COALESCE(df.nombre, 'SIN DIVISIÓN FORM.'),
                    ' / ',
                    COALESCE(f.nombre, 'SIN FORMULARIO')
                )
            END
            ORDER BY df.nombre, f.nombre
            SEPARATOR ' | '
        ) AS formularios_asociados

    FROM usuario u

    LEFT JOIN division_empresa du
        ON du.id = u.id_division

    LEFT JOIN formularioQuestion fq
        ON fq.id_usuario = u.id
       AND $fechaValida
";

/* filtros que deben afectar la carga, pero sin sacar al usuario del universo */
$types = "";
$params = [];

if (!empty($fecha_desde)) {
    $sql .= " AND DATE(fq.fechaPropuesta) >= ? ";
    $types .= "s";
    $params[] = $fecha_desde;
}

if (!empty($fecha_hasta)) {
    $sql .= " AND DATE(fq.fechaPropuesta) <= ? ";
    $types .= "s";
    $params[] = $fecha_hasta;
}

if ($estado_gestion === 'activa') {
    $sql .= " AND NOT $gestionFinalizada ";
}

if ($estado_gestion === 'finalizada') {
    $sql .= " AND $gestionFinalizada ";
}

$sql .= "
    LEFT JOIN formulario f
        ON f.id = fq.id_formulario
       $joinFormularioEstado

    LEFT JOIN division_empresa df
        ON df.id = f.id_division

    LEFT JOIN subdivision su
        ON su.id = u.id_subdivision

    WHERE u.id_perfil = 3
      AND u.activo = 1
      AND u.id_empresa = ?
      AND u.id_division = ?
";

$types .= "ii";
$params[] = $id_empresa;
$params[] = $division_usuario;

if ($subdivision_usuario > 0) {
    $sql .= " AND u.id_subdivision = ? ";
    $types .= "i";
    $params[] = $subdivision_usuario;
}

if ($clasificacion_usuario === 'interno' || $clasificacion_usuario === 'externo') {
    $sql .= " AND u.clasificacion_usuario = ? ";
    $types .= "s";
    $params[] = $clasificacion_usuario;
}


$sql .= "
    GROUP BY 
        u.id,
        u.nombre,
        u.apellido,
        u.usuario,
        u.clasificacion_usuario,
        du.nombre,
        su.nombre

    ORDER BY 
        ultima_fecha_propuesta IS NULL ASC,
        ultima_fecha_propuesta ASC,
        u.nombre ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$trabajadores = [];
while ($row = $res->fetch_assoc()) {
    $trabajadores[] = $row;
}
$stmt->close();

/* ======================================================
   KPIs GENERALES
====================================================== */
$totalTrabajadores = count($trabajadores);
$totalPlanificado  = 0;
$totalActivo       = 0;
$totalFinalizado   = 0;
$totalVencido      = 0;
$totalHoy          = 0;
$totalFuturo       = 0;

$trabajadoresSinPlanificacion = 0;
$trabajadoresCriticos = 0;

foreach ($trabajadores as $t) {
    $totalPlanificado += (int)($t['total_planificado'] ?? 0);
    $totalActivo      += (int)($t['total_activo'] ?? 0);
    $totalFinalizado  += (int)($t['total_finalizado'] ?? 0);
    $totalVencido     += (int)($t['total_vencido'] ?? 0);
    $totalHoy         += (int)($t['total_hoy'] ?? 0);
    $totalFuturo      += (int)($t['total_futuro'] ?? 0);

    $dias = diasCobertura($t['ultima_fecha_propuesta'] ?? null);

    if ($dias === null) {
        $trabajadoresSinPlanificacion++;
    } elseif ($dias <= 3) {
        $trabajadoresCriticos++;
    }
}

/* ======================================================
   KPIs PARA TARJETA RESUMEN
====================================================== */
$totalGeneralKpi   = (int)$totalPlanificado;
$totalCompletadas  = (int)$totalFinalizado;
$totalPendientes   = (int)$totalActivo;

$porcCompletadas = $totalGeneralKpi > 0
    ? round(($totalCompletadas / $totalGeneralKpi) * 100)
    : 0;

$porcPendientes = $totalGeneralKpi > 0
    ? round(($totalPendientes / $totalGeneralKpi) * 100)
    : 0;


$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Carga Programada</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">

    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">

    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

    <style>
        body {
            background: #f3f6fb;
            font-size: 14px;
        }

/* ======================================================
   TOOLBAR SUPERIOR - PANEL CARGA PROGRAMADA
====================================================== */
.programada-toolbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
}

/* ======================================================
   TÍTULO IZQUIERDA
====================================================== */
.programada-heading {
    flex: 1 1 320px;
    min-width: 280px;
    padding-top: 4px;
}

.programada-heading-accent {
    display: inline-block;
    width: 62px;
    height: 4px;
    border-radius: 999px;
    background: linear-gradient(90deg, #37c871, #1f7aec);
    margin-bottom: 14px;
}

.programada-heading-content {
    display: flex;
    flex-direction: column;
}

.programada-title-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
}

.programada-title {
    font-size: 34px;
    font-weight: 800;
    line-height: 1.1;
    color: #1d2b45;
    margin: 0;
    letter-spacing: -0.4px;
}

.programada-title i {
    color: #1d2b45;
}

.programada-help {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #7f8da6;
    color: #fff;
    font-size: 13px;
    cursor: default;
}

.programada-subtitle {
    font-size: 14px;
    color: #6f7f96;
    font-weight: 500;
    max-width: 560px;
    line-height: 1.45;
}

/* ======================================================
   CAJA DE FILTROS DERECHA
====================================================== */
.programada-filters-box {
    flex: 1 1 880px;
    background: #ffffff;
    border: 1px solid #dde4ee;
    border-radius: 22px;
    box-shadow: 0 8px 22px rgba(17, 24, 39, 0.08);
    padding: 14px 18px;
    min-width: 300px;
}

.programada-filters-form {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}

.programada-field {
    display: flex;
    flex-direction: column;
    margin: 0;
}

.programada-field label {
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    color: #5d6b81;
    margin-bottom: 6px;
    letter-spacing: .4px;
    white-space: nowrap;
}

/* Tamaños */
.field-md {
    flex: 1 1 180px;
    min-width: 160px;
}

.field-sm {
    flex: 1 1 150px;
    min-width: 140px;
}

.field-date {
    flex: 0 0 135px;
    min-width: 125px;
}

.field-btn {
    flex: 0 0 110px;
    min-width: 100px;
}

/* Separador entre fechas */
.programada-separator {
    align-self: flex-end;
    margin-bottom: 11px;
    color: #9aa7ba;
    font-weight: 700;
    font-size: 18px;
    line-height: 1;
}

/* Inputs */
.programada-filters-box .form-control {
    height: 44px;
    border-radius: 10px;
    border: 1px solid #cfd8e3;
    background: #f8fafc;
    color: #24344d;
    font-size: 15px;
    font-weight: 500;
    padding: 10px 14px;
    box-shadow: none !important;
    transition: all .18s ease;
}

.programada-filters-box .form-control:focus {
    border-color: #8aa8d8;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(31, 122, 236, 0.08) !important;
}

/* Botón */
.btn-programada-filtrar {
    height: 44px;
    width: 100%;
    border: 0;
    border-radius: 10px;
    background: #0f1933;
    color: #fff;
    font-weight: 800;
    font-size: 15px;
    transition: all .18s ease;
    padding: 0 18px;
}

.btn-programada-filtrar:hover {
    background: #1a2646;
    color: #fff;
}

.btn-programada-filtrar:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(15, 25, 51, 0.18) !important;
}

/* ======================================================
   AJUSTE GENERAL DEL CONTENEDOR
====================================================== */
.card-panel {
    max-width: 96%;
    margin-top: 26px;
    margin-bottom: 40px;
}

/* ======================================================
   RESPONSIVE
====================================================== */
@media (max-width: 1400px) {
    .programada-title {
        font-size: 30px;
    }
}

@media (max-width: 1200px) {
    .programada-toolbar {
        flex-direction: column;
    }

    .programada-heading,
    .programada-filters-box {
        width: 100%;
        flex: 1 1 100%;
    }

    .programada-subtitle {
        max-width: 100%;
    }
}

@media (max-width: 768px) {
    .programada-title {
        font-size: 26px;
    }

    .programada-filters-form {
        gap: 10px;
    }

    .field-md,
    .field-sm,
    .field-date,
    .field-btn {
        flex: 1 1 100%;
        min-width: 100%;
    }

    .programada-separator {
        display: none;
    }
}


/* ======================================================
   KPI RESUMEN HORIZONTAL
====================================================== */
.kpi-resumen-card {
    background: #ffffff;
    border: 1px solid #dce4ee;
    border-radius: 18px;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
    padding: 16px 22px;
}

.kpi-resumen-wrap {
    display: flex;
    align-items: center;
    gap: 22px;
    flex-wrap: wrap;
}

/* Bloques KPI chicos */
.kpi-mini-box {
    min-width: 170px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.kpi-mini-destacado {
    background: #d9edf9;
    border: 1px solid #b9d9ee;
    border-radius: 14px;
    padding: 14px 22px;
    min-width: 160px;
}

.kpi-mini-label {
    font-size: 13px;
    font-weight: 800;
    color: #5d7895;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 6px;
    line-height: 1.2;
}

.kpi-mini-label.success {
    color: #12b76a;
}

.kpi-mini-label.warning {
    color: #f59e0b;
}

.kpi-mini-value {
    font-size: 34px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
}

/* Separadores */
.kpi-divider {
    width: 1px;
    height: 50px;
    background: #d7dde7;
    flex: 0 0 1px;
}

/* Zona barra progreso */
.kpi-progress-box {
    flex: 1 1 420px;
    min-width: 280px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.kpi-progress-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
    gap: 10px;
}

.progress-left-label {
    color: #12b76a;
    font-size: 14px;
    font-weight: 800;
}

.progress-right-label {
    color: #f59e0b;
    font-size: 14px;
    font-weight: 800;
    text-align: right;
}

/* Barra */
.kpi-progress-bar {
    width: 100%;
    height: 14px;
    background: #edf1f6;
    border-radius: 999px;
    overflow: hidden;
    display: flex;
}

.kpi-progress-done {
    height: 100%;
    background: #14b86f;
    border-radius: 999px 0 0 999px;
}

.kpi-progress-pending {
    height: 100%;
    background: #f2a311;
    border-radius: 0 999px 999px 0;
}

/* ======================================================
   RESPONSIVE
====================================================== */
@media (max-width: 1200px) {
    .kpi-resumen-wrap {
        gap: 16px;
    }

    .kpi-mini-box,
    .kpi-mini-destacado {
        min-width: 140px;
    }
}

@media (max-width: 992px) {
    .kpi-divider {
        display: none;
    }

    .kpi-resumen-wrap {
        align-items: flex-start;
    }

    .kpi-progress-box {
        width: 100%;
        flex: 1 1 100%;
    }
}

@media (max-width: 768px) {
    .kpi-resumen-card {
        padding: 16px;
    }

    .kpi-resumen-wrap {
        flex-direction: column;
        align-items: stretch;
    }

    .kpi-mini-box,
    .kpi-mini-destacado,
    .kpi-progress-box {
        width: 100%;
        min-width: 100%;
    }

    .kpi-mini-destacado {
        padding: 14px 18px;
    }

    .kpi-mini-value {
        font-size: 28px;
    }

    .kpi-progress-top {
        flex-direction: column;
        align-items: flex-start;
    }

    .progress-right-label {
        text-align: left;
    }
}

/* ======================================================
   TABLA ESTILO MONITOR / CAMPAÑAS
====================================================== */
.table-card {
    border: 1px solid #dce4ee;
    border-radius: 18px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
}

#tablaCargaProgramada {
    width: 100% !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
    margin-bottom: 0 !important;
}

/* Header */
#tablaCargaProgramada thead th {
    background: #f4f7fb;
    color: #61728d;
    font-size: 13px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .3px;
    padding: 18px 16px;
    border-bottom: 1px solid #d9e2ec !important;
    border-top: 0 !important;
    border-left: 0 !important;
    border-right: 0 !important;
    vertical-align: middle;
    white-space: nowrap;
}

/* Cuerpo */
#tablaCargaProgramada tbody td {
    padding: 20px 16px;
    border-top: 0 !important;
    border-left: 0 !important;
    border-right: 0 !important;
    border-bottom: 1px solid #e7edf4 !important;
    vertical-align: middle;
    background: #fff;
    color: #1f2937;
    font-size: 14px;
}

#tablaCargaProgramada tbody tr:last-child td {
    border-bottom: 0 !important;
}

#tablaCargaProgramada tbody tr:hover td {
    background: #fbfdff;
}

/* Primera columna */
.worker-main {
    font-size: 14px;
    font-weight: 800;
    color: #61728d;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.worker-sub {
    font-size: 13px;
    color: #1f2937;
    font-weight: 700;
    margin-bottom: 2px;
}

.worker-meta {
    font-size: 12px;
    color: #7b8798;
}

/* Badges tipo usuario */
.badge-tipo {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 74px;
    height: 28px;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    border: 1px solid transparent;
}

.badge-tipo.interno {
    background: #e9f3ff;
    color: #1877f2;
    border-color: #cfe3ff;
}

.badge-tipo.externo {
    background: #f3f4f6;
    color: #374151;
    border-color: #d6dbe1;
}

.badge-tipo.sin-clasificar {
    background: #f3f4f6;
    color: #6b7280;
    border-color: #d6dbe1;
}

/* Pastillas numéricas */
.metric-box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    height: 32px;
    padding: 0 12px;
    background: #eef2f6;
    border: 1px solid #d8e0ea;
    border-radius: 9px;
    color: #5c6f89;
    font-size: 16px;
    font-weight: 800;
    line-height: 1;
}

.metric-box.metric-success {
    background: #dff6e8;
    border-color: #bceaca;
    color: #15915f;
}

.metric-box.metric-warning {
    background: #fff2db;
    border-color: #f8d79c;
    color: #dd8a00;
}

.metric-box.metric-info {
    background: #ebe7ff;
    border-color: #d6ceff;
    color: #6b46e5;
}

/* Fechas */
.date-main {
    font-size: 14px;
    font-weight: 700;
    color: #1f2937;
}

.date-sub {
    font-size: 12px;
    color: #8a97a8;
    margin-top: 2px;
}

/* Días cobertura */
.coverage-box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 58px;
    height: 34px;
    padding: 0 12px;
    border-radius: 10px;
    background: #f3f6fa;
    border: 1px solid #dde5ee;
    font-weight: 800;
    color: #42526b;
}

/* Estado */
.status-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 88px;
    height: 42px;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 80%;
    font-weight: 800;
    border: 1px solid transparent;
}

.status-chip.ok {
    background: #dff6e8;
    color: #169460;
    border-color: #bceaca;
}

.status-chip.warning {
    background: #fff2db;
    color: #d98800;
    border-color: #f4d59d;
}

.status-chip.danger {
    background: #ffe2e2;
    color: #d92d20;
    border-color: #f4b6b6;
}

.status-chip.neutral {
    background: #eef2f6;
    color: #667085;
    border-color: #d7dee8;
}

/* Formularios asociados */
.form-list {
    max-width: 280px;
    font-size: 13px;
    color: #1f2937;
    line-height: 1.35;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Botones acciones */
.action-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-grid-action {
    min-width: 92px;
    height: 40px;
    padding: 0 16px;
    border-radius: 10px;
    border: 1px solid #d6dee8;
    background: #f8fafc;
    color: #2a3442;
    font-size: 14px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none !important;
    box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
    transition: all .18s ease;
}

.btn-grid-action:hover {
    background: #ffffff;
    color: #111827;
    border-color: #c8d2dd;
    transform: translateY(-1px);
}

.btn-grid-action.primary {
    background: #ffffff;
    color: #1f2937;
}

.btn-grid-action.secondary {
    background: #f8fafc;
    color: #64748b;
}

/* DataTables compatibilidad */
.dataTables_wrapper .dataTables_filter input,
.dataTables_wrapper .dataTables_length select {
    border: 1px solid #d7e0ea;
    border-radius: 10px;
    padding: 6px 10px;
    background: #fff;
}

.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    padding: 14px 16px;
}

.dataTables_wrapper .paginate_button {
    border-radius: 8px !important;
}

/* Responsive */
@media (max-width: 1200px) {
    .form-list {
        max-width: 220px;
    }
}

@media (max-width: 992px) {
    #tablaCargaProgramada thead th,
    #tablaCargaProgramada tbody td {
        padding: 14px 12px;
    }

    .btn-grid-action {
        min-width: 78px;
        height: 36px;
        padding: 0 12px;
        font-size: 13px;
    }
}

/* ======================================================
   META DATOS DENTRO DE TRABAJADOR
====================================================== */
.worker-meta-stack {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.mini-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 24px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
    border: 1px solid transparent;
}

.mini-pill.interno {
    background: #e9f3ff;
    color: #1877f2;
    border-color: #cfe3ff;
}

.mini-pill.externo {
    background: #f3f4f6;
    color: #374151;
    border-color: #d6dbe1;
}

.mini-pill.sin-clasificar {
    background: #f3f4f6;
    color: #6b7280;
    border-color: #d6dbe1;
}

.mini-pill-soft {
    background: #f8fafc;
    color: #607089;
    border-color: #d9e2ec;
}

/* ======================================================
   ODÓMETRO
====================================================== */
.odo-gauge {
    --angle: 0deg;
    position: relative;
    width: 145px;
    height: 88px;
    margin: 0 auto;
}

.odo-arc-wrap {
    position: absolute;
    left: 50%;
    top: 4px;
    transform: translateX(-50%);
    width: 118px;
    height: 60px;
    overflow: hidden;
}

.odo-arc {
    position: absolute;
    left: 0;
    top: -58px;
    width: 118px;
    height: 118px;
    border-radius: 50%;
    background:
        conic-gradient(
            from 180deg,
            #ef4444 0deg 38deg,
            #f59e0b 38deg 82deg,
            #eab308 82deg 122deg,
            #10b981 122deg 180deg,
            #e5e7eb 180deg 360deg
        );
}

.odo-arc::after {
    content: "";
    position: absolute;
    inset: 12px;
    border-radius: 50%;
    background: #ffffff;
}

.odo-needle-wrap {
    position: absolute;
    left: 50%;
    bottom: 26px;
    width: 44px;
    height: 3px;
    transform-origin: 0 50%;
    transform: rotate(var(--angle));
    z-index: 4;
}

.odo-needle {
    display: block;
    width: 44px;
    height: 3px;
    background: #1f2937;
    border-radius: 999px;
}

.odo-center-dot {
    position: absolute;
    left: calc(50% - 6px);
    bottom: 21px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #111827;
    border: 2px solid #d8dee9;
    z-index: 5;
}

.odo-value {
    position: absolute;
    left: 0;
    right: 0;
    bottom: -2px;
    text-align: center;
    font-size: 16px;
    font-weight: 800;
    color: #111827;
    line-height: 1;
}

.odo-min,
.odo-max {
    position: absolute;
    bottom: 3px;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
}

.odo-min {
    left: 7px;
    color: #ef4444;
}

.odo-max {
    right: 4px;
    color: #10b981;
}

/* ======================================================
   GAUGE / ODÓMETRO SVG
====================================================== */
.gauge-box {
    width: 145px;
    height: 96px;
    margin: 0 auto;
    position: relative;
}

.gauge-svg {
    width: 145px;
    height: 92px;
    display: block;
    overflow: visible;
}

.gauge-track,
.gauge-segment {
    fill: none;
    stroke-width: 12;
}

.gauge-track {
    stroke: #e7ebf1;
    stroke-linecap: round;
}

.gauge-segment {
    stroke-linecap: butt;
}

.gauge-red {
    stroke: #ef4444;
}

.gauge-orange {
    stroke: #f59e0b;
}

.gauge-yellow {
    stroke: #eab308;
}

.gauge-green {
    stroke: #10b981;
}

.gauge-needle {
    stroke: #111827;
    stroke-width: 3.5;
    stroke-linecap: round;
    filter: drop-shadow(0 1px 1px rgba(0,0,0,.15));
}

.gauge-center-outer {
    fill: #e9eef5;
    stroke: #cfd8e3;
    stroke-width: 1;
}

.gauge-center-inner {
    fill: #111827;
}

.gauge-label {
    font-size: 11px;
    font-weight: 800;
    dominant-baseline: middle;
}

.gauge-label-min {
    fill: #ef4444;
    text-anchor: middle;
}

.gauge-label-max {
    fill: #10b981;
    text-anchor: middle;
}

.gauge-value {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 6px;
    text-align: center;
    font-size: 17px;
    font-weight: 900;
    color: #0f172a;
    line-height: 1;
}

/* ======================================================
   MODAL RESUMEN CARGA
====================================================== */
.modal-resumen-carga {
    border: 0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .25);
}

.modal-resumen-carga .modal-header {
    background: #f8fafc;
    border-bottom: 1px solid #dce4ee;
    padding: 18px 22px;
}

.modal-resumen-carga .modal-title {
    font-size: 20px;
    font-weight: 800;
    color: #16243d;
}

.modal-subtitle {
    font-size: 13px;
    color: #667085;
    margin-top: 4px;
    font-weight: 600;
}

.modal-resumen-carga .modal-body {
    padding: 22px;
    background: #ffffff;
}

.resumen-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 130px;
    font-size: 15px;
    font-weight: 700;
    color: #5f6f89;
}

.resumen-kpi-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 12px;
}

.resumen-kpi {
    background: #f8fafc;
    border: 1px solid #dce4ee;
    border-radius: 14px;
    padding: 14px 16px;
}

.resumen-kpi span {
    display: block;
    font-size: 12px;
    font-weight: 800;
    color: #667085;
    text-transform: uppercase;
    letter-spacing: .3px;
    margin-bottom: 6px;
}

.resumen-kpi strong {
    display: block;
    font-size: 22px;
    font-weight: 900;
    color: #101828;
}

.table-resumen-formularios thead th {
    background: #f1f5f9;
    color: #607089;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    border-bottom: 1px solid #dce4ee;
    padding: 13px 12px;
    white-space: nowrap;
}

.table-resumen-formularios tbody td {
    padding: 13px 12px;
    vertical-align: middle;
    border-top: 1px solid #edf2f7;
    font-size: 13px;
}

.resumen-form-title {
    font-weight: 800;
    color: #1f2937;
    line-height: 1.25;
}

.resumen-form-sub {
    font-size: 12px;
    color: #7b8798;
    margin-top: 2px;
}

.resumen-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 28px;
    padding: 0 10px;
    border-radius: 9px;
    background: #eef2f6;
    border: 1px solid #d8e0ea;
    color: #5c6f89;
    font-weight: 800;
}

.resumen-pill.warning {
    background: #fff2db;
    border-color: #f8d79c;
    color: #dd8a00;
}

.resumen-pill.danger {
    background: #ffe2e2;
    border-color: #f4b6b6;
    color: #d92d20;
}

.resumen-pill.success {
    background: #dff6e8;
    border-color: #bceaca;
    color: #15915f;
}

@media (max-width: 992px) {
    .resumen-kpi-row {
        grid-template-columns: repeat(2, minmax(150px, 1fr));
    }
}

@media (max-width: 576px) {
    .resumen-kpi-row {
        grid-template-columns: 1fr;
    }
}

/* ======================================================
   MODAL DETALLE CARGA POR FECHA
====================================================== */
.modal-detalle-dialog {
    max-width: 96vw;
}

.modal-detalle-carga {
    border: 0;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .25);
}

.modal-detalle-carga .modal-header {
    background: #f8fafc;
    border-bottom: 1px solid #dce4ee;
    padding: 18px 22px;
}

.modal-detalle-carga .modal-title {
    font-size: 20px;
    font-weight: 800;
    color: #16243d;
}

.modal-detalle-carga .modal-body {
    padding: 22px;
    background: #ffffff;
}

.detalle-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 140px;
    font-size: 15px;
    font-weight: 700;
    color: #5f6f89;
}

.detalle-kpi-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 12px;
}

.detalle-kpi {
    background: #f8fafc;
    border: 1px solid #dce4ee;
    border-radius: 14px;
    padding: 14px 16px;
}

.detalle-kpi span {
    display: block;
    font-size: 12px;
    font-weight: 800;
    color: #667085;
    text-transform: uppercase;
    letter-spacing: .3px;
    margin-bottom: 6px;
}

.detalle-kpi strong {
    display: block;
    font-size: 20px;
    font-weight: 900;
    color: #101828;
}

.detalle-scroll-wrapper {
    width: 100%;
    max-height: 62vh;
    overflow: auto;
    border: 1px solid #dce4ee;
    border-radius: 14px;
}

.table-detalle-fechas {
    min-width: 1100px;
    border-collapse: separate;
    border-spacing: 0;
}

.table-detalle-fechas thead th {
    position: sticky;
    top: 0;
    z-index: 5;
    background: #f1f5f9;
    color: #607089;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    border-bottom: 1px solid #dce4ee;
    padding: 12px 10px;
    white-space: nowrap;
}

.table-detalle-fechas tbody td {
    padding: 12px 10px;
    vertical-align: middle;
    border-top: 1px solid #edf2f7;
    font-size: 13px;
    background: #fff;
}

.table-detalle-fechas tbody tr:hover td {
    background: #fbfdff;
}

.detalle-sticky-formulario {
    position: sticky;
    left: 0;
    z-index: 4;
    min-width: 280px;
    max-width: 320px;
    background: #fff !important;
    box-shadow: 8px 0 12px rgba(15, 23, 42, .04);
}

.detalle-sticky-division {
    position: sticky;
    left: 280px;
    z-index: 4;
    min-width: 150px;
    background: #fff !important;
    box-shadow: 8px 0 12px rgba(15, 23, 42, .03);
}

.table-detalle-fechas thead .detalle-sticky-formulario,
.table-detalle-fechas thead .detalle-sticky-division {
    background: #f1f5f9 !important;
    z-index: 8;
}

.detalle-form-title {
    font-weight: 800;
    color: #1f2937;
    line-height: 1.25;
}

.detalle-form-sub {
    font-size: 12px;
    color: #7b8798;
    margin-top: 2px;
}

.detalle-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 28px;
    padding: 0 9px;
    border-radius: 9px;
    background: #eef2f6;
    border: 1px solid #d8e0ea;
    color: #5c6f89;
    font-weight: 800;
}

.detalle-pill.has-value {
    background: #dff6e8;
    border-color: #bceaca;
    color: #15915f;
}

.detalle-pill.total {
    background: #ebe7ff;
    border-color: #d6ceff;
    color: #6b46e5;
}

.detalle-total-row td {
    background: #f8fafc !important;
    font-weight: 800;
}

@media (max-width: 992px) {
    .detalle-kpi-row {
        grid-template-columns: repeat(2, minmax(150px, 1fr));
    }
}

@media (max-width: 576px) {
    .detalle-kpi-row {
        grid-template-columns: 1fr;
    }
}

/* ======================================================
   FECHA HEADER CON DÍA
====================================================== */
.fecha-head {
    display: flex;
    flex-direction: column;
    align-items: center;
    line-height: 1.15;
}

.fecha-head-top {
    font-size: 15px;
    font-weight: 800;
    color: #4b6488;
}

.fecha-head-day {
    font-size: 11px;
    font-weight: 700;
    color: #7b8798;
    margin-top: 3px;
    text-transform: capitalize;
}

/* ======================================================
   KPI CON CÍRCULO %
====================================================== */
.detalle-kpi-main-with-circle {
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    gap: 12px;
}

.detalle-mini-circle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 900;
    border: 2px solid #d8e0ea;
    background: #f8fafc;
    color: #5c6f89;
    flex: 0 0 38px;
    line-height: 1;
}

/* =========================================
   CELDA DE DÍA CON MINI %
========================================= */
.detalle-day-cell {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    min-height: 34px;
}

.detalle-percent-corner {
    position: absolute;
    top: -8px;
    right: -10px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 8px;
    font-weight: 900;
    line-height: 1;
    border: 1px solid #d8e0ea;
    background: #f8fafc;
    color: #5c6f89;
    box-shadow: 0 1px 3px rgba(0,0,0,.08);
    z-index: 2;
}

/* ======================================================
   TOTAL + % POR FILA
====================================================== */
.detalle-total-cell {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.detalle-percent-mini {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 900;
    border: 1px solid #d8e0ea;
    background: #f8fafc;
    color: #5c6f89;
    flex: 0 0 32px;
}

/* Colores según porcentaje */
.pct-low {
    background: #ffe2e2;
    border-color: #f4b6b6;
    color: #d92d20;
}

.pct-mid {
    background: #fff2db;
    border-color: #f8d79c;
    color: #dd8a00;
}

.pct-high {
    background: #dff6e8;
    border-color: #bceaca;
    color: #15915f;
}
        .worker-name {
            font-weight: 700;
            color: #111827;
        }

        .worker-sub {
            font-size: 12px;
            color: #6b7280;
        }

        .metric-pill {
            display: inline-block;
            min-width: 42px;
            padding: 4px 9px;
            border-radius: 999px;
            background: #eef2ff;
            font-weight: 700;
            text-align: center;
        }

        .text-small-muted {
            font-size: 12px;
            color: #6b7280;
        }

        .formulario-preview {
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        .btn-filtrar {
            border-radius: 12px;
            font-weight: 700;
        }

        .badge {
            padding: 7px 10px;
            border-radius: 999px;
        }
    </style>
</head>

<body>

<div class="container-fluid card-panel">

<div class="programada-toolbar mb-4">
    
    <div class="programada-heading">
        <span class="programada-heading-accent"></span>
        <div class="programada-heading-content">
            <div class="programada-title-row">
                <h1 class="programada-title">
                    <i class="fas fa-calendar-check mr-2"></i>
                    Panel de Carga Programada
                </h1>
                <span class="programada-help" title="Visualiza la carga programada por trabajador">
                    <i class="fas fa-question-circle"></i>
                </span>
            </div>
            <p class="programada-subtitle mb-0">
                Bienvenido, <?= h($nombre . ' ' . $apellido); ?>.
                Visualiza hasta qué fecha tiene planificación cada trabajador.
            </p>
        </div>
    </div>

    <div class="programada-filters-box">
        <form method="GET" class="programada-filters-form">

            <div class="programada-field field-md">
                <label>División</label>
                <select name="division_usuario" class="form-control">
                    <?php foreach ($divisiones as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= ((int)$d['id'] === $division_usuario ? 'selected' : '') ?>>
                            <?= h($d['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="programada-field field-md">
                <label>Subdivisión</label>
                <select name="subdivision_usuario" id="subdivision_usuario" class="form-control">
                    <option value="0">Todas</option>
                </select>
            </div>

            <div class="programada-field field-sm">
                <label>Tipo usuario</label>
                <select name="clasificacion_usuario" class="form-control">
                    <option value="todos" <?= $clasificacion_usuario === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="interno" <?= $clasificacion_usuario === 'interno' ? 'selected' : '' ?>>Interno</option>
                    <option value="externo" <?= $clasificacion_usuario === 'externo' ? 'selected' : '' ?>>Externo</option>
                </select>
            </div>

            <div class="programada-field field-sm">
                <label>Estado formulario</label>
                <select name="formulario_estado" class="form-control">
                    <option value="todos" <?= $formulario_estado === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="activos" <?= $formulario_estado === 'activos' ? 'selected' : '' ?>>Activos</option>
                    <option value="inactivos" <?= $formulario_estado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
                </select>
            </div>

            <div class="programada-field field-date">
                <label>Inicio</label>
                <input type="date" name="fecha_desde" class="form-control" value="<?= h($fecha_desde) ?>">
            </div>

            <div class="programada-separator">-</div>

            <div class="programada-field field-date">
                <label>Fin</label>
                <input type="date" name="fecha_hasta" class="form-control" value="<?= h($fecha_hasta) ?>">
            </div>

            <div class="programada-field field-btn">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-programada-filtrar">
                    Filtrar
                </button>
            </div>

        </form>
    </div>
</div>

<div class="kpi-resumen-card mb-4">
    <div class="kpi-resumen-wrap">

        <!-- KPI 1 -->
        <div class="kpi-mini-box kpi-mini-destacado">
            <div class="kpi-mini-label">TOTAL PLANIFICADO</div>
            <div class="kpi-mini-value"><?= $totalGeneralKpi ?></div>
        </div>

        <div class="kpi-divider"></div>

        <!-- KPI 2 -->
        <div class="kpi-mini-box">
            <div class="kpi-mini-label success">
                <i class="fas fa-check mr-1"></i> FINALIZADAS
            </div>
            <div class="kpi-mini-value"><?= $totalCompletadas ?></div>
        </div>

        <div class="kpi-divider"></div>

        <!-- KPI 3 -->
        <div class="kpi-mini-box">
            <div class="kpi-mini-label warning">
                <i class="far fa-clock mr-1"></i> ACTIVAS
            </div>
            <div class="kpi-mini-value"><?= $totalPendientes ?></div>
        </div>

        <!-- KPI progreso -->
        <div class="kpi-progress-box">
            <div class="kpi-progress-top">
                <span class="progress-left-label"><?= $porcCompletadas ?>% Finalizadas</span>
                <span class="progress-right-label"><?= $porcPendientes ?>% Activas</span>
            </div>

            <div class="kpi-progress-bar">
                <div class="kpi-progress-done" style="width: <?= $porcCompletadas ?>%;"></div>
                <div class="kpi-progress-pending" style="width: <?= $porcPendientes ?>%;"></div>
            </div>
        </div>

    </div>
</div>

    <div class="card table-card">
        <div class="card-body">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 class="mb-1">
                        <i class="fas fa-list"></i>
                        Resumen por trabajador
                    </h5>
                    <div class="text-small-muted">
                        La última fecha propuesta se calcula desde <strong>la ultima planificacion asignada</strong>.
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table id="tablaCargaProgramada" class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Trabajador</th>
                            <th class="text-center">Avance</th>
                            <th class="text-center">Planificado</th>
                            <th class="text-center">Activas</th>
                            <th class="text-center">Finalizadas</th>
                            <th class="text-center">Formularios</th>
                            <th>Primera pendiente</th>
                            <th>Última planificación</th>
                            <th class="text-center">Días cobertura</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
<?php foreach ($trabajadores as $t): ?>
    <?php
        $ultima = $t['ultima_fecha_propuesta'] ?? null;
        $primeraPendiente = $t['primera_fecha_pendiente'] ?? null;
        $dias = diasCobertura($ultima);

        /* ======================================================
           ESTADO DE COBERTURA
        ====================================================== */
        $claseEstado = 'neutral';
        $textoEstado = 'Sin planificación';

        if (!empty($ultima)) {
            if ($dias < 0) {
                $claseEstado = 'danger';
                $textoEstado = 'Vencida';
            } elseif ($dias <= 3) {
                $claseEstado = 'warning';
                $textoEstado = 'Crítica';
            } elseif ($dias <= 7) {
                $claseEstado = 'warning';
                $textoEstado = 'Baja cobertura';
            } else {
                $claseEstado = 'ok';
                $textoEstado = 'Con cobertura';
            }
        }

        /* ======================================================
           CLASIFICACIÓN USUARIO
        ====================================================== */
        $clasificacionUsuario = strtolower(trim($t['clasificacion_usuario'] ?? ''));

        $tipoClase = 'sin-clasificar';
        $tipoTexto = 'Sin clasificar';

        if ($clasificacionUsuario === 'interno') {
            $tipoClase = 'interno';
            $tipoTexto = 'Interno';
        } elseif ($clasificacionUsuario === 'externo') {
            $tipoClase = 'externo';
            $tipoTexto = 'Externo';
        }

        /* ======================================================
           PORCENTAJE FINALIZADO PARA ODÓMETRO
        ====================================================== */
        $totalPlanificadoTrabajador = (int)($t['total_planificado'] ?? 0);
        $totalFinalizadoTrabajador  = (int)($t['total_finalizado'] ?? 0);

        $porcentajeFinalizado = $totalPlanificadoTrabajador > 0
            ? round(($totalFinalizadoTrabajador / $totalPlanificadoTrabajador) * 100)
            : 0;

        $porcentajeFinalizado = max(0, min(100, $porcentajeFinalizado));

        // 0% apunta izquierda, 50% arriba, 100% derecha
        $angulo = -180 + ($porcentajeFinalizado * 1.8);

        $subdivisionTexto = !empty($t['subdivision_usuario'])
            ? $t['subdivision_usuario']
            : 'Sin subdivisión';
    ?>

    <tr>

        <!-- Trabajador -->
        <td>
            <div class="worker-main">
                <?= h($t['nombre'] . ' ' . $t['apellido']) ?>
            </div>

            <div class="worker-sub">
                <?= h($t['usuario']) ?>
            </div>

            <div class="worker-meta-stack mt-1">
                <span class="mini-pill <?= h($tipoClase) ?>">
                    <?= h($tipoTexto) ?>
                </span>

                <span class="mini-pill mini-pill-soft">
                    <?= h($subdivisionTexto) ?>
                </span>
            </div>
        </td>

        <!-- Avance / Odómetro -->
        <td class="text-center" data-order="<?= (int)$porcentajeFinalizado ?>">
            <div class="gauge-box">

                <svg class="gauge-svg" viewBox="0 0 140 92" aria-hidden="true">

                    <!-- Base gris -->
                    <path 
                        class="gauge-track"
                        d="M 20 70 A 50 50 0 0 1 120 70"
                        pathLength="100"
                    />

                    <!-- Segmento rojo -->
                    <path 
                        class="gauge-segment gauge-red"
                        d="M 20 70 A 50 50 0 0 1 120 70"
                        pathLength="100"
                        stroke-dasharray="22 100"
                        stroke-dashoffset="0"
                    />

                    <!-- Segmento naranja -->
                    <path 
                        class="gauge-segment gauge-orange"
                        d="M 20 70 A 50 50 0 0 1 120 70"
                        pathLength="100"
                        stroke-dasharray="28 100"
                        stroke-dashoffset="-22"
                    />

                    <!-- Segmento amarillo -->
                    <path 
                        class="gauge-segment gauge-yellow"
                        d="M 20 70 A 50 50 0 0 1 120 70"
                        pathLength="100"
                        stroke-dasharray="25 100"
                        stroke-dashoffset="-50"
                    />

                    <!-- Segmento verde -->
                    <path 
                        class="gauge-segment gauge-green"
                        d="M 20 70 A 50 50 0 0 1 120 70"
                        pathLength="100"
                        stroke-dasharray="25 100"
                        stroke-dashoffset="-75"
                    />

                    <!-- Aguja -->
                    <line 
                        class="gauge-needle"
                        x1="70"
                        y1="70"
                        x2="114"
                        y2="70"
                        transform="rotate(<?= $angulo ?> 70 70)"
                    />

                    <!-- Centro -->
                    <circle class="gauge-center-outer" cx="70" cy="70" r="7" />
                    <circle class="gauge-center-inner" cx="70" cy="70" r="3.5" />

                    <!-- Etiquetas -->
                    <text class="gauge-label gauge-label-min" x="20" y="88">0%</text>
                    <text class="gauge-label gauge-label-max" x="120" y="88">100%</text>

                </svg>

                <div class="gauge-value">
                    <?= (int)$porcentajeFinalizado ?>%
                </div>

            </div>
        </td>

        <!-- Planificado -->
        <td class="text-center">
            <span class="metric-box">
                <?= (int)($t['total_planificado'] ?? 0) ?>
            </span>
        </td>

        <!-- Activas -->
        <td class="text-center">
            <span class="metric-box metric-warning">
                <?= (int)($t['total_activo'] ?? 0) ?>
            </span>
        </td>

        <!-- Finalizadas -->
        <td class="text-center">
            <span class="metric-box metric-success">
                <?= (int)($t['total_finalizado'] ?? 0) ?>
            </span>
        </td>

        <!-- Formularios -->
        <td class="text-center">
            <span class="metric-box metric-info">
                <?= (int)($t['total_formularios'] ?? 0) ?>
            </span>
        </td>

        <!-- Primera pendiente -->
        <td>
            <div class="date-main">
                <?= $primeraPendiente ? date('d/m/Y', strtotime($primeraPendiente)) : '-' ?>
            </div>
            <div class="date-sub">Primera fecha pendiente</div>
        </td>

        <!-- Última planificación -->
        <td>
            <div class="date-main">
                <?= $ultima ? date('d/m/Y', strtotime($ultima)) : '-' ?>
            </div>
            <div class="date-sub">Cobertura máxima</div>
        </td>

        <!-- Días cobertura -->
        <td class="text-center">
            <span class="coverage-box">
                <?= $dias === null ? '-' : (int)$dias ?>
            </span>
        </td>

        <!-- Estado -->
        <td class="text-center">
            <span class="status-chip <?= h($claseEstado) ?>">
                <?= h($textoEstado) ?>
            </span>
        </td>

        <!-- Acciones -->
        <td class="text-center">
            <div class="action-wrap justify-content-center">
                <button type="button"
                        class="btn-grid-action secondary btn-ver-resumen"
                        data-id-ejecutor="<?= (int)$t['id_ejecutor'] ?>"
                        data-nombre="<?= h($t['nombre'] . ' ' . $t['apellido']) ?>"
                        data-usuario="<?= h($t['usuario']) ?>">
                    Resumen
                </button>

                <button type="button"
                        class="btn-grid-action primary btn-ver-detalle"
                        data-id-ejecutor="<?= (int)$t['id_ejecutor'] ?>"
                        data-nombre="<?= h($t['nombre'] . ' ' . $t['apellido']) ?>"
                        data-usuario="<?= h($t['usuario']) ?>">
                    Detalle
                </button>
            </div>
        </td>

    </tr>
<?php endforeach; ?>
                    </tbody>

                </table>
            </div>

        </div>
    </div>

</div>

<!-- MODALES-->

<div class="modal fade" id="modalResumenCarga" tabindex="-1" role="dialog" aria-labelledby="modalResumenCargaLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content modal-resumen-carga">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="modalResumenCargaLabel">
                        <i class="fas fa-list-alt mr-2"></i>
                        Resumen de carga activa
                    </h5>
                    <div class="modal-subtitle" id="modalResumenTrabajador">
                        -
                    </div>
                </div>

                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">

                <div id="modalResumenLoading" class="resumen-loading">
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    Cargando resumen...
                </div>

                <div id="modalResumenError" class="alert alert-warning d-none mb-0">
                    No fue posible cargar el resumen del trabajador.
                </div>

                <div id="modalResumenContenido" class="d-none">

                    <div class="resumen-kpi-row mb-3">
                        <div class="resumen-kpi">
                            <span>Total formularios activos</span>
                            <strong id="resumenTotalFormularios">0</strong>
                        </div>

                        <div class="resumen-kpi">
                            <span>Total pendiente</span>
                            <strong id="resumenTotalPendiente">0</strong>
                        </div>

                        <div class="resumen-kpi">
                            <span>Primera pendiente</span>
                            <strong id="resumenPrimeraPendiente">-</strong>
                        </div>

                        <div class="resumen-kpi">
                            <span>Última planificación</span>
                            <strong id="resumenUltimaPlanificacion">-</strong>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-resumen-formularios mb-0">
                            <thead>
                                <tr>
                                    <th>Formulario</th>
                                    <th>División</th>
                                    <th class="text-center">Pendientes</th>
                                    <th class="text-center">Vencidas</th>
                                    <th class="text-center">Hoy</th>
                                    <th class="text-center">Futuras</th>
                                    <th>Primera pendiente</th>
                                    <th>Última planificación</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyResumenFormularios">
                            </tbody>
                        </table>
                    </div>

                </div>

            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="modalDetalleCarga" tabindex="-1" role="dialog" aria-labelledby="modalDetalleCargaLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-detalle-dialog" role="document">
        <div class="modal-content modal-detalle-carga">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="modalDetalleCargaLabel">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        Detalle de planificación por fecha
                    </h5>
                    <div class="modal-subtitle" id="modalDetalleTrabajador">
                        -
                    </div>
                </div>

                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">

                <div id="modalDetalleLoading" class="detalle-loading">
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    Cargando detalle...
                </div>

                <div id="modalDetalleError" class="alert alert-warning d-none mb-0">
                    No fue posible cargar el detalle del trabajador.
                </div>

                <div id="modalDetalleContenido" class="d-none">

                    <div class="detalle-kpi-row mb-3">
                        <div class="detalle-kpi">
                            <span>Total formularios</span>
                            <strong id="detalleTotalFormularios">0</strong>
                        </div>

                        <div class="detalle-kpi">
                            <span>Total planificado</span>
                            <div class="detalle-kpi-main-with-circle">
                                <strong id="detalleTotalPlanificado">0</strong>
                                <span id="detallePctFinalizado" class="detalle-mini-circle">0%</span>
                            </div>
                        </div>

                        <div class="detalle-kpi">
                            <span>Total fechas</span>
                            <strong id="detalleTotalFechas">0</strong>
                        </div>

                        <div class="detalle-kpi">
                            <span>Rango</span>
                            <strong id="detalleRangoFechas">-</strong>
                        </div>
                    </div>

                    <div class="detalle-scroll-wrapper">
                        <table class="table table-sm table-detalle-fechas mb-0">
                            <thead id="theadDetalleFechas"></thead>
                            <tbody id="tbodyDetalleFechas"></tbody>
                        </table>
                    </div>

                </div>

            </div>

        </div>
    </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {

    function cargarSubdivisionesUsuario(idDivision, selected = 0) {
        if (!idDivision) {
            $('#subdivision_usuario').html('<option value="0">Todas</option>');
            return;
        }

        $.ajax({
            url: 'ajax_subdivisiones.php',
            type: 'GET',
            dataType: 'json',
            data: { division: idDivision },
            success: function(response) {
                let options = '<option value="0">Todas</option>';

                response.forEach(function(sub) {
                    let selectedAttr = parseInt(sub.id) === parseInt(selected) ? 'selected' : '';
                    options += `<option value="${sub.id}" ${selectedAttr}>${sub.nombre}</option>`;
                });

                $('#subdivision_usuario').html(options);
            },
            error: function() {
                $('#subdivision_usuario').html('<option value="0">Todas</option>');
            }
        });
    }

    $('[name="division_usuario"]').on('change', function() {
        let divisionId = $(this).val();
        cargarSubdivisionesUsuario(divisionId, 0);
    });

    let divisionInicial = $('[name="division_usuario"]').val();
    let subdivisionInicial = <?= (int)$subdivision_usuario ?>;

    if (divisionInicial) {
        cargarSubdivisionesUsuario(divisionInicial, subdivisionInicial);
    }

});
</script>

<script>
$(document).ready(function () {

    $.fn.dataTable.ext.errMode = 'none';

    const tablaSelector = '#tablaCargaProgramada';
    const $tabla = $(tablaSelector);

    if (!$tabla.length || !$.fn.DataTable) {
        return;
    }

    if ($.fn.DataTable.isDataTable(tablaSelector)) {
        $tabla.DataTable().destroy();
    }

    function extraerTexto(data) {
        return $('<div>').html(data).text().trim();
    }

    function extraerNumero(data) {
        const texto = extraerTexto(data);
        const match = texto.match(/-?\d+/);
        return match ? parseInt(match[0], 10) : 0;
    }

    function extraerFecha(data) {
        const texto = extraerTexto(data);
        const match = texto.match(/(\d{2})\/(\d{2})\/(\d{4})/);

        if (!match) return '99999999';

        return match[3] + match[2] + match[1];
    }

    $tabla.DataTable({
        pageLength: 25,
        lengthMenu: [
            [10, 25, 50, 100, -1],
            [10, 25, 50, 100, 'Todos']
        ],
        order: [[7, 'asc']],
        autoWidth: false,
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        },
        columnDefs: [
            {
                targets: [1, 2, 3, 4, 5, 8],
                render: function (data, type, row, meta) {
                    if (type === 'sort' || type === 'type') {
                        const td = meta.settings.aoData[meta.row].anCells[meta.col];
                        const orderValue = $(td).attr('data-order');

                        if (orderValue !== undefined) {
                            return parseInt(orderValue, 10);
                        }

                        return extraerNumero(data);
                    }

                    return data;
                }
            },
            {
                targets: [6, 7],
                render: function (data, type) {
                    if (type === 'sort' || type === 'type') {
                        return extraerFecha(data);
                    }

                    return data;
                }
            },
            {
                orderable: false,
                searchable: false,
                targets: [10]
            },
            {
                className: 'text-center',
                targets: [1, 2, 3, 4, 5, 8, 9, 10]
            }
        ]
    });

});
</script>

<script>
$(document).ready(function () {

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatFecha(value) {
        if (!value) return '-';

        const parts = String(value).split('-');
        if (parts.length !== 3) return escapeHtml(value);

        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function resetModalResumen() {
        $('#modalResumenLoading').removeClass('d-none');
        $('#modalResumenError').addClass('d-none');
        $('#modalResumenContenido').addClass('d-none');

        $('#tbodyResumenFormularios').html('');
        $('#resumenTotalFormularios').text('0');
        $('#resumenTotalPendiente').text('0');
        $('#resumenPrimeraPendiente').text('-');
        $('#resumenUltimaPlanificacion').text('-');
    }

    function renderResumen(response) {
        const rows = response.data || [];

        $('#resumenTotalFormularios').text(response.totales.total_formularios || 0);
        $('#resumenTotalPendiente').text(response.totales.total_pendiente || 0);
        $('#resumenPrimeraPendiente').text(formatFecha(response.totales.primera_pendiente));
        $('#resumenUltimaPlanificacion').text(formatFecha(response.totales.ultima_planificacion));

        if (!rows.length) {
            $('#tbodyResumenFormularios').html(`
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        No se encontraron formularios activos para este trabajador con los filtros seleccionados.
                    </td>
                </tr>
            `);
            return;
        }

        let html = '';

        rows.forEach(function (item) {
            html += `
                <tr>
                    <td>
                        <div class="resumen-form-title">
                            ${escapeHtml(item.formulario)}
                        </div>
                        <div class="resumen-form-sub">
                            ID formulario: ${escapeHtml(item.id_formulario)}
                        </div>
                    </td>

                    <td>
                        ${escapeHtml(item.division_formulario || '-')}
                        ${item.subdivision_formulario ? `<div class="resumen-form-sub">${escapeHtml(item.subdivision_formulario)}</div>` : ''}
                    </td>

                    <td class="text-center">
                        <span class="resumen-pill warning">${escapeHtml(item.total_pendiente)}</span>
                    </td>

                    <td class="text-center">
                        <span class="resumen-pill danger">${escapeHtml(item.total_vencido)}</span>
                    </td>

                    <td class="text-center">
                        <span class="resumen-pill">${escapeHtml(item.total_hoy)}</span>
                    </td>

                    <td class="text-center">
                        <span class="resumen-pill success">${escapeHtml(item.total_futuro)}</span>
                    </td>

                    <td>
                        <strong>${formatFecha(item.primera_pendiente)}</strong>
                    </td>

                    <td>
                        <strong>${formatFecha(item.ultima_planificacion)}</strong>
                    </td>
                </tr>
            `;
        });

        $('#tbodyResumenFormularios').html(html);
    }

    $(document).on('click', '.btn-ver-resumen', function () {
        const idEjecutor = $(this).data('id-ejecutor');
        const nombre = $(this).data('nombre') || '';
        const usuario = $(this).data('usuario') || '';

        resetModalResumen();

        $('#modalResumenTrabajador').text(nombre + ' / ' + usuario);
        $('#modalResumenCarga').modal('show');

        $.ajax({
            url: 'ajax_carga_programada_resumen.php',
            type: 'GET',
            dataType: 'json',
            data: {
                id_ejecutor: idEjecutor,
                division_usuario: '<?= (int)$division_usuario ?>',
                subdivision_usuario: '<?= (int)$subdivision_usuario ?>',
                clasificacion_usuario: '<?= h($clasificacion_usuario) ?>',
                estado_gestion: 'activa',
                formulario_estado: '<?= h($formulario_estado) ?>',
                fecha_desde: '<?= h($fecha_desde) ?>',
                fecha_hasta: '<?= h($fecha_hasta) ?>'
            },
            success: function (response) {
                $('#modalResumenLoading').addClass('d-none');

                if (!response || response.ok !== true) {
                    $('#modalResumenError').removeClass('d-none');
                    return;
                }

                renderResumen(response);
                $('#modalResumenContenido').removeClass('d-none');
            },
            error: function () {
                $('#modalResumenLoading').addClass('d-none');
                $('#modalResumenError').removeClass('d-none');
            }
        });
    });

});
</script>

<script>
$(document).ready(function () {

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatFecha(value) {
        if (!value) return '-';

        const parts = String(value).split('-');
        if (parts.length !== 3) return escapeHtml(value);

        return parts[2] + '/' + parts[1];
    }

    function formatFechaCompleta(value) {
        if (!value) return '-';

        const parts = String(value).split('-');
        if (parts.length !== 3) return escapeHtml(value);

        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

function resetModalDetalle() {
    $('#modalDetalleLoading').removeClass('d-none');
    $('#modalDetalleError').addClass('d-none');
    $('#modalDetalleContenido').addClass('d-none');

    $('#theadDetalleFechas').html('');
    $('#tbodyDetalleFechas').html('');

    $('#detalleTotalFormularios').text('0');
    $('#detalleTotalPlanificado').text('0');
    $('#detalleTotalFechas').text('0');
    $('#detalleRangoFechas').text('-');

    $('#detallePctFinalizado')
        .removeClass('pct-low pct-mid pct-high')
        .addClass('detalle-mini-circle')
        .text('0%');
}
    
    function nombreDia(fechaStr) {
        if (!fechaStr) return '';
    
        const partes = String(fechaStr).split('-');
        if (partes.length !== 3) return '';
    
        const anio = parseInt(partes[0], 10);
        const mes = parseInt(partes[1], 10) - 1;
        const dia = parseInt(partes[2], 10);
    
        const fecha = new Date(anio, mes, dia);
        const dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    
        return dias[fecha.getDay()];
    }
    
    function formatFechaHeader(value) {
        if (!value) return '-';
    
        const parts = String(value).split('-');
        if (parts.length !== 3) return value;
    
        return `
            <div class="fecha-head">
                <div class="fecha-head-top">${parts[2]}/${parts[1]}</div>
                <div class="fecha-head-day">${nombreDia(value)}</div>
            </div>
        `;
    }
    
    function getPctClass(pct) {
        pct = Number(pct) || 0;
    
        if (pct < 40) return 'pct-low';
        if (pct < 80) return 'pct-mid';
        return 'pct-high';
    }

function renderDetalle(response) { 
    const fechas = response.fechas || [];
    const rows = response.data || [];
    const totalesPorFecha = response.totales_por_fecha || {};
    const porcentajeGlobalPorFecha = response.porcentaje_global_por_fecha || {};
    const totales = response.totales || {};
    const pctGlobal = Number(totales.porcentaje_finalizado || 0);

    $('#detalleTotalFormularios').text(totales.total_formularios || 0);
    $('#detalleTotalPlanificado').text(totales.total_planificado || 0);
    $('#detalleTotalFechas').text(fechas.length || 0);

    $('#detallePctFinalizado')
        .removeClass('pct-low pct-mid pct-high')
        .addClass('detalle-mini-circle ' + getPctClass(pctGlobal))
        .text(pctGlobal + '%');

    if (fechas.length > 0) {
        $('#detalleRangoFechas').text(
            formatFechaCompleta(fechas[0]) + ' - ' + formatFechaCompleta(fechas[fechas.length - 1])
        );
    }

    if (!rows.length || !fechas.length) {
        $('#theadDetalleFechas').html('');
        $('#tbodyDetalleFechas').html(`
            <tr>
                <td class="text-center text-muted py-4">
                    No se encontraron planificaciones por fecha para este trabajador.
                </td>
            </tr>
        `);
        return;
    }

    let thead = `
        <tr>
            <th class="detalle-sticky-formulario">Formulario</th>
            <th class="detalle-sticky-division">División</th>
            <th class="text-center">Total</th>
    `;

    fechas.forEach(function (fecha) {
        thead += `<th class="text-center">${formatFechaHeader(fecha)}</th>`;
    });

    thead += `</tr>`;
    $('#theadDetalleFechas').html(thead);

    let tbody = '';

    rows.forEach(function (item) {
        const pct = Number(item.porcentaje_finalizado || 0);

        tbody += `
            <tr>
                <td class="detalle-sticky-formulario">
                    <div class="detalle-form-title">
                        ${escapeHtml(item.formulario)}
                    </div>
                    <div class="detalle-form-sub">
                        ID formulario: ${escapeHtml(item.id_formulario)}
                    </div>
                </td>

                <td class="detalle-sticky-division">
                    ${escapeHtml(item.division_formulario || '-')}
                    ${item.subdivision_formulario ? `<div class="detalle-form-sub">${escapeHtml(item.subdivision_formulario)}</div>` : ''}
                </td>

                <td class="text-center">
                    <div class="detalle-total-cell">
                        <span class="detalle-pill total">${escapeHtml(item.total)}</span>
                        <span class="detalle-percent-mini ${getPctClass(pct)}">${pct}%</span>
                    </div>
                </td>
        `;

        fechas.forEach(function (fecha) {
            const valor = item.fechas && item.fechas[fecha] ? item.fechas[fecha] : 0;
            const pctFecha = item.porcentaje_por_fecha && item.porcentaje_por_fecha[fecha]
                ? item.porcentaje_por_fecha[fecha]
                : 0;
        
            const clase = valor > 0 ? 'has-value' : '';
        
            tbody += `
                <td class="text-center">
                    <div class="detalle-day-cell">
                        <span class="detalle-pill ${clase}">${valor}</span>
                        ${valor > 0 ? `<span class="detalle-percent-corner ${getPctClass(pctFecha)}">${pctFecha}%</span>` : ''}
                    </div>
                </td>
            `;
        });

        tbody += `</tr>`;
    });

    tbody += `
        <tr class="detalle-total-row">
            <td class="detalle-sticky-formulario">
                TOTAL
            </td>
            <td class="detalle-sticky-division">
                -
            </td>
            <td class="text-center">
                <div class="detalle-total-cell">
                    <span class="detalle-pill total">${escapeHtml(totales.total_planificado || 0)}</span>
                    <span class="detalle-percent-mini ${getPctClass(pctGlobal)}">${pctGlobal}%</span>
                </div>
            </td>
    `;

        fechas.forEach(function (fecha) {
            const valor = totalesPorFecha[fecha] || 0;
            const pctFecha = porcentajeGlobalPorFecha[fecha] || 0;
            const clase = valor > 0 ? 'has-value' : '';
        
            tbody += `
                <td class="text-center">
                    <div class="detalle-day-cell">
                        <span class="detalle-pill ${clase}">${valor}</span>
                        ${valor > 0 ? `<span class="detalle-percent-corner ${getPctClass(pctFecha)}">${pctFecha}%</span>` : ''}
                    </div>
                </td>
            `;
        });

    tbody += `</tr>`;

    $('#tbodyDetalleFechas').html(tbody);
}

    $(document).on('click', '.btn-ver-detalle', function () {
        const idEjecutor = $(this).data('id-ejecutor');
        const nombre = $(this).data('nombre') || '';
        const usuario = $(this).data('usuario') || '';

        resetModalDetalle();

        $('#modalDetalleTrabajador').text(nombre + ' / ' + usuario);
        $('#modalDetalleCarga').modal('show');

        $.ajax({
            url: 'ajax_carga_programada_detalle.php',
            type: 'GET',
            dataType: 'json',
            data: {
                id_ejecutor: idEjecutor,
                division_usuario: '<?= (int)$division_usuario ?>',
                subdivision_usuario: '<?= (int)$subdivision_usuario ?>',
                clasificacion_usuario: '<?= h($clasificacion_usuario) ?>',
                estado_gestion: '<?= h($estado_gestion) ?>',
                formulario_estado: '<?= h($formulario_estado) ?>',
                fecha_desde: '<?= h($fecha_desde) ?>',
                fecha_hasta: '<?= h($fecha_hasta) ?>'
            },
            success: function (response) {
                $('#modalDetalleLoading').addClass('d-none');

                if (!response || response.ok !== true) {
                    $('#modalDetalleError').removeClass('d-none');
                    return;
                }

                renderDetalle(response);
                $('#modalDetalleContenido').removeClass('d-none');
            },
            error: function () {
                $('#modalDetalleLoading').addClass('d-none');
                $('#modalDetalleError').removeClass('d-none');
            }
        });
    });

});
</script>

</body>
</html>