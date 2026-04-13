<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

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

$empresa_seleccionada     = isset($_GET['empresa']) ? intval($_GET['empresa']) : 0;
$division_seleccionada    = isset($_GET['division']) ? intval($_GET['division']) : 0;
$estado_campana           = isset($_GET['estado_campana']) ? trim($_GET['estado_campana']) : '1';
$tipo_campana             = isset($_GET['tipo_campana']) ? intval($_GET['tipo_campana']) : 0;
$subdivision_seleccionada = isset($_GET['subdivision']) ? intval($_GET['subdivision']) : 0;
$buscar                   = isset($_GET['buscar']) ? trim($_GET['buscar']) : '0';

$empresa_id = intval($_SESSION['empresa_id'] ?? 0);

$nombre_empresa = obtenerNombreEmpresa($empresa_id);
$es_mentecreativa = (strtolower(trim($nombre_empresa)) === 'mentecreativa');

$formularios = [];

if ($buscar === '1') {
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
        LEFT JOIN division_empresa AS d ON d.id = f.id_division
        LEFT JOIN subdivision AS sd ON sd.id = f.id_subdivision
    ";

    $conditions = [];

    if ($es_mentecreativa) {
        if ($empresa_seleccionada > 0) {
            $conditions[] = "f.id_empresa = ?";
            $params[] = $empresa_seleccionada;
            $param_types .= "i";
        } else {
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

    if (!empty($conditions)) {
        $query .= " WHERE " . implode(" AND ", $conditions);
    }

    $query .= " ORDER BY f.fechaInicio DESC, f.id DESC";

    $formularios = cacheRemember(
        'form_listado_formularios_v1',
        [
            'empresa_seleccionada'     => $empresa_seleccionada,
            'division_seleccionada'    => $division_seleccionada,
            'estado_campana'           => $estado_campana,
            'tipo_campana'             => $tipo_campana,
            'subdivision_seleccionada' => $subdivision_seleccionada,
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
?>

<div class="table-responsive">
    <table id="tablaFormularios" class="table table-hover w-100">
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
                        $estado_texto       = obtenerTextoEstado($row['estado']);
                        $estado_clase       = obtenerClaseEstado($row['estado']);
                        $tipo_texto         = obtenerTextoTipo($row['tipo']);
                        $tipo_clase         = obtenerClaseTipo($row['tipo']);
                        $division_nombre    = !empty($row['division_nombre']) ? e($row['division_nombre']) : '<span class="table-muted">Sin División</span>';
                        $subdivision_nombre = !empty($row['subdivision_nombre']) ? e($row['subdivision_nombre']) : '<span class="table-muted">Sin Subdivisión</span>';
                        $editar_url         = "mod_formulario/editar_formulario.php?id=" . urlencode($row['id']);
                    ?>
                    <tr>
                        <td><strong>#<?php echo e($row['id']); ?></strong></td>
                        <td class="campaign-name"><?php echo e($row['nombre']); ?></td>
                        <td><?php echo e(formatearFecha($row['fechaInicio'])); ?></td>
                        <td><?php echo e(formatearFecha($row['fechaTermino'])); ?></td>
                        <td><span class="<?php echo e($estado_clase); ?>"><?php echo e($estado_texto); ?></span></td>
                        <td><span class="<?php echo e($tipo_clase); ?>"><?php echo e($tipo_texto); ?></span></td>
                        <td><?php echo $division_nombre; ?></td>
                        <td><?php echo $subdivision_nombre; ?></td>
                        <td>
                            <div class="action-group">
                            <button
                                type="button"
                                class="btn btn-warning btn-sm btn-icon btn-open-modal"
                                title="Editar"
                                data-modal-title="Editar formulario #<?php echo e($row['id']); ?>"
                                data-modal-url="<?php echo e($editar_url); ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            
                            <button
                                type="button"
                                class="btn btn-info btn-sm btn-icon btn-open-modal"
                                title="Ver Mapa"
                                data-modal-title="Mapa campaña #<?php echo e($row['id']); ?>"
                                data-modal-url="mod_formulario/mapa_campana.php?id=<?php echo urlencode($row['id']); ?>">
                                <i class="fas fa-map-marker-alt"></i>
                            </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">No se encontraron formularios.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>