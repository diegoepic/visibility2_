<?php
// Desactivar la visualización de errores en producción (habilítala solo para depuración)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir archivo de conexión y datos de sesión
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Filtros
$empresa_seleccionada  = isset($_GET['empresa']) ? intval($_GET['empresa']) : 0;
$division_seleccionada = isset($_GET['division']) ? intval($_GET['division']) : 0;
$estado_campana        = isset($_GET['estado_campana']) ? trim($_GET['estado_campana']) : '1';
$tipo_campana          = isset($_GET['tipo_campana']) ? intval($_GET['tipo_campana']) : 0;
$subdivision_seleccionada = isset($_GET['subdivision']) ? intval($_GET['subdivision']) : 0;

// Empresa del usuario
$empresa_id = $_SESSION['empresa_id'];

// Obtener nombre de la empresa
try {
    $nombre_empresa = obtenerNombreEmpresa($empresa_id);
} catch (Exception $e) {
    $_SESSION['error_formulario'] = $e->getMessage();
    header("Location: mod_formulario.php");
    exit();
}

// Determinar si es mentecreativa
$es_mentecreativa = false;
$nombre_empresa_limpio = strtolower(trim($nombre_empresa));
if ($nombre_empresa_limpio === 'mentecreativa') {
    $es_mentecreativa = true;
}

// Si es mentecreativa, obtener todas las empresas
if ($es_mentecreativa) {
    try {
        $empresas_all = obtenerEmpresasActivas();
    } catch (Exception $e) {
        $empresas_all = [];
        $_SESSION['error_formulario'] = "Error al obtener las empresas: " . $e->getMessage();
    }
    // Validar empresa
    if ($empresa_seleccionada > 0) {
        $empresas_ids = array_column($empresas_all, 'id');
        if (!in_array($empresa_seleccionada, $empresas_ids)) {
            $_SESSION['error_formulario'] = "Empresa no válida.";
            header("Location: mod_formulario.php");
            exit();
        }
    }
}

// Obtener divisiones
try {
    if ($es_mentecreativa && $empresa_seleccionada > 0) {
        $divisiones = obtenerDivisionesPorEmpresa($empresa_seleccionada);
    } elseif (!$es_mentecreativa) {
        // Usuarios normales -> divisiones de su empresa
        $divisiones = obtenerDivisionesPorEmpresa($empresa_id);
    } else {
        $divisiones = [];
    }
} catch (Exception $e) {
    $divisiones = [];
    $_SESSION['error_formulario'] = "Error al obtener divisiones: " . $e->getMessage();
}

// Construir consulta para formularios con filtros adicionales
$params = [];
$param_types = "";
$query = "
  SELECT
    f.id,
    f.nombre,
    f.fechaInicio,
    f.fechaTermino,
    f.estado,
    f.tipo,
    f.id_division,
    d.nombre  AS division_nombre,
    sd.nombre AS subdivision_nombre
  FROM formulario AS f
  LEFT JOIN division_empresa AS d
    ON d.id = f.id_division
  LEFT JOIN subdivision AS sd
    ON sd.id = f.id_subdivision
";

if ($es_mentecreativa) {
    $conditions = [];
    if ($empresa_seleccionada > 0) {
        $conditions[] = "f.id_empresa = ?";
        $params[]     = $empresa_seleccionada;
        $param_types .= "i";
    }
    if ($division_seleccionada > 0) {
        $conditions[] = "f.id_division = ?";
        $params[]     = $division_seleccionada;
        $param_types .= "i";
    }
    if ($subdivision_seleccionada > 0) {
        $conditions[] = "f.id_subdivision = ?";
        $params[]     = $subdivision_seleccionada;
        $param_types .= "i";
    }
    if ($estado_campana !== '0' && $estado_campana !== '') {
        $conditions[] = "f.estado = ?";
        $params[]     = $estado_campana;  // estado es VARCHAR en la BBDD
        $param_types .= "s";
    }
    if ($tipo_campana > 0) {
        $conditions[] = "f.tipo = ?";
        $params[]     = $tipo_campana;
        $param_types .= "i";
    }
    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }
    $formularios = ejecutarConsulta($query, $params, $param_types);
} else {
    // Para usuarios normales
    $conditions   = ["f.id_empresa = ?"];
    $params[]     = $empresa_id;
    $param_types .= "i";

    if ($division_seleccionada > 0) {
        $conditions[] = "f.id_division = ?";
        $params[]     = $division_seleccionada;
        $param_types .= "i";
    }
    if ($subdivision_seleccionada > 0) {
        $conditions[] = "f.id_subdivision = ?";
        $params[]     = $subdivision_seleccionada;
        $param_types .= "i";
    }
    if ($estado_campana !== '0' && $estado_campana !== '') {
        $conditions[] = "f.estado = ?";
        $params[]     = $estado_campana; // VARCHAR
        $param_types .= "s";
    }
    if ($tipo_campana > 0) {
        $conditions[] = "f.tipo = ?";
        $params[]     = $tipo_campana;
        $param_types .= "i";
    }

    $query .= " WHERE " . implode(" AND ", $conditions);
    $formularios = ejecutarConsulta($query, $params, $param_types);
}

// Obtener tipos de pregunta y renombrarlos en español
try {
    $tipos_pregunta_raw = ejecutarConsulta("SELECT id, name FROM question_type ORDER BY id ASC", [], '');
    $tipoTraduc = [
        'yes_no'          => 'Sí/No',
        'single_choice'   => 'Selección única',
        'multiple_choice' => 'Selección múltiple',
        'text'            => 'Texto',
        'numeric'         => 'Numérico',
        'date'            => 'Fecha',
        'photo'           => 'Foto',
    ];
    $tipos_pregunta = [];
    foreach ($tipos_pregunta_raw as $tp) {
        $id   = $tp['id'];
        $name = isset($tipoTraduc[$tp['name']]) ? $tipoTraduc[$tp['name']] : $tp['name'];
        $tipos_pregunta[] = ['id' => $id, 'name' => $name];
    }
} catch (Exception $e) {
    $tipos_pregunta = [];
    $_SESSION['error_formulario'] = "Error al obtener tipos de pregunta: " . $e->getMessage();
}

// Obtener materiales
try {
    $materiales = ejecutarConsulta("SELECT id, nombre FROM material ORDER BY nombre ASC", [], '');
} catch (Exception $e) {
    $materiales = [];
    $_SESSION['error_formulario'] = "Error al obtener materiales: " . $e->getMessage();
}

// Divisiones para el modal "Crear Formulario"
if ($es_mentecreativa && $empresa_seleccionada > 0) {
    try {
        $divisiones_modal = obtenerDivisionesPorEmpresa($empresa_seleccionada);
    } catch (Exception $e) {
        $divisiones_modal = [];
        $_SESSION['error_formulario'] = "Error al obtener divisiones modal: " . $e->getMessage();
    }
} elseif (!$es_mentecreativa) {
    try {
        $divisiones_modal = obtenerDivisionesPorEmpresa($empresa_id);
    } catch (Exception $e) {
        $divisiones_modal = [];
        $_SESSION['error_formulario'] = "Error al obtener divisiones modal: " . $e->getMessage();
    }
} else {
    $divisiones_modal = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Gestión de Formularios</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- Font Awesome 6 (solo una versión) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"> 
  <style>
    #globalSpinner {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255,255,255,0.8);
      z-index: 9999;
      align-items: center;
      justify-content: center;
    }
    #globalSpinner.show { display: flex; }
    body { background-color: #f8f9fa; }
    .container {
      background-color: #ffffff;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }
    h1, h2 { color: #343a40; }
    table { margin-top: 20px; }
    .mr-1{ margin-bottom: .25rem!important; }
    th {
      background-color: #007bff;
      color: #ffffff;
      text-align: center;
    }
    td { vertical-align: middle; }
    .btn-primary { background-color: #28a745; border-color: #28a745; }
    .btn-primary:hover { background-color: #218838; border-color: #1e7e34; }
    .btn-warning { background-color: #ffc107; border-color: #ffc107; }
    .btn-warning:hover { background-color: #e0a800; border-color: #d39e00; }
    .btn-danger { background-color: #dc3545; border-color: #dc3545; }
    .btn-danger:hover { background-color: #c82333; border-color: #bd2130; }
    .modal-header { background-color: #007bff; color: #ffffff; }
    .form-group label { font-weight: bold; }
    .alert-success { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
    .alert-danger  { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
    .question-row {
      border: 1px solid #ccc;
      background-color: #f9f9f9;
      border-radius: 5px;
      padding: 15px;
      margin-bottom: 15px;
    }
    .question-row .form-group { margin-bottom: 10px; }
    .input-group-append .btn { border-radius: 0; }
  </style>

  <script>
    function toggleCSVUpload(tipo) {
  var csvContainer         = document.getElementById('csvUploadContainer');
  var empresaDivContainer  = document.getElementById('empresaDivisionContainer');
  var modalidadContainer   = document.getElementById('modalidadContainer');
  var csvInput             = document.getElementById('csvFile');

  // NUEVO: contenedor & checkbox del flag IW requiere local
  var iwReqLocalContainer  = document.getElementById('iwReqLocalContainer');
  var iwReqLocalCheck      = document.getElementById('iw_requiere_local');

  // helpers
  const show = el => { if (el) el.style.display = 'block'; };
  const hide = el => { if (el) el.style.display = 'none'; };

  // elementos clave
  const subCont = document.getElementById('subdivisionContainer');
  const subSel  = document.getElementById('id_subdivision');
  const divSel  = document.getElementById('id_division');
  const empSel  = document.getElementById('empresa_form');
  const modSel  = document.getElementById('modalidad');

  // reset mínimos
  if (csvInput) csvInput.required = false;

  if (tipo === '1' || tipo === '3') {
    // Programada / IPT
    show(csvContainer);
    if (csvInput) csvInput.required = true;

    show(empresaDivContainer);
    if (divSel)  { divSel.disabled  = false; divSel.required  = true; }
    if (empSel)  { empSel.disabled  = false; empSel.required  = true; }

    show(modalidadContainer);
    if (modSel)  { modSel.disabled  = false; modSel.required  = true; }

    // Subdivisión disponible
    if (subCont && subSel) {
      subSel.disabled = false;
    }

    // NUEVO: ocultar y desmarcar el switch de IW
    hide(iwReqLocalContainer);
    if (iwReqLocalCheck) iwReqLocalCheck.checked = false;

  } else if (tipo === '2') {
    // IW: sin CSV ni modalidad, con Empresa/División, sin Subdivisión
    hide(csvContainer);

    show(empresaDivContainer);
    if (divSel)  { divSel.disabled  = false; divSel.required  = false; }
    if (empSel)  { empSel.disabled  = false; empSel.required  = true; }

    hide(modalidadContainer);
    if (modSel)  { modSel.required  = false; modSel.disabled = true; modSel.value = ''; }

    // Subdivisión oculta y en 0
    if (subCont && subSel) {
      hide(subCont);
      subSel.required = false;
      subSel.disabled = true;
      subSel.value    = '0';
    }

    // NUEVO: mostrar el switch de IW “requiere local”
    show(iwReqLocalContainer);

  } else {
    // Reset
    hide(csvContainer);
    hide(empresaDivContainer);
    hide(modalidadContainer);

    if (divSel) { divSel.required = false; divSel.disabled = true; }
    if (empSel) { empSel.required = false; empSel.disabled = false; }

    if (subCont && subSel) {
      hide(subCont);
      subSel.required = false; subSel.disabled = true; subSel.value = '0';
    }

    // NUEVO: ocultar y desmarcar el switch de IW
    hide(iwReqLocalContainer);
    if (iwReqLocalCheck) iwReqLocalCheck.checked = false;
  }
}


    // Inicializar estado al abrir página (en caso de preselección)
    document.addEventListener('DOMContentLoaded', function() {
      var selTipo = document.getElementById('tipo').value;
      toggleCSVUpload(selTipo);

      // precarga de subdivisión en filtros (si corresponde)
      const divisionFiltro = document.getElementById('division_filter');
      const subdivisionPreseleccionada = <?php echo (int)$subdivision_seleccionada; ?>;
      if (divisionFiltro) {
        const dv = divisionFiltro.value;
        if (dv && dv !== '0') {
          cargarSubdivisionesFiltro(dv, subdivisionPreseleccionada);
        } else {
          const cont = document.getElementById('subdivision_filter_container');
          if (cont) cont.style.display = 'none';
        }
      }
    });
  </script>
</head>
<body>
  <div id="globalSpinner">
    <div class="spinner-border text-primary" role="status">
      <span class="sr-only">Cargando…</span>
    </div>
  </div>
    
<div class="container mt-5">
  <h1 class="text-center">Gestión de Formularios</h1>

  <!-- Éxito / Error -->
  <?php if (isset($_SESSION['success_formulario'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?php 
        echo $_SESSION['success_formulario']; 
        unset($_SESSION['success_formulario']);
      ?>
      <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
  <?php endif; ?>
  <?php if (isset($_SESSION['error_formulario'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?php 
        echo $_SESSION['error_formulario']; 
        unset($_SESSION['error_formulario']);
      ?>
      <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
  <?php endif; ?>

  <!-- Filtros (mentecreativa) -->
  <?php if ($es_mentecreativa): ?>
    <form method="get" class="form-row" id="filterForm">
      <div class="form-group mr-3">
        <label for="empresa_filter" class="mr-2">Empresa:</label>
        <select id="empresa_filter" name="empresa" class="form-control" onchange="actualizarDivisionesFiltro()">
          <option value="0">-- Todas las Empresas --</option>
          <?php foreach ($empresas_all as $e_filtro): ?>
            <option value="<?php echo htmlspecialchars($e_filtro['id'], ENT_QUOTES, 'UTF-8'); ?>"
              <?php if ($empresa_seleccionada === intval($e_filtro['id'])) echo 'selected'; ?>>
              <?php echo htmlspecialchars($e_filtro['nombre'], ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group mr-3">
        <label for="division_filter" class="mr-2">División:</label>
        <select id="division_filter" name="division" class="form-control" onchange="this.form.submit()">
          <option value="0">-- Todas las Divisiones --</option>
          <?php foreach ($divisiones as $d_filtro): ?>
            <option value="<?php echo htmlspecialchars($d_filtro['id'], ENT_QUOTES, 'UTF-8'); ?>"
              <?php if ($division_seleccionada === intval($d_filtro['id'])) echo 'selected'; ?>>
              <?php echo htmlspecialchars($d_filtro['nombre'], ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="form-group mr-3" id="subdivision_filter_container" style="display:none;">
        <label for="subdivision_filter" class="mr-2">Subdivisión:</label>
        <select id="subdivision_filter" name="subdivision" class="form-control" onchange="this.form.submit()">
          <option value="0">-- Todas las Subdivisiones --</option>
        </select>
      </div>

      <!-- Filtro por Estado de la campaña -->
      <div class="form-group mr-3">
        <label for="estado_filter" class="mr-2">Estado:</label>
        <select id="estado_filter" name="estado_campana" class="form-control" onchange="this.form.submit()">
          <option value="0" <?php if($estado_campana === '0') echo 'selected'; ?>>-- Todos --</option>
          <option value="1" <?php if($estado_campana === '1') echo 'selected'; ?>>En curso</option>
          <option value="3" <?php if($estado_campana === '3') echo 'selected'; ?>>Finalizado</option>
        </select>
      </div>
      <!-- Filtro por Tipo de Campaña -->
      <div class="form-group mr-3">
        <label for="tipo_filter" class="mr-2">Tipo:</label>
        <select id="tipo_filter" name="tipo_campana" class="form-control" onchange="this.form.submit()">
          <option value="0" <?php if($tipo_campana === 0) echo 'selected'; ?>>-- Todos --</option>
          <option value="1" <?php if($tipo_campana === 1) echo 'selected'; ?>>Actividad Programada</option>
          <option value="2" <?php if($tipo_campana === 2) echo 'selected'; ?>>Actividad IW</option>
          <option value="3" <?php if($tipo_campana === 3) echo 'selected'; ?>>Actividad IPT</option>
        </select>
      </div>
      <div class="form-group col-md-3 align-self-end">
        <button type="submit" class="btn btn-secondary">Filtrar</button>
      </div>
    </form>
  <?php endif; ?>

  <!-- Nuevo: Botón para gestionar Sets de Preguntas -->
  <a href="mod_formulario/gestionar_sets.php" class="btn btn-info mb-4 mr-2">
    Gestionar Sets de Preguntas
  </a>
  
  <a href="mod_formulario/gestionar_materiales.php" class="btn btn-secondary mb-4 mr-2">
    Gestionar Materiales
  </a>

  <!-- Botón crear formulario -->
  <button type="button" class="btn btn-primary mb-4" data-toggle="modal" data-target="#crearFormularioModal">
    <i class="fas fa-plus-circle"></i> Crear Nuevo Formulario
  </button>

  <!-- Modal crear formulario -->
  <div class="modal fade" id="crearFormularioModal" tabindex="-1" role="dialog" aria-labelledby="crearFormularioModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
      <form action="mod_formulario/procesar.php" method="post" enctype="multipart/form-data" id="formCrearFormulario">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="crearFormularioModalLabel">Crear Nuevo Formulario</h5>
            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <!-- Datos del formulario -->
            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="nombre">Nombre del Formulario:</label>
                <input type="text" id="nombre" name="nombre" class="form-control" required>
              </div>
              <div class="form-group col-md-6">
                <label for="tipo">Tipo de Actividad:</label>
                <select id="tipo" name="tipo" class="form-control" required onchange="toggleCSVUpload(this.value)">
                  <option value="">Seleccione una opción</option>
                  <option value="1">Actividad Programada</option>
                  <option value="2">Actividad IW</option>
                  <option value="3">Actividad IPT</option>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group col-md-6">
                <label for="fechaInicio">Fecha de Inicio:</label>
                <input type="datetime-local" id="fechaInicio" name="fechaInicio" class="form-control">
              </div>
              <div class="form-group col-md-6">
                <label for="fechaTermino">Fecha de Término:</label>
                <input type="datetime-local" id="fechaTermino" name="fechaTermino" class="form-control">
              </div>
            </div>

            <div class="form-group">
              <label for="estado">Estado:</label>
              <select id="estado" name="estado" class="form-control" required>
                <option value="">Seleccione una opción</option>
                <option value="1">En curso</option>
                <option value="2">Proceso</option>
                <option value="3">Finalizado</option>
                <option value="4">Cancelado</option>
              </select>
            </div>

            <!-- CSV (si tipo=1 o 3) -->
            <div class="form-group" id="csvUploadContainer" style="display:none;">
              <label for="csvFile">Subir Archivo CSV:</label>
              <input type="file" id="csvFile" name="csvFile" class="form-control-file" accept=".csv">
              <small class="form-text text-muted">
                Debe contener las columnas: <strong>codigo</strong>, <strong>usuario</strong>, 
                <strong>material</strong>, <strong>valor_propuesto</strong>, <strong>fechapropuesta</strong>.
              </small>
            </div>

            <!-- Bloque Empresa y División -->
            <div id="empresaDivisionContainer">
              <?php if ($es_mentecreativa): ?>
                <div class="form-group">
                  <label for="empresa_form">Empresa:</label>
                  <select id="empresa_form" name="empresa_form" class="form-control" required onchange="actualizarDivisionesCrear()">
                    <option value="">-- Seleccione una Empresa --</option>
                    <?php foreach ($empresas_all as $empresa_crear): ?>
                      <option value="<?php echo htmlspecialchars($empresa_crear['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($empresa_crear['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              <?php else: ?>
                <div class="form-group">
                  <label for="empresa_display_modal">Empresa:</label>
                  <input type="text" id="empresa_display_modal" name="empresa_display_modal" 
                         class="form-control"
                         value="<?php echo htmlspecialchars($nombre_empresa, ENT_QUOTES, 'UTF-8'); ?>" disabled>
                </div>
                <input type="hidden" id="empresa_form_hidden" name="empresa_form" value="<?php echo htmlspecialchars($empresa_id, ENT_QUOTES, 'UTF-8'); ?>">
              <?php endif; ?>

              <div class="form-group" id="divisionContainer">
                <?php
                if ($es_mentecreativa && $empresa_seleccionada > 0) {
                    $empresa_actual_id = $empresa_seleccionada;
                } elseif (!$es_mentecreativa) {
                    $empresa_actual_id = $empresa_id;
                } else {
                    $empresa_actual_id = 0;
                }
                $tiene_divisiones_modal = false;
                if ($empresa_actual_id > 0) {
                    try {
                        $count_divisiones_modal = contarDivisionesPorEmpresa($empresa_actual_id);
                        if ($count_divisiones_modal > 0) {
                            $tiene_divisiones_modal = true;
                        }
                    } catch (Exception $e) {
                        $tiene_divisiones_modal = false;
                    }
                }
                ?>
                <?php if ($tiene_divisiones_modal): ?>
                  <label for="id_division">División:</label>
                  <select id="id_division" name="id_division" class="form-control">
                    <option value="">-- Seleccione una División --</option>
                    <?php foreach ($divisiones_modal as $division_modal): ?>
                      <option value="<?php echo htmlspecialchars($division_modal['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($division_modal['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                      </option>
                    <?php endforeach; ?>
                    <option value="0">-- Sin División --</option>
                  </select>
                <?php else: ?>
                  <input type="hidden" name="id_division" value="0">
                <?php endif; ?>
              </div>

              <!-- Subdivisión dependiente de la División (no aplica a IW; la ocultamos por JS) -->
              <div class="form-group" id="subdivisionContainer" style="display:none;">
                <label for="id_subdivision">Subdivisión:</label>
                <select id="id_subdivision" name="id_subdivision" class="form-control">
                  <option value="">-- Seleccione una Subdivisión --</option>
                  <option value="0">-- Sin Subdivisión --</option>
                </select>
                <small class="form-text text-muted">Opcional. Depende de la división seleccionada.</small>
              </div>

            </div>
            <!-- Fin Bloque Empresa y División -->
            
            <!-- Modalidad de Campaña (solo para Tipo 1 o 3) -->
            <div class="form-group" id="modalidadContainer" style="display: none;">
              <label for="modalidad">Modalidad de Campaña:</label>
              <select id="modalidad" name="modalidad" class="form-control">
                <option value="implementacion_auditoria">Implementación + Auditoría</option>
                <option value="solo_implementacion">Solo Implementación</option>
                <option value="solo_auditoria">Solo Auditoría</option>
                <option value="retiro">Retiro</option>
                <option value="entrega">Entrega</option>
              </select>
              <small class="form-text text-muted">
                (Solo aplicable a campañas Programadas/IPT: elige si hay 
                implementación y auditoría, solo implementación o solo auditoría.)
              </small>
            </div>
            
            <!-- Requiere local (solo IW) -->
            <div class="form-group" id="iwReqLocalContainer" style="display:none;">
              <div class="custom-control custom-switch">
                <input type="checkbox" class="custom-control-input" id="iw_requiere_local" name="iw_requiere_local" value="1">
                <label class="custom-control-label" for="iw_requiere_local">
                  Esta campaña IW requiere seleccionar un local?
                </label>
              </div>
              <small class="form-text text-muted">
                Si está activo, en la app se pedirá elegir un local (de la división de la campaña) antes de crear la visita IW.
              </small>
            </div>

          </div>
          <!-- /.modal-body -->

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
            <button type="submit" class="btn btn-primary">Crear Formulario</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- Tabla de Formularios Existentes -->
  <h2 class="mb-4">Formularios Creados</h2>
  <div class="table-responsive">
    <table class="table table-striped table-hover">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Fecha Inicio</th>
          <th>Fecha Término</th>
          <th>Estado</th>
          <th>Tipo</th>
          <th>División</th>
          <th>Subdivisión</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
     <?php if (!empty($formularios)): ?>
  <?php foreach ($formularios as $row): ?>
    <?php
      // Estado
      switch ($row['estado']) {
        case '1': $estado_texto = 'En curso';   break;
        case '2': $estado_texto = 'Proceso';    break;
        case '3': $estado_texto = 'Finalizado'; break;
        case '4': $estado_texto = 'Cancelado';  break;
        default:  $estado_texto = 'Desconocido';
      }
      // Tipo
      switch ($row['tipo']) {
        case '1': $tipo_texto = 'Actividad Programada'; break;
        case '2': $tipo_texto = 'Actividad IW';         break;
        case '3': $tipo_texto = 'Actividad IPT';        break;
        default:  $tipo_texto = 'Desconocido';
      }
      // División
      $division_nombre = $row['division_nombre']
        ? htmlspecialchars($row['division_nombre'], ENT_QUOTES, 'UTF-8')
        : 'Sin División';
      // Subdivisión
      $subdivision_nombre = $row['subdivision_nombre']
        ? htmlspecialchars($row['subdivision_nombre'], ENT_QUOTES, 'UTF-8')
        : 'Sin Subdivisión';

      // Fechas
      $fechaInicioFormateada  = $row['fechaInicio']
        ? date('d/m/Y H:i', strtotime($row['fechaInicio']))
        : '';
      $fechaTerminoFormateada = $row['fechaTermino']
        ? date('d/m/Y H:i', strtotime($row['fechaTermino']))
        : '';
      // URL de edición según tipo
      $editar_url = ($row['tipo'] === '2')
        ? "mod_formulario/editar_formularioIW.php?id=" . urlencode($row['id'])
        : "mod_formulario/editar_formulario.php?id="   . urlencode($row['id']);
    ?>
    <tr>
      <td><?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars($row['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= $fechaInicioFormateada ?></td>
      <td><?= $fechaTerminoFormateada ?></td>
      <td><?= $estado_texto ?></td>
      <td><?= $tipo_texto ?></td>
      <td><?= $division_nombre ?></td>
      <td><?= $subdivision_nombre ?></td>
      <td>
        <!-- Editar -->
        <a href="<?= $editar_url ?>" class="btn btn-sm btn-warning mr-1" title="Editar">
          <i class="fas fa-edit"></i>
        </a>
        <br>
        <!-- Mapa -->
        <a href="mod_formulario/mapa_campana.php?id=<?= $row['id'] ?>"
           class="btn btn-sm btn-info mr-1" title="Ver Mapa">
          <i class="fas fa-map-marker-alt"></i>
        </a>
      </td>
    </tr>
  <?php endforeach; ?>
<?php else: ?>
  <tr>
    <td colspan="9" class="text-center">No se encontraron formularios.</td>
  </tr>
<?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- jQuery + Bootstrap -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
function actualizarDivisionesCrear() {
  var empresaSelect = document.getElementById('empresa_form');
  var divisionContainer = document.getElementById('divisionContainer');
  var empresaSeleccionada = empresaSelect ? empresaSelect.value : '';

  if (empresaSeleccionada === "") {
    divisionContainer.style.display = 'none';
    divisionContainer.innerHTML = '';
    var hiddenInput = document.createElement('input');
    hiddenInput.type = 'hidden';
    hiddenInput.name = 'id_division';
    hiddenInput.value = '0';
    divisionContainer.appendChild(hiddenInput);

    // Reset subdivisión
    const subCont = document.getElementById('subdivisionContainer');
    const subSel  = document.getElementById('id_subdivision');
    if (subCont && subSel) {
      subCont.style.display = 'none';
      subSel.innerHTML = '<option value="">-- Seleccione una Subdivisión --</option><option value="0">-- Sin Subdivisión --</option>';
    }
    return;
  }

  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'obtener_divisiones_formulario.php?id_empresa=' + encodeURIComponent(empresaSeleccionada), true);
  xhr.onload = function() {
    if (this.status == 200) {
      try {
        var divisiones = JSON.parse(this.responseText);
        divisionContainer.innerHTML = '';

        if (divisiones.length > 0) {
          divisionContainer.style.display = 'block';

          var label = document.createElement('label');
          label.setAttribute('for', 'id_division');
          label.textContent = 'División:';
          divisionContainer.appendChild(label);

          var select = document.createElement('select');
          select.id = 'id_division';
          select.name = 'id_division';
          select.className = 'form-control';

          // requeridos según tipo (1/3 sí; 2 no)
          const tipoVal = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
          select.required = (tipoVal === '1' || tipoVal === '3');
          select.disabled = false;

          var defaultOption = document.createElement('option');
          defaultOption.value = '';
          defaultOption.textContent = '-- Seleccione una División --';
          select.appendChild(defaultOption);

          for (var i = 0; i < divisiones.length; i++) {
            var option = document.createElement('option');
            option.value = divisiones[i].id;
            option.textContent = divisiones[i].nombre;
            select.appendChild(option);
          }

          var noDivOption = document.createElement('option');
          noDivOption.value = '0';
          noDivOption.textContent = '-- Sin División --';
          select.appendChild(noDivOption);

          divisionContainer.appendChild(select);

          // Hook para cargar Subdivisiones al cambiar División
          select.addEventListener('change', function(){
            const t = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
            if (t === '1' || t === '3') {
              cargarSubdivisionesCrear(this.value);
            } else {
              // IW: mantener SUBDIVISIÓN oculta y en 0
              const subCont = document.getElementById('subdivisionContainer');
              const subSel  = document.getElementById('id_subdivision');
              if (subCont && subSel) {
                subCont.style.display = 'none';
                subSel.value    = '0';
                subSel.disabled = true;
                subSel.required = false;
              }
            }
          });
        } else {
          divisionContainer.style.display = 'none';
          divisionContainer.innerHTML = '';
          var hiddenInput = document.createElement('input');
          hiddenInput.type = 'hidden';
          hiddenInput.name = 'id_division';
          hiddenInput.value = '0';
          divisionContainer.appendChild(hiddenInput);

          // Reset subdivisión
          cargarSubdivisionesCrear('0');
        }
      } catch(e) {
        console.error("Error JSON:", e);
        divisionContainer.style.display = 'none';
        divisionContainer.innerHTML = '';
        var hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'id_division';
        hiddenInput.value = '0';
        divisionContainer.appendChild(hiddenInput);
        cargarSubdivisionesCrear('0');
      }
    } else {
      console.error("Error AJAX:", this.status);
      divisionContainer.style.display = 'none';
      divisionContainer.innerHTML = '';
      var hiddenInput = document.createElement('input');
      hiddenInput.type = 'hidden';
      hiddenInput.name = 'id_division';
      hiddenInput.value = '0';
      divisionContainer.appendChild(hiddenInput);
      cargarSubdivisionesCrear('0');
    }
  };
  xhr.send();
}

// Carga las subdivisiones del modal en base a la división
function cargarSubdivisionesCrear(divisionId) {
  const cont = document.getElementById('subdivisionContainer');
  const sel  = document.getElementById('id_subdivision');
  if (!cont || !sel) return;

  const tipoVal = document.getElementById('tipo') ? document.getElementById('tipo').value : '';

  if (!divisionId || divisionId === '' || divisionId === '0') {
    cont.style.display = (tipoVal === '1' || tipoVal === '3') ? 'block' : 'none';
    sel.innerHTML = '<option value="">-- Seleccione una Subdivisión --</option><option value="0">-- Sin Subdivisión --</option>';
    return;
  }

  // Si es IW, no mostramos subdivisión
  if (tipoVal === '2') {
    cont.style.display = 'none';
    sel.value = '0';
    sel.disabled = true;
    sel.required = false;
    return;
  }

  fetch('obtener_subdivisiones.php?id_division=' + encodeURIComponent(divisionId))
    .then(r => r.json())
    .then(list => {
      if (!Array.isArray(list) || list.length === 0) {
        sel.innerHTML = '<option value="0">-- Sin Subdivisión --</option>';
        cont.style.display = 'block';
        return;
      }
      sel.innerHTML = '<option value="">-- Seleccione una Subdivisión --</option>';
      list.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.nombre;
        sel.appendChild(opt);
      });
      const none = document.createElement('option');
      none.value = '0';
      none.textContent = '-- Sin Subdivisión --';
      sel.appendChild(none);
      cont.style.display = 'block';
    })
    .catch(() => {
      sel.innerHTML = '<option value="0">-- Sin Subdivisión --</option>';
      cont.style.display = 'block';
    });
}

function actualizarDivisionesFiltro() {
  var empresaId = document.getElementById('empresa_filter').value;
  var divisionSelect = document.getElementById('division_filter');

  // RESET de Subdivisión
  var subCont = document.getElementById('subdivision_filter_container');
  var subSel  = document.getElementById('subdivision_filter');
  if (subCont && subSel) {
    subSel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
    subSel.value = '0';
    subCont.style.display = 'none';
  }

  if (empresaId !== "0" && empresaId !== "") {
    $.ajax({
      url: 'obtener_divisiones_formulario.php',
      type: 'GET',
      data: { id_empresa: empresaId },
      dataType: 'json',
      success: function(response) {
        divisionSelect.innerHTML = '<option value="0">-- Todas las Divisiones --</option>';
        if (response.length > 0) {
          for (var i = 0; i < response.length; i++) {
            var option = document.createElement('option');
            option.value = response[i].id;
            option.text = response[i].nombre;
            divisionSelect.appendChild(option);
          }
        }
        document.getElementById('filterForm').submit();
      },
      error: function(xhr, status, error) {
        console.error('Error al obtener divisiones:', error);
        alert('Error al cargar divisiones.');
        divisionSelect.innerHTML = '<option value="0">-- Todas las Divisiones --</option>';
      }
    });
  } else {
    divisionSelect.innerHTML = '<option value="0">-- Todas las Divisiones --</option>';
    document.getElementById('filterForm').submit();
  }
}

function cargarSubdivisionesFiltro(divisionId, preselectId) {
  const cont = document.getElementById('subdivision_filter_container');
  const sel  = document.getElementById('subdivision_filter');
  if (!cont || !sel) return;

  if (!divisionId || divisionId === '0') {
    cont.style.display = 'none';
    sel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
    sel.value = '0';
    return;
  }

  fetch('obtener_subdivisiones.php?id_division=' + encodeURIComponent(divisionId))
    .then(r => r.json())
    .then(list => {
      sel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
      if (Array.isArray(list) && list.length) {
        list.forEach(s => {
          const opt = document.createElement('option');
          opt.value = s.id;
          opt.textContent = s.nombre;
          sel.appendChild(opt);
        });
        cont.style.display = 'block';
        if (preselectId && preselectId > 0) {
          sel.value = String(preselectId);
        }
      } else {
        // Sin registros: muestra igual el selector con "todas"
        cont.style.display = 'block';
      }
    })
    .catch(() => {
      sel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
      cont.style.display = 'block';
    });
}

// ---- Preguntas personalizadas (se mantiene tu lógica actual) ----
function addQuestionRow() {
  let container = document.getElementById('questionContainer');
  let index = container.children.length;

  let row = document.createElement('div');
  row.className = 'question-row';

  row.innerHTML = 
    '<div class="form-group">' +
      '<label>Texto de la Pregunta:</label>' +
      '<input type="text" class="form-control" name="formQuestions[' + index + '][question_text]" placeholder="Ej: ¿Hay stock suficiente?" required>' +
    '</div>' +
    '<div class="form-group">' +
      '<label>Tipo de Pregunta:</label>' +
      '<select class="form-control" name="formQuestions[' + index + '][id_question_type]" onchange="toggleOptions(this, ' + index + ')" required>' +
        '<option value="">-- Seleccionar Tipo --</option>' +
        tiposPregunta.map(function(tp) {
          return '<option value="' + tp.id + '">' + tp.name + '</option>';
        }).join('') +
      '</select>' +
    '</div>' +
    '<div class="form-group">' +
      '<label>¿Requerida?</label>' +
      '<input type="checkbox" name="formQuestions[' + index + '][is_required]" value="1">' +
    '</div>' +
    '<div class="form-group" id="optionContainer_' + index + '" style="display:none;">' +
      '<label>Opciones:</label>' +
      '<div id="optionRows_' + index + '"></div>' +
      '<button type="button" class="btn btn-sm btn-secondary" onclick="addOptionRow(' + index + ')">+ Opción</button>' +
    '</div>' +
    '<button type="button" class="btn btn-danger mt-2" onclick="this.parentNode.remove()">Eliminar Pregunta</button>';

  container.appendChild(row);
}

function toggleOptions(selectElem, questionIndex) {
  const val = parseInt(selectElem.value, 10);
  const optionContainer = document.getElementById('optionContainer_' + questionIndex);
  const optionRows = document.getElementById('optionRows_' + questionIndex);
  if (!optionContainer || !optionRows) return;

  optionContainer.style.display = 'none';
  optionRows.innerHTML = '';

  switch(val) {
    case 1: // yes/no
      optionContainer.style.display = 'block';
      addFixedYesNoOptions(questionIndex);
      break;
    case 2: // single
    case 3: // multiple
      optionContainer.style.display = 'block';
      break;
    default:
      break;
  }
}

function addFixedYesNoOptions(questionIndex) {
  const optionRows = document.getElementById('optionRows_' + questionIndex);
  if (!optionRows) return;

  optionRows.innerHTML = '';

  const fijas = ['Sí', 'No'];
  fijas.forEach(function(texto, idx) {
    let div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = 
      '<input type="text" class="form-control" name="formQuestions[' + questionIndex + '][options][' + idx + '][option_text]" value="' + texto + '" readonly>' +
      '<div class="input-group-append">' +
        '<button type="button" class="btn btn-secondary" disabled>Fijo</button>' +
      '</div>';
    optionRows.appendChild(div);
  });
}

function addOptionRow(questionIndex) {
  const cont = document.getElementById('optionRows_' + questionIndex);
  if (!cont) return;
  const optIndex = cont.children.length;

  let div = document.createElement('div');
  div.className = 'input-group mb-2';
  div.innerHTML = 
    '<input type="text" class="form-control" name="formQuestions[' + questionIndex + '][options][' + optIndex + '][option_text]" placeholder="Texto opción">' +
    '<div class="input-group-append">' +
      '<button type="button" class="btn btn-danger" onclick="this.parentNode.parentNode.remove()">X</button>' +
    '</div>';
  cont.appendChild(div);
}

// Spinner al cambiar de vista (mapa/editar)
document.addEventListener('DOMContentLoaded', function(){
  function showGlobalSpinner() {
    document.getElementById('globalSpinner').classList.add('show');
  }
  document.querySelectorAll('a.btn-info, a.btn-warning').forEach(function(el){
    el.addEventListener('click', showGlobalSpinner);
  });

  // Validación de rango de fechas al enviar el modal (solo tipos 1 y 3)
  const form = document.getElementById('formCrearFormulario');
  if (form) {
    form.addEventListener('submit', function(e){
      const tipo = document.getElementById('tipo')?.value;
      if (tipo === '1' || tipo === '3') {
        const fIni = document.getElementById('fechaInicio')?.value;
        const fFin = document.getElementById('fechaTermino')?.value;
        if (!fIni || !fFin) {
          alert('Para Programadas/IPT debes completar las fechas.');
          e.preventDefault();
          return;
        }
        const ini = new Date(fIni);
        const fin = new Date(fFin);
        if (fin < ini) {
          alert('La fecha de término debe ser mayor o igual a la de inicio');
          e.preventDefault();
          return;
        }
        // Reforzar CSV obligatorio en 1/3
        const csv = document.getElementById('csvFile');
        if (!csv || !csv.files || csv.files.length === 0) {
          alert('Debes subir un CSV para campañas Programadas/IPT.');
          e.preventDefault();
          return;
        }
      } else if (tipo === '2') {
        // Para IW no exigimos división (se puede dejar en "Sin División"),
        // pero si se desea forzar, aquí se podría validar.
        // También, si el usuario es MC, empresa es requerida por toggleCSVUpload.
      }
    });
  }

  // Si ya existe el select de división (cuando no es mentecreativa), enganchar carga de subdivisiones
  const divSel = document.getElementById('id_division');
  if (divSel) {
    divSel.addEventListener('change', function(){
      const t = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
      if (t === '1' || t === '3') {
        cargarSubdivisionesCrear(this.value);
      } else {
        const subCont = document.getElementById('subdivisionContainer');
        const subSel  = document.getElementById('id_subdivision');
        if (subCont && subSel) {
          subCont.style.display = 'none';
          subSel.value    = '0';
          subSel.disabled = true;
          subSel.required = false;
        }
      }
    });

    // solo precargar subdivisiones para tipos 1/3
    const t = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
    if ((t === '1' || t === '3') && divSel.value) {
      cargarSubdivisionesCrear(divSel.value);
    }
  }
});
</script>
</body>
</html>
