<?php
// Ojo: aquí ya NO va session_start();
// index.php ya inicia la sesión antes del include.

// ⚙️ Mostrar errores en entorno de prueba
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ini_set('max_execution_time', 60);
ini_set('memory_limit', '512M');

$logFile = __DIR__ . '/error_dashboard.log';
function logError($message) {
    global $logFile;
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($logFile, "$timestamp $message\n", FILE_APPEND);
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

if (!isset($_SESSION['division_id']) || empty($_SESSION['division_id'])) {
    logError("Acceso sin sesión activa.");
    die("<p style='color:red;text-align:center;'>Error: sesión no iniciada. Por favor, vuelva a ingresar al sistema.</p>");
}

$userDivision = (int)$_SESSION['division_id'];

if ($userDivision === 1 && isset($_GET['division_id'])) {
    $division_id = (int)$_GET['division_id'];
} else {
    $division_id = $userDivision;
}

if (!$conn || $conn->connect_error) {
    logError("Error de conexión a la base de datos: " . $conn->connect_error);
    die("<p style='color:red;text-align:center;'>Error de conexión a la base de datos.</p>");
}

$query = "SELECT * FROM dashboard_items WHERE is_active = 1 AND id_division = '$division_id' ORDER BY orden ASC";
$result = $conn->query($query);
if ($result === false) {
    logError("Error en consulta dashboard_items: " . $conn->error . " | Query: " . $query);
    die("<p style='color:red;text-align:center;'>Error al cargar los dashboards.</p>");
}
?>

<style>
#divisionSelectorContainer {
    position: fixed;
    top: 10px;
    left: 10px;
    z-index: 1000;
    padding: 6px 10px;
    font-size: 14px;
}
</style>

<?php
try {
    if ($userDivision == 1):
?>
<div id="divisionSelectorContainer">
    <label style="font-weight:600;">División:</label>
    <div class="select" tabindex="1">
        <?php
        $queryDiv = "SELECT * FROM division_empresa WHERE estado = 1 ORDER BY nombre ASC";
        $resultDiv = $conn->query($queryDiv);

        if ($resultDiv === false) {
            logError("Error en consulta division_empresa: " . $conn->error);
            echo "<p style='color:red;'>Error al cargar divisiones.</p>";
        } else {
            $radioName = "divisionSelect";
            $optIndex = 1;

            while ($div = $resultDiv->fetch_assoc()):
                $isSelected = ($div['id'] == $division_id);
                $checked = $isSelected ? 'checked' : '';
                $labelClass = $isSelected ? 'option active' : 'option';
        ?>
            <input
                class="selectopt"
                name="<?php echo $radioName; ?>"
                type="radio"
                id="opt<?php echo $optIndex; ?>"
                value="<?php echo $div['id']; ?>"
                <?php echo $checked; ?>
            >
            <label
                for="opt<?php echo $optIndex; ?>"
                class="<?php echo $labelClass; ?>"
                onclick="location.href='?mod=home&division_id=<?php echo $div['id']; ?>'">
                <?php echo htmlspecialchars($div['nombre']); ?>
            </label>
        <?php
                $optIndex++;
            endwhile;
        }
        ?>
    </div>
</div>
<?php
    endif;
} catch (Throwable $e) {
    logError("Error al renderizar selector: " . $e->getMessage());
    echo "<p style='color:red;'>Error al renderizar el selector.</p>";
}
?>

<div class="slider-container">
    <div class="accordion-slider">
        <?php
        try {
            if ($result->num_rows > 0):
                $count = 1;
                while ($row = $result->fetch_assoc()):
        ?>
            <div class="slide"
                 data-url="dashboard.php?id=<?php echo $row['id']; ?>"
                 style="background-image: url('<?php echo $row['image_url']; ?>');">
                <div class="slide-content">
                    <div class="slide-number"><?php echo sprintf('%02d', $count); ?></div>
                    <div class="car-brand"><?php echo htmlspecialchars($row['main_label']); ?></div>
                    <div class="car-subtitle"><?php echo htmlspecialchars($row['sub_label']); ?></div>
                </div>
                <div class="add-button"></div>
            </div>
        <?php
                    $count++;
                endwhile;
            else:
        ?>
            <p style="color:#333;">No existen dashboards disponibles para esta división.</p>
        <?php
            endif;
        } catch (Throwable $e) {
            logError("Error al renderizar slides: " . $e->getMessage());
            echo "<p style='color:red;'>Error al mostrar los dashboards.</p>";
        }
        ?>

        <button class="navigation-arrows nav-prev">‹</button>
        <button class="navigation-arrows nav-next">›</button>
    </div>
</div>