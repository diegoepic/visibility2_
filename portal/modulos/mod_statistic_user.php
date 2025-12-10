<?php
// mod_create_user.php

// Habilitar la visualización de errores para depuración (desactivar en producción)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Incluir archivos necesarios
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_user/generate_token.php';

// Obtener datos necesarios
$empresas = obtenerEmpresasActivas();
$perfiles = obtenerPerfiles(); // Obtener los perfiles
$usuarios = obtenerUsuariosConActividad();

// Obtener datos de sesión
$empresa_id_session = $_SESSION['empresa_id'];
$empresa_nombre_session = $_SESSION['empresa_nombre'];

// Generar el token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generate_csrf_token(32);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Mentecreativa | Visibility 2</title>
    <link rel="icon" href="../images/logo/logo-Visibility.png" type="image/png">
    <!-- Meta y Enlaces -->
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 4 desde CDN -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.12/css/dataTables.bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/1.2.2/css/buttons.bootstrap.min.css">    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
       

    <style>
        /* Ajustes personalizados para el Modal de Edición */
        #editarUsuarioModal .modal-header {
            background-color: #007bff; /* Azul Bootstrap */
            color: white;
        }

        #editarUsuarioModal .modal-title {
            font-weight: bold;
        }


        /* Ajustar el tamaño de la imagen de perfil en el modal */
        #actual_fotoPerfil {
            border-radius: 50%;
            border: 2px solid #ddd;
            max-width: 150px;
            height: auto;
        }
        
        /* estilos para la tabla de usuaios */
        .table-responsive {
          margin-top: 2%;
          border: 1px solid #dee2e6;
          border-radius: 0.25rem;
          box-shadow: 0 2px 6px rgba(0,0,0,0.05);
          overflow: hidden;
        }
        
        .card-header.bg-success {
          /*background-color: #0069d9 !important;
          border-bottom: none;*/
            background: #93C01F;
            background-image: linear-gradient(to left, #E7F4CB 0%, #CBE47B 40%, #A7D13A 75%, #93C01F 100%);
            color: #fff;
            padding: 15px;
            border-radius: 3px 3px 0 0;          
        }
        .card-header .card-title {
          color: #fff;
          font-weight: 500;
        }
        
        #example thead th {
          /*text-transform: uppercase;
          font-size: 0.875rem;
          border-bottom: 2px solid #dee2e6;
          color: #495057;*/
            background-color:#4b545c;
            padding: 20px 15px;
            text-align: left;
            font-weight: 500;
            font-size: 12px;
            color: #fff;
            text-transform: uppercase;          
        }
        
        .badge {
          font-size: 0.75rem;
          padding: 0.4em 0.6em;
        }
                        

        /* Clase personalizada para hacer el modal desplazable */
        .modal-body-scrollable {
            max-height: 70vh; /* Ajusta este valor según tus necesidades */
            overflow-y: auto;
        }
        .material-row { margin-bottom: 10px; }
        .remove-material-btn { margin-top: 32px; }
        thead input {width: 100%;padding: 3px;box-sizing: border-box;}
        .pagination>li>a, .pagination>li>span {
        position: relative;
        float: left;
        padding: 6px 12px;
        margin-left: -1px;
        line-height: 1.42857143;
        color: #337ab7;
        text-decoration: none;
        background-color: #fff;
        border: 1px solid #ddd;
    }        
        .uppercase { text-transform: uppercase; }
        .btn-default {
            color: #333;
            background-color: #fff;
            border-color: #ccc;
        }
        
        .dt-buttons { 
          float: left; 
          margin-right: 10px; 
        }
        .dataTables_filter { 
          float: right; 
        }
        .btn-default{
        background-color: #f8f9fa;
        border-color: #ddd;
        color: #444;
        }
        .table-responsive{
            margin-top:2%;
        }
        .container-fluid{
            margin-top:3%;
        }        
        .mb-2{
            margin-top:1%;
        }
        
        .bg-success-mc {
            /* background-color: #28a745 !important; */
            background: #93C01F;
            background-image: linear-gradient(to left, #E7F4CB 0%, #CBE47B 40%, #A7D13A 75%, #93C01F 100%);
            color: #fff;
            padding: 15px;
            border-radius: 3px 3px 0 0;
        } 
        div.dataTables_wrapper div.dataTables_length select {
            margin-left: 5px;
            margin-right: 5px;
        }        
        div.dataTables_wrapper div.dataTables_info {
            padding-top: 0px!important;
            margin-left: 1%;
        }                
    </style>
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
    <!-- Contenido Principal -->
    <section class="content">
        <div class="container-fluid">

            <!-- Mostrar mensajes de éxito o error -->
            <?php if (isset($_SESSION['success_formulario'])): ?>
                <div class="alert alert-success">
                    <?php
                    echo $_SESSION['success_formulario'];
                    unset($_SESSION['success_formulario']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_formulario'])): ?>
                <div class="alert alert-danger">
                    <?php
                    echo $_SESSION['error_formulario'];
                    unset($_SESSION['error_formulario']);
                    ?>
                </div>
            <?php endif; ?>
            
                             <table id="example" class="table table-sm table-bordered table-hover" cellspacing="0" width="100%">
              <thead>
                <tr>
                  <th>Nombre Completo</th>
                  <th>Usuario</th>
                  <th>Empresa</th>
                  <th>División</th>
                  <th>Perfil</th>
                  <th>Fecha Creación</th>
                  <th>Último Login</th>
                  <th>Logeos</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($usuarios as $usuario): ?>
                  <tr>
                    <td class="uppercase"><?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_completo'], 'UTF-8')); ?></td>
                    <td class="uppercase"><?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_login'], 'UTF-8')); ?></td>
                    <td class="uppercase"><?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_empresa'], 'UTF-8')); ?></td>
                    <td class="uppercase">
                      <?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_division'] ?: 'N/A', 'UTF-8')); ?>
                    </td>
                    <td class="uppercase"><?php echo htmlspecialchars(mb_strtoupper($usuario['nombre_perfil'], 'UTF-8')); ?></td>
                    <td><?php echo htmlspecialchars($usuario['fechaCreacion']); ?></td>
                    <td>
                      <?php echo $usuario['UltimoLogin'] ? htmlspecialchars($usuario['UltimoLogin']) : '<span class="text-muted">Nunca</span>'; ?>
                    </td>
                    <td><?php echo (int)$usuario['logeos']; ?></td>
                    <td>
                      <?php if ($usuario['activo']): ?>
                        <span class="badge badge-success">Activo</span>
                      <?php else: ?>
                        <span class="badge badge-danger">Inactivo</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

        </div>
    </section>
</div>

<!-- Modal para Crear Usuario y Carga Masiva -->
<div class="modal fade" id="crearUsuarioModal" tabindex="-1" role="dialog" aria-labelledby="crearUsuarioModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success-mc">
                <h5 class="modal-title" id="crearUsuarioModalLabel">Gestión de Usuarios</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <!-- Contenido del Modal -->
            <div class="modal-body">
                <!-- Pestañas -->
                <ul class="nav nav-tabs" id="tabUsuarios" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="crearUsuario-tab" data-toggle="tab" href="#crearUsuario" role="tab" aria-controls="crearUsuario" aria-selected="true">Crear Usuario</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="cargaMasiva-tab" data-toggle="tab" href="#cargaMasiva" role="tab" aria-controls="cargaMasiva" aria-selected="false">Carga Masiva</a>
                    </li>
                </ul>
                <!-- Contenido de las Pestañas -->
                <div class="tab-content" id="tabUsuariosContent">
                    <!-- Formulario de Crear Usuario -->
                    <div class="tab-pane fade show active" id="crearUsuario" role="tabpanel" aria-labelledby="crearUsuario-tab">
                        <form class="form-horizontal mt-3" method="POST" action="mod_user/procesar.php" enctype="multipart/form-data">
                            <div class="card-body">
                                <!-- Token CSRF -->
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                <h4>INFORMACIÓN DEL TRABAJADOR</h4>
                                <!-- RUT -->
                                <div class="form-group row">
                                    <label for="rut" class="col-sm-2 col-form-label">Rut:*</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="rut" class="form-control" id="rut" placeholder="Ingrese el RUT" required>
                                    </div>
                                </div>
                                <!-- Nombre -->
                                <div class="form-group row">
                                    <label for="nombre" class="col-sm-2 col-form-label">Nombre:*</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="nombre" class="form-control" id="nombre" placeholder="Ingrese el nombre" required>
                                    </div>
                                </div>
                                <!-- Apellido -->
                                <div class="form-group row">
                                    <label for="apellido" class="col-sm-2 col-form-label">Apellido:*</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="apellido" class="form-control" id="apellido" placeholder="Ingrese el apellido" required>
                                    </div>
                                </div>
                                <!-- Teléfono -->
                                <div class="form-group row">
                                    <label for="telefono" class="col-sm-2 col-form-label">Teléfono:*</label>
                                    <div class="col-sm-10">
                                        <input type="tel" name="telefono" class="form-control" id="telefono" placeholder="Ingrese el teléfono" required>
                                    </div>
                                </div>
                                <!-- Correo -->
                                <div class="form-group row">
                                    <label for="email" class="col-sm-2 col-form-label">Correo:*</label>
                                    <div class="col-sm-10">
                                        <input type="email" name="email" class="form-control" id="email" placeholder="Ingrese el correo electrónico" required>
                                    </div>
                                </div>
                                <h4>INFORMACIÓN DE USUARIO</h4>
                                <!-- Usuario -->
                                <div class="form-group row">
                                    <label for="usuario" class="col-sm-2 col-form-label">Usuario:*</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="usuario" class="form-control" id="usuario" placeholder="Ingrese el nombre de usuario" required>
                                    </div>
                                </div>
                                <!-- Contraseña -->
                                <div class="form-group row">
                                    <label for="password" class="col-sm-2 col-form-label">Contraseña:*</label>
                                    <div class="col-sm-10">
                                        <input type="password" name="password" class="form-control" id="password" placeholder="Ingrese la contraseña" required>
                                    </div>
                                </div>
                                <!-- Perfil -->
                                <div class="form-group row">
                                    <label for="selectPerfil" class="col-sm-2 col-form-label">Perfil:*</label>
                                    <div class="col-sm-10">
                                        <select class="form-control" id="selectPerfil" name="id_perfil" required>
                                            <option value="">Seleccione un perfil</option>
                                            <?php foreach ($perfiles as $perfil): ?>
                                                <option value="<?php echo htmlspecialchars($perfil['id']); ?>"><?php echo htmlspecialchars($perfil['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- Empresa -->
                                <div class="form-group row">
                                    <label for="selectEmpresas" class="col-sm-2 col-form-label">Empresa:*</label>
                                    <div class="col-sm-10">
                                        <select class="form-control" id="selectEmpresas" name="id_empresa" required>
                                            <option value="">Seleccione una empresa</option>
                                            <?php foreach ($empresas as $empresa): ?>
                                                <option value="<?php echo htmlspecialchars($empresa['id']); ?>"><?php echo htmlspecialchars($empresa['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- División -->
                                <div class="form-group row" id="divisionField" style="display: none;">
                                    <label for="selectDivision" class="col-sm-2 col-form-label">División:*</label>
                                    <div class="col-sm-10">
                                        <select class="form-control" id="selectDivision" name="id_division">
                                            <option value="">Seleccione una división</option>
                                            <!-- Las opciones se cargarán dinámicamente mediante AJAX -->
                                        </select>
                                    </div>
                                </div>
                                <!-- Foto de perfil -->
                                <div class="form-group row">
                                    <label for="fotoPerfil" class="col-sm-2 col-form-label">Foto de perfil:</label>
                                    <div class="col-sm-10">
                                        <div class="custom-file">
                                            <input type="file" class="custom-file-input" name="fotoPerfil" id="fotoPerfil" accept=".jpg, .jpeg, .png">
                                            <label class="custom-file-label" for="fotoPerfil">Seleccionar imagen</label>
                                        </div>
                                        <small class="form-text text-muted">Formatos permitidos: JPG, JPEG, PNG. Tamaño máximo: 2MB.</small>
                                    </div>
                                </div>
                            </div>
                            <!-- /.card-body -->
                            <div class="card-footer text-right">
                                <button type="submit" class="btn btn-primary">Crear Usuario</button>
                            </div>
                            <!-- /.card-footer -->
                        </form>
                    </div>
                    <!-- Fin del Formulario de Crear Usuario -->

                    <!-- Formulario de Carga Masiva -->
                    <div class="tab-pane fade" id="cargaMasiva" role="tabpanel" aria-labelledby="cargaMasiva-tab">
                        <!-- Aquí va el formulario de carga masiva -->
                        <!-- Puedes incluir el código correspondiente de tu formulario de carga masiva -->
                        <form class="form-horizontal mt-3" method="POST" action="mod_user/procesar_csv.php" enctype="multipart/form-data">
                            <div class="card-body">
                                <!-- Token CSRF -->
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                <h4>CARGA MASIVA DE USUARIOS</h4>
                                <!-- Archivo CSV -->
                                <div class="form-group row">
                                    <label for="csvFile" class="col-sm-2 col-form-label">Archivo CSV:*</label>
                                    <div class="col-sm-10">
                                        <div class="custom-file">
                                            <input type="file" class="custom-file-input" name="csvFile" id="csvFile" accept=".csv" required>
                                            <label class="custom-file-label" for="csvFile">Seleccionar archivo</label>
                                        </div>
                                        <small class="form-text text-muted">Formato requerido: CSV. Asegúrate de seguir la plantilla proporcionada.</small>
                                        <a href="mod_user/descargar_plantilla.php"> Descargar plantilla de prueba</a>
                                    </div>
                                </div>
                                <!-- Empresa (Opcional, si aplica) -->
                                <div class="form-group row">
                                    <label for="empresa_id_csv" class="col-sm-2 col-form-label">Empresa:</label>
                                    <div class="col-sm-10">
                                        <select class="form-control" id="empresa_id_csv" name="empresa_id_csv">
                                            <option value="">Seleccione una empresa</option>
                                            <?php foreach ($empresas as $empresa): ?>
                                                <option value="<?php echo htmlspecialchars($empresa['id']); ?>"><?php echo htmlspecialchars($empresa['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- División (Opcional, si aplica) -->
                                <div class="form-group row">
                                    <label for="division_id_csv" class="col-sm-2 col-form-label">División:</label>
                                    <div class="col-sm-10">
                                        <select class="form-control" id="division_id_csv" name="division_id">
                                            <option value="">Seleccione una división</option>
                                            <!-- Las opciones se cargarán dinámicamente mediante AJAX -->
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <!-- /.card-body -->
                            <div class="card-footer text-right">
                                <button type="submit" class="btn btn-primary">Cargar Usuarios</button>
                            </div>
                            <!-- /.card-footer -->
                        </form>
                    </div>
                    <!-- Fin del Formulario de Carga Masiva -->
                </div>
                <!-- Fin del Contenido de las Pestañas -->
            </div>
        </div>
    </div>
    <!-- Fin del Modal -->
</div>

<!-- Modal para Editar Usuario -->
<div class="modal fade" id="editarUsuarioModal" tabindex="-1" role="dialog" aria-labelledby="editarUsuarioModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <!-- Formulario dentro del modal-body para una mejor estructura -->
            <div class="modal-header bg-success-mc">
                <h5 class="modal-title" id="editarUsuarioModalLabel">Editar Usuario</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="editarUsuarioForm" method="POST" action="mod_user/procesar_modificacion.php" enctype="multipart/form-data">
                <div class="modal-body modal-body-scrollable">
                    <!-- Campos del Formulario -->
                    <input type="hidden" name="usuario_id" id="editar_usuario_id">
                    <!-- Token CSRF -->
                    <input type="hidden" name="csrf_token" id="csrf_token_edit" value="<?php echo $_SESSION['csrf_token']; ?>">

                    <h4>INFORMACIÓN DEL TRABAJADOR</h4>
                    <!-- RUT (No editable) -->
                    <div class="form-group row">
                        <label for="editar_rut" class="col-sm-2 col-form-label">Rut:</label>
                        <div class="col-sm-10">
                            <input type="text" name="rut" class="form-control" id="editar_rut" required>
                        </div>
                    </div>
                    <!-- Nombre -->
                    <div class="form-group row">
                        <label for="editar_nombre" class="col-sm-2 col-form-label">Nombre:</label>
                        <div class="col-sm-10">
                            <input type="text" name="nombre" class="form-control" id="editar_nombre" required>
                        </div>
                    </div>
                    <!-- Apellido -->
                    <div class="form-group row">
                        <label for="editar_apellido" class="col-sm-2 col-form-label">Apellido:</label>
                        <div class="col-sm-10">
                            <input type="text" name="apellido" class="form-control" id="editar_apellido" required>
                        </div>
                    </div>
                    <!-- Teléfono -->
                    <div class="form-group row">
                        <label for="editar_telefono" class="col-sm-2 col-form-label">Teléfono:</label>
                        <div class="col-sm-10">
                            <input type="tel" name="telefono" class="form-control" id="editar_telefono" required>
                        </div>
                    </div>
                    <!-- Correo -->
                    <div class="form-group row">
                        <label for="editar_email" class="col-sm-2 col-form-label">Correo:</label>
                        <div class="col-sm-10">
                            <input type="email" name="email" class="form-control" id="editar_email" required>
                        </div>
                    </div>
                    <h4>INFORMACIÓN DE USUARIO</h4>
                    <!-- Usuario -->
                    <div class="form-group row">
                        <label for="editar_usuario" class="col-sm-2 col-form-label">Usuario:</label>
                        <div class="col-sm-10">
                            <input type="text" name="usuario" class="form-control" id="editar_usuario" required>
                        </div>
                    </div>
                    <!-- Perfil -->
                    <div class="form-group row">
                        <label for="editar_perfil" class="col-sm-2 col-form-label">Perfil:</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editar_perfil" name="id_perfil" required>
                                <option value="">Seleccione un perfil</option>
                                <?php foreach ($perfiles as $perfil): ?>
                                    <option value="<?php echo htmlspecialchars($perfil['id']); ?>"><?php echo htmlspecialchars($perfil['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <!-- Empresa -->
                    <div class="form-group row">
                        <label for="editar_empresa" class="col-sm-2 col-form-label">Empresa:</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editar_empresa" name="id_empresa" required>
                                <option value="">Seleccione una empresa</option>
                                <?php foreach ($empresas as $empresa): ?>
                                    <option value="<?php echo htmlspecialchars($empresa['id']); ?>"><?php echo htmlspecialchars($empresa['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <!-- División -->
                    <div class="form-group row" id="editar_divisionField" style="display: none;">
                        <label for="editar_division" class="col-sm-2 col-form-label">División:</label>
                        <div class="col-sm-10">
                            <select class="form-control" id="editar_division" name="id_division">
                                <option value="">Seleccione una división</option>
                                <!-- Las opciones se cargarán dinámicamente mediante AJAX -->
                            </select>
                        </div>
                    </div>
                    <!-- Contraseña -->
                    <div class="form-group row">
                      <label class="col-sm-2 col-form-label">Contraseña:</label>
                      <div class="col-sm-10">
                        <button type="button" id="btnCambiarClave" class="btn btn-outline-secondary">
                          <i class="fas fa-key"></i> Cambiar contraseña
                        </button>
                      </div>
                    </div>
                    <div id="bloqueCambioClave" style="display:none;">
                      <div class="form-group row">
                        <label for="new_password" class="col-sm-2 col-form-label">Nueva contraseña:</label>
                        <div class="col-sm-10">
                          <input type="password" name="new_password" class="form-control" id="new_password">
                        </div>
                      </div>
                      <div class="form-group row">
                        <label for="confirm_password" class="col-sm-2 col-form-label">Confirmar contraseña:</label>
                        <div class="col-sm-10">
                          <input type="password" name="confirm_password" class="form-control" id="confirm_password">
                        </div>
                      </div>
                    </div>
                    <!-- Foto de perfil -->
                    <div class="form-group row">
                        <label for="editar_fotoPerfil" class="col-sm-2 col-form-label">Foto de perfil:</label>
                        <div class="col-sm-10">
                            <!-- Mostrar imagen actual -->
                            <div class="mb-2">
                                <img src="" alt="Foto de perfil actual" id="actual_fotoPerfil" style="max-width: 150px;">
                            </div>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="fotoPerfil" id="editar_fotoPerfil" accept=".jpg, .jpeg, .png">
                                <label class="custom-file-label" for="editar_fotoPerfil">Seleccionar nueva imagen</label>
                            </div>
                            <small class="form-text text-muted">Formatos permitidos: JPG, JPEG, PNG. Tamaño máximo: 2MB. No subas ningun archivo si no deseas cambiar tu foto.</small>
                        </div>
                    </div>
                </div>
                <!-- /.modal-body -->
                <div class="modal-footer">
                    <!-- Botones dentro del formulario para asegurar que el botón "Guardar Cambios" envíe el formulario -->
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
                <!-- /.modal-footer -->
            </form>
        </div>
    </div>
</div>
<!-- Fin del Modal para Editar Usuario -->

<!-- Scripts -->
<!-- jQuery desde CDN -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<!-- Bootstrap 4 desde CDN -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="/visibility2/portal/dist/js/jquery.dataTables.min.js"></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/dataTables.buttons.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.colVis.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.html5.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.print.min.js'></script>
<script src='https://cdn.datatables.net/1.10.12/js/dataTables.bootstrap.min.js'></script>
<script src='https://cdn.datatables.net/buttons/1.2.2/js/buttons.bootstrap.min.js'></script>
<script src='https://cdnjs.cloudflare.com/ajax/libs/jszip/2.5.0/jszip.min.js'></script>
<script src='https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/vfs_fonts.js'></script>
<script src='https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/pdfmake.min.js'></script>
<!-- bs-custom-file-input -->
<script src="../plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>





<script>
    $(document).ready(function () {
	//Only needed for the filename of export files.
	//Normally set in the title tag of your page.
	document.title = "Simple DataTable";
	// Create search inputs in footer
	$("#example tfoot th").each(function () {
		var title = $(this).text();
		$(this).html('<input type="text" placeholder="Buscar ' + title + '" />');
	});
	// DataTable initialisation
	var table = $("#example").DataTable({
		dom: '<"dt-buttons"Bf><"clear">lirtp',
		paging: true,
		autoWidth: true,
		buttons: [
			"colvis",
			"copyHtml5",
			"csvHtml5",
			"excelHtml5",
			"pdfHtml5",
			"print"
		],
		initComplete: function (settings, json) {
			var footer = $("#example tfoot tr");
			$("#example thead").append(footer);
		}
	});

	// Apply the search
	$("#example thead").on("keyup", "input", function () {
		table.column($(this).parent().index())
		.search(this.value)
		.draw();
	});
});
</script>


<script>
    $(function () {
        bsCustomFileInput.init();
    });

    $(document).ready(function(){
        // Función para mostrar u ocultar el campo de división según el perfil seleccionado en Crear Usuario
        function controlarCampoDivisionCrear() {
            var perfilSeleccionado = $('#selectPerfil').find('option:selected').text().toLowerCase();
            var divisionsAvailable = $('#selectDivision option').length > 1;

            if ((perfilSeleccionado === 'coordinador' || perfilSeleccionado === 'editor' || perfilSeleccionado === 'ejecutor' || perfilSeleccionado === 'visor') && divisionsAvailable) {
                $('#divisionField').show();
                $('#selectDivision').attr('required', 'required');
            } else {
                $('#divisionField').hide();
                $('#selectDivision').removeAttr('required');
                $('#selectDivision').val(''); // Limpiar selección de división
            }
        }

        // Función para cargar divisiones según la empresa seleccionada en Crear Usuario
        $('#selectEmpresas').on('change', function(){
            var empresaId = $(this).val();
            if (empresaId) {
                $.ajax({
                    type: 'GET',
                    url: 'mod_user/obtener_divisiones.php',
                    data: {empresa_id: empresaId},
                    success: function(html){
                        $('#selectDivision').html(html);
                        controlarCampoDivisionCrear();
                    },
                    error: function(xhr, status, error) {
                        console.error('Error en la petición AJAX:', error);
                        $('#divisionField').hide();
                        $('#selectDivision').html('<option value="">Seleccione una división</option>');
                    }
                });
            } else {
                $('#divisionField').hide();
                $('#selectDivision').html('<option value="">Seleccione una división</option>');
            }
        });

        // Escuchar cambios en el selector de perfil en Crear Usuario
        $('#selectPerfil').on('change', function(){
            controlarCampoDivisionCrear();
        });

        // Inicializar la visibilidad del campo de división al cargar la página en Crear Usuario
        controlarCampoDivisionCrear();

        // Manejar el evento de clic en el botón "Editar" usando delegación de eventos
        $(document).on('click', '.editar-usuario-btn', function(){
            var usuarioId = $(this).data('id');
            console.log('Usuario ID:', usuarioId);

            // Realizar una petición AJAX para obtener los datos del usuario
            $.ajax({
                url: 'mod_user/get_user.php',
                type: 'GET',
                data: {id: usuarioId},
                dataType: 'json',
                success: function(response){
                    if(response.status === 'success'){
                        var data = response.data;
                        console.log('Datos del usuario:', data);
                        // Llenar los campos del modal con los datos del usuario
                        $('#editar_usuario_id').val(data.id);
                        $('#editar_rut').val(data.rut);
                        $('#editar_nombre').val(data.nombre);
                        $('#editar_apellido').val(data.apellido);
                        $('#editar_telefono').val(data.telefono);
                        $('#editar_email').val(data.email);
                        $('#editar_usuario').val(data.usuario);
                        $('#editar_perfil').val(data.id_perfil);
                        $('#editar_empresa').val(data.id_empresa);
                        $('#actual_fotoPerfil').attr('src', data.fotoPerfil ? data.fotoPerfil : 'ruta_por_defecto.jpg'); // Ruta por defecto si no hay foto

                        // Cargar las divisiones correspondientes a la empresa del usuario
                        cargarDivisionesEditar(data.id_empresa, data.id_division);
                    } else {
                        alert(response.message);
                    }
                },
                error: function(xhr, status, error){
                    console.error('Error en la petición AJAX:', error);
                    alert('Hubo un error al obtener los datos del usuario.');
                }
            });
        });

        // Función para cargar divisiones en el modal de edición
        function cargarDivisionesEditar(id_empresa, id_division){
            if(id_empresa){
                $.ajax({
                    url: 'mod_user/obtener_divisiones.php',
                    type: 'GET',
                    data: {empresa_id: id_empresa},
                    success: function(html){
                        $('#editar_division').html(html);
                        if(id_division){
                            $('#editar_division').val(id_division);
                        }
                        // Mostrar u ocultar el campo de división según el perfil
                        controlarCampoDivisionEditar();
                        // Mostrar el modal
                        $('#editarUsuarioModal').modal('show');
                    },
                    error: function(xhr, status, error){
                        console.error('Error en la petición AJAX:', error);
                        $('#editar_division').html('<option value="">Seleccione una división</option>');
                        $('#editar_divisionField').hide();
                        // Mostrar el modal incluso si hay un error al cargar las divisiones
                        $('#editarUsuarioModal').modal('show');
                    }
                });
            } else {
                $('#editar_division').html('<option value="">Seleccione una división</option>');
                $('#editar_divisionField').hide();
                // Mostrar el modal
                $('#editarUsuarioModal').modal('show');
            }
        }
        // Mostrar/ocultar bloque de cambio de contraseña
        $('#btnCambiarClave').on('click', function() {
          $('#bloqueCambioClave').toggle();
          // Si lo acabamos de ocultar, limpiar los campos
          if ($('#bloqueCambioClave').is(':hidden')) {
            $('#new_password, #confirm_password').val('');
          }
        });
        
        $('#editarUsuarioModal').on('show.bs.modal', function() {
          // Ocultar y limpiar campos de contraseña
          $('#bloqueCambioClave').hide();
          $('#new_password, #confirm_password').val('');
        });
        
        
        // Función para mostrar u ocultar el campo de división según el perfil seleccionado en Editar Usuario
        function controlarCampoDivisionEditar() {
            var perfilSeleccionado = $('#editar_perfil').find('option:selected').text().toLowerCase();
            var divisionsAvailable = $('#editar_division').find('option').length > 1;

            if ((perfilSeleccionado === 'visor' || perfilSeleccionado === 'ejecutor' || perfilSeleccionado === 'coordinador' || perfilSeleccionado === 'editor') && divisionsAvailable) {
                $('#editar_divisionField').show();
                $('#editar_division').attr('required', 'required');
            } else {
                $('#editar_divisionField').hide();
                $('#editar_division').removeAttr('required');
                $('#editar_division').val(''); // Limpiar selección de división
            }
        }

        // Escuchar cambios en el selector de perfil en Editar Usuario
        $('#editar_perfil').on('change', function(){
            controlarCampoDivisionEditar();
        });

        // Escuchar cambios en el selector de empresa en Editar Usuario
        $('#editar_empresa').on('change', function(){
            var empresaId = $(this).val();
            if (empresaId) {
                $.ajax({
                    url: 'mod_user/obtener_divisiones.php',
                    type: 'GET',
                    data: {empresa_id: empresaId},
                    success: function(html){
                        $('#editar_division').html(html);
                        controlarCampoDivisionEditar();
                    },
                    error: function(xhr, status, error){
                        console.error('Error en la petición AJAX:', error);
                        $('#editar_division').html('<option value="">Seleccione una división</option>');
                        $('#editar_divisionField').hide();
                    }
                });
            } else {
                $('#editar_division').html('<option value="">Seleccione una división</option>');
                $('#editar_divisionField').hide();
            }
        });

        // Manejar el evento de clic en el botón "Eliminar" usando delegación de eventos
        $(document).on('click', '.eliminar-usuario-btn', function(){
            var usuarioId = $(this).data('id');
            var fila = $('#usuario-' + usuarioId);

            if(confirm('¿Estás seguro de que deseas eliminar (desactivar) este usuario?')){
                $.ajax({
                    url: 'mod_user/eliminar_usuario.php',
                    type: 'POST',
                    data: {
                        id: usuarioId,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    },
                    dataType: 'json',
                    success: function(response){
                        if(response.status === 'success'){
                            alert('Usuario desactivado exitosamente.');
                            // Actualizar el estado en la tabla
                            fila.find('td:nth-child(6)').html('<span class="badge badge-danger">Inactivo</span>');
                            // Cambiar los botones
                            fila.find('.eliminar-usuario-btn').replaceWith('<button type="button" class="btn btn-sm btn-secondary reactivar-usuario-btn" data-id="' + usuarioId + '"><i class="fas fa-undo"></i> Reactivar</button>');
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error){
                        console.error('Error en la petición AJAX:', error);
                        alert('Hubo un error al desactivar el usuario.');
                    }
                });
            }
        });

        // Manejar el evento de clic en el botón "Reactivar" usando delegación de eventos
        $(document).on('click', '.reactivar-usuario-btn', function(){
            var usuarioId = $(this).data('id');
            var fila = $('#usuario-' + usuarioId);

            if(confirm('¿Estás seguro de que deseas reactivar este usuario?')){
                $.ajax({
                    url: 'mod_user/reactivar_usuario.php',
                    type: 'POST',
                    data: {
                        id: usuarioId,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    },
                    dataType: 'json',
                    success: function(response){
                        if(response.status === 'success'){
                            alert('Usuario reactivado exitosamente.');
                            // Actualizar el estado en la tabla
                            fila.find('td:nth-child(6)').html('<span class="badge badge-success">Activo</span>');
                            // Cambiar los botones
                            fila.find('.reactivar-usuario-btn').replaceWith('<button type="button" class="btn btn-sm btn-danger eliminar-usuario-btn" data-id="' + usuarioId + '"><i class="fas fa-trash-alt"></i> Eliminar</button>');
                        } else {
                            alert('Error: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error){
                        console.error('Error en la petición AJAX:', error);
                        alert('Hubo un error al reactivar el usuario.');
                    }
                });
            }
        });

        // Manejar el cambio de los filtros para actualizar la tabla
        $('#filtroEstado, #filtroEmpresa, #filtroPerfil, #filtroDivision').on('change', function(){
            var estado = $('#filtroEstado').val();
            var empresa = $('#filtroEmpresa').val();
            var perfil = $('#filtroPerfil').val();
            var division = $('#filtroDivision').val();

            // Realizar una petición AJAX para obtener los usuarios filtrados
            $.ajax({
                url: 'mod_user/filtrar_usuarios.php',
                type: 'GET',
                data: {
                    filtro_estado: estado,
                    filtro_empresa: empresa,
                    filtro_perfil: perfil,
                    filtro_division: division
                },
                dataType: 'json',
                success: function(response){
                    if(response.data && response.data.length > 0){
                        // Limpiar la tabla actual
                        $('#tablaUsuarios tbody').empty();

                        // Recorrer los datos y agregarlos a la tabla
                        $.each(response.data, function(index, usuario){
                            var fila = '<tr id="usuario-' + usuario.id + '">';
                            fila += '<td class="uppercase">' + usuario.nombre_completo + '</td>';
                            fila += '<td class="uppercase">' + usuario.nombre_login + '</td>';
                            fila += '<td class="uppercase">' + usuario.nombre_empresa + '</td>';
                            fila += '<td class="uppercase">' + usuario.nombre_division + '</td>';
                            fila += '<td class="uppercase">' + usuario.nombre_perfil + '</td>';
                            fila += '<td class="uppercase">' + usuario.activo + '</td>';
                            fila += '<td class="uppercase">' + usuario.acciones + '</td>';
                            fila += '</tr>';

                            $('#tablaUsuarios tbody').append(fila);
                        });
                    } else {
                        // Si no hay datos, mostrar un mensaje
                        $('#tablaUsuarios tbody').html('<tr><td colspan="7" class="text-center">No se encontraron usuarios.</td></tr>');
                    }
                },
                error: function(xhr, status, error){
                    console.error('Error en la petición AJAX:', error);
                    alert('Hubo un error al filtrar los usuarios.');
                }
            });
        });

        // Función para cargar divisiones según la empresa seleccionada en el filtro
        $('#filtroEmpresa').on('change', function(){
            var empresaId = $(this).val();
            if (empresaId && empresaId !== 'todos') {
                $.ajax({
                    url: 'mod_user/obtener_divisiones.php',
                    type: 'GET',
                    data: {empresa_id: empresaId},
                    success: function(html){
                        $('#filtroDivision').html(html);
                        $('#filtroDivision').prepend('<option value="todos">Todas las Divisiones</option>');
                        $('#filtroDivision').val('todos'); // Resetear selección
                    },
                    error: function(xhr, status, error){
                        console.error('Error en la petición AJAX:', error);
                        $('#filtroDivision').html('<option value="todos">Todas las Divisiones</option>');
                    }
                });
            } else {
                $('#filtroDivision').html('<option value="todos">Todas las Divisiones</option>');
            }
        });

        // Inicializar los filtros al cargar la página
        $('#filtroEstado').trigger('change');
        $('#filtroEmpresa').trigger('change');
    });
</script>

<script>
    $(function () {
        bsCustomFileInput.init();
    });

    $(document).ready(function(){
        // Función para mostrar u ocultar el campo de división según la empresa seleccionada en Carga Masiva
        function actualizarDivisiones() {
            var empresaId = $('#empresa_id_csv').val();
            if (empresaId) {
                $.ajax({
                    url: 'mod_user/obtener_divisiones.php',
                    type: 'GET',
                    data: {empresa_id: empresaId},
                    success: function(html){
                        $('#division_id_csv').html(html);
                    },
                    error: function(xhr, status, error) {
                        console.error('Error en la petición AJAX:', error);
                        $('#division_id_csv').html('<option value="">Seleccione una división</option>');
                    }
                }); 
            } else {
                $('#division_id_csv').html('<option value="">Seleccione una división</option>');
            }
        }

        // Cargar divisiones cuando se selecciona una empresa en Carga Masiva (Solo para "mentecreativa")
        $('#empresa_id_csv').on('change', function(){
            actualizarDivisiones();
        });
    });
</script>

</body>
</html>
