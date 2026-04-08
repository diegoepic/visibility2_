<?php
session_start();

// Incluimos la conexión a la base de datos
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/helpers_image.php';

$mensaje = "";

// Valores por defecto obtenidos de la sesión
$default_empresa  = $_SESSION['empresa_id'];
$default_division = $_SESSION['division_id'];

// Procesamiento del formulario de creación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
    // Se obtienen los valores enviados de los selects
    $empresa_id  = $conn->real_escape_string($_POST['empresa']);
    $division_id = $conn->real_escape_string($_POST['division']);
    
    // Procesamos el archivo de imagen subido
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        if (!function_exists('imagewebp')) {
            $mensaje = "Error: el servidor no tiene soporte para conversión WebP.";
        } elseif ($_FILES['image']['size'] > 5 * 1024 * 1024) {
            $mensaje = "Error: El tamaño del archivo es demasiado grande.";
        } else {
            try {
                $destinationDir = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/dashboard/';
                $resultadoWebp = convertirImagenAWebp($_FILES['image'], $destinationDir, 82);
                $image_url = '/visibility2/portal/uploads/dashboard/' . $resultadoWebp['filename'];
            } catch (Exception $e) {
                $mensaje = "Error: " . $e->getMessage();
            }
        }
    } else {
        $mensaje = "Error: Por favor sube un archivo de imagen.";
    }

        $sqlMax = "
          SELECT COALESCE(MAX(orden), 0) AS maxo
          FROM dashboard_items
          WHERE id_empresa = '$empresa_id'
            AND id_division = '$division_id'
        ";
        $resMax = $conn->query($sqlMax);
        if ($resMax) {
            $rowMax = $resMax->fetch_assoc();
            $nextOrden = intval($rowMax['maxo']) + 1;
        } else {
            // En caso de error, ponemos 1 por defecto
            $nextOrden = 1;
        }
    // Si no hubo error con la imagen, procesamos el resto del formulario
    if($mensaje == "") {
        $target_url  = $conn->real_escape_string($_POST['target_url']);
        $main_label  = $conn->real_escape_string($_POST['main_label']);
        $sub_label   = $conn->real_escape_string($_POST['sub_label']);
        $icon_class  = $conn->real_escape_string($_POST['icon_class']);
        $is_active   = isset($_POST['is_active']) ? 1 : 0;
        
        // Inserción del nuevo registro en la base de datos
        $sql = "
          INSERT INTO dashboard_items
            (id_empresa, id_division, image_url, target_url,
             main_label, sub_label, icon_class, is_active, orden)
          VALUES
            (
              '$empresa_id',
              '$division_id',
              '$image_url',
              '$target_url',
              '$main_label',
              '$sub_label',
              '$icon_class',
              '$is_active',
              '$nextOrden'
            )
        ";
        if ($conn->query($sql) === TRUE) {
            $mensaje = "Dashboard item creado exitosamente.";
        } else {
            $mensaje = "Error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
  <head>
    <meta charset="UTF-8">
    <title>Crear Dashboard Item</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.0/normalize.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,700">
    <!-- Theme style de AdminLTE -->
    <link rel="stylesheet" href="dist/css/adminlte.min.css">
    <link rel="stylesheet" href="./style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <style>
body.dashboard-admin-body {
  margin: 0;
  min-height: 100vh;
  font-family: 'Roboto', sans-serif;
  color: #d9e7ff;
  background:
    radial-gradient(circle at top left, rgba(0, 214, 255, 0.08), transparent 28%),
    radial-gradient(circle at top right, rgba(128, 90, 213, 0.10), transparent 30%),
    linear-gradient(135deg, #07111f 0%, #0a1630 45%, #07101d 100%);
}

.dashboard-admin-shell {
  width: 100%;
  max-width: 100%;
  padding: 24px;
  box-sizing: border-box;
}

.dashboard-admin-grid {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  gap: 22px;
  align-items: start;
}

.dashboard-sidebar-panel,
.dashboard-form-card,
.dashboard-table-card {
  position: relative;
  background: linear-gradient(180deg, rgba(8, 20, 42, 0.95), rgba(6, 16, 34, 0.95));
  border: 1px solid rgba(110, 202, 255, 0.12);
  border-radius: 18px;
  box-shadow:
    0 0 0 1px rgba(255,255,255,0.02) inset,
    0 12px 30px rgba(0,0,0,0.35);
}

.dashboard-sidebar-panel {
  padding: 22px 18px;
  min-height: 420px;
  overflow: hidden;
}

.dashboard-main-panel {
  display: flex;
  flex-direction: column;
  gap: 22px;
  min-width: 0;
}

.dashboard-form-card {
  padding: 24px;
  min-width: 0;
}

.dashboard-table-card {
  padding: 22px;
  width: 100%;
  max-width: 100%;
}

.dashboard-table-card-full {
  width: 100%;
  max-width: 100%;
  margin-top: 22px;
  position: relative;
}

.panel-glow {
  position: absolute;
  left: -40px;
  top: 40%;
  width: 4px;
  height: 110px;
  border-radius: 999px;
  background: linear-gradient(180deg, #2cf3ff, #755cff);
  box-shadow: 0 0 20px rgba(44, 243, 255, 0.5);
}

.panel-section {
  position: relative;
  z-index: 1;
}

.section-kicker,
.panel-kicker {
  margin-bottom: 18px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: #6dbdf3;
}

.section-kicker i {
  margin-right: 8px;
  color: #56e8ff;
}

.filter-group {
  margin-bottom: 18px;
}

.filter-group label,
.form-field label {
  display: block;
  margin-bottom: 8px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1.8px;
  text-transform: uppercase;
  color: #6fa9da;
}

.panel-topbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 20px;
  margin-bottom: 22px;
  padding-bottom: 18px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}

.panel-title {
  margin: 0;
  font-size: 42px;
  line-height: 1.05;
  font-weight: 800;
  color: #ebf4ff;
}

.panel-meta {
  min-width: 180px;
  text-align: right;
}

.panel-meta span {
  display: block;
  margin-bottom: 6px;
  font-size: 12px;
  color: #7e97b8;
}

.panel-meta strong {
  font-size: 18px;
  font-weight: 700;
  color: #58dfff;
}

.modern-alert {
  margin-bottom: 18px;
  padding: 14px 16px;
  border-radius: 12px;
  color: #dffaff;
  background: rgba(88, 223, 255, 0.08);
  border: 1px solid rgba(88, 223, 255, 0.20);
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(260px, 1fr));
  gap: 18px 14px;
}

.form-field {
  min-width: 0;
}

.toggle-field {
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
}

.toggle-wrap {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 46px;
}

.toggle-label {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: #d3e6ff;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 24px;
}

.select-wrap {
  position: relative;
}

.modern-select,
.modern-input,
.modern-file {
  width: 100%;
  height: 48px;
  padding: 0 16px;
  border-radius: 12px;
  border: 1px solid rgba(92, 140, 200, 0.22);
  outline: none;
  color: #e7f4ff;
  font-size: 14px;
  font-weight: 500;
  letter-spacing: .2px;
  background:
    linear-gradient(180deg, rgba(3, 10, 24, 0.96), rgba(2, 8, 20, 0.96));
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,0.03),
    0 0 0 rgba(0,0,0,0);
  transition: all .22s ease;
}

.modern-input::placeholder {
  color: #5d7698;
  opacity: 1;
}

.modern-select:hover,
.modern-input:hover,
.modern-file:hover {
  border-color: rgba(101, 233, 255, 0.35);
}

.modern-select:focus,
.modern-input:focus,
.modern-file:focus {
  border-color: rgba(101, 233, 255, 0.7);
  background:
    linear-gradient(180deg, rgba(4, 12, 28, 0.98), rgba(3, 10, 24, 0.98));
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,0.04),
    0 0 0 3px rgba(78, 220, 255, 0.10),
    0 0 18px rgba(78, 220, 255, 0.10);
}

.modern-select {
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  padding-right: 42px;
  cursor: pointer;
  color: #e7f4ff;
  background-color: #020b1d;
  background-image:
    linear-gradient(180deg, rgba(3, 10, 24, 0.96), rgba(2, 8, 20, 0.96));
}

.select-wrap::after {
  content: "\f107";
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  font-family: "Font Awesome 6 Free";
  font-size: 13px;
  font-weight: 900;
  color: #71dfff;
  opacity: .95;
}

.modern-select option {
  background-color: #071427;
  color: #d9ecff;
}

.modern-select option:checked,
.modern-select option:hover {
  background-color: #12345a;
  color: #ffffff;
}

.dashboard-sidebar-panel .modern-select {
  height: 42px;
  font-size: 14px;
  border-radius: 10px;
  color: #ffffff;
  background-color: #020b1d;
  border-color: rgba(86, 232, 255, 0.28);
}

.dashboard-sidebar-panel .modern-select:focus {
  border-color: rgba(86, 232, 255, 0.8);
  box-shadow:
    0 0 0 3px rgba(86, 232, 255, 0.12),
    0 0 16px rgba(86, 232, 255, 0.10);
}

.dashboard-sidebar-panel .modern-select option {
  background: #08182d;
  color: #eaf6ff;
}

.custom-file-upload {
  display: flex;
  align-items: center;
  gap: 12px;
  height: 48px;
  padding: 0 12px;
  border-radius: 12px;
  border: 1px solid rgba(92, 140, 200, 0.22);
  background: linear-gradient(180deg, rgba(3, 10, 24, 0.96), rgba(2, 8, 20, 0.96));
  transition: all .22s ease;
}

.custom-file-upload:focus-within,
.custom-file-upload:hover {
  border-color: rgba(101, 233, 255, 0.45);
  box-shadow: 0 0 0 3px rgba(78, 220, 255, 0.08);
}

.custom-file-btn {
  padding: 8px 14px;
  border-radius: 10px;
  border: 1px solid rgba(113, 223, 255, 0.28);
  background: linear-gradient(180deg, #102847, #0b1d36);
  color: #dff8ff;
  font-weight: 600;
  cursor: pointer;
}

.custom-file-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #8ea9c8;
  font-size: 14px;
}

.modern-file {
  display: flex;
  align-items: center;
  padding: 8px 12px;
  color: #9fc0de;
}

.modern-file::-webkit-file-upload-button,
.modern-file::file-selector-button {
  margin-right: 12px;
  padding: 9px 14px;
  border-radius: 10px;
  border: 1px solid rgba(113, 223, 255, 0.28);
  background: linear-gradient(180deg, #102847, #0b1d36);
  color: #dff8ff;
  font-weight: 600;
  cursor: pointer;
  transition: all .2s ease;
}

.modern-file::-webkit-file-upload-button:hover,
.modern-file::file-selector-button:hover {
  border-color: rgba(113, 223, 255, 0.55);
  box-shadow: 0 0 12px rgba(113, 223, 255, 0.10);
}

.sidebar-status-card {
  margin-top: 24px;
  padding: 14px;
  border-radius: 12px;
  border: 1px solid rgba(84, 211, 255, 0.14);
  background: linear-gradient(180deg, rgba(10, 28, 54, 0.95), rgba(9, 24, 46, 0.95));
}

.status-title {
  display: block;
  margin-bottom: 10px;
  font-size: 12px;
  font-weight: 700;
  color: #79b2da;
}

.status-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.status-text {
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  color: #9feaff;
}

.status-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #71f3ff;
  box-shadow: 0 0 12px rgba(113, 243, 255, 0.7);
}

.modern-switch {
  position: relative;
  display: inline-block;
  width: 62px;
  height: 32px;
}

.modern-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.modern-slider {
  position: absolute;
  inset: 0;
  cursor: pointer;
  border-radius: 999px;
  background: rgba(255,255,255,0.16);
  transition: .3s;
}

.modern-slider::before {
  content: "";
  position: absolute;
  left: 4px;
  top: 4px;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #ffffff;
  transition: .3s;
}

.modern-switch input:checked + .modern-slider {
  background: linear-gradient(90deg, #9aefff, #53ddff);
  box-shadow: 0 0 14px rgba(83, 221, 255, 0.45);
}

.modern-switch input:checked + .modern-slider::before {
  transform: translateX(30px);
}

.btn-create-dashboard {
  min-width: 280px;
  padding: 15px 34px;
  border: none;
  border-radius: 14px;
  background: linear-gradient(90deg, #86ebff, #59dfff);
  color: #083047;
  font-weight: 800;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  box-shadow: 0 8px 24px rgba(89, 223, 255, 0.30);
  transition: all .25s ease;
}

.btn-create-dashboard:hover {
  transform: translateY(-1px);
  box-shadow: 0 12px 28px rgba(89, 223, 255, 0.40);
}

.table-card-header {
  margin-bottom: 18px;
  padding-bottom: 14px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
}

.table-card-header h2 {
  margin: 0;
  font-size: 24px;
  color: #eef5ff;
}

.table-card-body {
  width: 100%;
  overflow-x: auto;
}

.dashboard-table-wrap {
  width: 100%;
  overflow-x: auto;
}

.dashboard-items-table {
  width: 100%;
  min-width: 1400px;
  table-layout: fixed;
  border-collapse: collapse;
  margin-bottom: 0;
  color: #d8e6ff;
}

.dashboard-items-table th,
.dashboard-items-table td {
  vertical-align: middle !important;
  padding: 10px;
  border: 1px solid rgba(255,255,255,0.08);
}

.dashboard-items-table th {
  font-size: 13px;
  font-weight: 700;
  text-align: left;
  color: #8fe9ff;
  background: rgba(86, 232, 255, 0.08);
}

.dashboard-items-table td {
  color: #d8e6ff;
  background: rgba(255,255,255,0.02);
}

.dashboard-items-table th:nth-child(1),
.dashboard-items-table td:nth-child(1) { width: 60px; }

.dashboard-items-table th:nth-child(2),
.dashboard-items-table td:nth-child(2) { width: 80px; }

.dashboard-items-table th:nth-child(3),
.dashboard-items-table td:nth-child(3) { width: 140px; }

.dashboard-items-table th:nth-child(4),
.dashboard-items-table td:nth-child(4) { width: 260px; }

.dashboard-items-table th:nth-child(5),
.dashboard-items-table td:nth-child(5) { width: 190px; }

.dashboard-items-table th:nth-child(6),
.dashboard-items-table td:nth-child(6) { width: 190px; }

.dashboard-items-table th:nth-child(7),
.dashboard-items-table td:nth-child(7) { width: 140px; }

.dashboard-items-table th:nth-child(8),
.dashboard-items-table td:nth-child(8) { width: 80px; text-align: center; }

.dashboard-items-table th:nth-child(9),
.dashboard-items-table td:nth-child(9) { width: 130px; }

.dashboard-items-table th:nth-child(10),
.dashboard-items-table td:nth-child(10) { width: 100px; text-align: center; }

.table-input,
.table-textarea {
  width: 100%;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid rgba(92, 140, 200, 0.22);
  outline: none;
  color: #e7f4ff;
  font-size: 13px;
  background: rgba(3, 10, 24, 0.96);
  transition: all .2s ease;
}

.table-input:focus,
.table-textarea:focus {
  border-color: rgba(101, 233, 255, 0.65);
  box-shadow: 0 0 0 3px rgba(78, 220, 255, 0.08);
}

.table-textarea {
  min-height: 60px;
  max-height: 90px;
  resize: vertical;
  line-height: 1.35;
}

.image-thumb-btn {
  position: relative;
  width: 110px;
  height: 72px;
  padding: 0;
  overflow: hidden;
  border-radius: 10px;
  border: 1px solid rgba(86, 232, 255, 0.18);
  background: rgba(3, 10, 24, 0.96);
  cursor: pointer;
  transition: all .2s ease;
}

.image-thumb-btn:hover {
  border-color: rgba(86, 232, 255, 0.45);
  box-shadow: 0 0 0 3px rgba(86, 232, 255, 0.08);
}

.table-thumb {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thumb-overlay {
  position: absolute;
  inset: auto 0 0 0;
  padding: 5px 0;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1px;
  text-transform: uppercase;
  color: #dff7ff;
  background: rgba(2, 10, 24, 0.82);
}

.table-check-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0;
}

.table-save-btn {
  min-width: 92px;
  border-radius: 10px;
  font-weight: 700;
  border: 1px solid rgba(86, 232, 255, 0.18);
  box-shadow: 0 6px 14px rgba(0,0,0,0.18);
}

.table-save-btn:disabled {
  opacity: .75;
  cursor: wait;
}

.empty-dashboard-items {
  padding: 18px;
  border-radius: 12px;
  color: #cfe6ff;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.06);
}

.dashboard-dark-modal {
  position: relative;
  color: #eaf5ff;
  background: linear-gradient(180deg, rgba(8, 20, 42, 0.98), rgba(6, 16, 34, 0.98));
  border: 1px solid rgba(110, 202, 255, 0.14);
  border-radius: 18px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.35);
}

.dashboard-dark-modal .modal-title {
  color: #f0f7ff;
}

.image-preview-box {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 260px;
  overflow: hidden;
  border-radius: 14px;
  border: 1px solid rgba(86, 232, 255, 0.18);
  background: rgba(3, 10, 24, 0.96);
}

.image-preview-box img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.image-upload-overlay,
.table-save-overlay {
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(4px);
}

.image-upload-overlay {
  position: absolute;
  inset: 0;
  z-index: 1055;
  border-radius: 18px;
  background: rgba(3, 10, 24, 0.78);
}

.table-save-overlay {
  position: absolute;
  inset: 0;
  z-index: 20;
  border-radius: 18px;
  background: rgba(3, 10, 24, 0.68);
}

.image-upload-box,
.table-save-box {
  width: min(92%, 360px);
  padding: 24px 20px;
  text-align: center;
  color: #eaf6ff;
  border-radius: 16px;
  border: 1px solid rgba(86, 232, 255, 0.20);
  background: linear-gradient(180deg, rgba(10, 28, 54, 0.98), rgba(7, 20, 38, 0.98));
  box-shadow: 0 12px 30px rgba(0,0,0,0.35);
}

.image-upload-box h5,
.table-save-box h5 {
  margin: 0;
  font-weight: 700;
  color: #f3f9ff;
}

.image-upload-box p,
.table-save-box p {
  color: #9fb8d2 !important;
}

@media (max-width: 1100px) {
  .dashboard-admin-grid {
    grid-template-columns: 1fr;
  }

  .dashboard-main-panel {
    min-width: 0;
  }

  .form-grid {
    grid-template-columns: 1fr;
  }

  .panel-topbar {
    flex-direction: column;
  }

  .panel-meta {
    text-align: left;
  }

  .panel-title {
    font-size: 32px;
  }

  .btn-create-dashboard {
    width: 100%;
    min-width: auto;
  }
}
    </style>
  </head>
<body class="dashboard-admin-body">
  <div class="dashboard-admin-shell">
    <div class="dashboard-admin-grid">

      <!-- PANEL IZQUIERDO -->
      <aside class="dashboard-sidebar-panel">
        <div class="panel-glow"></div>

        <div class="panel-section">
          <div class="section-kicker">
            <i class="fas fa-filter"></i>
            <span>Filtros Globales</span>
          </div>

          <div class="filter-group">
            <label for="empresa">Empresa</label>
            <div class="select-wrap">
              <select class="modern-select" name="empresa" id="empresa">
                <?php 
                $queryEmpresa = "SELECT * FROM empresa WHERE activo = 1";
                $resultEmpresa = $conn->query($queryEmpresa);
                while ($empresa = $resultEmpresa->fetch_assoc()):
                  $selected = ($empresa['id'] == $default_empresa) ? "selected" : "";
                ?>
                  <option value="<?php echo $empresa['id']; ?>" <?php echo $selected; ?>>
                    <?php echo htmlspecialchars($empresa['nombre']); ?>
                  </option>
                <?php endwhile; ?>
              </select>
            </div>
          </div>

          <div class="filter-group">
            <label for="division">División</label>
            <div class="select-wrap">
              <select class="modern-select" name="division" id="division">
                <?php 
                $queryDivision = "SELECT * FROM division_empresa WHERE id_empresa = '$default_empresa' AND estado = 1";
                $resultDivision = $conn->query($queryDivision);
                while ($division = $resultDivision->fetch_assoc()):
                  $selected = ($division['id'] == $default_division) ? "selected" : "";
                ?>
                  <option value="<?php echo $division['id']; ?>" <?php echo $selected; ?>>
                    <?php echo htmlspecialchars($division['nombre']); ?>
                  </option>
                <?php endwhile; ?>
              </select>
            </div>
          </div>

          <div class="sidebar-status-card">
            <span class="status-title">System Status</span>
            <div class="status-row">
              <span class="status-text">Operational</span>
              <span class="status-dot"></span>
            </div>
          </div>
        </div>
      </aside>

      <!-- PANEL DERECHO -->
      <main class="dashboard-main-panel">
        <div class="dashboard-form-card">
          <div class="panel-topbar">
            <div>
              <div class="panel-kicker">Editor de dashboards</div>
              <h1 class="panel-title">Configuration Terminal</h1>
            </div>
            <div class="panel-meta">
              <span>Ultima Acttualizacion</span>
              <strong><?php echo date('Y.m.d - H:i:s'); ?></strong>
            </div>
          </div>

          <?php if ($mensaje != ""): ?>
            <div class="modern-alert">
              <?php echo htmlspecialchars($mensaje); ?>
            </div>
          <?php endif; ?>

          <form class="modern-form" action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="empresa" id="form_empresa" value="<?php echo $default_empresa; ?>">
            <input type="hidden" name="division" id="form_division" value="<?php echo $default_division; ?>">

            <div class="form-grid">
                 <div class="form-field">
                  <label for="image">Imagen</label>
                  <div class="custom-file-upload">
                    <input type="file" id="image" name="image" hidden required>
                    <button type="button" class="custom-file-btn" id="triggerFile">
                      Seleccionar archivo
                    </button>
                    <span class="custom-file-name" id="fileName">Ningún archivo seleccionado</span>
                  </div>
                </div>

              <div class="form-field">
                <label for="target_url">URL de destino</label>
                <input type="text" id="target_url" name="target_url" class="modern-input" required>
              </div>

              <div class="form-field">
                <label for="main_label">Etiqueta principal</label>
                <input type="text" id="main_label" name="main_label" class="modern-input" required>
              </div>

              <div class="form-field">
                <label for="sub_label">Etiqueta secundaria</label>
                <input type="text" id="sub_label" name="sub_label" class="modern-input" required>
              </div>

              <div class="form-field">
                <label for="icon_class">Ícono</label>
                <input type="text" id="icon_class" name="icon_class" class="modern-input" required placeholder="Ej: fas fa-car-bump">
              </div>

              <div class="form-field toggle-field">
                <label>Estado</label>
                <div class="toggle-wrap">
                  <label class="modern-switch">
                    <input type="checkbox" id="is_active" name="is_active" checked>
                    <span class="modern-slider"></span>
                  </label>
                  <span class="toggle-label">Activo</span>
                </div>
              </div>
            </div>

            <div class="form-actions">
              <input type="hidden" name="crear" value="1">
              <button type="submit" class="btn-create-dashboard">
                Crear Dashboard Item
              </button>
            </div>
          </form>
        </div>
      </main>
     </div> 
  <section class="dashboard-table-card dashboard-table-card-full">
    <div class="table-card-header">
      <div>
        <div class="panel-kicker">Registro</div>
        <h2>Dashboard Items Creados</h2>
      </div>
    </div>

    <div class="table-card-body" id="dashboard_table_container">
      <!-- AJAX -->
    </div>

    <div id="tableSaveOverlay" class="table-save-overlay" style="display:none;">
      <div class="table-save-box">
        <div class="spinner-border text-info mb-3" role="status" style="width:3rem;height:3rem;">
          <span class="sr-only">Guardando...</span>
        </div>
        <h5 class="mb-2">Actualizando registro</h5>
        <p class="mb-0 text-muted">Guardando cambios del dashboard, por favor espera...</p>
      </div>
    </div>
  </section>    
    </div>
<div id="ajaxFeedback" class="modern-alert" style="display:none;"></div>  
<div class="modal fade" id="imageUpdateModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered" role="document">
    <div class="modal-content dashboard-dark-modal">
      <div class="modal-header border-0">
        <div>
          <div class="panel-kicker mb-1">Image Manager</div>
          <h5 class="modal-title mb-0">Actualizar imagen</h5>
        </div>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <form id="imageUpdateForm" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="hidden" name="id" id="modal_item_id">

          <div class="image-preview-box">
            <img id="modal_image_preview" src="" alt="Vista previa">
          </div>

          <div class="form-group mt-3">
            <label for="modal_image_file">Seleccionar nueva imagen</label>
            <input type="file" class="modern-file" name="image" id="modal_image_file" accept=".jpg,.jpeg,.png,.gif" required>
          </div>

          <div id="modalImageFeedback" class="modern-alert" style="display:none;"></div>
        </div>

        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-light" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn-create-dashboard px-4" id="btnGuardarImagen" style="min-width:auto;">
          Guardar imagen
        </button>
        </div>
      </form>
    <div id="imageUploadOverlay" class="image-upload-overlay" style="display:none;">
      <div class="image-upload-box">
        <div class="spinner-border text-info mb-3" role="status" style="width:3rem;height:3rem;">
          <span class="sr-only">Guardando...</span>
        </div>
        <h5 class="mb-2">Guardando imagen</h5>
        <p class="mb-0 text-muted">Procesando y convirtiendo a WebP, por favor espera...</p>
      </div>
    </div>      
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>
<script>
      // Función para actualizar la tabla de dashboard items
          function updateDashboardTable(){
            var empresa_id = $("#empresa").val();
            var division_id = $("#division").val();
            $.ajax({
              url: 'modulos/obtener_dashboard_items.php',
              type: 'GET',
              data: { empresa_id: empresa_id, division_id: division_id },
              success: function(data){
                $("#dashboard_table_container").html(data);
              }
            });
          }
            $(document).on('click', '.js-save-dashboard-row', function () {
              const $btn = $(this);
              const $row = $btn.closest('tr');
              const $overlay = $('#tableSaveOverlay');
            
              const formData = new FormData();
              formData.append('id', $row.data('id'));
              formData.append('orden', $row.find('[name="orden"]').val() || 1);
              formData.append('target_url', $row.find('[name="target_url"]').val() || '');
              formData.append('main_label', $row.find('[name="main_label"]').val() || '');
              formData.append('sub_label', $row.find('[name="sub_label"]').val() || '');
              formData.append('icon_class', $row.find('[name="icon_class"]').val() || '');
            
              if ($row.find('[name="is_active"]').is(':checked')) {
                formData.append('is_active', '1');
              }
            
              $overlay.fadeIn(150);
              $btn.prop('disabled', true).text('Guardando...');
            
              $.ajax({
                url: 'modulos/actualizar_dashboard_item.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
              })
              .done(function(resp){
                if (resp && resp.success) {
                  updateDashboardTable();
                } else {
                  alert((resp && resp.error) ? resp.error : 'No se pudo guardar el registro.');
                }
              })
              .fail(function(xhr){
                let msg = 'Error al guardar el registro.';
                if (xhr.responseText) {
                  console.error('Respuesta guardar fila:', xhr.responseText);
                }
                alert(msg);
              })
              .always(function(){
                $overlay.fadeOut(150);
                $btn.prop('disabled', false).text('Guardar');
              });
            });      
          // Al iniciar la página se carga la tabla con los valores por defecto
          $(document).ready(function(){
            updateDashboardTable();
          });
        
          // Si se cambia alguno de los selects, se actualiza la tabla
          $("#empresa, #division").change(function(){
            updateDashboardTable();
          });
      
      // Al cambiar la Empresa, se actualiza el select de División y la tabla
      $("#empresa").change(function(){
        var empresa_id = $(this).val();
        $("#form_empresa").val(empresa_id);
        $.ajax({
          url: 'modulos/obtener_divisiones.php',
          type: 'GET',
          data: { empresa_id: empresa_id },
          success: function(data){
            $("#division").html(data);
            // Actualizamos el input oculto de División con el primer valor obtenido
            var firstDivision = $("#division option:first").val();
            $("#form_division").val(firstDivision);
            // Actualizamos la tabla con la nueva combinación de Empresa y División
            updateDashboardTable();
          }
        });
      });
      
      // Al cambiar la División, sincronizamos el input oculto y actualizamos la tabla
      $("#division").change(function(){
        var division_id = $(this).val();
        $("#form_division").val(division_id);
        updateDashboardTable();
      });
    </script>
<script>
        document.getElementById('triggerFile').addEventListener('click', function () {
          document.getElementById('image').click();
        });
        
        document.getElementById('image').addEventListener('change', function () {
          const fileName = this.files.length ? this.files[0].name : 'Ningún archivo seleccionado';
          document.getElementById('fileName').textContent = fileName;
        });
    </script>
<script>
$(document).on('click', '.js-open-image-modal', function () {
  const itemId   = $(this).data('id');
  const imageUrl = $(this).data('image');

  $('#modal_item_id').val(itemId);
  $('#modal_image_preview').attr('src', imageUrl);
  $('#modal_image_file').val('');
  $('#modalImageFeedback').hide().html('');
  $('#imageUploadOverlay').hide();

  $('#imageUpdateModal').modal({
    backdrop: 'static',
    keyboard: false
  });

  $('#imageUpdateModal').modal('show');
});

$('#modal_image_file').on('change', function () {
  const file = this.files[0];
  if (!file) return;

  const reader = new FileReader();
  reader.onload = function (e) {
    $('#modal_image_preview').attr('src', e.target.result);
  };
  reader.readAsDataURL(file);
});

$('#imageUpdateForm').on('submit', function (e) {
  e.preventDefault();

  const formData = new FormData(this);
  const $overlay = $('#imageUploadOverlay');
  const $submitBtn = $('#imageUpdateForm button[type="submit"]');
  const $cancelBtn = $('#imageUpdateForm button[data-dismiss="modal"]');

  $('#modalImageFeedback').hide().html('');
  $overlay.fadeIn(150);
  $submitBtn.prop('disabled', true).text('Guardando...');
  $cancelBtn.prop('disabled', true);

  $.ajax({
    url: 'modulos/actualizar_dashboard_imagen.php',
    type: 'POST',
    data: formData,
    processData: false,
    contentType: false,
    dataType: 'json'
  })
  .done(function(resp){
    if(resp && resp.success){
      $('#modalImageFeedback')
        .removeClass('alert-danger')
        .addClass('alert-success')
        .html('Imagen actualizada correctamente.')
        .show();

      updateDashboardTable();

      setTimeout(function(){
        $('#imageUpdateModal').modal('hide');
      }, 700);
    } else {
      $('#modalImageFeedback')
        .removeClass('alert-success')
        .addClass('alert-danger')
        .html((resp && resp.error) ? resp.error : 'No se pudo actualizar la imagen.')
        .show();
    }
  })
  .fail(function(xhr){
    let msg = 'Error de red o servidor al actualizar la imagen.';

    if (xhr.responseText) {
      msg += '<br><br><small style="word-break:break-word;">' + xhr.responseText + '</small>';
    }

    $('#modalImageFeedback')
      .removeClass('alert-success')
      .addClass('alert-danger')
      .html(msg)
      .show();

    console.error('Respuesta del servidor:', xhr.responseText);
  })
  .always(function(){
    $overlay.fadeOut(150);
    $submitBtn.prop('disabled', false).text('Guardar imagen');
    $cancelBtn.prop('disabled', false);
  });
});
</script>
  </body>
</html>
