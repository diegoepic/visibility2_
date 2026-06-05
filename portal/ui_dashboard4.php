<?php
session_start();

/*
|--------------------------------------------------------------------------
| Configuración de entorno
|--------------------------------------------------------------------------
| En producción puedes dejar display_errors en 0.
*/
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('max_execution_time', 60);
ini_set('memory_limit', '512M');

/*
|--------------------------------------------------------------------------
| Log local
|--------------------------------------------------------------------------
*/
$logFile = __DIR__ . '/error_dashboard.log';

function logError($message) {
    global $logFile;
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($logFile, "$timestamp $message\n", FILE_APPEND);
}

/*
|--------------------------------------------------------------------------
| Funciones auxiliares
|--------------------------------------------------------------------------
*/
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function divisionInitials($name) {
    $name = trim((string)$name);

    if ($name === '') {
        return 'CL';
    }

    $words = preg_split('/\s+/', $name);
    $initials = '';

    foreach ($words as $word) {
        if ($word !== '') {
            if (function_exists('mb_substr')) {
                $initials .= mb_substr($word, 0, 1, 'UTF-8');
            } else {
                $initials .= substr($word, 0, 1);
            }
        }

        if (strlen($initials) >= 2) {
            break;
        }
    }

    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($initials ?: 'CL', 'UTF-8');
    }

    return strtoupper($initials ?: 'CL');
}

function orbitPositionFromAngle($angle, $rx, $ry, $size, $delay = 0) {
    $rad = deg2rad($angle);

    return [
        'x' => round(cos($rad) * $rx, 2),
        'y' => round(sin($rad) * $ry, 2),
        'size' => $size,
        'delay' => $delay
    ];
}

function getInterleavedAngles($capacity, $topFirst = true) {
    /*
      Estos ángulos representan los "espacios intermedios"
      entre los 8 nodos del anillo principal.
    */
    $gapAngles = [-67.5, -22.5, 22.5, 67.5, 112.5, 157.5, -157.5, -112.5];

    /*
      Reordenamos para priorizar primero la parte superior,
      así los extras se ven más armónicos arriba.
    */
    if ($topFirst) {
        $priorityOrder = [7, 0, 1, 2, 3, 4, 5, 6];
    } else {
        $priorityOrder = [0, 1, 2, 3, 4, 5, 6, 7];
    }

    $ordered = [];
    foreach ($priorityOrder as $idx) {
        $ordered[] = $gapAngles[$idx];
    }

    return array_slice($ordered, 0, $capacity);
}

function buildOrbitPosition($index, $total, $mode = 'clients') {
    if ($total <= 0) {
        return [
            'x' => 0,
            'y' => 0,
            'size' => 150,
            'delay' => 0
        ];
    }

    /*
      ==============================
      CONFIGURACIÓN SEGÚN MODO
      ==============================
    */
    if ($mode === 'clients') {
        $ring1RadiusX = 430;
        $ring1RadiusY = 285;
        $ring1Size    = ($total <= 8) ? 150 : 138;

        $ring2RadiusX = 535;
        $ring2RadiusY = 350;
        $ring2Size    = 112;

        $ring3RadiusX = 620;
        $ring3RadiusY = 410;
        $ring3Size    = 96;
    } else {
        // dashboards
        $ring1RadiusX = 400;
        $ring1RadiusY = 250;
        $ring1Size    = ($total <= 8) ? 145 : 128;

        $ring2RadiusX = 500;
        $ring2RadiusY = 315;
        $ring2Size    = 108;

        $ring3RadiusX = 580;
        $ring3RadiusY = 380;
        $ring3Size    = 94;
    }

    /*
      ==============================
      RING 1
      Hasta 8 elementos en órbita principal
      ==============================
    */
    $ring1Count = min($total, 8);

    if ($index < $ring1Count) {
        $step = 360 / $ring1Count;
        $angle = -90 + ($index * $step);

        return orbitPositionFromAngle(
            $angle,
            $ring1RadiusX,
            $ring1RadiusY,
            $ring1Size,
            round(($index % 7) * 0.22, 2)
        );
    }

    /*
      ==============================
      RING 2
      Hasta 8 elementos extra
      ubicados entre los espacios del ring 1
      ==============================
    */
    $remainingAfterRing1 = $total - $ring1Count;
    $ring2Count = min($remainingAfterRing1, 8);

    if ($index < ($ring1Count + $ring2Count)) {
        $localIndex = $index - $ring1Count;
        $ring2Angles = getInterleavedAngles($ring2Count, true);
        $angle = $ring2Angles[$localIndex];

        return orbitPositionFromAngle(
            $angle,
            $ring2RadiusX,
            $ring2RadiusY,
            $ring2Size,
            round(($index % 7) * 0.22, 2)
        );
    }

    /*
      ==============================
      RING 3
      Si hay muchos elementos, se agrega tercer anillo
      Distribución completa pero más abierta y pequeña
      ==============================
    */
    $remainingAfterRing2 = $remainingAfterRing1 - $ring2Count;
    $ring3Count = max(0, $remainingAfterRing2);

    if ($ring3Count > 0) {
        $localIndex = $index - $ring1Count - $ring2Count;
        $step = 360 / $ring3Count;

        /*
          Para que no coincida exactamente con ring1,
          desplazamos medio paso.
        */
        $angle = -90 + ($localIndex * $step) + ($step / 2);

        return orbitPositionFromAngle(
            $angle,
            $ring3RadiusX,
            $ring3RadiusY,
            $ring3Size,
            round(($index % 7) * 0.22, 2)
        );
    }

    return [
        'x' => 0,
        'y' => 0,
        'size' => 120,
        'delay' => 0
    ];
}

function buildSparkPositions($total, $mode = 'clients') {
    $sparks = [];

    if ($total <= 0) {
        return $sparks;
    }

    // mismos radios base del primer anillo
    if ($mode === 'clients') {
        $ringRadiusX = 430;
        $ringRadiusY = 285;
    } else {
        $ringRadiusX = 400;
        $ringRadiusY = 250;
    }

    // solo tomamos el primer anillo para calcular huecos
    $ringCount = min($total, 8);

    // si solo hay 1 cliente, ponemos 4 sparks cardinales
    if ($ringCount === 1) {
        $angles = [-90, 0, 90, 180];
    } else {
        $angles = [];
        $step = 360 / $ringCount;

        // spark entre cada nodo
        for ($i = 0; $i < $ringCount; $i++) {
            $angles[] = -90 + ($i * $step) + ($step / 2);
        }
    }

    foreach ($angles as $i => $angle) {
        $rad = deg2rad($angle);

        // un pelín más cerca del centro para que quede "entre medio"
        $x = cos($rad) * ($ringRadiusX * 0.96);
        $y = sin($rad) * ($ringRadiusY * 0.96);

        $sparks[] = [
            'x' => round($x, 2),
            'y' => round($y, 2),
            'size' => ($i % 2 === 0) ? 12 : 8,
            'duration' => ($i % 2 === 0) ? '4.8s' : '5.6s',
            'delay' => round($i * 0.35, 2)
        ];
    }

    return $sparks;
}

/*
|--------------------------------------------------------------------------
| Conexión
|--------------------------------------------------------------------------
*/
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (!isset($_SESSION['division_id']) || empty($_SESSION['division_id'])) {
    logError("Acceso sin sesión activa.");
    die("<p style='color:red;text-align:center;'>Error: sesión no iniciada. Por favor, vuelva a ingresar al sistema.</p>");
}

$userDivision = (int)$_SESSION['division_id'];

if (!$conn || $conn->connect_error) {
    logError("Error de conexión a la base de datos: " . ($conn->connect_error ?? 'sin detalle'));
    die("<p style='color:red;text-align:center;'>Error de conexión a la base de datos.</p>");
}

$isAdminDivision = ($userDivision === 1);
$requestedDivisionId = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

$viewMode = 'clients';
$division_id = 0;

if ($isAdminDivision) {
    if ($requestedDivisionId > 0) {
        $viewMode = 'dashboards';
        $division_id = $requestedDivisionId;
    } else {
        $viewMode = 'clients';
    }
} else {
    $viewMode = 'dashboards';
    $division_id = $userDivision;
}


/*
|--------------------------------------------------------------------------
| Modo de visualización
|--------------------------------------------------------------------------
| - Admin/división 1 sin division_id: vista clientes.
| - Admin/división 1 con division_id: vista dashboards de esa división.
| - Usuario normal: vista dashboards de su propia división.
*/
$isAdminDivision = ($userDivision === 1);
$requestedDivisionId = isset($_GET['division_id']) ? (int)$_GET['division_id'] : 0;

$viewMode = 'clients';
$division_id = 0;

if ($isAdminDivision) {
    if ($requestedDivisionId > 0) {
        $viewMode = 'dashboards';
        $division_id = $requestedDivisionId;
    } else {
        $viewMode = 'clients';
    }
} else {
    $viewMode = 'dashboards';
    $division_id = $userDivision;
}

/*
|--------------------------------------------------------------------------
| Datos para vista clientes
|--------------------------------------------------------------------------
*/
$divisiones = [];
$mcDivision = null;

if ($viewMode === 'clients') {
    $queryDiv = "
        SELECT 
            de.id,
            de.nombre,
            de.image_url,
            COUNT(di.id) AS total_dashboards
        FROM division_empresa de
        LEFT JOIN dashboard_items di
            ON di.id_division = de.id
           AND di.is_active = 1
        WHERE de.estado = 1
        GROUP BY de.id, de.nombre, de.image_url
        ORDER BY de.nombre ASC
    ";

    $resultDiv = $conn->query($queryDiv);

    if ($resultDiv === false) {
        logError('Error en consulta division_empresa: ' . $conn->error);
        die("<p style='color:red;text-align:center;'>Error al cargar divisiones.</p>");
    }

    while ($div = $resultDiv->fetch_assoc()) {
        $nombreNormalizado = mb_strtoupper(trim($div['nombre'] ?? ''), 'UTF-8');
        $nombreNormalizado = str_replace([' ', '-', '_'], '', $nombreNormalizado);

        if ($nombreNormalizado === 'MC' || $nombreNormalizado === 'MENTECREATIVA') {
            $mcDivision = $div;
            continue;
        }

        $divisiones[] = $div;
    }
}

$mcCenterName = 'MC';
$mcCenterLogo = '';
$mcCenterUrl  = '#';

if (!empty($mcDivision)) {
    $mcCenterName = $mcDivision['nombre'] ?? 'MC';
    $mcCenterLogo = $mcDivision['image_url'] ?? '';
    $mcCenterUrl  = 'ui_dashboard2.php?division_id=' . (int)$mcDivision['id'];
}

/*
|--------------------------------------------------------------------------
| Datos para vista dashboards
|--------------------------------------------------------------------------
*/
$divisionActual = null;
$dashboards = [];

if ($viewMode === 'dashboards') {
    $stmtDivision = $conn->prepare("
        SELECT 
            id,
            nombre,
            image_url
        FROM division_empresa
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmtDivision) {
        logError('Error prepare division_empresa: ' . $conn->error);
        die("<p style='color:red;text-align:center;'>Error al preparar consulta de división.</p>");
    }

    $stmtDivision->bind_param("i", $division_id);
    $stmtDivision->execute();
    $resDivision = $stmtDivision->get_result();
    $divisionActual = $resDivision->fetch_assoc();
    $stmtDivision->close();

    if (!$divisionActual) {
        die("<p style='color:red;text-align:center;'>La división seleccionada no existe.</p>");
    }

    $stmtDash = $conn->prepare("
        SELECT 
            id,
            main_label,
            sub_label,
            image_url,
            target_url,
            icon_class,
            orden
        FROM dashboard_items
        WHERE is_active = 1
          AND id_division = ?
        ORDER BY orden ASC
    ");

    if (!$stmtDash) {
        logError('Error prepare dashboard_items: ' . $conn->error);
        die("<p style='color:red;text-align:center;'>Error al preparar consulta de dashboards.</p>");
    }

    $stmtDash->bind_param("i", $division_id);
    $stmtDash->execute();
    $resDash = $stmtDash->get_result();

    while ($dash = $resDash->fetch_assoc()) {
        $dashboards[] = $dash;
    }

    $stmtDash->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Visibility | Clientes</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- 
      Si el CSS anterior te pisa estilos, deja comentada esta línea.
      <link rel="stylesheet" href="css/dashboard1.css">
    -->

    <style>
        :root {
            --blue-900: #042d78;
            --blue-800: #06409e;
            --blue-700: #0a5ed8;
            --blue-500: #3b8cff;
            --text-main: #0f2345;
            --text-muted: #62718c;
            --glass: rgba(255,255,255,.78);
            --border: rgba(35, 80, 140, .10);
            --shadow-soft: 0 22px 55px rgba(30, 64, 120, .14);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
            color: var(--text-main);
            overflow: hidden;
            background:
                radial-gradient(circle at 50% 42%, rgba(62, 142, 255, .22) 0%, rgba(62, 142, 255, .08) 22%, rgba(255,255,255,0) 46%),
                linear-gradient(135deg, #f7faff 0%, #eef3fb 45%, #e8edf6 100%);
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            pointer-events: none;
            border-radius: 50%;
            filter: blur(10px);
            opacity: .9;
        }

        body::before {
            width: 520px;
            height: 520px;
            top: -180px;
            right: -140px;
            background: radial-gradient(circle, rgba(72, 149, 255, .18), rgba(255,255,255,0) 65%);
        }

        body::after {
            width: 460px;
            height: 460px;
            bottom: -160px;
            left: -130px;
            background: radial-gradient(circle, rgba(120, 198, 255, .16), rgba(255,255,255,0) 65%);
        }

        .page-shell {
            width: 100%;
            height: 100vh;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
        }

        .brand-top {
            position: fixed;
            top: 24px;
            left: 28px;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #0d2347;
            font-weight: 800;
            letter-spacing: .2px;
            opacity: .92;
        }

        .brand-mark {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            background: linear-gradient(135deg, #0b69e3, #002f87);
            box-shadow: 0 10px 24px rgba(0, 72, 170, .18);
            position: relative;
        }

        .brand-mark::before {
            content: "";
            position: absolute;
            width: 9px;
            height: 20px;
            left: 9px;
            top: 7px;
            border-radius: 8px;
            background: rgba(255,255,255,.95);
            transform: rotate(-28deg);
        }

        .brand-mark::after {
            content: "";
            position: absolute;
            width: 9px;
            height: 25px;
            left: 19px;
            top: 4px;
            border-radius: 8px;
            background: rgba(255,255,255,.72);
            transform: rotate(28deg);
        }

        .top-actions {
            position: fixed;
            top: 22px;
            right: 28px;
            z-index: 20;
            display: flex;
            gap: 12px;
        }

        .top-action {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            border: 1px solid rgba(10, 54, 120, .06);
            background: rgba(255,255,255,.68);
            backdrop-filter: blur(12px);
            box-shadow: 0 12px 28px rgba(25, 60, 110, .10);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #304769;
            font-weight: 800;
            text-decoration: none;
        }

        .orbit-stage {
            position: relative;
            width: min(1180px, 96vw);
            height: min(760px, calc(100vh - 70px));
            min-height: 620px;
            display: flex;
            align-items: center;
            justify-content: center;
            isolation: isolate;
        }

        .orbit-bg {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 1;
        }

        .orbit-line {
            position: absolute;
            left: 50%;
            top: 50%;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            border: 1px solid rgba(255,255,255,.68);
            box-shadow:
                inset 0 0 28px rgba(65, 138, 255, .08),
                0 0 20px rgba(65, 138, 255, .08);
        }

        .orbit-line.line-1 {
            width: 520px;
            height: 330px;
            border-style: solid;
            opacity: .92;
        }

        .orbit-line.line-2 {
            width: 720px;
            height: 460px;
            border-style: dashed;
            border-color: rgba(120, 172, 235, .32);
            opacity: .75;
        }

        .orbit-line.line-3 {
            width: 930px;
            height: 590px;
            border-color: rgba(255,255,255,.55);
            opacity: .72;
        }

        .orbit-line.line-4 {
            width: 1080px;
            height: 680px;
            border-style: dashed;
            border-color: rgba(120, 172, 235, .22);
            opacity: .52;
        }

        .spark-layer {
            position: absolute;
            inset: 0;
            z-index: 4; /* detrás de los nodos, pero delante del fondo */
            pointer-events: none;
        }
        
        .spark {
            position: absolute;
            left: calc(50% + var(--x));
            top: calc(50% + var(--y));
            width: var(--size);
            height: var(--size);
            border-radius: 50%;
            transform: translate(-50%, -50%) scale(.75);
            background: radial-gradient(
                circle,
                rgba(255,255,255,1) 0%,
                rgba(214,239,255,.95) 35%,
                rgba(255,255,255,0) 75%
            );
            box-shadow:
                0 0 10px rgba(120, 200, 255, .85),
                0 0 20px rgba(90, 176, 255, .60),
                0 0 34px rgba(90, 176, 255, .28);
            opacity: .85;
            animation: pulseSpark var(--duration) ease-in-out infinite;
            animation-delay: var(--delay);
        }
        
        @keyframes pulseSpark {
            0%, 100% {
                transform: translate(-50%, -50%) scale(.65);
                opacity: .35;
            }
        
            50% {
                transform: translate(-50%, -50%) scale(1.18);
                opacity: 1;
            }
        }

        .center-node {
            position: absolute;
            left: 50%;
            top: 50%;
            z-index: 6;
            width: 275px;
            height: 275px;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            color: #fff;
            background:
                radial-gradient(circle at 35% 28%,
                    rgba(245, 247, 250, .98),
                    rgba(210, 217, 226, .98) 34%,
                    rgba(160, 170, 182, 1) 74%);
            box-shadow:
                0 0 0 16px rgba(255,255,255,.22),
                0 0 0 28px rgba(180, 190, 205, .10),
                0 30px 80px rgba(120, 130, 145, .20),
                inset 0 0 45px rgba(255,255,255,.18);          
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }

        .center-node::before {
            content: "";
            position: absolute;
            inset: 16px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,.18);
        }

        .center-node::after {
            content: "";
            position: absolute;
            width: 160px;
            height: 60px;
            top: 25px;
            left: 42px;
            border-radius: 50%;
            background: rgba(255,255,255,.13);
            filter: blur(20px);
            transform: rotate(-18deg);
        }

        .center-icon {
            position: relative;
            z-index: 2;
            width: 46px;
            height: 46px;
            margin-bottom: 8px;
            opacity: .96;
        }

        .center-title {
            position: relative;
            z-index: 2;
            font-size: 34px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: 1.6px;
            text-transform: uppercase;
        }

        .center-subtitle {
            position: relative;
            z-index: 2;
            margin-top: 10px;
            max-width: 185px;
            font-size: 12px;
            line-height: 1.35;
            font-weight: 600;
            color: rgba(255,255,255,.76);
        }

        .nodes-layer {
            position: absolute;
            inset: 0;
            z-index: 5;
        }

        .orbit-node {
            position: absolute;
            left: 50%;
            top: 50%;
            width: var(--node-size);
            height: var(--node-size);
            transform: translate(-50%, -50%) translate(var(--x), var(--y));
            text-decoration: none;
            color: var(--text-main);
            border-radius: 50%;
            outline: none;
        }

        .orbit-node-inner {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background:
                linear-gradient(145deg, rgba(255,255,255,.96), rgba(247,250,255,.88));
            border: 1px solid rgba(255,255,255,.82);
            box-shadow:
                0 22px 45px rgba(31, 73, 135, .15),
                inset 0 0 0 8px rgba(255,255,255,.62),
                inset 0 -18px 30px rgba(50, 112, 200, .05);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 18px;
            position: relative;
            overflow: hidden;
            animation: floatNode 5.2s ease-in-out infinite;
            animation-delay: var(--delay);
            transition:
                transform .24s ease,
                box-shadow .24s ease,
                border-color .24s ease;
        }

        .orbit-node-inner::before {
            content: "";
            position: absolute;
            inset: -30%;
            background:
                radial-gradient(circle at 35% 25%, rgba(255,255,255,.95), rgba(255,255,255,0) 40%),
                radial-gradient(circle at 65% 80%, rgba(75, 151, 255, .08), rgba(255,255,255,0) 46%);
            pointer-events: none;
        }

        .orbit-node:hover .orbit-node-inner,
        .orbit-node:focus-visible .orbit-node-inner {
            transform: scale(1.065) translateY(-5px);
            box-shadow:
                0 30px 70px rgba(28, 83, 175, .22),
                0 0 0 8px rgba(70, 148, 255, .10),
                inset 0 0 0 8px rgba(255,255,255,.70);
            border-color: rgba(72, 149, 255, .35);
        }

        @keyframes floatNode {
            0%, 100% {
                translate: 0 0;
            }

            50% {
                translate: 0 -9px;
            }
        }

        .node-logo {
            position: relative;
            z-index: 2;
            width: 80%;
            height: 80%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
        }

        .node-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
            filter: drop-shadow(0 6px 8px rgba(20, 55, 110, .08));
        }

        .node-logo.has-fallback::before {
            content: attr(data-initials);
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: linear-gradient(135deg, #edf5ff, #ffffff);
            border: 1px solid rgba(20, 80, 150, .09);
            color: #0b54b2;
            font-size: 24px;
            font-weight: 900;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 0 5px rgba(255,255,255,.72);
        }

        .node-name {
            position: relative;
            z-index: 2;
            font-size: 14px;
            font-weight: 900;
            letter-spacing: .3px;
            text-transform: uppercase;
            text-align: center;
            line-height: 1.10;
            color: #10254b;
            max-width: 100px;
            word-break: break-word;
        }

        .node-count,
        .node-subtitle {
            position: relative;
            z-index: 2;
            margin-top: 5px;
            font-size: 10px;
            line-height: 1.2;
            color: #6a7892;
            font-weight: 700;
            text-align: center;
            max-width: 98px;
        }

        .dashboard-node .node-name {
            font-size: 13px;
            max-width: 102px;
        }

        .dashboard-node .node-logo {
            width: 68px;
            height: 46px;
            margin-bottom: 7px;
        }

        .hint-pill {
            position: absolute;
            left: 50%;
            bottom: -33px;
            transform: translateX(-50%);
            z-index: 1;
            min-width: min(560px, 86vw);
            min-height: 46px;
            padding: 11px 22px;
            border-radius: 999px;
            background: rgba(255,255,255,.70);
            border: 1px solid rgba(255,255,255,.78);
            box-shadow:
                0 18px 45px rgba(40, 80, 130, .10),
                inset 0 0 20px rgba(255,255,255,.65);
            backdrop-filter: blur(14px);
            color: #506789;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }

        .hint-pill svg {
            width: 19px;
            height: 19px;
            color: #315f9d;
            flex: 0 0 auto;
        }

        .back-button {
            position: fixed;
            top: 26px;
            left: 28px;
            z-index: 30;
            border: 1px solid rgba(20, 70, 130, .10);
            background: rgba(255,255,255,.76);
            backdrop-filter: blur(16px);
            border-radius: 999px;
            padding: 12px 17px;
            color: #193d72;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 14px 34px rgba(31, 73, 135, .10);
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 42px rgba(31, 73, 135, .16);
        }

        .empty-state {
            position: absolute;
            left: 50%;
            top: calc(50% + 205px);
            transform: translateX(-50%);
            z-index: 9;
            padding: 14px 20px;
            border-radius: 18px;
            background: rgba(255,255,255,.78);
            color: #52647f;
            box-shadow: 0 18px 42px rgba(31, 73, 135, .12);
            font-size: 14px;
            font-weight: 700;
            text-align: center;
        }

        .footer-copy {
            position: fixed;
            left: 28px;
            bottom: 18px;
            z-index: 20;
            color: #6f7d94;
            font-size: 12px;
            font-weight: 600;
        }

        .footer-copy strong {
            color: #0b66d8;
        }

        @media (max-width: 980px) {
            html,
            body {
                overflow: auto;
            }

            .page-shell {
                height: auto;
                min-height: 100vh;
                padding: 84px 18px 86px;
                align-items: flex-start;
            }

            .brand-top {
                top: 18px;
                left: 18px;
            }

            .top-actions {
                top: 16px;
                right: 18px;
            }

            .orbit-stage {
                width: 100%;
                height: auto;
                min-height: auto;
                display: block;
                padding-top: 10px;
            }

            .orbit-bg {
                display: none;
            }

            .center-node {
                position: relative;
                left: auto;
                top: auto;
                transform: none;
                margin: 0 auto 28px;
                width: 220px;
                height: 220px;
            }

            .center-title {
                font-size: 26px;
            }

            .nodes-layer {
                position: relative;
                inset: auto;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
                gap: 18px;
                width: min(760px, 100%);
                margin: 0 auto;
            }

            .orbit-node {
                position: relative;
                left: auto;
                top: auto;
                transform: none;
                width: 100%;
                height: 145px;
            }

            .orbit-node-inner {
                animation: none;
            }

            .hint-pill {
                position: relative;
                left: auto;
                bottom: auto;
                transform: none;
                margin: 28px auto 0;
                min-width: 0;
                width: min(560px, 100%);
            }

            .back-button {
                top: 18px;
                left: 18px;
            }

            .footer-copy {
                position: relative;
                left: auto;
                bottom: auto;
                text-align: center;
                margin: 24px 0 0;
            }

            .empty-state {
                position: relative;
                left: auto;
                top: auto;
                transform: none;
                margin: 18px auto 0;
                max-width: 420px;
            }
        }
.center-node.mc-center {
    background:
        radial-gradient(circle at 35% 28%, rgba(173, 220, 85, .98), rgba(138, 188, 49, .98) 34%, rgba(102, 165, 48, 1) 74%);
    box-shadow:
        0 0 0 16px rgba(255,255,255,.22),
        0 0 0 28px rgba(146, 201, 63, .10),
        0 30px 80px rgba(108, 168, 50, .34),
        inset 0 0 45px rgba(255,255,255,.12);
}

.center-node.center-action {
    text-decoration: none;
    cursor: pointer;
    transition:
        transform .25s ease,
        box-shadow .25s ease,
        filter .25s ease;
}

.center-node.center-action:hover {
    transform: translate(-50%, -50%) scale(1.045);
    box-shadow:
        0 0 0 16px rgba(255,255,255,.26),
        0 0 0 34px rgba(146, 201, 63, .16),
        0 36px 95px rgba(108, 168, 50, .42),
        inset 0 0 48px rgba(255,255,255,.16);
    filter: brightness(1.04);
}

.center-mc-logo {
    position: relative;
    z-index: 2;
    width: 190px;
    height: 125px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 6px;
}

.center-mc-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    display: block;
    filter: drop-shadow(0 12px 18px rgba(35, 85, 20, .22));
}

.center-mc-logo.has-fallback::before {
    content: attr(data-initials);
    width: 84px;
    height: 84px;
    border-radius: 50%;
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.28);
    color: #ffffff;
    font-size: 28px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: inset 0 0 0 6px rgba(255,255,255,.16);
}

.mc-title {
    font-size: 34px;
    letter-spacing: 1.5px;
}

.neural-light-canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    z-index: 4;
    pointer-events: none;
    opacity: 1;
}

/* Asegura que los nodos sigan sobre las líneas */
.nodes-layer {
    z-index: 5;
}

.center-node {
    z-index: 6;
}

.back-main-button {
    position: fixed;
    top: 26px;
    left: 28px;
    z-index: 50;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 18px;
    border-radius: 999px;
    border: 1px solid rgba(22, 88, 145, .12);
    background: rgba(255,255,255,.78);
    backdrop-filter: blur(16px);
    color: #194675;
    font-size: 13px;
    font-weight: 800;
    text-decoration: none;
    box-shadow: 0 14px 34px rgba(31, 73, 135, .12);
    transition: transform .2s ease, box-shadow .2s ease;
}

.back-main-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 18px 44px rgba(31, 73, 135, .18);
}

.selected-division-center {
    background:
        radial-gradient(circle at 35% 28%,
            rgba(179, 224, 96, .98),
            rgba(143, 194, 58, .98) 34%,
            rgba(104, 168, 48, 1) 74%);
    box-shadow:
        0 0 0 16px rgba(255,255,255,.22),
        0 0 0 28px rgba(146, 201, 63, .10),
        0 30px 80px rgba(108, 168, 50, .28),
        inset 0 0 45px rgba(255,255,255,.10);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}

.selected-division-center .center-mc-logo {
    width: 210px;
    height: 150px;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.selected-division-center .center-mc-logo img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    display: block;
    filter: drop-shadow(0 10px 18px rgba(20, 50, 100, .18));
}

.selected-division-center .center-subtitle,
.selected-division-center .center-title {
    display: none;
}

.dashboard-node .orbit-node-inner.dashboard-card {
    position: relative;
    padding: 10px;
    justify-content: center;
    overflow: hidden;
}

.dashboard-media {
    width: 88%;
    height: 74px;
    border-radius: 18px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,.45);
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.55);
}

.dashboard-media img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    border-radius: 18px;
}

.dashboard-media.has-fallback::before {
    content: attr(data-initials);
    width: 68px;
    height: 68px;
    border-radius: 18px;
    background: linear-gradient(135deg, #eef6ff, #ffffff);
    color: #0b54b2;
    font-size: 22px;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: inset 0 0 0 4px rgba(255,255,255,.65);
}

.dashboard-title-badge {
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 12px;
    background: rgba(255, 255, 255, 0.90);
    color: #10254b;
    border-radius: 14px;
    padding: 8px 10px;
    font-size: 11px;
    font-weight: 800;
    line-height: 1.15;
    text-align: center;
    box-shadow: 0 10px 20px rgba(30, 60, 110, .10);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
}

.dashboard-node .node-name,
.dashboard-node .node-subtitle,
.dashboard-node .node-logo {
    display: none;
}

.dashboard-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 18px;
}

.dashboard-modal.is-open {
    display: flex;
}

.dashboard-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(7, 18, 38, 0.38);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.dashboard-modal-content {
    position: relative;
    z-index: 2;
    width: 95vw;
    height: 95vh;
    background: rgba(255, 255, 255, 0.96);
    border-radius: 24px;
    overflow: hidden;
    box-shadow:
        0 30px 90px rgba(18, 45, 90, .28),
        0 0 0 1px rgba(255,255,255,.65);
    display: flex;
    flex-direction: column;
    animation: modalIn .22s ease both;
}

@keyframes modalIn {
    from {
        opacity: 0;
        transform: scale(.97) translateY(10px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

.dashboard-modal-header {
    height: 58px;
    min-height: 58px;
    padding: 10px 16px 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(248, 251, 255, .96);
    border-bottom: 1px solid rgba(30, 80, 140, .10);
}

.dashboard-modal-header strong {
    display: block;
    font-size: 14px;
    color: #10254b;
    font-weight: 900;
}

.dashboard-modal-header small {
    display: block;
    margin-top: 2px;
    font-size: 11px;
    color: #71819b;
    font-weight: 600;
}

.dashboard-modal-close {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: none;
    background: #eef4fb;
    color: #173b72;
    font-size: 26px;
    line-height: 1;
    cursor: pointer;
    transition: all .2s ease;
}

.dashboard-modal-close:hover {
    background: #dfeaf8;
    transform: scale(1.06);
}

#dashboardModalFrame {
    width: 100%;
    height: calc(95vh - 58px);
    border: none;
    background: #ffffff;
}

body.modal-open {
    overflow: hidden;
}

@media (max-width: 768px) {
    .dashboard-modal {
        padding: 8px;
    }

    .dashboard-modal-content {
        width: 98vw;
        height: 96vh;
        border-radius: 18px;
    }

    #dashboardModalFrame {
        height: calc(96vh - 58px);
    }
}
    </style>
</head>

<body>

<?php if ($viewMode === 'dashboards' && $isAdminDivision): ?>
    <a href="ui_dashboard2.php" class="back-button">← Volver a clientes</a>
<?php else: ?>
<?php endif; ?>

<?php
$totalOrbitItems = ($viewMode === 'clients') ? count($divisiones) : count($dashboards);
$sparks = buildSparkPositions($totalOrbitItems, $viewMode);
?>

<?php if ($viewMode === 'dashboards' && $isAdminDivision): ?>
    <a href="ui_dashboard2.php" class="back-main-button">
        ← Volver a clientes
    </a>
<?php endif; ?>

<main class="page-shell">
    <section class="orbit-stage">

            <div class="orbit-bg" aria-hidden="true">
                <div class="orbit-line line-1"></div>
                <div class="orbit-line line-2"></div>
                <div class="orbit-line line-3"></div>
                <div class="orbit-line line-4"></div>
            </div>
            
            
<canvas id="neuralLightCanvas" class="neural-light-canvas"></canvas>            
            
            <div class="spark-layer" aria-hidden="true">
                <?php foreach ($sparks as $spark): ?>
                    <span
                        class="spark"
                        style="
                            --x: <?php echo $spark['x']; ?>px;
                            --y: <?php echo $spark['y']; ?>px;
                            --size: <?php echo $spark['size']; ?>px;
                            --duration: <?php echo $spark['duration']; ?>;
                            --delay: <?php echo $spark['delay']; ?>s;
                        "
                    ></span>
                <?php endforeach; ?>
            </div>            

            <div class="spark-layer" aria-hidden="true">
                <!-- Puntos principales -->
                <span class="spark" style="--left: 50%; --top: 8%;  --size: 14px; --duration: 4.8s; --delay: .2s;"></span>
                <span class="spark" style="--left: 50%; --top: 88%; --size: 14px; --duration: 5.2s; --delay: .9s;"></span>
                <span class="spark" style="--left: 12%; --top: 50%; --size: 14px; --duration: 4.6s; --delay: .5s;"></span>
                <span class="spark" style="--left: 88%; --top: 50%; --size: 14px; --duration: 5.0s; --delay: 1.1s;"></span>
            
                <!-- Puntos secundarios -->
                <span class="spark" style="--left: 24%; --top: 22%; --size: 9px;  --duration: 5.4s; --delay: .4s;"></span>
                <span class="spark" style="--left: 76%; --top: 22%; --size: 9px;  --duration: 4.9s; --delay: 1.2s;"></span>
                <span class="spark" style="--left: 24%; --top: 78%; --size: 9px;  --duration: 5.8s; --delay: .8s;"></span>
                <span class="spark" style="--left: 76%; --top: 78%; --size: 9px;  --duration: 4.7s; --delay: 1.4s;"></span>
            </div>

        <?php if ($viewMode === 'clients'): ?>

<?php if ($viewMode === 'clients'): ?>

    <a href="<?php echo h($mcCenterUrl); ?>" class="center-node mc-center center-action">
        <div class="center-mc-logo <?php echo empty($mcCenterLogo) ? 'has-fallback' : ''; ?>"
             data-initials="MC">
            <?php if (!empty($mcCenterLogo)): ?>
                <img
                    src="<?php echo h($mcCenterLogo); ?>"
                    alt="<?php echo h($mcCenterName); ?>"
                    loading="lazy"
                    onerror="this.style.display='none'; this.parentElement.classList.add('has-fallback');"
                >
            <?php endif; ?>
        </div>
    </a>

<?php else: ?>

    <?php
        $divisionNombre = $divisionActual['nombre'] ?? 'División';
        $divisionLogo = $divisionActual['image_url'] ?? '';
        $divisionInitials = divisionInitials($divisionNombre);
    ?>

<div class="center-node selected-division-center">
    <div class="center-mc-logo <?php echo empty($divisionLogo) ? 'has-fallback' : ''; ?>"
         data-initials="<?php echo h($divisionInitials); ?>">
        <?php if (!empty($divisionLogo)): ?>
            <img
                src="<?php echo h($divisionLogo); ?>"
                alt="<?php echo h($divisionNombre); ?>"
                loading="lazy"
                onerror="this.style.display='none'; this.parentElement.classList.add('has-fallback');"
            >
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

            <div class="nodes-layer">
                <?php if (count($divisiones) > 0): ?>
                    <?php foreach ($divisiones as $i => $div): ?>
                        <?php
                            $pos = buildOrbitPosition($i, count($divisiones), 'clients');
                            $nombre = $div['nombre'] ?? '';
                            $logo = $div['image_url'] ?? '';
                            $totalDashboards = (int)($div['total_dashboards'] ?? 0);
                            $initials = divisionInitials($nombre);
                        ?>

                        <a
                            class="orbit-node client-node"
                            href="ui_dashboard2.php?division_id=<?php echo (int)$div['id']; ?>"
                            style="
                                --x: <?php echo $pos['x']; ?>px;
                                --y: <?php echo $pos['y']; ?>px;
                                --node-size: <?php echo $pos['size']; ?>px;
                                --delay: <?php echo $pos['delay']; ?>s;
                            "
                            title="<?php echo h($nombre); ?>"
                        >
                            <div class="orbit-node-inner">
                                <div class="node-logo <?php echo empty($logo) ? 'has-fallback' : ''; ?>"
                                     data-initials="<?php echo h($initials); ?>">
                                    <?php if (!empty($logo)): ?>
                                        <img
                                            src="<?php echo h($logo); ?>"
                                            alt="<?php echo h($nombre); ?>"
                                            loading="lazy"
                                            onerror="this.style.display='none'; this.parentElement.classList.add('has-fallback');"
                                        >
                                    <?php endif; ?>
                                </div>

                                <?php if (false): ?>
                                    <div class="node-name"><?php echo h($nombre); ?></div>
                                
                                    <div class="node-count">
                                        <?php echo $totalDashboards; ?>
                                        <?php echo $totalDashboards === 1 ? 'dashboard' : 'dashboards'; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        No existen divisiones activas para mostrar.
                    </div>
                <?php endif; ?>
            </div>

            <div class="hint-pill">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M8.5 11.5V5.7a1.7 1.7 0 1 1 3.4 0v5.1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M11.9 10.8V8.4a1.7 1.7 0 0 1 3.4 0v3.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M15.3 11.7V10a1.7 1.7 0 1 1 3.4 0v5.1c0 4-2.7 6.9-6.9 6.9h-.5c-2.1 0-3.9-.9-5.2-2.5L3.3 16a1.8 1.8 0 0 1 2.7-2.4l2.5 2.3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Haz clic en un cliente para explorar sus dashboards y reportes
            </div>

        <?php else: ?>

            <?php
                $divisionNombre = $divisionActual['nombre'] ?? 'División';
                $divisionLogo = $divisionActual['image_url'] ?? '';
                $divisionInitials = divisionInitials($divisionNombre);
            ?>

            <div class="center-node">
                <div class="node-logo <?php echo empty($divisionLogo) ? 'has-fallback' : ''; ?>"
                     data-initials="<?php echo h($divisionInitials); ?>">
                    <?php if (!empty($divisionLogo)): ?>
                        <img
                            src="<?php echo h($divisionLogo); ?>"
                            alt="<?php echo h($divisionNombre); ?>"
                            loading="lazy"
                            onerror="this.style.display='none'; this.parentElement.classList.add('has-fallback');"
                        >
                    <?php endif; ?>
                </div>
            </div>

<div class="nodes-layer">

    <?php if ($viewMode === 'clients'): ?>

        <?php if (count($divisiones) > 0): ?>
            <?php foreach ($divisiones as $i => $div): ?>
                <?php
                    $pos = buildOrbitPosition($i, count($divisiones), 'clients');

                    $nombre = $div['nombre'] ?? '';
                    $logo = $div['image_url'] ?? '';
                    $totalDashboards = (int)($div['total_dashboards'] ?? 0);
                    $initials = divisionInitials($nombre);
                ?>

                <a
                    class="orbit-node client-node"
                    href="ui_dashboard2.php?division_id=<?php echo (int)$div['id']; ?>"
                    style="
                        --x: <?php echo $pos['x']; ?>px;
                        --y: <?php echo $pos['y']; ?>px;
                        --node-size: <?php echo $pos['size']; ?>px;
                        --delay: <?php echo $pos['delay']; ?>s;
                    "
                    title="<?php echo h($nombre); ?>"
                >
                    <div class="orbit-node-inner">
                        <div class="node-logo <?php echo empty($logo) ? 'has-fallback' : ''; ?>"
                             data-initials="<?php echo h($initials); ?>">
                            <?php if (!empty($logo)): ?>
                                <img
                                    src="<?php echo h($logo); ?>"
                                    alt="<?php echo h($nombre); ?>"
                                    loading="lazy"
                                    onerror="this.style.display='none'; this.parentElement.classList.add('has-fallback');"
                                >
                            <?php endif; ?>
                        </div>

                        <?php if (false): ?>
                            <div class="node-name"><?php echo h($nombre); ?></div>

                            <div class="node-count">
                                <?php echo $totalDashboards; ?>
                                <?php echo $totalDashboards === 1 ? 'dashboard' : 'dashboards'; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                No existen divisiones activas para mostrar.
            </div>
        <?php endif; ?>

    <?php else: ?>

        <?php if (count($dashboards) > 0): ?>
            <?php foreach ($dashboards as $i => $dash): ?>
                <?php
                    $pos = buildOrbitPosition($i, count($dashboards), 'dashboards');

                    $dashName = $dash['main_label'] ?? 'Dashboard';
                    $dashSubtitle = $dash['sub_label'] ?? '';
                    $dashImage = $dash['image_url'] ?? '';
                    $dashInitials = divisionInitials($dashName);

                    $dashUrl = 'dashboard.php?id=' . (int)$dash['id'];
                ?>

                        <a
                            class="orbit-node dashboard-node js-open-dashboard-modal"
                            href="<?php echo h($dashUrl); ?>"
                            data-dashboard-title="<?php echo h($dashName); ?>"
                            style="
                                --x: <?php echo $pos['x']; ?>px;
                                --y: <?php echo $pos['y']; ?>px;
                                --node-size: <?php echo $pos['size']; ?>px;
                                --delay: <?php echo $pos['delay']; ?>s;
                            "
                            title="<?php echo h($dashName); ?>"
                        >
                        <div class="orbit-node-inner dashboard-card">
                            
                            <div class="dashboard-media <?php echo empty($dashImage) ? 'has-fallback' : ''; ?>"
                                 data-initials="<?php echo h($dashInitials); ?>">
                                <?php if (!empty($dashImage)): ?>
                                    <img
                                        src="<?php echo h($dashImage); ?>"
                                        alt="<?php echo h($dashName); ?>"
                                        loading="lazy"
                                        onerror="this.style.display='none'; this.parentElement.classList.add('has-fallback');"
                                    >
                                <?php endif; ?>
                            </div>
                    
                            <div class="dashboard-title-badge">
                                <?php echo h($dashName); ?>
                            </div>
                    
                            <?php if (false && trim($dashSubtitle) !== ''): ?>
                                <div class="node-subtitle"><?php echo h($dashSubtitle); ?></div>
                            <?php endif; ?>
                    
                        </div>
                    </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                No existen dashboards disponibles para esta división.
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

            <div class="hint-pill">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v13a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 18.5v-13Z" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                Haz clic en un dashboard para abrir el reporte
            </div>

        <?php endif; ?>

    </section>
</main>

<div id="dashboardModal" class="dashboard-modal" aria-hidden="true">
    <div class="dashboard-modal-backdrop" data-close-modal></div>

    <div class="dashboard-modal-content">
        <div class="dashboard-modal-header">
            <div>
                <strong id="dashboardModalTitle">Dashboard</strong>
                <small>Vista integrada del reporte</small>
            </div>

            <button type="button" class="dashboard-modal-close" data-close-modal>
                ×
            </button>
        </div>

        <iframe 
            id="dashboardModalFrame" 
            src="" 
            frameborder="0"
            loading="lazy"
            allowfullscreen>
        </iframe>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /*
      Pequeña mejora visual:
      al entrar, los nodos aparecen suavemente.
    */
    const nodes = document.querySelectorAll('.orbit-node');

    nodes.forEach((node, index) => {
        node.style.opacity = '0';
        node.style.transition = 'opacity .45s ease, filter .45s ease';
        node.style.filter = 'blur(6px)';

        setTimeout(() => {
            node.style.opacity = '1';
            node.style.filter = 'blur(0)';
        }, 80 + (index * 55));
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('dashboardModal');
    const iframe = document.getElementById('dashboardModalFrame');
    const title = document.getElementById('dashboardModalTitle');
    const openButtons = document.querySelectorAll('.js-open-dashboard-modal');
    const closeButtons = document.querySelectorAll('[data-close-modal]');

    if (!modal || !iframe) return;

    function openDashboardModal(url, dashboardTitle) {
        iframe.src = url;

        if (title) {
            title.textContent = dashboardTitle || 'Dashboard';
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    function closeDashboardModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');

        setTimeout(function () {
            iframe.src = '';
        }, 180);
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();

            const url = button.getAttribute('href');
            const dashboardTitle = button.dataset.dashboardTitle || button.getAttribute('title') || 'Dashboard';

            if (!url || url === '#') return;

            openDashboardModal(url, dashboardTitle);
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeDashboardModal);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeDashboardModal();
        }
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const stage = document.querySelector('.orbit-stage');
    const canvas = document.getElementById('neuralLightCanvas');
    const centerNode = document.querySelector('.center-node');
    const orbitNodes = document.querySelectorAll('.orbit-node');

    if (!stage || !canvas || !centerNode || orbitNodes.length === 0) return;

    const ctx = canvas.getContext('2d');

    let width = 0;
    let height = 0;
    let dpr = window.devicePixelRatio || 1;
    let activeNode = null;
    let time = 0;

    function resizeCanvas() {
        const rect = stage.getBoundingClientRect();

        width = rect.width;
        height = rect.height;
        dpr = window.devicePixelRatio || 1;

        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);

        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';

        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function getCenterPoint(element) {
        const rect = element.getBoundingClientRect();
        const base = stage.getBoundingClientRect();

        return {
            x: rect.left - base.left + rect.width / 2,
            y: rect.top - base.top + rect.height / 2
        };
    }

    function drawOscillatingLine(fromEl, toEl) {
        if (!fromEl || !toEl) return;

        const start = getCenterPoint(fromEl);
        const end = getCenterPoint(toEl);

        const startX = start.x;
        const startY = start.y;
        const endX = end.x;
        const endY = end.y;

        const dx = endX - startX;
        const dy = endY - startY;
        const angle = Math.atan2(dy, dx);

        const steps = 48;

        /*
          Color adaptado a la visual clara:
          celeste/azul suave, con glow blanco.
        */
        const mainColor = '#5bbdff';
        const glowColor = 'rgba(91, 189, 255, 0.65)';

        ctx.save();

        ctx.shadowBlur = 18;
        ctx.shadowColor = glowColor;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        /*
          Capas similares a Nexus:
          - una línea principal
          - una línea secundaria más fina
          - un halo ancho y suave
        */
        const layers = [
            { phase: 0,   alpha: 0.82, thick: 2.4, amp: 18 },
            { phase: 2.2, alpha: 0.36, thick: 1.4, amp: 24 },
            { phase: 5.1, alpha: 0.16, thick: 5.2, amp: 14 }
        ];

        layers.forEach(layer => {
            ctx.beginPath();
            ctx.moveTo(startX, startY);

            for (let i = 1; i <= steps; i++) {
                const t = i / steps;

                const bx = startX + dx * t;
                const by = startY + dy * t;

                /*
                  Envelope hace que la onda sea suave:
                  empieza casi recta, se mueve más al centro,
                  y vuelve a estabilizarse al llegar al cliente.
                */
                const envelope = Math.sin(t * Math.PI);

                const wave1 = Math.sin(t * 14 - time * 3.2 + layer.phase) * layer.amp * envelope;
                const wave2 = Math.cos(t * 8 + time * 2.1 + layer.phase) * 8 * envelope;

                const offset = wave1 + wave2;

                const nx = bx + Math.cos(angle + Math.PI / 2) * offset;
                const ny = by + Math.sin(angle + Math.PI / 2) * offset;

                ctx.lineTo(nx, ny);
            }

            ctx.strokeStyle = mainColor;
            ctx.lineWidth = layer.thick;
            ctx.globalAlpha = layer.alpha;
            ctx.stroke();
        });

        /*
          Pequeños puntos luminosos sobre la línea,
          más sutiles que en Nexus para que no ensucien la visual clara.
        */
        ctx.shadowBlur = 10;
        ctx.shadowColor = 'rgba(255,255,255,.95)';
        ctx.fillStyle = '#ffffff';

const particleCount = 28;

for (let p = 0; p < particleCount; p++) {
    const t = ((time * 0.38) + (p / particleCount)) % 1;

    const bx = startX + dx * t;
    const by = startY + dy * t;

    const envelope = Math.sin(t * Math.PI);

    const wave =
        Math.sin(t * 14 - time * 4.2 + p) * 18 * envelope +
        Math.cos(t * 9 + time * 2.6 + p) * 5 * envelope;

    const px = bx + Math.cos(angle + Math.PI / 2) * wave;
    const py = by + Math.sin(angle + Math.PI / 2) * wave;

    const size = p % 3 === 0 ? 2.2 : 1.45;

    ctx.beginPath();
    ctx.globalAlpha = envelope * 0.82;
    ctx.arc(px, py, size, 0, Math.PI * 2);
    ctx.fill();
}

        ctx.restore();
        ctx.globalAlpha = 1;
    }

    function animate() {
        ctx.clearRect(0, 0, width, height);
        time += 0.022;

        if (activeNode && window.innerWidth > 768) {
            drawOscillatingLine(centerNode, activeNode);
        }

        requestAnimationFrame(animate);
    }

    orbitNodes.forEach(node => {
        node.addEventListener('mouseenter', function () {
            activeNode = node;
        });

        node.addEventListener('mouseleave', function () {
            activeNode = null;
        });

        node.addEventListener('focus', function () {
            activeNode = node;
        });

        node.addEventListener('blur', function () {
            activeNode = null;
        });
    });

    window.addEventListener('resize', resizeCanvas);

    resizeCanvas();
    animate();
});
</script>

</body>
</html>