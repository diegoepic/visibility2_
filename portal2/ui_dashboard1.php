<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('max_execution_time', 60);
ini_set('memory_limit', '512M');

$logFile = __DIR__ . '/error_dashboard.log';

function logError($message)
{
    global $logFile;
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($logFile, $timestamp . ' ' . $message . PHP_EOL, FILE_APPEND);
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (!isset($_SESSION['division_id']) || empty($_SESSION['division_id'])) {
    logError('Acceso sin sesión activa.');
    http_response_code(401);
    exit("<p style='color:red;text-align:center;'>Error: sesión no iniciada. Por favor, vuelva a ingresar al sistema.</p>");
}

$userDivision = (int)($_SESSION['division_id'] ?? 0);
$division_id = ($userDivision === 1 && isset($_GET['division_id']))
    ? (int)$_GET['division_id']
    : $userDivision;

session_write_close();

if (!isset($conn) || !$conn || $conn->connect_error) {
    logError('Error de conexión a la base de datos: ' . ($conn->connect_error ?? 'Conexión no disponible'));
    http_response_code(500);
    exit("<p style='color:red;text-align:center;'>Error de conexión a la base de datos.</p>");
}

$stmt = $conn->prepare("
    SELECT id, main_label, sub_label, image_url
    FROM dashboard_items
    WHERE is_active = 1
      AND id_division = ?
    ORDER BY orden ASC
");

if (!$stmt) {
    logError('Error prepare dashboard_items: ' . $conn->error);
    http_response_code(500);
    exit("<p style='color:red;text-align:center;'>Error al preparar la carga de dashboards.</p>");
}

$stmt->bind_param('i', $division_id);

if (!$stmt->execute()) {
    logError('Error execute dashboard_items: ' . $stmt->error);
    http_response_code(500);
    exit("<p style='color:red;text-align:center;'>Error al cargar los dashboards.</p>");
}

$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link rel="stylesheet" href="css/dashboard1.css">
    <link rel="stylesheet" href="css/topmenu.css">
<style>
#divisionSelectorContainer {
  width: calc(100% - 80px);
  max-width: 1450px;
  margin: 18px auto 14px;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

#divisionSelectorContainer label {
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}

#divisionSelectNative {
  min-width: 220px;
  max-width: 320px;
  width: 100%;
  height: 42px;
  padding: 0 42px 0 12px;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  background: #4b5563;
  color: #fff;
  font-size: 14px;
  outline: none;
}

#divisionSelectNative:focus {
  border-color: #12cfff;
  box-shadow: 0 0 0 3px rgba(18, 207, 255, 0.18);
}

@media (max-width: 768px) {
  #divisionSelectorContainer {
    width: calc(100% - 24px);
    margin: 14px auto 12px;
  }

  #divisionSelectorContainer label,
  #divisionSelectNative {
    width: 100%;
    max-width: none;
  }
}
    </style>
</head>
<body class="dashboard-page">

<?php include $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal2/includes/topmenu.php'; ?>

<main class="dashboard-content">
    <?php
    if ($userDivision === 1):
        $resultDiv = false;
        $stmtDiv = $conn->prepare("
            SELECT id, nombre
            FROM division_empresa
            WHERE estado = 1
            ORDER BY nombre ASC
        ");

        if (!$stmtDiv) {
            logError('Error prepare division_empresa: ' . $conn->error);
            echo "<p style='color:red;'>Error al cargar divisiones.</p>";
        } elseif (!$stmtDiv->execute()) {
            logError('Error execute division_empresa: ' . $stmtDiv->error);
            echo "<p style='color:red;'>Error al cargar divisiones.</p>";
        } else {
            $resultDiv = $stmtDiv->get_result();
        }
    ?>
<div id="divisionSelectorContainer">
  <label for="divisionSelectNative">División:</label>
  <select id="divisionSelectNative" onchange="if(this.value) location.href='ui_dashboard1.php?division_id=' + this.value;">
    <?php while ($div = $resultDiv->fetch_assoc()): ?>
      <option value="<?php echo (int)$div['id']; ?>" <?php echo ((int)$div['id'] === $division_id) ? 'selected' : ''; ?>>
        <?php echo htmlspecialchars($div['nombre'], ENT_QUOTES, 'UTF-8'); ?>
      </option>
    <?php endwhile; ?>
  </select>
</div>
    <?php
        if ($stmtDiv instanceof mysqli_stmt) {
            $stmtDiv->close();
        }
    endif;
    ?>

    <div class="slider-container">
        <div class="accordion-slider">
            <?php if ($result && $result->num_rows > 0): ?>
                <?php $count = 1; ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div
                        class="slide"
                        data-url="dashboard.php?id=<?php echo (int)$row['id']; ?>"
                        style="background-image: url('<?php echo htmlspecialchars($row['image_url'], ENT_QUOTES, 'UTF-8'); ?>');">
                        <div class="slide-content">
                            <div class="slide-number"><?php echo sprintf('%02d', $count); ?></div>
                            <div class="car-brand"><?php echo htmlspecialchars($row['main_label'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="car-subtitle"><?php echo htmlspecialchars($row['sub_label'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="add-button"></div>
                    </div>
                    <?php $count++; ?>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color:white;">No existen dashboards disponibles para esta división.</p>
            <?php endif; ?>

            <button class="navigation-arrows nav-prev" type="button">‹</button>
            <button class="navigation-arrows nav-next" type="button">›</button>
        </div>
    </div>
</main>

<script src="//cdnjs.cloudflare.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>
<script src="js/accordeon.js"></script>
<script src="js/topmenu.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    let redirectTimeout = setTimeout(function () {
        window.location.href = "https://visibility.cl/portal/dashboard1_test.php";
    }, 8000);

    window.addEventListener("load", function () {
        clearTimeout(redirectTimeout);
    });

    window.addEventListener("error", function () {
        window.location.href = "https://visibility.cl/portal/dashboard1_test.php";
    });
});
</script>

</body>
</html>
<?php
if ($stmt instanceof mysqli_stmt) {
    $stmt->close();
}
$conn->close();
?>