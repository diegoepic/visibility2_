<?php
// editar_usuario.php

// Incluir archivos necesarios
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// Incluir la función de generación de tokens
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_user/generate_token.php';

// Verificar si el ID del usuario está presente
if (isset($_GET['id'])) {
    $usuario_id = intval($_GET['id']);
} else {
    echo "ID de usuario no proporcionado.";
    exit();
}

// Obtener los datos del usuario desde la base de datos
$stmt = $conn->prepare("
    SELECT 
        u.id,
        u.rut,
        u.nombre,
        u.apellido,
        u.telefono,
        u.email,
        u.usuario,
        u.fotoPerfil,
        u.id_empresa,
        u.id_division,
        u.id_perfil
    FROM 
        usuario u
    WHERE 
        u.id = ?
");
$stmt->bind_param("i", $usuario_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "Usuario no encontrado.";
    exit();
}

$usuario = $result->fetch_assoc();
$stmt->close();

// Obtener todas las empresas activas
$empresas = obtenerEmpresasActivas();

// Obtener los perfiles disponibles
$perfiles = obtenerPerfiles();

// Obtener las divisiones de la empresa actual del usuario
$divisiones = obtenerDivisionesPorEmpresa($usuario['id_empresa']);

// Generar el token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generate_csrf_token(32);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Editar Usuario - Visibility 2</title>
    <link rel="icon" href="../images/logo/logo-Visibility.png" type="image/png">
    <!-- Meta y Enlaces -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- CSS -->
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- Estilo del Tema -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:400,400i,700&display=fallback">
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
    <!-- Contenido Principal -->
    <section class="content">
        <div class="container-fluid">
            <!-- Mostrar mensajes de éxito o error -->
            <?php if (isset($_SESSION['success_modificacion'])): ?>
                <div class="alert alert-success">
                    <?php
                    echo $_SESSION['success_modificacion'];
                    unset($_SESSION['success_modificacion']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_modificacion'])): ?>
                <div class="alert alert-danger">
                    <?php
                    echo $_SESSION['error_modificacion'];
                    unset($_SESSION['error_modificacion']);
                    ?>
                </div>
            <?php endif; ?>

            <!-- Formulario de Edición de Usuario -->
            <div class="row">
                <div class="col-md-8 offset-md-2">
                    <div class="card">
                        <div class="card-header bg-success">
                            <h3 class="card-title">Editar Usuario</h3>
                        </div>
                        <!-- /.card-header -->
                        <form class="form-horizontal" method="POST" action="procesar_modificacion.php" enctype="multipart/form-data">
                            <div class="card-body">
                                <input type="hidden" name="usuario_id" value="<?php echo $usuario['id']; ?>">
                                <!-- Token CSRF -->
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                <h4>INFORMACIÓN DEL TRABAJADOR</h4>
                                <!-- RUT (No editable) -->
                                <div class="form-group row">
                                    <label for="inputRut" class="col-sm-2 col-form-label">Rut:</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="rut" class="form-control" id="inputRut" value="<?php echo htmlspecialchars($usuario['rut']); ?>" disabled>
                                    </div>
                                </div>
                                <!-- Nombre -->
                                <div class="form-group row">
                                    <label for="inputNombre" class="col-sm-2 col-form-label">Nombre:</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="nombre" class="form-control" id="inputNombre" value="<?php echo htmlspecialchars($usuario['nombre']); ?>" required>
                                    </div>
                                </div>
                                <!-- Apellido -->
                                <div class="form-group row">
                                    <label for="inputApellido" class="col-sm-2 col-form-label">Apellido:</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="apellido" class="form-control" id="inputApellido" value="<?php echo htmlspecialchars($usuario['apellido']); ?>" required>
                                    </div>
                                </div>
                                <!-- Teléfono -->
                                <div class="form-group row">
                                    <label for="inputTelefono" class="col-sm-2 col-form-label">Teléfono:</label>
                                    <div class="col-sm-10">
                                        <input type="tel" name="telefono" class="form-control" id="inputTelefono" value="<?php echo htmlspecialchars($usuario['telefono']); ?>" required>
                                    </div>
                                </div>
                                <!-- Correo -->
                                <div class="form-group row">
                                    <label for="inputEmail3" class="col-sm-2 col-form-label">Correo:</label>
                                    <div class="col-sm-10">
                                        <input type="email" name="email" class="form-control" id="inputEmail3" value="<?php echo htmlspecialchars($usuario['email']); ?>" required>
                                    </div>
                                </div>
                                <h4>INFORMACIÓN DE USUARIO</h4>
                                <!-- Usuario -->
                                <div class="form-group row">
                                    <label for="inputUsuario" class="col-sm-2 col-form-label">Usuario:</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="usuario" class="form-control" id="inputUsuario" value="<?php echo htmlspecialchars($usuario['usuario']); ?>" required>
                                    </div>
                                </div>
                                <!-- Perfil -->
                                <div class="form-group row">
                                    <label for="selectPerfil" class="col-sm-2 col-form-label">Perfil:</label>
                                    <div class="col-sm-10">
                                        <select class="form-control" id="selectPerfil" name="id_perfil" required>
                                            <option value="">Seleccione un perfil</option>
                                            <?php foreach ($perfiles as $perfil): ?>
                                                <option value="<?php echo htmlspecialchars($perfil['id']); ?>" <?php if ($usuario['id_perfil'] == $perfil['id']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($perfil['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- Empresa -->
                                <div class="form-group row">
                                    <label for="selectEmpresas" class="col-sm-2 col-form-label">Empresa:</label>
                                    <div class="col-sm-10">
                                        <select class="form-control" id="selectEmpresas" name="id_empresa" required>
                                            <option value="">Seleccione una empresa</option>
                                            <?php foreach ($empresas as $empresa): ?>
                                                <option value="<?php echo htmlspecialchars($empresa['id']); ?>" <?php if ($usuario['id_empresa'] == $empresa['id']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($empresa['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- División -->
                                <div class="form-group row" id="divisionField">
                                    <label for="selectDivision" class="col-sm-2 col-form-label">División:</label>
                                    <div class="col-sm-10">
                                        <select class="form-control" id="selectDivision" name="id_division">
                                            <option value="">Seleccione una división</option>
                                            <?php foreach ($divisiones as $division): ?>
                                                <option value="<?php echo htmlspecialchars($division['id']); ?>" <?php if ($usuario['id_division'] == $division['id']) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($division['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- Contraseña -->
                                <div class="form-group row">
                                    <label for="clave" class="col-sm-2 col-form-label">Contraseña:</label>
                                    <div class="col-sm-10">
                                        <input type="password" name="password" class="form-control" id="clave" placeholder="Dejar en blanco para no cambiar">
                                        <small class="form-text text-muted">Deja este campo en blanco si no deseas cambiar la contraseña.</small>
                                    </div>
                                </div>
                                <!-- Foto de perfil -->
                                <div class="form-group row">
                                    <label for="fotoPerfil" class="col-sm-2 col-form-label">Foto de perfil:</label>
                                    <div class="col-sm-10">
                                        <!-- Mostrar imagen actual -->
                                        <div class="mb-2">
                                            <img src="<?php echo htmlspecialchars($usuario['fotoPerfil']); ?>" alt="Foto de perfil actual" style="max-width: 150px;">
                                        </div>
                                        <div class="custom-file">
                                            <input type="file" class="custom-file-input" name="fotoPerfil" id="fotoPerfil" accept=".jpg, .jpeg, .png">
                                            <label class="custom-file-label" for="fotoPerfil">Seleccionar nueva imagen</label>
                                        </div>
                                        <small class="form-text text-muted">Formatos permitidos: JPG, JPEG, PNG. Tamaño máximo: 2MB. Deja en blanco si no deseas cambiar la foto.</small>
                                    </div>
                                </div>
                            </div>
                            <!-- /.card-body -->
                            <div class="card-footer text-right">
                                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                                <a href="../mod_create_user.php" class="btn btn-secondary">Cancelar</a>
                            </div>
                            <!-- /.card-footer -->
                        </form>
                    </div>
                </div>
            </div>
            <!-- /.row -->
        </div>
        <!-- /.container-fluid -->
    </section>
    <!-- /.content -->
</div>
<!-- /.wrapper -->

<!-- Scripts -->
<!-- jQuery -->
<script src="../plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- bs-custom-file-input -->
<script src="../plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>
<!-- AdminLTE App -->
<script src="../dist/js/adminlte.min.js"></script>
<script>
    $(function () {
        bsCustomFileInput.init();
    });

    $(document).ready(function(){
        // Función para mostrar u ocultar el campo de división según el perfil seleccionado
        function controlarCampoDivision() {
            var perfilSeleccionado = $('#selectPerfil').find('option:selected').text().toLowerCase();
            var divisionsAvailable = $('#selectDivision option').length > 1;

            if ((perfilSeleccionado === 'visor' || perfilSeleccionado === 'ejecutor') && divisionsAvailable) {
                $('#divisionField').show();
                $('#selectDivision').attr('required', 'required');
            } else {
                $('#divisionField').hide();
                $('#selectDivision').removeAttr('required');
                $('#selectDivision').val(''); // Limpiar selección de división
            }
        }

        // Cargar divisiones cuando se selecciona una empresa
        $('#selectEmpresas').on('change', function(){
            var empresaId = $(this).val();
            if (empresaId) {
                $.ajax({
                    type: 'GET',
                    url: 'obtener_divisiones.php',
                    data: {empresa_id: empresaId},
                    success: function(html){
                        $('#selectDivision').html(html);
                        controlarCampoDivision();
                    },
                    error: function(xhr, status, error) {
                        console.error('Error en la petición AJAX:', error);
                        $('#divisionField').hide();
                        $('#selectDivision').html('<option value="">Seleccione una divisi&oacute;n</option>');
                    }
                }); 
            } else {
                $('#divisionField').hide();
                $('#selectDivision').html('<option value="">Seleccione una divisi&oacute;n</option>');
            }
        });

        // Escuchar cambios en el selector de perfil
        $('#selectPerfil').on('change', function(){
            controlarCampoDivision();
        });

        // Inicializar la visibilidad del campo de división al cargar la página
        controlarCampoDivision();
    });
</script>
</body>
</html>
