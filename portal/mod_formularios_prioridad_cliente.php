<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Santiago');

$guard = __DIR__ . '/_session_guard.php';
if (is_file($guard)) {
    require_once $guard;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

mysqli_set_charset($conn, 'utf8mb4');

const DIVISION_MC_ID = 1;

function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function bindParams(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }

    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = &$params[$key];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function fetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error preparando consulta: ' . $conn->error);
    }

    bindParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error ejecutando consulta: ' . $error);
    }

    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $rows = fetchAll(
        $conn,
        "SELECT COUNT(*) AS total
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?",
        'ss',
        [$table, $column]
    );

    return (int)($rows[0]['total'] ?? 0) > 0;
}

function updateFormularioCliente(mysqli $conn, int $formularioId, string $prioridad, int $solicitaFinalizacion, string $motivo, int $usuarioId, int $divisionSesion): void {
    $permitidas = ['ALTO', 'MEDIO', 'BAJO'];
    $prioridad = strtoupper(trim($prioridad));

    if (!in_array($prioridad, $permitidas, true)) {
        throw new Exception('Prioridad no valida.');
    }

    $motivo = trim($motivo);
    if (mb_strlen($motivo, 'UTF-8') > 255) {
        $motivo = mb_substr($motivo, 0, 255, 'UTF-8');
    }

    $params = [$prioridad, $solicitaFinalizacion, $motivo, $usuarioId, $formularioId];
    $types = 'sisii';
    $whereDivision = '';

    if ($divisionSesion !== DIVISION_MC_ID) {
        $whereDivision = ' AND id_division = ?';
        $params[] = $divisionSesion;
        $types .= 'i';
    }

    $sql = "
        UPDATE formulario
        SET prioridad_cliente = ?,
            solicita_finalizacion = ?,
            motivo_finalizacion_cliente = ?,
            updated_prioridad_cliente_by = ?,
            updated_prioridad_cliente_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
          AND deleted_at IS NULL
          AND tipo = 1
          {$whereDivision}
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Error preparando actualizacion: ' . $conn->error);
    }

    bindParams($stmt, $types, $params);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new Exception('Error actualizando formulario: ' . $error);
    }

    if ($stmt->affected_rows < 1) {
        $stmt->close();
        throw new Exception('No se actualizo el formulario. Verifica permisos o division.');
    }

    $stmt->close();
}

function prioridadClass(string $prioridad): string {
    switch ($prioridad) {
        case 'ALTO': return 'priority-high';
        case 'BAJO': return 'priority-low';
        default: return 'priority-medium';
    }
}

function estadoTexto($estado): string {
    switch ((string)$estado) {
        case '1': return 'EN CURSO';
        case '2': return 'EN PROCESO';
        case '3': return 'FINALIZADA';
        case '4': return 'CANCELADA';
        default: return 'ESTADO ' . (string)$estado;
    }
}

$divisionSesion = (int)($_SESSION['division_id'] ?? 0);
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$esMC = $divisionSesion === DIVISION_MC_ID;
$mensaje = '';
$error = '';

$requiredColumns = [
    'prioridad_cliente',
    'solicita_finalizacion',
    'motivo_finalizacion_cliente',
    'updated_prioridad_cliente_at',
    'updated_prioridad_cliente_by',
];

$missingColumns = [];
try {
    foreach ($requiredColumns as $column) {
        if (!columnExists($conn, 'formulario', $column)) {
            $missingColumns[] = $column;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_cliente') {
        if (!empty($missingColumns)) {
            throw new Exception('Faltan columnas en formulario. Ejecuta sql_formulario_prioridad_cliente.sql antes de guardar.');
        }

        updateFormularioCliente(
            $conn,
            (int)($_POST['formulario_id'] ?? 0),
            (string)($_POST['prioridad_cliente'] ?? 'MEDIO'),
            isset($_POST['solicita_finalizacion']) ? 1 : 0,
            (string)($_POST['motivo_finalizacion_cliente'] ?? ''),
            $usuarioId,
            $divisionSesion
        );

        $mensaje = 'Cambios guardados correctamente.';
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

$divisionFiltro = $esMC ? (int)($_GET['division'] ?? 0) : $divisionSesion;
if (!$esMC && $divisionFiltro <= 0) {
    $divisionFiltro = -1;
}
$busqueda = trim((string)($_GET['q'] ?? ''));
$prioridadFiltro = strtoupper(trim((string)($_GET['prioridad'] ?? '')));
$finalizacionFiltro = trim((string)($_GET['finalizacion'] ?? ''));
$tradeFiltro = (int)($_GET['trade'] ?? 0);

$divisiones = [];
$trades = [];
$formularios = [];

try {
    $divisiones = fetchAll(
        $conn,
        "SELECT id, nombre
         FROM division_empresa
         WHERE estado = 1
         ORDER BY nombre ASC"
    );

    $tradeWhere = "WHERE f.deleted_at IS NULL
                   AND f.estado IN ('1','2')
                   AND f.tipo = 1
                   AND f.id_trade IS NOT NULL
                   AND f.id_trade > 0";
    $tradeParams = [];
    $tradeTypes = '';

    if (!$esMC || $divisionFiltro > 0) {
        $tradeWhere .= " AND f.id_division = ?";
        $tradeParams[] = $divisionFiltro;
        $tradeTypes .= 'i';
    }

    $trades = fetchAll(
        $conn,
        "SELECT DISTINCT
             f.id_trade,
             COALESCE(NULLIF(TRIM(t.nombre), ''), CONCAT('TRADE ', f.id_trade)) AS nombre_trade
         FROM formulario f
         LEFT JOIN trade t ON t.id = f.id_trade
         {$tradeWhere}
         ORDER BY nombre_trade ASC",
        $tradeTypes,
        $tradeParams
    );

    $selectPriority = empty($missingColumns)
        ? "COALESCE(f.prioridad_cliente, 'MEDIO') AS prioridad_cliente,
           COALESCE(f.solicita_finalizacion, 0) AS solicita_finalizacion,
           COALESCE(f.motivo_finalizacion_cliente, '') AS motivo_finalizacion_cliente,
           f.updated_prioridad_cliente_at,
           u2.usuario AS actualizado_por"
        : "'MEDIO' AS prioridad_cliente,
           0 AS solicita_finalizacion,
           '' AS motivo_finalizacion_cliente,
           NULL AS updated_prioridad_cliente_at,
           NULL AS actualizado_por";

    $joinUser = empty($missingColumns)
        ? "LEFT JOIN usuario u2 ON u2.id = f.updated_prioridad_cliente_by"
        : "";
    $orderPriority = empty($missingColumns)
        ? "FIELD(COALESCE(f.prioridad_cliente, 'MEDIO'), 'ALTO', 'MEDIO', 'BAJO')"
        : "2";

    $params = [];
    $types = '';
    $where = "WHERE f.deleted_at IS NULL
              AND f.estado IN ('1','2')
              AND f.tipo = 1";

    if (!$esMC || $divisionFiltro > 0) {
        $where .= " AND f.id_division = ?";
        $params[] = $divisionFiltro;
        $types .= 'i';
    }

    if ($busqueda !== '') {
        $where .= " AND (f.nombre LIKE ? OR CAST(f.id AS CHAR) LIKE ?)";
        $like = '%' . $busqueda . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    if ($tradeFiltro > 0) {
        $where .= " AND f.id_trade = ?";
        $params[] = $tradeFiltro;
        $types .= 'i';
    }

    if (empty($missingColumns) && in_array($prioridadFiltro, ['ALTO','MEDIO','BAJO'], true)) {
        $where .= " AND COALESCE(f.prioridad_cliente, 'MEDIO') = ?";
        $params[] = $prioridadFiltro;
        $types .= 's';
    }

    if (empty($missingColumns) && $finalizacionFiltro !== '') {
        $where .= " AND COALESCE(f.solicita_finalizacion, 0) = ?";
        $params[] = (int)$finalizacionFiltro;
        $types .= 'i';
    }

    $sql = "
        SELECT
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino,
            f.estado,
            f.tipo,
            f.modalidad,
            de.nombre AS division,
            s.nombre AS subdivision,
            cf.nombre AS categoria,
            t.nombre AS trade,
            CASE
                WHEN f.modalidad = 'solo_auditoria'
                    THEN COUNT(fq.id_local)
                ELSE COUNT(DISTINCT fq.id_local)
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
                WHEN f.modalidad = 'implementacion_por_etapas'
                    THEN COUNT(DISTINCT
                        CASE
                            WHEN fq.etapa_material IN ('implementado','retirado')
                            THEN l.codigo
                        END
                    )
                ELSE COUNT(DISTINCT
                    CASE
                        WHEN fq.pregunta IN ('implementado_auditado','solo_implementado','solo_auditoria','solo_retirado')
                        THEN l.codigo
                    END
                )
            END AS locales_implementados,
            {$selectPriority}
        FROM formulario f
        LEFT JOIN division_empresa de ON de.id = f.id_division
        LEFT JOIN subdivision s ON s.id = f.id_subdivision
        LEFT JOIN categoria_formulario cf ON cf.id = f.id_categoria_formulario
        LEFT JOIN trade t ON t.id = f.id_trade
        LEFT JOIN formularioQuestion fq ON fq.id_formulario = f.id
        LEFT JOIN local l ON l.id = fq.id_local
        {$joinUser}
        {$where}
        GROUP BY
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino,
            f.estado,
            f.tipo,
            f.modalidad,
            de.nombre,
            s.nombre,
            cf.nombre,
            t.nombre
            " . (empty($missingColumns) ? ", f.prioridad_cliente, f.solicita_finalizacion, f.motivo_finalizacion_cliente, f.updated_prioridad_cliente_at, u2.usuario" : "") . "
        ORDER BY
            {$orderPriority},
            f.fechaTermino ASC,
            f.id DESC
    ";

    $formularios = fetchAll($conn, $sql, $types, $params);
} catch (Exception $e) {
    $error = $e->getMessage();
}

$total = count($formularios);
$totalAlto = count(array_filter($formularios, fn($f) => $f['prioridad_cliente'] === 'ALTO'));
$totalFinalizacion = count(array_filter($formularios, fn($f) => (int)$f['solicita_finalizacion'] === 1));
$totalProgramados = array_sum(array_map(fn($f) => (int)($f['locales_programados'] ?? 0), $formularios));
$totalVisitados = array_sum(array_map(fn($f) => (int)($f['locales_visitados'] ?? 0), $formularios));
$totalImplementados = array_sum(array_map(fn($f) => (int)($f['locales_implementados'] ?? 0), $formularios));
$ratioVisitaGlobal = $totalProgramados > 0 ? round(($totalVisitados / $totalProgramados) * 100) : 0;
$ratioImplementacionGlobal = $totalProgramados > 0 ? round(($totalImplementados / $totalProgramados) * 100) : 0;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prioridad de campañas</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
    :root {
        --navy: #14264a;
        --navy-soft: #253c68;
        --primary: #5278ff;
        --primary-2: #675cff;
        --blue: #2ea7ff;
        --green: #1fc765;
        --mint: #4dd0a8;
        --purple: #7257ff;

        --page: #edf4ff;
        --card: rgba(255, 255, 255, .84);
        --card-solid: #ffffff;
        --line: #d7e4f7;
        --line-soft: #e9f0fb;
        --text: #13284e;
        --muted: #71809a;

        --radius-xl: 30px;
        --radius-lg: 24px;
        --radius-md: 16px;

        --shadow-soft: 0 22px 55px rgba(38, 67, 118, .14);
        --shadow-card: 0 16px 38px rgba(38, 67, 118, .11);
        --shadow-small: 0 8px 20px rgba(38, 67, 118, .10);
    }

    * {
        box-sizing: border-box;
    }

    html,
    body {
        min-height: 100%;
    }

    body {
        margin: 0;
        background:
            radial-gradient(circle at 8% 5%, rgba(82, 120, 255, .20), transparent 31%),
            radial-gradient(circle at 92% 22%, rgba(73, 210, 164, .10), transparent 28%),
            linear-gradient(135deg, #eaf2ff 0%, #f8fbff 50%, #edf4ff 100%);
        color: var(--text);
        font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
        font-size: 13px;
        overflow-x: hidden;
    }

    body::before {
        content: "";
        position: fixed;
        inset: 0;
        pointer-events: none;
        background:
            linear-gradient(115deg, rgba(255,255,255,.48), transparent 38%),
            linear-gradient(270deg, rgba(255,255,255,.42), transparent 43%);
        z-index: -1;
    }

    .page {
        width: calc(100% - 96px);
        max-width: none;
        margin: 30px auto 44px;
        padding: 0;
    }

    .hero {
        position: relative;
        overflow: hidden;
        background: rgba(255, 255, 255, .80);
        color: var(--text);
        border: 1px solid rgba(190, 207, 235, .88);
        border-radius: var(--radius-xl);
        padding: 28px 32px;
        box-shadow: var(--shadow-soft);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    .hero::before {
        content: "";
        position: absolute;
        inset: 0;
        background:
            linear-gradient(135deg, rgba(82, 120, 255, .13), transparent 39%),
            radial-gradient(circle at 88% 0%, rgba(255,255,255,.92), transparent 36%);
        pointer-events: none;
    }

    .hero > * {
        position: relative;
        z-index: 1;
    }

    .hero h3 {
        display: flex;
        align-items: center;
        gap: 16px;
        margin: 0;
        color: var(--navy);
        font-size: 29px;
        font-weight: 950;
        line-height: 1.08;
        letter-spacing: -.04em;
    }

    .hero h3 i {
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 20px;
        color: #5578ff;
        font-size: 25px;
        background: linear-gradient(145deg, #eef6ff, #dfeaff);
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.95),
            0 14px 26px rgba(82, 120, 255, .18);
    }

    .hero .text-light {
        margin-top: 8px;
        margin-left: 74px;
        color: var(--muted) !important;
        font-size: 12px;
        font-weight: 850;
        letter-spacing: -.01em;
    }

    .hero .status-pill {
        background: rgba(82, 120, 255, .12);
        color: #3556c9;
        border: 1px solid rgba(82, 120, 255, .16);
    }

    .alert {
        border: 0;
        border-radius: 20px;
        padding: 15px 18px;
        font-size: 13px;
        font-weight: 800;
        box-shadow: var(--shadow-small);
    }

    .alert-success {
        background: #e8fff2;
        color: #166534;
    }

    .alert-danger {
        background: #fff0f0;
        color: #991b1b;
    }

    .alert-warning {
        background: #fff8e6;
        color: #92400e;
    }

    .row.mb-4 {
        margin-left: -14px;
        margin-right: -14px;
        margin-bottom: 22px !important;
    }

    .row.mb-4 > .col-md {
        flex: 1 1 220px;
        max-width: none;
        padding-left: 14px;
        padding-right: 14px;
    }

    .metric {
        position: relative;
        overflow: hidden;
        min-height: 94px;
        padding: 18px 22px 18px 88px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        background: rgba(255, 255, 255, .86);
        border: 1px solid rgba(202, 216, 238, .92);
        border-radius: 24px;
        box-shadow: var(--shadow-card);
        color: #70809b;
        font-size: 11px;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: .035em;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .metric::before {
        content: "\f0a1";
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        width: 58px;
        height: 58px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        font-size: 24px;
        color: #fff;
        background: linear-gradient(135deg, var(--primary), var(--primary-2));
        box-shadow: 0 14px 24px rgba(82, 120, 255, .28);
    }

    .metric::after {
        content: "";
        position: absolute;
        right: -36px;
        bottom: -46px;
        width: 115px;
        height: 115px;
        border-radius: 999px;
        background: rgba(82, 120, 255, .07);
        pointer-events: none;
    }

    .row.mb-4 > .col-md:nth-child(2) .metric::before {
        content: "\f071";
        background: linear-gradient(135deg, #2ea7ff, #218bf5);
        box-shadow: 0 14px 24px rgba(46, 167, 255, .25);
    }

    .row.mb-4 > .col-md:nth-child(3) .metric::before {
        content: "\f11e";
        background: linear-gradient(135deg, #49d2a4, #20c766);
        box-shadow: 0 14px 24px rgba(32, 199, 102, .24);
    }

    .row.mb-4 > .col-md:nth-child(4) .metric::before {
        content: "\f3c5";
        background: linear-gradient(135deg, #49d2a4, #31c48d);
        box-shadow: 0 14px 24px rgba(73, 210, 164, .22);
    }

    .row.mb-4 > .col-md:nth-child(5) .metric::before {
        content: "\f00c";
        background: linear-gradient(135deg, #7c5cff, #6246ea);
        box-shadow: 0 14px 24px rgba(114, 85, 255, .24);
    }

    .metric:hover {
        transform: translateY(-3px);
        border-color: rgba(82, 120, 255, .38);
        box-shadow: 0 22px 48px rgba(38, 67, 118, .16);
    }

    .metric strong {
        order: 2;
        display: block;
        margin-top: 5px;
        color: var(--navy);
        font-size: 28px;
        line-height: 1;
        font-weight: 950;
        letter-spacing: -.05em;
    }

    .panel {
        position: relative;
        background: rgba(255, 255, 255, .80);
        border: 1px solid rgba(202, 216, 238, .92);
        border-radius: var(--radius-xl);
        padding: 26px 24px;
        box-shadow: var(--shadow-soft);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
    }

    .panel.mb-4 {
        padding: 78px 24px 30px;
    }

    .panel.mb-4::before {
        content: "Filtros de análisis";
        position: absolute;
        left: 24px;
        top: 24px;
        color: var(--navy);
        font-size: 21px;
        font-weight: 950;
        letter-spacing: -.035em;
        line-height: 1;
    }

    .panel.mb-4::after {
        content: "Selecciona división y filtros para actualizar la vista.";
        position: absolute;
        left: 24px;
        top: 52px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 850;
    }

    .panel.mb-4 form.row {
        margin-left: -12px;
        margin-right: -12px;
        row-gap: 18px;
    }

    .panel.mb-4 form.row > [class*="col-"] {
        padding-left: 12px;
        padding-right: 12px;
    }

    .panel.mb-4 .col-md-3 {
        flex: 1 1 310px;
        max-width: none;
    }

    .panel.mb-4 .col-md-2 {
        flex: 1 1 190px;
        max-width: none;
    }

    .panel.mb-4 .col-md-1 {
        flex: 0 0 175px;
        max-width: 175px;
    }

    label {
        display: block;
        margin-bottom: 8px;
        color: #30476b;
        font-size: 12px;
        font-weight: 950;
        letter-spacing: -.01em;
    }

    .form-control {
        height: 45px;
        border: 1px solid #c8d7ee;
        border-radius: 15px;
        background-color: rgba(255, 255, 255, .94);
        color: #18305d;
        font-size: 13px;
        font-weight: 850;
        padding: 0 15px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
        transition: border-color .18s ease, box-shadow .18s ease, background-color .18s ease;
    }

    .form-control::placeholder {
        color: #7b8aa5;
        font-weight: 750;
    }

    .form-control:focus {
        border-color: rgba(82, 120, 255, .85);
        background-color: #fff;
        box-shadow:
            0 0 0 4px rgba(82, 120, 255, .13),
            inset 0 1px 0 rgba(255,255,255,.9);
        outline: 0;
    }

    select.form-control {
        cursor: pointer;
    }

    textarea.form-control,
    textarea {
        min-height: 45px;
        resize: vertical;
        padding-top: 12px;
        padding-bottom: 12px;
    }

    .btn {
        border: 0;
        border-radius: 15px;
        font-size: 13px;
        font-weight: 950;
        letter-spacing: -.01em;
        transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
    }

    .btn:hover {
        transform: translateY(-2px);
        filter: brightness(1.02);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn-primary {
        height: 45px;
        color: #fff;
        background: linear-gradient(135deg, var(--primary), var(--primary-2));
        box-shadow: 0 14px 24px rgba(82, 120, 255, .26);
    }

    .btn-primary:hover,
    .btn-primary:focus {
        background: linear-gradient(135deg, #4d73f5, #6555ef);
        box-shadow: 0 16px 28px rgba(82, 120, 255, .32);
    }

    .btn-success {
        color: #fff;
        background: linear-gradient(135deg, #22c55e, #16a34a);
        box-shadow: 0 12px 22px rgba(22, 163, 74, .22);
    }

    .btn-success:hover,
    .btn-success:focus {
        background: linear-gradient(135deg, #20bd59, #149647);
        box-shadow: 0 14px 24px rgba(22, 163, 74, .28);
    }

    .btn-block {
        width: 100%;
    }

    .page > .panel:last-child {
        width: 100%;
        max-width: none;
        padding: 88px 22px 22px;
    }

    .page > .panel:last-child::before {
        content: "Campañas activas";
        position: absolute;
        left: 24px;
        top: 25px;
        color: var(--navy);
        font-size: 21px;
        font-weight: 950;
        letter-spacing: -.035em;
        line-height: 1;
    }

    .page > .panel:last-child::after {
        content: "Resumen ejecutivo por campaña, con avance de visita y ejecución.";
        position: absolute;
        left: 24px;
        top: 53px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 850;
    }

    .table-wrap {
        width: 100%;
        max-width: none;
        max-height: 72vh;
        overflow: auto;
        border: 1px solid rgba(202, 216, 238, .88);
        border-radius: 22px;
        background: rgba(248, 251, 255, .82);
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.9),
            0 10px 24px rgba(38, 67, 118, .06);
    }

    .table {
        width: 100% !important;
        min-width: 1360px;
        margin: 0;
        border-collapse: separate;
        border-spacing: 0 8px;
        padding: 8px;
        table-layout: auto;
    }

    .table thead th,
    th {
        position: sticky;
        top: 0;
        z-index: 5;
        padding: 15px 14px !important;
        border: 0 !important;
        background: #f1f6ff;
        color: #082657;
        font-size: 11px;
        font-weight: 950;
        text-transform: uppercase;
        letter-spacing: .035em;
        white-space: nowrap;
        box-shadow: 0 1px 0 rgba(202, 216, 238, .9);
    }

    .table thead th:first-child,
    th:first-child {
        border-radius: 17px 0 0 17px;
    }

    .table thead th:last-child,
    th:last-child {
        border-radius: 0 17px 17px 0;
    }

    .table tbody td,
    td {
        vertical-align: middle !important;
        padding: 15px 14px !important;
        border-top: 0 !important;
        border-bottom: 1px solid rgba(226, 235, 248, .9);
        background: rgba(255, 255, 255, .94);
        color: #10254c;
        font-size: 12px;
        font-weight: 800;
    }

    tbody tr {
        transition: transform .18s ease, box-shadow .18s ease;
    }

    tbody tr:hover {
        background: transparent;
        transform: translateY(-1px);
        box-shadow: 0 12px 24px rgba(38, 67, 118, .09);
    }

    tbody tr:hover td {
        background: #f9fbff;
    }

    tbody tr td:first-child {
        border-radius: 18px 0 0 18px;
        border-left: 1px solid rgba(226, 235, 248, .9);
    }

    tbody tr td:last-child {
        border-radius: 0 18px 18px 0;
        border-right: 1px solid rgba(226, 235, 248, .9);
    }

    .campaign-name {
        min-width: 280px;
        max-width: 420px;
        color: #082350;
        font-size: 14px;
        font-weight: 950;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: -.02em;
    }

    .campaign-name strong,
    td strong {
        color: #205dff;
        font-weight: 950;
    }

    .readonly-note {
        color: var(--muted);
        font-size: 10.5px;
        line-height: 1.35;
        font-weight: 800;
        text-transform: none;
        letter-spacing: 0;
    }

    .pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 27px;
        padding: 7px 11px;
        border-radius: 999px;
        font-size: 10.5px;
        font-weight: 950;
        line-height: 1;
        white-space: nowrap;
        text-transform: uppercase;
        letter-spacing: .025em;
    }

    .priority-high {
        background: #ffe8e8;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }

    .priority-medium {
        background: #e9f1ff;
        color: #315fca;
        border: 1px solid #c9d9ff;
    }

    .priority-low {
        background: #e7fff1;
        color: #15803d;
        border: 1px solid #bbf7d0;
    }

    .status-pill {
        background: #eaf2ff;
        color: #315fca;
        border: 1px solid #ccdcff;
    }

    .finalize-pill {
        background: #ffe8ef;
        color: #be123c;
        border: 1px solid #fecdd3;
    }

    .ratio-cell {
        min-width: 150px;
    }

    .ratio-number {
        margin-bottom: 7px;
        color: #10254c;
        font-size: 12px;
        font-weight: 950;
        text-align: right;
    }

    .ratio-bar {
        height: 7px;
        overflow: hidden;
        border-radius: 999px;
        background: #e6edf8;
    }

    .ratio-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #5278ff, #6b5cff);
        box-shadow: 0 0 12px rgba(82, 120, 255, .28);
    }

    .ratio-fill-ok {
        background: linear-gradient(90deg, #49d2a4, #20c766);
        box-shadow: 0 0 12px rgba(32, 199, 102, .24);
    }

    .form-inline-controls {
        min-width: 460px;
    }

    .form-inline-controls .d-flex {
        gap: 9px !important;
    }

    .form-inline-controls select {
        max-width: 120px;
    }

    .form-inline-controls .form-control-sm {
        height: 38px;
        border-radius: 13px;
        font-size: 11.5px;
        padding: 0 11px;
    }

    .form-inline-controls input[type="text"].form-control-sm {
        flex: 1 1 185px;
        min-width: 180px;
    }

    .form-inline-controls label.mb-0 {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        height: 38px;
        margin: 0;
        padding: 0 10px;
        border-radius: 999px;
        background: #f1f6ff;
        color: #30476b;
        font-size: 11px;
        font-weight: 900;
        white-space: nowrap;
    }

    .form-inline-controls input[type="checkbox"] {
        width: 15px;
        height: 15px;
        accent-color: var(--primary);
    }

    .btn-sm {
        width: 38px;
        height: 38px;
        padding: 0;
        border-radius: 13px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    button:disabled,
    .form-control:disabled {
        cursor: not-allowed;
        opacity: .62;
        box-shadow: none;
    }

    .table-footer {
        margin-top: 12px;
        padding: 14px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: rgba(255, 255, 255, .74);
        border: 1px solid rgba(202, 216, 238, .88);
        border-radius: 18px;
        color: #637493;
        font-size: 12px;
        font-weight: 850;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.9);
    }

    .footer-arrows {
        display: flex;
        gap: 12px;
        color: #5278ff;
        font-size: 13px;
    }

    .footer-arrows i {
        width: 30px;
        height: 30px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        background: #eef4ff;
        border: 1px solid #d8e5ff;
    }

    .dataTables_wrapper,
    .dataTables_scroll,
    .dataTables_scrollHead,
    .dataTables_scrollBody {
        width: 100% !important;
        max-width: none !important;
    }

    .dataTables_wrapper .row:first-child {
        margin: 0 0 12px;
        padding: 13px 16px;
        border: 1px solid rgba(202, 216, 238, .88);
        border-radius: 18px;
        background: rgba(255,255,255,.70);
    }

    .dataTables_length label,
    .dataTables_filter label {
        margin: 0;
        color: #637493;
        font-size: 12px;
        font-weight: 850;
    }

    .dataTables_length select,
    .dataTables_filter input {
        height: 38px;
        border: 1px solid #c8d7ee;
        border-radius: 14px;
        background: #fff;
        color: #18305d;
        font-size: 12px;
        font-weight: 850;
        outline: 0;
    }

    .dataTables_filter input {
        min-width: 280px;
        padding: 0 14px;
    }

    .dataTables_paginate .pagination {
        justify-content: flex-end;
        margin-top: 14px;
        gap: 6px;
    }

    .page-item .page-link {
        border: 1px solid #d8e5ff;
        border-radius: 13px !important;
        color: #71809a;
        font-size: 12px;
        font-weight: 900;
        background: rgba(255,255,255,.80);
        box-shadow: none;
    }

    .page-item.active .page-link {
        color: #fff;
        background: linear-gradient(135deg, var(--primary), var(--primary-2));
        border-color: transparent;
        box-shadow: 0 10px 20px rgba(82, 120, 255, .24);
    }

    .table-wrap::-webkit-scrollbar,
    .dataTables_scrollBody::-webkit-scrollbar {
        width: 10px;
        height: 10px;
    }

    .table-wrap::-webkit-scrollbar-track,
    .dataTables_scrollBody::-webkit-scrollbar-track {
        background: #eef4ff;
        border-radius: 999px;
    }

    .table-wrap::-webkit-scrollbar-thumb,
    .dataTables_scrollBody::-webkit-scrollbar-thumb {
        background: #c7d7f0;
        border-radius: 999px;
        border: 2px solid #eef4ff;
    }

    .table-wrap::-webkit-scrollbar-thumb:hover,
    .dataTables_scrollBody::-webkit-scrollbar-thumb:hover {
        background: #9fb7dd;
    }

    @media (max-width: 1199px) {
        .page {
            width: calc(100% - 48px);
        }

        .hero h3 {
            font-size: 25px;
        }

        .form-inline-controls {
            min-width: 410px;
        }

        .table {
            min-width: 1280px;
        }
    }

    @media (max-width: 767px) {
        .page {
            width: calc(100% - 24px);
            margin-top: 18px;
        }

        .hero,
        .panel {
            border-radius: 22px;
        }

        .hero {
            padding: 22px 18px;
        }

        .hero h3 {
            align-items: flex-start;
            font-size: 22px;
        }

        .hero h3 i {
            width: 54px;
            height: 54px;
            flex-basis: 54px;
            border-radius: 18px;
            font-size: 22px;
        }

        .hero .text-light {
            margin-left: 70px;
        }

        .metric {
            min-height: 90px;
            padding-left: 82px;
        }

        .metric::before {
            width: 54px;
            height: 54px;
        }

        .panel.mb-4 .col-md-1,
        .panel.mb-4 .col-md-2,
        .panel.mb-4 .col-md-3 {
            flex: 1 1 100%;
            max-width: 100%;
        }

        .form-inline-controls {
            min-width: 330px;
        }

        .table {
            min-width: 1180px;
        }
    }
</style>

</head>
<body>
<div class="page">
    <div class="hero mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h3 class="mb-1"><i class="fa-solid fa-flag"></i> Prioridad de campañas activas</h3>
                <div class="text-light">Clientes pueden priorizar campañas y solicitar finalización según su división.</div>
            </div>
            <div class="mt-3 mt-md-0">
                <span class="pill status-pill">DIVISIÓN SESIÓN: <?php echo h($division_nombre_session ?? $divisionSesion); ?></span>
            </div>
        </div>
    </div>

    <?php if ($mensaje): ?><div class="alert alert-success"><?php echo h($mensaje); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>
    <?php if (!empty($missingColumns)): ?>
        <div class="alert alert-warning">
            <strong>Falta actualizar la tabla formulario.</strong>
            Ejecuta el archivo <code>sql_formulario_prioridad_cliente.sql</code>. Columnas pendientes:
            <code><?php echo h(implode(', ', $missingColumns)); ?></code>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md mb-3"><div class="metric"><strong><?php echo number_format($total, 0, ',', '.'); ?></strong>Campañas activas</div></div>
        <div class="col-md mb-3"><div class="metric"><strong><?php echo number_format($totalAlto, 0, ',', '.'); ?></strong>Prioridad alta</div></div>
        <div class="col-md mb-3"><div class="metric"><strong><?php echo number_format($totalFinalizacion, 0, ',', '.'); ?></strong>Solicitan finalización</div></div>
        <div class="col-md mb-3"><div class="metric"><strong><?php echo (int)$ratioVisitaGlobal; ?>%</strong>Ratio visita</div></div>
        <div class="col-md mb-3"><div class="metric"><strong><?php echo (int)$ratioImplementacionGlobal; ?>%</strong>Ratio implementación</div></div>
    </div>

    <div class="panel mb-4">
        <form method="get" class="row align-items-end">
            <?php if ($esMC): ?>
                <div class="col-md-3">
                    <label>División</label>
                    <select name="division" class="form-control">
                        <option value="0">TODAS</option>
                        <?php foreach ($divisiones as $division): ?>
                            <option value="<?php echo (int)$division['id']; ?>" <?php echo $divisionFiltro === (int)$division['id'] ? 'selected' : ''; ?>>
                                <?php echo h($division['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label>Buscar campaña</label>
                <input type="text" name="q" class="form-control" value="<?php echo h($busqueda); ?>" placeholder="Nombre o ID">
            </div>
            <div class="col-md-2">
                <label>Trade</label>
                <select name="trade" class="form-control">
                    <option value="0">TODOS</option>
                    <?php foreach ($trades as $trade): ?>
                        <option value="<?php echo (int)$trade['id_trade']; ?>" <?php echo $tradeFiltro === (int)$trade['id_trade'] ? 'selected' : ''; ?>>
                            <?php echo h($trade['nombre_trade']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label>Prioridad</label>
                <select name="prioridad" class="form-control" <?php echo !empty($missingColumns) ? 'disabled' : ''; ?>>
                    <option value="">TODAS</option>
                    <?php foreach (['ALTO','MEDIO','BAJO'] as $prioridad): ?>
                        <option value="<?php echo $prioridad; ?>" <?php echo $prioridadFiltro === $prioridad ? 'selected' : ''; ?>><?php echo $prioridad; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label>Finalización</label>
                <select name="finalizacion" class="form-control" <?php echo !empty($missingColumns) ? 'disabled' : ''; ?>>
                    <option value="">TODAS</option>
                    <option value="1" <?php echo $finalizacionFiltro === '1' ? 'selected' : ''; ?>>SOLICITADA</option>
                    <option value="0" <?php echo $finalizacionFiltro === '0' ? 'selected' : ''; ?>>NO SOLICITADA</option>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-block"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="table-wrap">
            <table class="table table-hover table-sm mb-0">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Campaña</th>
                    <th>División</th>
                    <th>Fechas</th>
                    <th>Ratio visita</th>
                    <th>Ratio implementación</th>
                    <th>Prioridad</th>
                    <th>Finalización</th>
                    <th>Gestión cliente</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($formularios)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No hay campañas activas para los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($formularios as $formulario): ?>
                    <?php
                    $prioridad = strtoupper((string)($formulario['prioridad_cliente'] ?? 'MEDIO'));
                    if (!in_array($prioridad, ['ALTO','MEDIO','BAJO'], true)) {
                        $prioridad = 'MEDIO';
                    }
                    $programados = (int)($formulario['locales_programados'] ?? 0);
                    $visitados = (int)($formulario['locales_visitados'] ?? 0);
                    $implementados = (int)($formulario['locales_implementados'] ?? 0);
                    $ratioVisita = $programados > 0 ? round(($visitados / $programados) * 100) : 0;
                    $ratioImplementacion = $programados > 0 ? round(($implementados / $programados) * 100) : 0;
                    ?>
                    <tr>
                        <td><strong>#<?php echo (int)$formulario['id']; ?></strong></td>
                        <td class="campaign-name">
                            <?php echo h($formulario['nombre']); ?>
                        
                            <div class="readonly-note">
                                <?php echo h($formulario['categoria'] ?: '-'); ?> · <?php echo h($formulario['trade'] ?: '-'); ?>
                            </div>
                        
                            <div class="mt-2">
                                <span class="pill status-pill">
                                    <?php echo h(estadoTexto($formulario['estado'])); ?>
                                </span>
                            </div>
                        </td>
                        <td><?php echo h($formulario['division']); ?></td>
                        <td>
                            <div><?php echo h(!empty($formulario['fechaInicio']) ? date('d/m/Y', strtotime($formulario['fechaInicio'])) : '-'); ?></div>
                            <div class="readonly-note">al <?php echo h(!empty($formulario['fechaTermino']) ? date('d/m/Y', strtotime($formulario['fechaTermino'])) : '-'); ?></div>
                        </td>
                        <td class="ratio-cell">
                            <div class="ratio-number"><?php echo (int)$ratioVisita; ?>%</div>
                            <div class="ratio-bar"><div class="ratio-fill" style="width:<?php echo min(100, max(0, (int)$ratioVisita)); ?>%"></div></div>
                            <div class="readonly-note"><?php echo $visitados; ?> / <?php echo $programados; ?></div>
                        </td>
                        <td class="ratio-cell">
                            <div class="ratio-number"><?php echo (int)$ratioImplementacion; ?>%</div>
                            <div class="ratio-bar"><div class="ratio-fill ratio-fill-ok" style="width:<?php echo min(100, max(0, (int)$ratioImplementacion)); ?>%"></div></div>
                            <div class="readonly-note"><?php echo $implementados; ?> / <?php echo $programados; ?></div>
                        </td>
                        <td><span class="pill <?php echo prioridadClass($prioridad); ?>"><?php echo h($prioridad); ?></span></td>
                        <td>
                            <?php if ((int)$formulario['solicita_finalizacion'] === 1): ?>
                                <span class="pill finalize-pill">SOLICITADA</span>
                            <?php else: ?>
                                <span class="readonly-note">NO SOLICITADA</span>
                            <?php endif; ?>
                            <?php if (!empty($formulario['motivo_finalizacion_cliente'])): ?>
                                <div class="readonly-note mt-1"><?php echo h($formulario['motivo_finalizacion_cliente']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="form-inline-controls">
                            <form method="post" class="mb-0">
                                <input type="hidden" name="action" value="update_cliente">
                                <input type="hidden" name="formulario_id" value="<?php echo (int)$formulario['id']; ?>">
                                <div class="d-flex align-items-center flex-wrap" style="gap:8px;">
                                    <select name="prioridad_cliente" class="form-control form-control-sm" <?php echo !empty($missingColumns) ? 'disabled' : ''; ?>>
                                        <?php foreach (['ALTO','MEDIO','BAJO'] as $opcion): ?>
                                            <option value="<?php echo $opcion; ?>" <?php echo $prioridad === $opcion ? 'selected' : ''; ?>><?php echo $opcion; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="mb-0">
                                        <input type="checkbox" name="solicita_finalizacion" value="1" <?php echo (int)$formulario['solicita_finalizacion'] === 1 ? 'checked' : ''; ?> <?php echo !empty($missingColumns) ? 'disabled' : ''; ?>>
                                        finalizar
                                    </label>
                                    <input type="text" name="motivo_finalizacion_cliente" class="form-control form-control-sm" value="<?php echo h($formulario['motivo_finalizacion_cliente']); ?>" placeholder="Motivo finalización" <?php echo !empty($missingColumns) ? 'disabled' : ''; ?>>
                                    <button class="btn btn-sm btn-success" <?php echo !empty($missingColumns) ? 'disabled' : ''; ?>>
                                        <i class="fa-solid fa-floppy-disk"></i>
                                    </button>
                                </div>
                                <?php if (!empty($formulario['updated_prioridad_cliente_at'])): ?>
                                    <div class="readonly-note mt-1">
                                        Actualizado: <?php echo h(date('d/m/Y H:i', strtotime($formulario['updated_prioridad_cliente_at']))); ?>
                                        <?php echo !empty($formulario['actualizado_por']) ? 'por ' . h($formulario['actualizado_por']) : ''; ?>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-footer">
            <span>Mostrando <?php echo number_format($total, 0, ',', '.'); ?> campañas activas</span>
            <span class="footer-arrows"><i class="fa-solid fa-chevron-left"></i><i class="fa-solid fa-chevron-right"></i></span>
        </div>
    </div>
</div>
</body>
</html>

