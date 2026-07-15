<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

/* =========================================================
   Helpers
========================================================= */
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function obtenerTextoEstado($estado): string {
    switch ((string)$estado) {
        case '1': return 'En curso';
        case '2': return 'Proceso';
        case '3': return 'Finalizado';
        case '4': return 'Cancelado';
        default:  return 'Desconocido';
    }
}

function obtenerClaseEstado($estado): string {
    switch ((string)$estado) {
        case '1': return 'badge-status badge-status-success';
        case '2': return 'badge-status badge-status-warning';
        case '3': return 'badge-status badge-status-primary';
        case '4': return 'badge-status badge-status-danger';
        default:  return 'badge-status badge-status-secondary';
    }
}

function obtenerTextoTipo($tipo): string {
    switch ((string)$tipo) {
        case '1': return 'Actividad Programada';
        case '2': return 'Actividad IW';
        case '3': return 'Actividad IPT';
        default:  return 'Desconocido';
    }
}

function obtenerClaseTipo($tipo): string {
    switch ((string)$tipo) {
        case '1': return 'badge-soft badge-soft-success';
        case '2': return 'badge-soft badge-soft-info';
        case '3': return 'badge-soft badge-soft-purple';
        default:  return 'badge-soft badge-soft-secondary';
    }
}

function formatearFecha($fecha): string {
    return !empty($fecha) ? date('d/m/Y H:i', strtotime($fecha)) : '-';
}

function cacheRemember(string $namespace, array $payload, int $ttl, callable $resolver) {
    $dir = __DIR__ . '/cache_mod_formulario';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $hash = md5(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $file = $dir . '/' . $namespace . '_' . $hash . '.cache';

    if (is_file($file) && (time() - filemtime($file) < $ttl)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = @unserialize($raw);
            if ($data !== false || $raw === serialize(false)) {
                return $data;
            }
        }
    }

    $data = $resolver();
    @file_put_contents($file, serialize($data), LOCK_EX);

    return $data;
}

function normalizarCatalogoFormulario(string $valor, int $maxLength = 150): string {
    $valor = trim($valor);
    $map = [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'á' => 'A', 'à' => 'A', 'â' => 'A', 'ä' => 'A', 'ã' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'é' => 'E', 'è' => 'E', 'ê' => 'E', 'ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'í' => 'I', 'ì' => 'I', 'î' => 'I', 'ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O', 'ó' => 'O', 'ò' => 'O', 'ô' => 'O', 'ö' => 'O', 'õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ú' => 'U', 'ù' => 'U', 'û' => 'U', 'ü' => 'U',
        'Ñ' => 'N', 'ñ' => 'N', 'Ç' => 'C', 'ç' => 'C'
    ];
    $valor = strtr($valor, $map);
    $valor = strtoupper($valor);
    $valor = preg_replace('/[^A-Z0-9 ]+/', ' ', $valor);
    $valor = preg_replace('/\s+/', ' ', $valor);
    return substr(trim($valor), 0, $maxLength);
}

function limpiarCacheModuloFormulario(): void {
    $dir = __DIR__ . '/cache_mod_formulario';
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*.cache') ?: [] as $file) {
        @unlink($file);
    }
}

function crearCatalogoFormulario(string $tabla, string $nombre, string $descripcion = ''): void {
    global $conn;

    if (!in_array($tabla, ['categoria_formulario', 'trade'], true)) {
        throw new Exception('Catálogo no permitido.');
    }

    $nombre = normalizarCatalogoFormulario($nombre, 150);
    $descripcion = normalizarCatalogoFormulario($descripcion, 255);

    if ($nombre === '') {
        throw new Exception('El nombre es obligatorio.');
    }

    $existentes = ejecutarConsulta(
        "SELECT id FROM {$tabla} WHERE nombre = ? AND deleted_at IS NULL LIMIT 1",
        [$nombre],
        's'
    );

    if (!empty($existentes)) {
        throw new Exception('Ya existe un registro con ese nombre.');
    }

    $sql = "INSERT INTO {$tabla} (nombre, descripcion, estado, created_at, updated_at)
            VALUES (?, ?, 1, NOW(), NOW())";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception('Error preparando guardado: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'ss', $nombre, $descripcion);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception('Error al guardar: ' . $error);
    }

    mysqli_stmt_close($stmt);
    limpiarCacheModuloFormulario();
}

function actualizarCatalogoFormulario(string $tabla, int $id, string $nombre, string $descripcion = ''): void {
    global $conn;

    if (!in_array($tabla, ['categoria_formulario', 'trade'], true)) {
        throw new Exception('Catálogo no permitido.');
    }

    $id = intval($id);
    $nombre = normalizarCatalogoFormulario($nombre, 150);
    $descripcion = normalizarCatalogoFormulario($descripcion, 255);

    if ($id <= 0) {
        throw new Exception('Registro no válido.');
    }

    if ($nombre === '') {
        throw new Exception('El nombre es obligatorio.');
    }

    $existentes = ejecutarConsulta(
        "SELECT id FROM {$tabla} WHERE nombre = ? AND id <> ? AND deleted_at IS NULL LIMIT 1",
        [$nombre, $id],
        'si'
    );

    if (!empty($existentes)) {
        throw new Exception('Ya existe otro registro con ese nombre.');
    }

    $sql = "UPDATE {$tabla}
            SET nombre = ?, descripcion = ?, estado = 1, updated_at = NOW()
            WHERE id = ? AND deleted_at IS NULL";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception('Error preparando actualización: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'ssi', $nombre, $descripcion, $id);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception('Error al actualizar: ' . $error);
    }

    mysqli_stmt_close($stmt);
    limpiarCacheModuloFormulario();
}

function desactivarCatalogoFormulario(string $tabla, int $id): void {
    global $conn;

    if (!in_array($tabla, ['categoria_formulario', 'trade'], true)) {
        throw new Exception('Catálogo no permitido.');
    }

    $id = intval($id);
    if ($id <= 0) {
        throw new Exception('Registro no válido.');
    }

    $sql = "UPDATE {$tabla}
            SET estado = 0, updated_at = NOW()
            WHERE id = ? AND deleted_at IS NULL";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception('Error preparando desactivación: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'i', $id);
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception('Error al desactivar: ' . $error);
    }

    mysqli_stmt_close($stmt);
    limpiarCacheModuloFormulario();
}

function obtenerCatalogoActivoFormulario(string $tabla): array {
    if (!in_array($tabla, ['categoria_formulario', 'trade'], true)) {
        throw new Exception('Catálogo no permitido.');
    }

    return cacheRemember(
        'form_catalogo_' . $tabla . '_v1',
        [],
        1800,
        function () use ($tabla) {
            return ejecutarConsulta(
                "SELECT id, nombre, descripcion
                 FROM {$tabla}
                 WHERE estado = 1 AND deleted_at IS NULL
                 ORDER BY nombre ASC",
                [],
                ''
            );
        }
    );
}

/* =========================================================
   Filtros
========================================================= */
$empresa_seleccionada      = isset($_GET['empresa']) ? intval($_GET['empresa']) : 0;
$division_seleccionada     = isset($_GET['division']) ? intval($_GET['division']) : 0;
$estado_campana            = isset($_GET['estado_campana']) ? trim($_GET['estado_campana']) : '1';
$tipo_campana              = isset($_GET['tipo_campana']) ? intval($_GET['tipo_campana']) : 0;
$subdivision_seleccionada  = isset($_GET['subdivision']) ? intval($_GET['subdivision']) : 0;
$categoria_seleccionada    = isset($_GET['categoria_formulario']) ? intval($_GET['categoria_formulario']) : 0;
$trade_seleccionado        = isset($_GET['trade']) ? intval($_GET['trade']) : 0;

$empresa_id = intval($_SESSION['empresa_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['catalog_action'])) {
    try {
        $action = trim((string)$_POST['catalog_action']);
        $catalog_id = intval($_POST['catalog_id'] ?? 0);
        $nombre = (string)($_POST['catalog_nombre'] ?? '');
        $descripcion = (string)($_POST['catalog_descripcion'] ?? '');

        if ($action === 'actualizar_categoria_formulario') {
            actualizarCatalogoFormulario('categoria_formulario', $catalog_id, $nombre, $descripcion);
            $_SESSION['success_formulario'] = 'Categoría actualizada correctamente.';
        } elseif ($action === 'desactivar_categoria_formulario') {
            desactivarCatalogoFormulario('categoria_formulario', $catalog_id);
            $_SESSION['success_formulario'] = 'Categoría desactivada correctamente.';
        } elseif ($action === 'actualizar_trade') {
            actualizarCatalogoFormulario('trade', $catalog_id, $nombre, $descripcion);
            $_SESSION['success_formulario'] = 'Trade actualizado correctamente.';
        } elseif ($action === 'desactivar_trade') {
            desactivarCatalogoFormulario('trade', $catalog_id);
            $_SESSION['success_formulario'] = 'Trade desactivado correctamente.';
        } elseif ($action === 'crear_categoria_formulario') {
            crearCatalogoFormulario('categoria_formulario', $nombre, $descripcion);
            $_SESSION['success_formulario'] = 'Categoría creada correctamente.';
        } elseif ($action === 'crear_trade') {
            crearCatalogoFormulario('trade', $nombre, $descripcion);
            $_SESSION['success_formulario'] = 'Trade creado correctamente.';
        } else {
            throw new Exception('Acción no válida.');
        }
    } catch (Exception $e) {
        $_SESSION['error_formulario'] = $e->getMessage();
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: mod_formulario.php' . ($qs !== '' ? '?' . $qs : ''));
    exit();
}

/* =========================================================
   Empresa usuario
========================================================= */
try {
    $nombre_empresa = cacheRemember(
        'form_nombre_empresa_v1',
        ['empresa_id' => $empresa_id],
        1800,
        function () use ($empresa_id) {
            return obtenerNombreEmpresa($empresa_id);
        }
    );
} catch (Exception $e) {
    $_SESSION['error_formulario'] = $e->getMessage();
    header("Location: mod_formulario.php");
    exit();
}

$es_mentecreativa = (strtolower(trim($nombre_empresa)) === 'mentecreativa');

/* =========================================================
   Empresas activas si es mentecreativa
========================================================= */
$empresas_all = [];
if ($es_mentecreativa) {
    try {
        $empresas_all = cacheRemember(
            'form_empresas_activas_v1',
            [],
            1800,
            function () {
                return obtenerEmpresasActivas();
            }
        );
    } catch (Exception $e) {
        $_SESSION['error_formulario'] = "Error al obtener las empresas: " . $e->getMessage();
    }

    if ($empresa_seleccionada > 0) {
        $empresas_ids = array_column($empresas_all, 'id');
        if (!in_array($empresa_seleccionada, $empresas_ids)) {
            $_SESSION['error_formulario'] = "Empresa no válida.";
            header("Location: mod_formulario.php");
            exit();
        }
    }
}

/* =========================================================
   Divisiones
========================================================= */
$empresa_objetivo_divisiones = 0;

if ($es_mentecreativa && $empresa_seleccionada > 0) {
    $empresa_objetivo_divisiones = $empresa_seleccionada;
} elseif (!$es_mentecreativa) {
    $empresa_objetivo_divisiones = $empresa_id;
}

$divisiones = [];

try {
    if ($empresa_objetivo_divisiones > 0) {
        $divisiones = cacheRemember(
            'form_divisiones_empresa_v1',
            ['empresa_id' => $empresa_objetivo_divisiones],
            1800,
            function () use ($empresa_objetivo_divisiones) {
                return obtenerDivisionesPorEmpresa($empresa_objetivo_divisiones);
            }
        );
    }
} catch (Exception $e) {
    $divisiones = [];
    $_SESSION['error_formulario'] = "Error al obtener divisiones: " . $e->getMessage();
}

$divisiones_modal = $divisiones;

try {
    $categorias_formulario = obtenerCatalogoActivoFormulario('categoria_formulario');
} catch (Exception $e) {
    $categorias_formulario = [];
    $_SESSION['error_formulario'] = "Error al obtener categorías: " . $e->getMessage();
}

try {
    $trades = obtenerCatalogoActivoFormulario('trade');
} catch (Exception $e) {
    $trades = [];
    $_SESSION['error_formulario'] = "Error al obtener trades: " . $e->getMessage();
}

/* =========================================================
   Consulta formularios
========================================================= */
$ejecutarBusqueda = isset($_GET['buscar']) && $_GET['buscar'] === '1';
$formularios = [];

if ($ejecutarBusqueda) {
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
            f.id_categoria_formulario,
            f.id_trade,
            d.nombre  AS division_nombre,
            sd.nombre AS subdivision_nombre,
            cf.nombre AS categoria_nombre,
            tr.nombre AS trade_nombre
        FROM formulario AS f
        LEFT JOIN division_empresa AS d ON d.id = f.id_division
        LEFT JOIN subdivision AS sd ON sd.id = f.id_subdivision
        LEFT JOIN categoria_formulario AS cf ON cf.id = f.id_categoria_formulario
        LEFT JOIN trade AS tr ON tr.id = f.id_trade
    ";

    $conditions = [];

    if ($es_mentecreativa) {
        if ($empresa_seleccionada > 0) {
            $conditions[] = "f.id_empresa = ?";
            $params[] = $empresa_seleccionada;
            $param_types .= "i";
        } else {
            // Si Mente Creativa no ha elegido empresa, no devuelve nada
            $conditions[] = "1 = 0";
        }
    } else {
        $conditions[] = "f.id_empresa = ?";
        $params[] = $empresa_id;
        $param_types .= "i";
    }

    if ($division_seleccionada > 0) {
        $conditions[] = "f.id_division = ?";
        $params[] = $division_seleccionada;
        $param_types .= "i";
    }

    if ($subdivision_seleccionada > 0) {
        $conditions[] = "f.id_subdivision = ?";
        $params[] = $subdivision_seleccionada;
        $param_types .= "i";
    }

    if ($estado_campana !== '0' && $estado_campana !== '') {
        $conditions[] = "f.estado = ?";
        $params[] = $estado_campana;
        $param_types .= "s";
    }

    if ($tipo_campana > 0) {
        $conditions[] = "f.tipo = ?";
        $params[] = $tipo_campana;
        $param_types .= "i";
    }

    if ($categoria_seleccionada > 0) {
        $conditions[] = "f.id_categoria_formulario = ?";
        $params[] = $categoria_seleccionada;
        $param_types .= "i";
    }

    if ($trade_seleccionado > 0) {
        $conditions[] = "f.id_trade = ?";
        $params[] = $trade_seleccionado;
        $param_types .= "i";
    }

    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }

    $query .= " ORDER BY f.fechaInicio DESC, f.id DESC";

    $formularios = ejecutarConsulta($query, $params, $param_types);

    $formularios = cacheRemember(
        'form_listado_formularios_v1',
        [
            'empresa_seleccionada'     => $empresa_seleccionada,
            'division_seleccionada'    => $division_seleccionada,
            'estado_campana'           => $estado_campana,
            'tipo_campana'             => $tipo_campana,
            'subdivision_seleccionada' => $subdivision_seleccionada,
            'categoria_seleccionada'   => $categoria_seleccionada,
            'trade_seleccionado'       => $trade_seleccionado,
            'empresa_id'               => $empresa_id,
            'es_mentecreativa'         => $es_mentecreativa ? 1 : 0,
            'buscar'                   => 1
        ],
        300,
        function () use ($query, $params, $param_types) {
            return ejecutarConsulta($query, $params, $param_types);
        }
    );
}

/* =========================================================
   Tipos de pregunta
========================================================= */
try {
    $tipos_pregunta_raw = cacheRemember(
        'form_question_types_v1',
        [],
        3600,
        function () {
            return ejecutarConsulta("SELECT id, name FROM question_type ORDER BY id ASC", [], '');
        }
    );

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
        $tipos_pregunta[] = [
            'id'   => $tp['id'],
            'name' => $tipoTraduc[$tp['name']] ?? $tp['name']
        ];
    }
} catch (Exception $e) {
    $tipos_pregunta = [];
    $_SESSION['error_formulario'] = "Error al obtener tipos de pregunta: " . $e->getMessage();
}

try {
    $materiales = cacheRemember(
        'form_materiales_v1',
        [],
        3600,
        function () {
            return ejecutarConsulta("SELECT id, nombre FROM material ORDER BY nombre ASC", [], '');
        }
    );
} catch (Exception $e) {
    $materiales = [];
    $_SESSION['error_formulario'] = "Error al obtener materiales: " . $e->getMessage();
}

/* =========================================================
   Divisiones modal crear
========================================================= */
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
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap / DataTables / FontAwesome -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" type="text/css" href="../css/mod_formulario.css">      

    <script>
        function toggleCSVUpload(tipo) {
            var csvContainer         = document.getElementById('csvUploadContainer');
            var empresaDivContainer  = document.getElementById('empresaDivisionContainer');
            var modalidadContainer   = document.getElementById('modalidadContainer');
            var csvInput             = document.getElementById('csvFile');

            var iwReqLocalContainer  = document.getElementById('iwReqLocalContainer');
            var iwReqLocalCheck      = document.getElementById('iw_requiere_local');

            const show = el => { if (el) el.style.display = 'block'; };
            const hide = el => { if (el) el.style.display = 'none'; };

            const subCont = document.getElementById('subdivisionContainer');
            const subSel  = document.getElementById('id_subdivision');
            const divSel  = document.getElementById('id_division');
            const empSel  = document.getElementById('empresa_form');
            const modSel  = document.getElementById('modalidad');

            if (csvInput) csvInput.required = false;

            if (tipo === '1' || tipo === '3') {
                show(csvContainer);
                if (csvInput) csvInput.required = true;

                show(empresaDivContainer);
                if (divSel)  { divSel.disabled = false; divSel.required = true; }
                if (empSel)  { empSel.disabled = false; empSel.required = true; }

                show(modalidadContainer);
                if (modSel)  { modSel.disabled = false; modSel.required = true; }

                if (subCont && subSel) {
                    subSel.disabled = false;
                }

                hide(iwReqLocalContainer);
                if (iwReqLocalCheck) iwReqLocalCheck.checked = false;

            } else if (tipo === '2') {
                hide(csvContainer);

                show(empresaDivContainer);
                if (divSel)  { divSel.disabled = false; divSel.required = false; }
                if (empSel)  { empSel.disabled = false; empSel.required = true; }

                hide(modalidadContainer);
                if (modSel)  { modSel.required = false; modSel.disabled = true; modSel.value = ''; }

                if (subCont && subSel) {
                    hide(subCont);
                    subSel.required = false;
                    subSel.disabled = true;
                    subSel.value    = '0';
                }

                show(iwReqLocalContainer);

            } else {
                hide(csvContainer);
                hide(empresaDivContainer);
                hide(modalidadContainer);

                if (divSel) { divSel.required = false; divSel.disabled = true; }
                if (empSel) { empSel.required = false; empSel.disabled = false; }

                if (subCont && subSel) {
                    hide(subCont);
                    subSel.required = false;
                    subSel.disabled = true;
                    subSel.value = '0';
                }

                hide(iwReqLocalContainer);
                if (iwReqLocalCheck) iwReqLocalCheck.checked = false;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            var tipoSelect = document.getElementById('tipo');
            if (tipoSelect) {
                toggleCSVUpload(tipoSelect.value);
            }

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
    <div class="spinner-border text-success" style="width:3rem;height:3rem;" role="status">
        <span class="sr-only">Cargando…</span>
    </div>
</div>

<div class="page-shell">
    <div class="module-card">

        <div class="page-header">
            <div>
                <h1 class="page-title">Gestión de Formularios</h1>
                <p class="page-subtitle">Administra campañas, filtros, tipos de actividad y accesos rápidos del módulo.</p>
            </div>

            <div class="count-pill">
                <span class="count"><?php echo count($formularios); ?></span>
                <span class="label">formularios encontrados</span>
            </div>
        </div>

        <?php if (isset($_SESSION['success_formulario'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php
                    echo e($_SESSION['success_formulario']);
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
                    echo e($_SESSION['error_formulario']);
                    unset($_SESSION['error_formulario']);
                ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($es_mentecreativa): ?>
            <div class="section-card">
                <div class="section-title">
                    <i class="fas fa-filter"></i>
                    <span>Filtros de búsqueda</span>
                </div>

                <form method="get" id="filterForm">
                    <div class="form-row filters-row">
                        <div class="form-group col-md-3">
                            <label for="empresa_filter">Empresa</label>
                            <select id="empresa_filter" name="empresa" class="form-control" onchange="actualizarDivisionesFiltro()">
                                <option value="0">-- Todas las Empresas --</option>
                                <?php foreach ($empresas_all as $e_filtro): ?>
                                    <option value="<?php echo e($e_filtro['id']); ?>" <?php echo ($empresa_seleccionada === intval($e_filtro['id'])) ? 'selected' : ''; ?>>
                                        <?php echo e($e_filtro['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="division_filter">División</label>
                            <select id="division_filter" name="division" class="form-control" onchange="this.form.submit()">
                                <option value="0">-- Todas las Divisiones --</option>
                                <?php foreach ($divisiones as $d_filtro): ?>
                                    <option value="<?php echo e($d_filtro['id']); ?>" <?php echo ($division_seleccionada === intval($d_filtro['id'])) ? 'selected' : ''; ?>>
                                        <?php echo e($d_filtro['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-md-3" id="subdivision_filter_container" style="display:none;">
                            <label for="subdivision_filter">Subdivisión</label>
                            <select id="subdivision_filter" name="subdivision" class="form-control">
                                <option value="0">-- Todas las Subdivisiones --</option>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="estado_filter">Estado</label>
                            <select id="estado_filter" name="estado_campana" class="form-control">
                                <option value="0" <?php echo ($estado_campana === '0') ? 'selected' : ''; ?>>-- Todos --</option>
                                <option value="1" <?php echo ($estado_campana === '1') ? 'selected' : ''; ?>>En curso</option>
                                <option value="3" <?php echo ($estado_campana === '3') ? 'selected' : ''; ?>>Finalizado</option>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="tipo_filter">Tipo</label>
                            <select id="tipo_filter" name="tipo_campana" class="form-control">
                                <option value="0" <?php echo ($tipo_campana === 0) ? 'selected' : ''; ?>>-- Todos --</option>
                                <option value="1" <?php echo ($tipo_campana === 1) ? 'selected' : ''; ?>>Actividad Programada</option>
                                <option value="2" <?php echo ($tipo_campana === 2) ? 'selected' : ''; ?>>Actividad IW</option>
                                <option value="3" <?php echo ($tipo_campana === 3) ? 'selected' : ''; ?>>Actividad IPT</option>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="categoria_formulario_filter">Categoría</label>
                            <select id="categoria_formulario_filter" name="categoria_formulario" class="form-control">
                                <option value="0" <?php echo ($categoria_seleccionada === 0) ? 'selected' : ''; ?>>-- Todas --</option>
                                <?php foreach ($categorias_formulario as $categoria): ?>
                                    <option value="<?php echo e($categoria['id']); ?>" <?php echo ($categoria_seleccionada === intval($categoria['id'])) ? 'selected' : ''; ?>>
                                        <?php echo e($categoria['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-md-3">
                            <label for="trade_filter">Trade</label>
                            <select id="trade_filter" name="trade" class="form-control">
                                <option value="0" <?php echo ($trade_seleccionado === 0) ? 'selected' : ''; ?>>-- Todos --</option>
                                <?php foreach ($trades as $trade): ?>
                                    <option value="<?php echo e($trade['id']); ?>" <?php echo ($trade_seleccionado === intval($trade['id'])) ? 'selected' : ''; ?>>
                                        <?php echo e($trade['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-md-3 d-flex align-items-end">
                            <button type="submit" name="buscar" value="1" class="btn btn-dark btn-modern btn-block">
                                <i class="fas fa-search mr-1"></i> Filtrar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="toolbar-actions">
            <a href="mod_formulario/gestionar_sets.php" class="btn btn-soft-info btn-modern">
                <i class="fas fa-layer-group mr-1"></i> Gestionar Sets de Preguntas
            </a>

            <a href="mod_formulario/gestionar_materiales.php" class="btn btn-soft-secondary btn-modern">
                <i class="fas fa-boxes-stacked mr-1"></i> Gestionar Materiales
            </a>
            
            <a href="mod_formulario_habilitar.php" class="btn btn-soft-secondary btn-modern">
                <i class="fas fa-car mr-1"></i> Gestionar complementarios
            </a>            

            <button type="button" class="btn btn-soft-secondary btn-modern" data-toggle="modal" data-target="#gestionarCategoriasModal">
                <i class="fas fa-tags mr-1"></i> Gestionar Categorías
            </button>

            <button type="button" class="btn btn-soft-secondary btn-modern" data-toggle="modal" data-target="#gestionarTradeModal">
                <i class="fas fa-store mr-1"></i> Gestionar Trade
            </button>

            <button type="button" class="btn btn-primary btn-modern" data-toggle="modal" data-target="#crearFormularioModal">
                <i class="fas fa-plus-circle mr-1"></i> Crear Nuevo Formulario
            </button>
        </div>

        <!-- Overlay de procesamiento -->
        <div id="formSubmitOverlay" class="form-submit-overlay">
            <div class="form-submit-box">
                <div class="spinner-border text-success mb-3" role="status" style="width:3rem;height:3rem;">
                    <span class="sr-only">Procesando...</span>
                </div>
                <h5 class="mb-2">Procesando formulario</h5>
                <p class="mb-3 text-muted">Subiendo archivo y registrando información, por favor espera...</p>
        
                <div class="progress" style="height: 10px; border-radius: 999px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                         role="progressbar"
                         style="width: 100%">
                    </div>
                </div>
        
                <small class="d-block mt-3 text-muted">No cierres esta ventana ni recargues la página.</small>
            </div>
        </div>
        
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
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="nombre">Nombre del Formulario</label>
                                    <input type="text" id="nombre" name="nombre" class="form-control" required>
                                </div>
        
                                <div class="form-group col-md-6">
                                    <label for="tipo">Tipo de Actividad</label>
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
                                    <label for="fechaInicio">Fecha de Inicio</label>
                                    <input type="datetime-local" id="fechaInicio" name="fechaInicio" class="form-control">
                                </div>
        
                                <div class="form-group col-md-6">
                                    <label for="fechaTermino">Fecha de Término</label>
                                    <input type="datetime-local" id="fechaTermino" name="fechaTermino" class="form-control">
                                </div>
                            </div>
        
                            <div class="form-group">
                                <label for="estado">Estado</label>
                                <select id="estado" name="estado" class="form-control" required>
                                    <option value="">Seleccione una opción</option>
                                    <option value="1">En curso</option>
                                    <option value="2">Proceso</option>
                                    <option value="3">Finalizado</option>
                                    <option value="4">Cancelado</option>
                                </select>
                            </div>
        
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="id_categoria_formulario">Categoría</label>
                                    <select id="id_categoria_formulario" name="id_categoria_formulario" class="form-control">
                                        <option value="0">-- Sin categoría --</option>
                                        <?php foreach ($categorias_formulario as $categoria): ?>
                                            <option value="<?php echo e($categoria['id']); ?>"><?php echo e($categoria['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group col-md-6">
                                    <label for="id_trade">Trade</label>
                                    <select id="id_trade" name="id_trade" class="form-control">
                                        <option value="0">-- Sin trade --</option>
                                        <?php foreach ($trades as $trade): ?>
                                            <option value="<?php echo e($trade['id']); ?>"><?php echo e($trade['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group" id="csvUploadContainer" style="display:none;">
                                <label for="csvFile">Subir Archivo CSV</label>
                                <input type="file" id="csvFile" name="csvFile" class="form-control-file" accept=".csv">
        
                                <div class="mt-2 p-3 border rounded bg-light">
                                    <small class="d-block mb-2 text-muted">
                                        El archivo debe contener estas columnas:
                                        <strong>codigo</strong>,
                                        <strong>usuario</strong>,
                                        <strong>material</strong>,
                                        <strong>categoria</strong>,
                                        <strong>marca</strong>,
                                        <strong>valor_propuesto</strong>,
                                        <strong>fechapropuesta</strong>.
                                    </small>
        
                                    <small class="d-block mb-2 text-muted">
                                        Formatos válidos para fecha:
                                        <strong>31-03-2026</strong>,
                                        <strong>31/03/2026</strong>,
                                        <strong>2026-03-31</strong>
                                        o
                                        <strong>2026/03/31</strong>.
                                    </small>
        
                                    <a href="mod_formulario/templates/template_formulario.csv"
                                       class="btn btn-sm btn-outline-success"
                                       download>
                                        <i class="fas fa-download mr-1"></i> Descargar plantilla CSV
                                    </a>
                                </div>
                            </div>
        
                            <div id="empresaDivisionContainer">
                                <?php if ($es_mentecreativa): ?>
                                    <div class="form-group">
                                        <label for="empresa_form">Empresa</label>
                                        <select id="empresa_form" name="empresa_form" class="form-control" required onchange="actualizarDivisionesCrear()">
                                            <option value="">-- Seleccione una Empresa --</option>
                                            <?php foreach ($empresas_all as $empresa_crear): ?>
                                                <option value="<?php echo e($empresa_crear['id']); ?>">
                                                    <?php echo e($empresa_crear['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php else: ?>
                                    <div class="form-group">
                                        <label for="empresa_display_modal">Empresa</label>
                                        <input type="text" id="empresa_display_modal" class="form-control" value="<?php echo e($nombre_empresa); ?>" disabled>
                                    </div>
                                    <input type="hidden" id="empresa_form_hidden" name="empresa_form" value="<?php echo e($empresa_id); ?>">
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
                                            $tiene_divisiones_modal = ($count_divisiones_modal > 0);
                                        } catch (Exception $e) {
                                            $tiene_divisiones_modal = false;
                                        }
                                    }
                                    ?>
        
                                    <?php if ($tiene_divisiones_modal): ?>
                                        <label for="id_division">División</label>
                                        <select id="id_division" name="id_division" class="form-control">
                                            <option value="">-- Seleccione una División --</option>
                                            <?php foreach ($divisiones_modal as $division_modal): ?>
                                                <option value="<?php echo e($division_modal['id']); ?>">
                                                    <?php echo e($division_modal['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <option value="0">-- Sin División --</option>
                                        </select>
                                    <?php else: ?>
                                        <input type="hidden" name="id_division" value="0">
                                    <?php endif; ?>
                                </div>
        
                                <div class="form-group" id="subdivisionContainer" style="display:none;">
                                    <label for="id_subdivision">Subdivisión</label>
                                    <select id="id_subdivision" name="id_subdivision" class="form-control">
                                        <option value="">-- Seleccione una Subdivisión --</option>
                                        <option value="0">-- Sin Subdivisión --</option>
                                    </select>
                                    <small class="form-text text-muted">Opcional. Depende de la división seleccionada.</small>
                                </div>
                            </div>
        
                            <div class="form-group" id="modalidadContainer" style="display:none;">
                                <label for="modalidad">Modalidad de Campaña</label>
                                <select id="modalidad" name="modalidad" class="form-control">
                                    <option value="implementacion_auditoria">Implementación + Auditoría</option>
                                    <option value="solo_implementacion">Solo Implementación</option>
                                    <option value="solo_auditoria">Solo Auditoría</option>
                                    <option value="retiro">Retiro</option>
                                    <option value="entrega">Entrega</option>
                                    <option value="implementacion_por_etapas">Implementación por Etapas (Armado/Entregado/Implementado/Retirado)</option>
                                </select>
                                <small class="form-text text-muted">
                                    Solo aplicable a campañas Programadas/IPT.
                                </small>
                            </div>
        
                            <div class="form-group" id="iwReqLocalContainer" style="display:none;">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="iw_requiere_local" name="iw_requiere_local" value="1">
                                    <label class="custom-control-label" for="iw_requiere_local">
                                        Esta campaña IW requiere seleccionar un local?
                                    </label>
                                </div>
                                <small class="form-text text-muted">
                                    Si está activo, en la app se pedirá elegir un local antes de crear la visita IW.
                                </small>
                            </div>
                        </div>
        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light btn-modern" data-dismiss="modal">Cerrar</button>
                            <button type="submit" class="btn btn-primary btn-modern" id="btnSubmitFormulario">
                                <i class="fas fa-save mr-1"></i> Crear Formulario
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="gestionarCategoriasModal" tabindex="-1" role="dialog" aria-labelledby="gestionarCategoriasModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <form method="post" action="mod_formulario.php<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . e($_SERVER['QUERY_STRING']) : ''; ?>" class="catalog-form">
                    <input type="hidden" name="catalog_action" value="crear_categoria_formulario">
                    <input type="hidden" name="catalog_id" value="0">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="gestionarCategoriasModalLabel">
                                <i class="fas fa-tags mr-1"></i> Gestionar Categorías
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="categoria_nombre">Nombre</label>
                                    <input type="text" id="categoria_nombre" name="catalog_nombre" class="form-control catalog-normalize" maxlength="150" required>
                                    <small class="form-text text-muted">Se guardará en mayúsculas y sin caracteres especiales.</small>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="categoria_descripcion">Descripción</label>
                                    <input type="text" id="categoria_descripcion" name="catalog_descripcion" class="form-control catalog-normalize" maxlength="255">
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Categoría</th>
                                            <th>Descripción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($categorias_formulario)): ?>
                                            <tr><td colspan="2" class="text-muted">Sin categorías registradas.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($categorias_formulario as $categoria): ?>
                                                <tr>
                                                    <td><?php echo e($categoria['nombre']); ?></td>
                                                    <td>
                                                        <?php echo e($categoria['descripcion'] ?? ''); ?>
                                                        <div class="mt-2">
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-primary catalog-edit-btn"
                                                                    data-action="actualizar_categoria_formulario"
                                                                    data-id="<?php echo e($categoria['id']); ?>"
                                                                    data-name="<?php echo e($categoria['nombre']); ?>"
                                                                    data-description="<?php echo e($categoria['descripcion'] ?? ''); ?>">
                                                                <i class="fas fa-pen"></i> Editar
                                                            </button>
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-danger catalog-disable-btn"
                                                                    data-action="desactivar_categoria_formulario"
                                                                    data-id="<?php echo e($categoria['id']); ?>"
                                                                    data-name="<?php echo e($categoria['nombre']); ?>">
                                                                <i class="fas fa-eye-slash"></i> Ocultar
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light btn-modern" data-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-outline-secondary btn-modern catalog-cancel-edit" style="display:none;">
                                Cancelar edición
                            </button>
                            <button type="submit" class="btn btn-primary btn-modern catalog-submit-btn">
                                <i class="fas fa-save mr-1"></i> Crear Categoría
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="gestionarTradeModal" tabindex="-1" role="dialog" aria-labelledby="gestionarTradeModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <form method="post" action="mod_formulario.php<?php echo !empty($_SERVER['QUERY_STRING']) ? '?' . e($_SERVER['QUERY_STRING']) : ''; ?>" class="catalog-form">
                    <input type="hidden" name="catalog_action" value="crear_trade">
                    <input type="hidden" name="catalog_id" value="0">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="gestionarTradeModalLabel">
                                <i class="fas fa-store mr-1"></i> Gestionar Trade
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="trade_nombre">Nombre</label>
                                    <input type="text" id="trade_nombre" name="catalog_nombre" class="form-control catalog-normalize" maxlength="150" required>
                                    <small class="form-text text-muted">Se guardará en mayúsculas y sin caracteres especiales.</small>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="trade_descripcion">Descripción</label>
                                    <input type="text" id="trade_descripcion" name="catalog_descripcion" class="form-control catalog-normalize" maxlength="255">
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Trade</th>
                                            <th>Descripción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($trades)): ?>
                                            <tr><td colspan="2" class="text-muted">Sin trades registrados.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($trades as $trade): ?>
                                                <tr>
                                                    <td><?php echo e($trade['nombre']); ?></td>
                                                    <td>
                                                        <?php echo e($trade['descripcion'] ?? ''); ?>
                                                        <div class="mt-2">
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-primary catalog-edit-btn"
                                                                    data-action="actualizar_trade"
                                                                    data-id="<?php echo e($trade['id']); ?>"
                                                                    data-name="<?php echo e($trade['nombre']); ?>"
                                                                    data-description="<?php echo e($trade['descripcion'] ?? ''); ?>">
                                                                <i class="fas fa-pen"></i> Editar
                                                            </button>
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-danger catalog-disable-btn"
                                                                    data-action="desactivar_trade"
                                                                    data-id="<?php echo e($trade['id']); ?>"
                                                                    data-name="<?php echo e($trade['nombre']); ?>">
                                                                <i class="fas fa-eye-slash"></i> Ocultar
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light btn-modern" data-dismiss="modal">Cerrar</button>
                            <button type="button" class="btn btn-outline-secondary btn-modern catalog-cancel-edit" style="display:none;">
                                Cancelar edición
                            </button>
                            <button type="submit" class="btn btn-primary btn-modern catalog-submit-btn">
                                <i class="fas fa-save mr-1"></i> Crear Trade
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

<div class="section-title mt-4 mb-3">
    <i class="fas fa-table"></i>
    <span>Formularios creados</span>
</div>

<div id="formularioTableContainer" class="table-wrap">
    <div class="table-loading-state text-center py-5">
        <div class="spinner-border text-success mb-3" role="status">
            <span class="sr-only">Cargando...</span>
        </div>
        <div class="text-muted">Cargando formularios...</div>
    </div>
</div>

    </div>
</div>

<div class="modal fade" id="contenidoExternoModal" tabindex="-1" role="dialog" aria-labelledby="contenidoExternoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document" style="max-width: 96%;">
        <div class="modal-content" style="height: 92vh;">
            <div class="modal-header">
                <h5 class="modal-title" id="contenidoExternoModalLabel">Detalle</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body p-0" style="height: calc(92vh - 56px); overflow: hidden;">
                <iframe
                    id="contenidoExternoFrame"
                    src="about:blank"
                    style="width:100%; height:100%; border:0;"
                    loading="lazy">
                </iframe>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script>
    const tiposPregunta = <?php echo json_encode($tipos_pregunta, JSON_UNESCAPED_UNICODE); ?>;
</script>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js"></script>

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
                    label.textContent = 'División';
                    divisionContainer.appendChild(label);

                    var select = document.createElement('select');
                    select.id = 'id_division';
                    select.name = 'id_division';
                    select.className = 'form-control';

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

                    select.addEventListener('change', function(){
                        const t = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
                        if (t === '1' || t === '3') {
                            cargarSubdivisionesCrear(this.value);
                        } else {
                            const subCont = document.getElementById('subdivisionContainer');
                            const subSel  = document.getElementById('id_subdivision');
                            if (subCont && subSel) {
                                subCont.style.display = 'none';
                                subSel.value = '0';
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

                    cargarSubdivisionesCrear('0');
                }
            } catch(e) {
                console.error("Error JSON:", e);
            }
        }
    };
    xhr.send();
}

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
                cargarTablaFormularios(true);
            },
            error: function(xhr, status, error) {
                console.error('Error al obtener divisiones:', error);
                divisionSelect.innerHTML = '<option value="0">-- Todas las Divisiones --</option>';
            }
        });
    } else {
        divisionSelect.innerHTML = '<option value="0">-- Todas las Divisiones --</option>';
        cargarTablaFormularios(true);
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
                cont.style.display = 'block';
            }
        })
        .catch(() => {
            sel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
            cont.style.display = 'block';
        });
}

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
        case 1:
            optionContainer.style.display = 'block';
            addFixedYesNoOptions(questionIndex);
            break;
        case 2:
        case 3:
            optionContainer.style.display = 'block';
            break;
    }
}

function addFixedYesNoOptions(questionIndex) {
    const optionRows = document.getElementById('optionRows_' + questionIndex);
    if (!optionRows) return;

    optionRows.innerHTML = '';
    ['Sí', 'No'].forEach(function(texto, idx) {
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

document.addEventListener('DOMContentLoaded', function(){
    function normalizeCatalogInput(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toUpperCase()
            .replace(/[^A-Z0-9 ]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trimStart();
    }

    document.querySelectorAll('.catalog-normalize').forEach(function(input) {
        input.addEventListener('input', function() {
            const normalized = normalizeCatalogInput(this.value);
            if (this.value !== normalized) {
                this.value = normalized;
            }
        });
        input.addEventListener('blur', function() {
            this.value = normalizeCatalogInput(this.value).trim();
        });
    });

    document.querySelectorAll('.catalog-form').forEach(function(formCatalog) {
        const actionInput = formCatalog.querySelector('[name="catalog_action"]');
        const defaultAction = actionInput ? actionInput.value : '';
        formCatalog.dataset.defaultAction = defaultAction;

        formCatalog.addEventListener('submit', function(event) {
            this.querySelectorAll('.catalog-normalize').forEach(function(input) {
                input.value = normalizeCatalogInput(input.value).trim();
            });

            const nombre = this.querySelector('[name="catalog_nombre"]');
            if (!nombre || nombre.value.trim() === '') {
                event.preventDefault();
                alert('El nombre es obligatorio.');
            }
        });
    });

    document.querySelectorAll('.catalog-edit-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            const formCatalog = this.closest('.catalog-form');
            if (!formCatalog) return;

            const actionInput = formCatalog.querySelector('[name="catalog_action"]');
            const idInput = formCatalog.querySelector('[name="catalog_id"]');
            const nameInput = formCatalog.querySelector('[name="catalog_nombre"]');
            const descriptionInput = formCatalog.querySelector('[name="catalog_descripcion"]');
            const submitButton = formCatalog.querySelector('.catalog-submit-btn');
            const cancelButton = formCatalog.querySelector('.catalog-cancel-edit');

            if (actionInput) actionInput.value = this.dataset.action || formCatalog.dataset.defaultAction || '';
            if (idInput) idInput.value = this.dataset.id || '0';
            if (nameInput) nameInput.value = normalizeCatalogInput(this.dataset.name || '').trim();
            if (descriptionInput) descriptionInput.value = normalizeCatalogInput(this.dataset.description || '').trim();
            if (submitButton) submitButton.innerHTML = '<i class="fas fa-save mr-1"></i> Actualizar';
            if (cancelButton) cancelButton.style.display = '';

            if (nameInput) nameInput.focus();
        });
    });

    document.querySelectorAll('.catalog-cancel-edit').forEach(function(button) {
        button.addEventListener('click', function() {
            const formCatalog = this.closest('.catalog-form');
            if (!formCatalog) return;

            const actionInput = formCatalog.querySelector('[name="catalog_action"]');
            const idInput = formCatalog.querySelector('[name="catalog_id"]');
            const nameInput = formCatalog.querySelector('[name="catalog_nombre"]');
            const descriptionInput = formCatalog.querySelector('[name="catalog_descripcion"]');
            const submitButton = formCatalog.querySelector('.catalog-submit-btn');

            if (actionInput) actionInput.value = formCatalog.dataset.defaultAction || '';
            if (idInput) idInput.value = '0';
            if (nameInput) nameInput.value = '';
            if (descriptionInput) descriptionInput.value = '';
            if (submitButton) {
                submitButton.innerHTML = formCatalog.dataset.defaultAction === 'crear_trade'
                    ? '<i class="fas fa-save mr-1"></i> Crear Trade'
                    : '<i class="fas fa-save mr-1"></i> Crear Categoría';
            }
            this.style.display = 'none';
        });
    });

    document.querySelectorAll('.catalog-disable-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            const formCatalog = this.closest('.catalog-form');
            if (!formCatalog) return;

            const name = this.dataset.name || 'este registro';
            if (!confirm(`¿Quieres ocultar ${name}? Dejará de aparecer en los filtros y selectores.`)) {
                return;
            }

            const actionInput = formCatalog.querySelector('[name="catalog_action"]');
            const idInput = formCatalog.querySelector('[name="catalog_id"]');
            if (actionInput) actionInput.value = this.dataset.action || '';
            if (idInput) idInput.value = this.dataset.id || '0';
            formCatalog.submit();
        });
    });

    function showGlobalSpinner() {
        document.getElementById('globalSpinner').classList.add('show');
    }

    document.querySelectorAll('a.btn-info, a.btn-warning').forEach(function(el){
        el.addEventListener('click', showGlobalSpinner);
    });

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
                    alert('La fecha de término debe ser mayor o igual a la de inicio.');
                    e.preventDefault();
                    return;
                }

                const csv = document.getElementById('csvFile');
                if (!csv || !csv.files || csv.files.length === 0) {
                    alert('Debes subir un CSV para campañas Programadas/IPT.');
                    e.preventDefault();
                    return;
                }
            }
        });
    }

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
                    subSel.value = '0';
                    subSel.disabled = true;
                    subSel.required = false;
                }
            }
        });
    }


});
</script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
    const formCrear = document.getElementById('formCrearFormulario');
    const overlay = document.getElementById('formSubmitOverlay');
    const btnSubmit = document.getElementById('btnSubmitFormulario');

    if (formCrear) {
        formCrear.addEventListener('submit', function () {
            if (overlay) {
                overlay.classList.add('show');
            }

            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Procesando...';
            }
        });
    }
});
</script>

<script>
$.fn.dataTable.ext.errMode = 'none';

$(document).on('error.dt', function (e, settings, techNote, message) {
    console.warn('DataTables error:', message);

    const tabla = document.getElementById('tablaFormularios');
    if (tabla) {
        console.log('Cantidad headers:', tabla.querySelectorAll('thead th').length);
        tabla.querySelectorAll('tbody tr').forEach((tr, i) => {
            console.log('Fila', i + 1, 'columnas:', tr.children.length);
        });
    }
});
</script>

<script>
let tablaFormulariosDT = null;
let formularioAbortController = null;

function getFiltrosFormulario() {
    const form = document.getElementById('filterForm');

    if (!form) {
        return new URLSearchParams({ buscar: 1 });
    }

    const formData = new FormData(form);
    const params = new URLSearchParams();

    for (const [key, value] of formData.entries()) {
        params.append(key, value);
    }

    params.set('buscar', '1');
    return params;
}

function destruirDataTableFormularios() {
    if ($.fn.DataTable.isDataTable('#tablaFormularios')) {
        $('#tablaFormularios').DataTable().destroy();
    }
}

function inicializarDataTableFormularios() {
    tablaFormulariosDT = $('#tablaFormularios').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        autoWidth: false,
        responsive: true,
        destroy: true,
        language: {
            emptyTable: "No se encontraron formularios.",
            info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
            infoEmpty: "Mostrando 0 a 0 de 0 registros",
            infoFiltered: "(filtrado de _MAX_ registros totales)",
            lengthMenu: "Mostrar _MENU_ registros",
            loadingRecords: "Cargando...",
            processing: "Procesando...",
            search: "Buscar:",
            zeroRecords: "No se encontraron coincidencias",
            paginate: {
                first: "Primero",
                last: "Último",
                next: "Siguiente",
                previous: "Anterior"
            }
        }
    });
}

function actualizarContadorFormularios() {
    const countElement = document.querySelector('.count-pill .count');
    const filas = document.querySelectorAll('#tablaFormularios tbody tr');

    if (!countElement) return;

    if (!filas.length) {
        countElement.textContent = '0';
        return;
    }

    if (filas.length === 1) {
        const td = filas[0].querySelector('td[colspan]');
        if (td) {
            countElement.textContent = '0';
            return;
        }
    }

    countElement.textContent = String(filas.length);
}

function enlazarBotonesConSpinner() {
    document.querySelectorAll('#tablaFormularios a.btn-info, #tablaFormularios a.btn-warning').forEach(function(el) {
        el.addEventListener('click', function() {
            const spinner = document.getElementById('globalSpinner');
            if (spinner) {
                spinner.classList.add('show');
            }
        });
    });
}

function esperar(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function fetchConReintento(url, options = {}, maxIntentos = 3) {
    let ultimoError = null;

    for (let intento = 1; intento <= maxIntentos; intento++) {
        try {
            const response = await fetch(url, options);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return await response.text();
        } catch (error) {
            ultimoError = error;

            if (error.name === 'AbortError') {
                throw error;
            }

            if (intento < maxIntentos) {
                await esperar(500 * intento);
            }
        }
    }

    throw ultimoError;
}

async function cargarTablaFormularios(pushUrl = false) {
    const container = document.getElementById('formularioTableContainer');
    const params = getFiltrosFormulario();

    if (!container) return;

    if (formularioAbortController) {
        formularioAbortController.abort();
    }

    formularioAbortController = new AbortController();

    container.innerHTML = `
        <div class="table-loading-state text-center py-5">
            <div class="spinner-border text-success mb-3" role="status">
                <span class="sr-only">Cargando...</span>
            </div>
            <div class="text-muted">Cargando formularios...</div>
        </div>
    `;

    try {
        const html = await fetchConReintento(
            'mod_formulario/ajax_listado_formularios.php?' + params.toString(),
            {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: formularioAbortController.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            },
            3
        );

        destruirDataTableFormularios();
        container.innerHTML = html;
        inicializarDataTableFormularios();

        if (pushUrl) {
            const newUrl = window.location.pathname + '?' + params.toString();
            window.history.replaceState({}, '', newUrl);
        }

        actualizarContadorFormularios();
        enlazarBotonesConSpinner();
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        console.error('Error cargando formularios:', error);

        container.innerHTML = `
            <div class="alert alert-danger mb-0">
                No fue posible cargar los formularios. Intenta nuevamente.
            </div>
        `;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.getElementById('filterForm');
    const divisionFilter = document.getElementById('division_filter');
    const subdivisionFilter = document.getElementById('subdivision_filter');
    const estadoFilter = document.getElementById('estado_filter');
    const tipoFilter = document.getElementById('tipo_filter');

    cargarTablaFormularios(false);

    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            cargarTablaFormularios(true);
        });
    }

    if (divisionFilter) {
        divisionFilter.onchange = function() {
            const divisionId = this.value;

            if (divisionId && divisionId !== '0') {
                cargarSubdivisionesFiltro(divisionId, 0);

                setTimeout(function() {
                    cargarTablaFormularios(true);
                }, 250);
            } else {
                const subCont = document.getElementById('subdivision_filter_container');
                const subSel = document.getElementById('subdivision_filter');
                if (subCont && subSel) {
                    subSel.innerHTML = '<option value="0">-- Todas las Subdivisiones --</option>';
                    subSel.value = '0';
                    subCont.style.display = 'none';
                }
                cargarTablaFormularios(true);
            }
        };
    }

    if (subdivisionFilter) {
        subdivisionFilter.addEventListener('change', function() {
            cargarTablaFormularios(true);
        });
    }

    if (estadoFilter) {
        estadoFilter.addEventListener('change', function() {
            cargarTablaFormularios(true);
        });
    }

    if (tipoFilter) {
        tipoFilter.addEventListener('change', function() {
            cargarTablaFormularios(true);
        });
    }
});
</script>

<script>
function abrirModalExterno(url, titulo) {
    const modal = $('#contenidoExternoModal');
    const frame = document.getElementById('contenidoExternoFrame');
    const title = document.getElementById('contenidoExternoModalLabel');

    if (title) {
        title.textContent = titulo || 'Detalle';
    }

    if (frame) {
        frame.src = url;
    }

    modal.modal('show');
}

$(document).on('click', '.btn-open-modal', function () {
    const url = $(this).data('modal-url');
    const titulo = $(this).data('modal-title') || 'Detalle';

    abrirModalExterno(url, titulo);
});

$('#contenidoExternoModal').on('hidden.bs.modal', function () {
    const frame = document.getElementById('contenidoExternoFrame');
    if (frame) {
        frame.src = 'about:blank';
    }
});

</script>
</body>
</html>
