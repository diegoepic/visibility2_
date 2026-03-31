<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

$mensaje = '';
$tipoMensaje = 'success';
$idFormularioSeleccionado = isset($_GET['id_formulario']) ? (int)$_GET['id_formulario'] : 0;

/* =========================================================
   GUARDAR ASIGNACIÓN DE DIVISIONES
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idFormularioSeleccionado = isset($_POST['id_formulario']) ? (int)$_POST['id_formulario'] : 0;
    $divisionesSeleccionadas = isset($_POST['divisiones']) && is_array($_POST['divisiones'])
        ? array_map('intval', $_POST['divisiones'])
        : [];

    $divisionesSeleccionadas = array_values(array_unique(array_filter($divisionesSeleccionadas)));

    try {
        if ($idFormularioSeleccionado <= 0) {
            throw new Exception('Debes seleccionar un formulario.');
        }

        // Validar que el formulario exista y sea tipo 2
        $sqlValidaFormulario = "
            SELECT id, nombre, tipo
            FROM formulario
            WHERE id = ?
              AND tipo = 2
              AND deleted_at IS NULL
            LIMIT 1
        ";
        $stmt = $conn->prepare($sqlValidaFormulario);
        $stmt->bind_param("i", $idFormularioSeleccionado);
        $stmt->execute();
        $resFormulario = $stmt->get_result();

        if ($resFormulario->num_rows === 0) {
            throw new Exception('El formulario seleccionado no existe o no corresponde a tipo 2.');
        }

        $conn->begin_transaction();

        // Eliminar asignaciones actuales
        $sqlDelete = "DELETE FROM formulario_division_habilitada WHERE id_formulario = ?";
        $stmtDelete = $conn->prepare($sqlDelete);
        $stmtDelete->bind_param("i", $idFormularioSeleccionado);
        $stmtDelete->execute();

        // Insertar nuevas asignaciones
        if (!empty($divisionesSeleccionadas)) {
            $sqlInsert = "
                INSERT INTO formulario_division_habilitada (id_formulario, id_division)
                VALUES (?, ?)
            ";
            $stmtInsert = $conn->prepare($sqlInsert);

            foreach ($divisionesSeleccionadas as $idDivision) {
                $stmtInsert->bind_param("ii", $idFormularioSeleccionado, $idDivision);
                $stmtInsert->execute();
            }
        }

        $conn->commit();

        header("Location: ?id_formulario={$idFormularioSeleccionado}&guardado=1");
        exit;

    } catch (Throwable $e) {
        if ($conn->errno) {
            $conn->rollback();
        }
        $mensaje = $e->getMessage();
        $tipoMensaje = 'danger';
    }
}

/* =========================================================
   MENSAJE DE ÉXITO
========================================================= */
if (isset($_GET['guardado']) && $_GET['guardado'] == '1') {
    $mensaje = 'Las divisiones habilitadas se guardaron correctamente.';
    $tipoMensaje = 'success';
}

/* =========================================================
   LISTAR FORMULARIOS TIPO 2
========================================================= */
$formularios = [];
$sqlFormularios = "
    SELECT 
        f.id,
        f.nombre,
        f.estado,
        f.fechaInicio,
        f.fechaTermino,
        f.modalidad,
        f.id_division
    FROM formulario f
    WHERE f.deleted_at IS NULL
      AND f.tipo = 2
      AND f.estado = 1
    ORDER BY f.nombre ASC
";
$resFormularios = $conn->query($sqlFormularios);
while ($row = $resFormularios->fetch_assoc()) {
    $formularios[] = $row;
}

/* =========================================================
   FORMULARIO SELECCIONADO
========================================================= */
$formularioActual = null;
$divisionesMarcadas = [];
$divisionesAsociadasDetalle = [];

if ($idFormularioSeleccionado > 0) {
    foreach ($formularios as $f) {
        if ((int)$f['id'] === $idFormularioSeleccionado) {
            $formularioActual = $f;
            break;
        }
    }

    if ($formularioActual) {
        $sqlAsignadas = "
            SELECT 
                fdh.id_division,
                de.nombre
            FROM formulario_division_habilitada fdh
            INNER JOIN division_empresa de 
                ON de.id = fdh.id_division
            WHERE fdh.id_formulario = ?
            ORDER BY de.nombre ASC
        ";
        $stmt = $conn->prepare($sqlAsignadas);
        $stmt->bind_param("i", $idFormularioSeleccionado);
        $stmt->execute();
        $resAsignadas = $stmt->get_result();

        while ($row = $resAsignadas->fetch_assoc()) {
            $divisionesMarcadas[] = (int)$row['id_division'];
            $divisionesAsociadasDetalle[] = $row;
        }
    }
}

/* =========================================================
   LISTAR DIVISIONES
   CAMBIA de.nombre SI TU CAMPO REAL TIENE OTRO NOMBRE
========================================================= */
$divisiones = [];
$sqlDivisiones = "
    SELECT 
        de.id,
        de.nombre
    FROM division_empresa de
    ORDER BY de.nombre ASC
";
$resDivisiones = $conn->query($sqlDivisiones);
while ($row = $resDivisiones->fetch_assoc()) {
    $divisiones[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Asignación de divisiones a formularios complementarios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        body{
            background: #f4f6f9;
            font-family: "Segoe UI", Tahoma, sans-serif;
            font-size: 0.92rem;
        }

        .page-header{
            background: linear-gradient(135deg, #0f2c59, #174a8b);
            color: #fff;
            border-radius: 18px;
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(15, 44, 89, .18);
        }

        .page-header h2{
            margin: 0;
            font-size: 1.55rem;
            font-weight: 700;
        }

        .page-header p{
            margin: 8px 0 0;
            opacity: .92;
        }

        .panel-card{
            background: #fff;
            border-radius: 18px;
            border: 1px solid #e6ebf2;
            box-shadow: 0 6px 20px rgba(18, 38, 63, .06);
            overflow: hidden;
        }

        .panel-card .panel-title{
            padding: 16px 20px;
            border-bottom: 1px solid #edf1f5;
            font-weight: 700;
            color: #17345f;
            background: #fafbfd;
        }

        .list-formularios{
            max-height: 630px;
            overflow-y: auto;
        }

        .formulario-item{
            display: block;
            padding: 14px 16px;
            border-bottom: 1px solid #f0f2f6;
            text-decoration: none !important;
            color: #243447;
            transition: .18s ease;
        }

        .formulario-item:hover{
            background: #f8fbff;
        }

        .formulario-item.active{
            background: #eaf3ff;
            border-left: 4px solid #1e63c6;
            padding-left: 12px;
        }

        .formulario-item .nombre{
            font-weight: 700;
            color: #163861;
            margin-bottom: 4px;
        }

        .formulario-item .meta{
            font-size: .83rem;
            color: #6b7785;
        }

        .badgex{
            display: inline-block;
            padding: 4px 10px;
            font-size: .75rem;
            border-radius: 999px;
            font-weight: 600;
            margin-right: 6px;
            margin-top: 6px;
        }

        .badgex.estado{
            background: #eef5ff;
            color: #2457a7;
        }

        .badgex.modalidad{
            background: #eef8f0;
            color: #277a42;
        }

        .contenido-card{
            padding: 20px;
        }

        .formulario-info{
            background: #f8fbff;
            border: 1px solid #dce9f9;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .formulario-info h5{
            margin: 0 0 8px;
            color: #17345f;
            font-weight: 700;
        }

        .tools-bar{
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 16px;
        }

        .tools-bar .form-control{
            max-width: 320px;
        }

        .division-grid{
            border: 1px solid #e9edf3;
            border-radius: 14px;
            padding: 14px;
            background: #fcfdff;
            max-height: 420px;
            overflow-y: auto;
        }

        .division-item{
            border: 1px solid #e8edf5;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 10px;
            background: #fff;
            transition: .18s ease;
        }

        .division-item:hover{
            border-color: #c8d9f1;
            background: #f9fbff;
        }

        .division-item label{
            margin: 0;
            width: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .division-item .nombre-division{
            font-weight: 600;
            color: #2a3e59;
        }

        .empty-box{
            border: 2px dashed #d5deea;
            border-radius: 18px;
            padding: 42px 20px;
            text-align: center;
            color: #6c7a89;
            background: #fbfcfe;
        }

        .contador{
            font-weight: 700;
            color: #163861;
        }

        .btn-main{
            background: #174a8b;
            border-color: #174a8b;
            color: #fff;
        }

        .btn-main:hover{
            background: #123d73;
            border-color: #123d73;
            color: #fff;
        }

        @media (max-width: 991px){
            .list-formularios{
                max-height: 340px;
            }

            .division-grid{
                max-height: 360px;
            }
        }
.tabla-asociadas-wrap{
    margin-top: 22px;
    border: 1px solid #e9edf3;
    border-radius: 14px;
    overflow: hidden;
    background: #fff;
}

.tabla-asociadas-header{
    padding: 14px 16px;
    background: #f7faff;
    border-bottom: 1px solid #e9edf3;
    font-weight: 700;
    color: #17345f;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.tabla-asociadas-header .mini-count{
    font-size: .82rem;
    color: #5f6f82;
    font-weight: 600;
}

.tabla-asociadas{
    margin: 0;
}

.tabla-asociadas th{
    background: #fbfcfe;
    color: #17345f;
    font-size: .84rem;
    border-top: none !important;
}

.tabla-asociadas td{
    vertical-align: middle !important;
    font-size: .88rem;
}

.badge-asociada{
    background: #eaf3ff;
    color: #1b5fc1;
    border-radius: 999px;
    padding: 5px 10px;
    font-size: .75rem;
    font-weight: 700;
}        
    </style>
</head>
<body>

<div class="container-fluid mt-4 mb-4">
    <div class="page-header">
        <h2><i class="fas fa-layer-group mr-2"></i>Asignación de divisiones a formularios complementarios</h2>
        <p>Administra qué divisiones pueden visualizar formularios de tipo 2.</p>
    </div>

    <?php if (!empty($mensaje)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($tipoMensaje); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($mensaje); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- COLUMNA IZQUIERDA -->
        <div class="col-lg-4 mb-4">
            <div class="panel-card">
                <div class="panel-title">
                    <i class="fas fa-file-alt mr-2"></i>Formularios tipo 2
                </div>

                <div class="list-formularios">
                    <?php if (empty($formularios)): ?>
                        <div class="p-4 text-muted">No existen formularios tipo 2 registrados.</div>
                    <?php else: ?>
                        <?php foreach ($formularios as $f): ?>
                            <?php $activo = ((int)$f['id'] === (int)$idFormularioSeleccionado); ?>
                            <a href="?id_formulario=<?php echo (int)$f['id']; ?>" class="formulario-item <?php echo $activo ? 'active' : ''; ?>">
                                <div class="nombre"><?php echo htmlspecialchars($f['nombre']); ?></div>
                                <div class="meta">
                                    ID: <?php echo (int)$f['id']; ?>
                                    <?php if (!empty($f['fechaInicio'])): ?>
                                        | Inicio: <?php echo htmlspecialchars(date('d-m-Y', strtotime($f['fechaInicio']))); ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if (!empty($f['estado'])): ?>
                                        <span class="badgex estado"><?php echo htmlspecialchars($f['estado']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($f['modalidad'])): ?>
                                        <span class="badgex modalidad"><?php echo htmlspecialchars($f['modalidad']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- COLUMNA DERECHA -->
        <div class="col-lg-8 mb-4">
            <div class="panel-card">
                <div class="panel-title">
                    <i class="fas fa-sitemap mr-2"></i>Divisiones habilitadas
                </div>

                <div class="contenido-card">
                    <?php if (!$formularioActual): ?>
                        <div class="empty-box">
                            <i class="fas fa-hand-point-left fa-2x mb-3"></i>
                            <div><strong>Selecciona un formulario tipo 2</strong></div>
                            <div class="mt-2">Luego podrás definir qué divisiones lo visualizarán.</div>
                        </div>
                    <?php else: ?>
                        <div class="formulario-info">
                            <h5><?php echo htmlspecialchars($formularioActual['nombre']); ?></h5>
                            <div class="text-muted">
                                <strong>ID formulario:</strong> <?php echo (int)$formularioActual['id']; ?>
                                <?php if (!empty($formularioActual['estado'])): ?>
                                    | <strong>Estado:</strong> <?php echo htmlspecialchars($formularioActual['estado']); ?>
                                <?php endif; ?>
                                <?php if (!empty($formularioActual['modalidad'])): ?>
                                    | <strong>Modalidad:</strong> <?php echo htmlspecialchars($formularioActual['modalidad']); ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="id_formulario" value="<?php echo (int)$formularioActual['id']; ?>">

                            <div class="tools-bar">
                                <input type="text" id="buscadorDivisiones" class="form-control" placeholder="Buscar división...">

                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnSeleccionarTodas">
                                    <i class="fas fa-check-double mr-1"></i>Seleccionar todas
                                </button>

                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnLimpiar">
                                    <i class="fas fa-eraser mr-1"></i>Limpiar
                                </button>

                                <span class="ml-auto contador">
                                    Seleccionadas: <span id="contadorSeleccionadas">0</span>
                                </span>
                            </div>

                            <div class="division-grid" id="divisionGrid">
                                <?php if (empty($divisiones)): ?>
                                    <div class="text-muted">No hay divisiones disponibles.</div>
                                <?php else: ?>
                                    <?php foreach ($divisiones as $d): ?>
                                        <?php $checked = in_array((int)$d['id'], $divisionesMarcadas, true); ?>
                                        <div class="division-item division-row" data-name="<?php echo htmlspecialchars(mb_strtolower($d['nombre'])); ?>">
                                            <label>
                                                <span class="nombre-division">
                                                    <?php echo htmlspecialchars($d['nombre']); ?>
                                                </span>
                                                <span>
                                                    <input
                                                        type="checkbox"
                                                        class="chk-division"
                                                        name="divisiones[]"
                                                        value="<?php echo (int)$d['id']; ?>"
                                                        <?php echo $checked ? 'checked' : ''; ?>
                                                    >
                                                </span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="mt-3 d-flex justify-content-end">
                                <button type="submit" class="btn btn-main px-4">
                                    <i class="fas fa-save mr-2"></i>Guardar asignación
                                </button>
                            </div>
                            <div class="tabla-asociadas-wrap">
                                <div class="tabla-asociadas-header">
                                    <span><i class="fas fa-list-check mr-2"></i>Divisiones actualmente habilitadas</span>
                                    <span class="mini-count">
                                        Total: <?php echo count($divisionesAsociadasDetalle); ?>
                                    </span>
                                </div>
                            
                                <?php if (empty($divisionesAsociadasDetalle)): ?>
                                    <div class="p-4 text-muted">
                                        Este formulario no tiene divisiones habilitadas actualmente.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover tabla-asociadas mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width: 90px;">ID</th>
                                                    <th>División</th>
                                                    <th style="width: 140px;">Estado</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($divisionesAsociadasDetalle as $divisionAsociada): ?>
                                                    <tr>
                                                        <td><?php echo (int)$divisionAsociada['id_division']; ?></td>
                                                        <td><?php echo htmlspecialchars($divisionAsociada['nombre']); ?></td>
                                                        <td>
                                                            <span class="badge-asociada">Habilitada</span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>                            
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>



<script>
(function () {
    const buscador = document.getElementById('buscadorDivisiones');
    const filas = document.querySelectorAll('.division-row');
    const checkboxes = document.querySelectorAll('.chk-division');
    const contador = document.getElementById('contadorSeleccionadas');
    const btnTodas = document.getElementById('btnSeleccionarTodas');
    const btnLimpiar = document.getElementById('btnLimpiar');

    function actualizarContador() {
        if (!contador) return;
        let total = 0;
        checkboxes.forEach(chk => {
            if (chk.checked) total++;
        });
        contador.textContent = total;
    }

    if (buscador) {
        buscador.addEventListener('input', function () {
            const texto = this.value.trim().toLowerCase();

            filas.forEach(fila => {
                const nombre = fila.getAttribute('data-name') || '';
                fila.style.display = nombre.includes(texto) ? '' : 'none';
            });
        });
    }

    if (btnTodas) {
        btnTodas.addEventListener('click', function () {
            checkboxes.forEach(chk => chk.checked = true);
            actualizarContador();
        });
    }

    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', function () {
            checkboxes.forEach(chk => chk.checked = false);
            actualizarContador();
        });
    }

    checkboxes.forEach(chk => {
        chk.addEventListener('change', actualizarContador);
    });

    actualizarContador();
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>