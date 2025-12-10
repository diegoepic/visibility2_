<?php
session_start();

// Incluimos la conexión a la base de datos
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

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
        // Definimos formatos permitidos
        $allowed = array("jpg" => "image/jpeg", "jpeg" => "image/jpeg", "png" => "image/png", "gif" => "image/gif");
        $filename = $_FILES['image']['name'];
        $filetype = $_FILES['image']['type'];
        $filesize = $_FILES['image']['size'];
        
        // Verificamos la extensión
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if(!array_key_exists($ext, $allowed)) {
            $mensaje = "Error: Por favor selecciona un formato de archivo válido (JPG, JPEG, PNG, GIF).";
        } elseif($filesize > 5 * 1024 * 1024) { // 5MB máximo
            $mensaje = "Error: El tamaño del archivo es demasiado grande.";
        } else {
            // Verificamos el tipo MIME
            if(in_array($filetype, $allowed)) {
                // Creamos un nombre único para el archivo
                $newFilename = uniqid() . "." . $ext;
                // Definimos la ruta de destino (asegúrate de que la carpeta exista y tenga permisos de escritura)
                $destination = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/uploads/dashboard/' . $newFilename;
                // Movemos el archivo subido a la carpeta destino
                if(move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                    // Definimos la URL relativa a la imagen para almacenar en la base de datos
                    $image_url = '/visibility2/portal/uploads/dashboard/' . $newFilename;
                } else {
                    $mensaje = "Error al subir el archivo.";
                }
            } else {
                $mensaje = "Error: Formato de archivo no permitido.";
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
  </head>
  <body>
    <div class="container-fluid mt-3">
      <div class="row">
        <div class="col-12">
          <!-- Filtros: Empresa y División -->
          <div class="card">
            <div class="card-header bg-info">
              <h3 class="card-title">Filtros</h3>
            </div>
            <div class="card-body">
              <div class="form-group row">
                <label for="empresa" class="col-sm-2 col-form-label">Empresa:</label>
                <div class="col-sm-4">
                  <select class="form-control" name="empresa" id="empresa">
                    <?php 
                    // Consultamos todas las empresas activas
                    $queryEmpresa = "SELECT * FROM empresa WHERE activo = 1";
                    $resultEmpresa = $conn->query($queryEmpresa);
                    while ($empresa = $resultEmpresa->fetch_assoc()):
                      $selected = ($empresa['id'] == $default_empresa) ? "selected" : "";
                    ?>
                      <option value="<?php echo $empresa['id']; ?>" <?php echo $selected; ?>>
                        <?php echo $empresa['nombre']; ?>
                      </option>
                    <?php endwhile; ?>
                  </select>
                </div>
                <label for="division" class="col-sm-2 col-form-label">División:</label>
                <div class="col-sm-4">
                  <select class="form-control" name="division" id="division">
                    <?php 
                    // Consultamos las divisiones asociadas a la empresa por defecto
                    $queryDivision = "SELECT * FROM division_empresa WHERE id_empresa = '$default_empresa' AND estado = 1";
                    $resultDivision = $conn->query($queryDivision);
                    while ($division = $resultDivision->fetch_assoc()):
                      $selected = ($division['id'] == $default_division) ? "selected" : "";
                    ?>
                      <option value="<?php echo $division['id']; ?>" <?php echo $selected; ?>>
                        <?php echo $division['nombre']; ?>
                      </option>
                    <?php endwhile; ?>
                  </select>
                </div>
              </div>
            </div>
          </div>
          <!-- Formulario de creación en tarjeta -->
          <div class="card">
            <div class="card-header bg-success">
              <h3 class="card-title">Crear Dashboard Item</h3>
            </div>
            <div class="card-body">
              <?php if ($mensaje != ""): ?>
                <div class="alert alert-info"><?php echo $mensaje; ?></div>
              <?php endif; ?>
              <!-- Se agregó enctype para permitir la carga de archivos -->
              <form class="form-horizontal" action="" method="post" enctype="multipart/form-data">
                <!-- Inputs ocultos para enviar la Empresa y División seleccionadas -->
                <input type="hidden" name="empresa" id="form_empresa" value="<?php echo $default_empresa; ?>">
                <input type="hidden" name="division" id="form_division" value="<?php echo $default_division; ?>">
                
                <div class="form-group row">
                  <label for="image" class="col-sm-2 col-form-label">Imagen:</label>
                  <div class="col-sm-10">
                    <input type="file" id="image" name="image" class="form-control" required>
                  </div>
                </div>
                
                <div class="form-group row">
                  <label for="target_url" class="col-sm-2 col-form-label">URL de Destino:</label>
                  <div class="col-sm-10">
                    <input type="text" id="target_url" name="target_url" class="form-control" required>
                  </div>
                </div>
                
                <div class="form-group row">
                  <label for="main_label" class="col-sm-2 col-form-label">Etiqueta Principal:</label>
                  <div class="col-sm-10">
                    <input type="text" id="main_label" name="main_label" class="form-control" required>
                  </div>
                </div>
                
                <div class="form-group row">
                  <label for="sub_label" class="col-sm-2 col-form-label">Etiqueta Secundaria:</label>
                  <div class="col-sm-10">
                    <input type="text" id="sub_label" name="sub_label" class="form-control" required>
                  </div>
                </div>
                
                <div class="form-group row">
                  <label for="icon_class" class="col-sm-2 col-form-label">Clase del Ícono:</label>
                  <div class="col-sm-10">
                    <input type="text" id="icon_class" name="icon_class" class="form-control" required placeholder="Ej: fas fa-car-bump">
                  </div>
                </div>
                
                <div class="form-group row">
                  <div class="col-sm-2">Activo:</div>
                  <div class="col-sm-10">
                    <div class="form-check">
                      <input type="checkbox" class="form-check-input" id="is_active" name="is_active" checked>
                      <label class="form-check-label" for="is_active">Activo</label>
                    </div>
                  </div>
                </div>
                
                <div class="form-group row">
                  <div class="col-sm-10 offset-sm-2">
                    <!-- Se añade un campo oculto para identificar que es el formulario de creación -->
                    <input type="hidden" name="crear" value="1">
                    <button type="submit" class="btn btn-primary">Crear Dashboard Item</button>
                  </div>
                </div>
              </form>
            </div>
          </div>
          <!-- Tabla de Dashboard Items en tarjeta con edición en línea -->
        <div class="card">
          <div class="card-header bg-warning">
            <h3 class="card-title">Dashboard Items Creados</h3>
          </div>
          <div class="card-body" id="dashboard_table_container">
            <!-- La tabla se cargará vía AJAX según los filtros seleccionados -->
          </div>
        </div>
          <!-- Fin tarjeta -->
        </div>
      </div>
    </div>
    
    <!-- Script para actualizar selects y la tabla -->
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
            $(document).on('submit', 'form[action="modulos/actualizar_dashboard_item.php"]', function(e){
              e.preventDefault();
              var $form = $(this);
            
              var formData = new FormData(this);
              $.ajax({
                url: $form.attr('action'),
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json'
              })
              .done(function(resp){
                if(resp.success){
                  // recargamos sólo la tabla sin salir de la página
                  updateDashboardTable();
                } else {
                  alert('Error al guardar: ' + resp.error);
                }
              })
              .fail(function(){
                alert('Error de red o servidor al guardar.');
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
  </body>
</html>
