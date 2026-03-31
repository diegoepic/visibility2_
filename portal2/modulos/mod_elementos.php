<?php
// mod_elementos.php

// Habilitar la visualización de errores para depuración (desactivar en producción)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Iniciar la sesión
session_start();

// Incluir la conexión a la base de datos y funciones
include 'db.php';

// Obtener empresas activas e inactivas
$empresas_activas = obtenerEmpresasActivas();
$empresas = obtenerEmpresas();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Mentecreativa | Visibility 2</title>
    <link rel="icon" href="../images/logo/logo-Visibility.png" type="image/png">
    <!-- Meta y Enlaces -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../plugins/fontawesome-free/css/all.min.css">
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="../plugins/bootstrap/css/bootstrap.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="../dist/css/adminlte.min.css">
</head>
<body class="hold-transition sidebar-mini">
    <div class="wrapper">
        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <!-- Crear Empresa -->
                    <div class="col-md-6">
                        <div class="card card-success">
                            <div class="card-header">
                                <h3 class="card-title">Crear Empresa</h3>
                            </div>
                            <form class="form-horizontal" method="POST" action="mod_elementos/procesar.php">
                                <div class="card-body">
                                    <div class="form-group row">
                                        <label for="inputEmpresa" class="col-sm-2 col-form-label">EMPRESA:*</label>
                                        <div class="col-sm-10">
                                            <input type="text" name="inputEmpresa" class="form-control" id="inputEmpresa" placeholder="NOMBRE EMPRESA..." required>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label for="inputDivision" class="col-sm-2 col-form-label">DIVISI&Oacute;N:</label>
                                        <div class="col-sm-10">
                                            <input type="text" name="inputDivision" class="form-control" id="inputDivision" placeholder="NOMBRE DIVISI&Oacute;N (Opcional)">
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <button type="submit" class="btn btn-primary">Agregar Empresa</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <!-- Crear División -->
                    <div class="col-md-6">
                        <div class="card card-success">
                            <div class="card-header">
                                <h3 class="card-title">Crear Divisi&oacute;n</h3>
                            </div>
                            <form class="form-horizontal" method="POST" action="mod_elementos/procesar_division.php">
                                <div class="form-group row">
                                    <label for="selectEmpresa" class="col-sm-2 col-form-label">Empresa:</label>
                                    <div class="col-sm-10">
                                        <select name="empresa_id" id="selectEmpresa" class="form-control" required>
                                            <?php foreach ($empresas_activas as $empresa): ?>
                                                <option value="<?php echo $empresa['id']; ?>"><?php echo htmlspecialchars($empresa['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group row">
                                    <label for="inputDivision" class="col-sm-2 col-form-label">Divisi&oacute;n:</label>
                                    <div class="col-sm-10">
                                        <input type="text" name="inputDivision" class="form-control" id="inputDivision" placeholder="NOMBRE DIVISI&Oacute;N..." required>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <button type="submit" class="btn btn-primary">Agregar Divisi&oacute;n</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <!-- Actualizar Estado de Empresa -->
                    <div class="col-md-6">
                        <div class="card card-success">
                            <div class="card-header">
                                <h3 class="card-title">Empresas creadas</h3>
                            </div>
                            <form class="form-horizontal" method="POST" action="mod_elementos/actualizar.php">
                                <div class="card-body">
                                    <div class="form-group row">
                                        <label for="selectEmpresas" class="col-sm-2 col-form-label">Empresas:</label>
                                        <div class="col-sm-10">
                                            <select class="form-control" id="selectEmpresas" name="empresa_id" required>
                                                <option value="">-- Seleccione una Empresa --</option>
                                                <?php foreach ($empresas as $empresa): ?>
                                                    <option value="<?php echo $empresa['id']; ?>" data-activo="<?php echo $empresa['activo']; ?>">
                                                        <?php echo htmlspecialchars($empresa['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label for="estado" class="col-sm-2 col-form-label">Estado:</label>
                                        <div class="col-sm-10">
                                            <select class="form-control" name="estado_empresa" id="estado" required>
                                                <option value="1">Activo</option>
                                                <option value="0">Inactivo</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <button type="submit" class="btn btn-primary">Actualizar Estado</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
    
    <!-- Mostrar Mensajes -->
    <?php if (isset($_GET['mensaje'])): ?>
        <?php if ($_GET['mensaje'] === 'empresa_duplicada'): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <strong>Advertencia:</strong> Esta empresa ya existe.
                <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php elseif ($_GET['mensaje'] === 'exito'): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Éxito:</strong> Operación realizada con éxito.
                <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php elseif ($_GET['mensaje'] === 'division_duplicada'): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <strong>Advertencia:</strong> Esta división ya existe para esta empresa.
                <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php elseif ($_GET['mensaje'] === 'error&detalle='): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <strong>Error:</strong> Ocurrió un error al realizar la operación.
                <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Scripts -->
    <script src="../plugins/jquery/jquery.min.js"></script>
    <script src="../plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../plugins/bs-custom-file-input/bs-custom-file-input.min.js"></script>
    <script src="../dist/js/adminlte.min.js"></script>
    <script>
        $(function () {
            bsCustomFileInput.init();

            // Escuchar el cambio en el select de empresas
            $('#selectEmpresas').on('change', function() {
                // Obtener el valor del atributo data-activo de la opción seleccionada
                var estadoActivo = $(this).find('option:selected').data('activo');

                // Establecer el estado en el segundo select
                $('#estado').val(estadoActivo);
            });

            // Disparar el evento change al cargar la página para mostrar el estado de la primera empresa
            $('#selectEmpresas').change();
        });
    </script>
</body>
</html>

