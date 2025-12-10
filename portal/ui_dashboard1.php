<?php
session_start();

// ⚙️ Mostrar errores en entorno de prueba (quítalo o desactívalo en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ⚙️ Aumentar recursos para evitar timeouts por consultas pesadas
ini_set('max_execution_time', 60);
ini_set('memory_limit', '512M');

// 📄 Ruta de log local (puedes ajustar si tienes una carpeta de logs)
$logFile = __DIR__ . '/error_dashboard.log';
function logError($message) {
    global $logFile;
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($logFile, "$timestamp $message\n", FILE_APPEND);
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// 🧩 Verificar sesión válida
if (!isset($_SESSION['division_id']) || empty($_SESSION['division_id'])) {
    logError("Acceso sin sesión activa.");
    die("<p style='color:red;text-align:center;'>Error: sesión no iniciada. Por favor, vuelva a ingresar al sistema.</p>");
}

// División del usuario
$userDivision = (int)$_SESSION['division_id'];

// 🔒 Determinar división activa
if ($userDivision === 1 && isset($_GET['division_id'])) {
    $division_id = (int)$_GET['division_id'];
} else {
    $division_id = $userDivision;
}

// 🔍 Validar conexión
if (!$conn || $conn->connect_error) {
    logError("Error de conexión a la base de datos: " . $conn->connect_error);
    die("<p style='color:red;text-align:center;'>Error de conexión a la base de datos.</p>");
}

// 🧠 Ejecutar consulta principal con manejo de errores
$query = "SELECT * FROM dashboard_items WHERE is_active = 1 AND id_division = '$division_id' ORDER BY orden ASC";
$result = $conn->query($query);
if ($result === false) {
    logError("Error en consulta dashboard_items: " . $conn->error . " | Query: " . $query);
    die("<p style='color:red;text-align:center;'>Error al cargar los dashboards.</p>");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Dashboard</title>
  <link rel="stylesheet" href="css/dashboard1.css">  
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
</head>

<body>
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
              onclick="location.href='ui_dashboard1.php?division_id=<?php echo $div['id']; ?>'">
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
          while ($row = $result->fetch_assoc()): ?>
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
        else: ?>
          <p style="color:white;">No existen dashboards disponibles para esta división.</p>
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

  <script src='//cdnjs.cloudflare.com/ajax/libs/jquery/2.1.3/jquery.min.js'></script>
  <script src="js/accordeon.js"></script>
  
  <script>
document.addEventListener("DOMContentLoaded", function() {
  // Si el body tarda demasiado o hay un error JS global, redirige
  let redirectTimeout = setTimeout(() => {
    window.location.href = "https://visibility.cl/portal/dashboard1_test.php";
  }, 8000); // 8 segundos de espera

  // Si la página cargó correctamente, cancelamos la redirección
  window.addEventListener("load", function() {
    clearTimeout(redirectTimeout);
  });

  // Captura errores globales del navegador
  window.addEventListener("error", function() {
    window.location.href = "https://visibility.cl/portal/dashboard1_test.php";
  });
});
</script>

</body>
</html>

