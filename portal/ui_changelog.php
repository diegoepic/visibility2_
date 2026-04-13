<?php
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
ini_set('log_errors', '1');
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

/* Cargar primeros 3 registros */
$sql = "
SELECT 
    sc.id,
    sc.modulo,
    sc.titulo,
    sc.descripcion,
    sc.fecha_cambio,
    sc.usuario_registro,
    CONCAT(
        COALESCE(u.nombre, ''), 
        CASE 
            WHEN u.apellido IS NOT NULL AND u.apellido <> '' 
            THEN CONCAT(' ', u.apellido)
            ELSE ''
        END
    ) AS nombreUsuario,
    sc.criticidad,
    sc.estado,
    sc.tipo_gestion,
    sct.nombre AS tipo_nombre,
    sct.color AS tipo_color,
    sct.icono AS tipo_icono

FROM system_changelog sc

LEFT JOIN system_changelog_types sct 
    ON TRIM(LOWER(sct.nombre)) = TRIM(LOWER(sc.tipo_gestion))

LEFT JOIN usuario u 
    ON u.id = sc.usuario_registro     

WHERE sc.deleted_at IS NULL
  AND sc.estado = 'publicado'

ORDER BY sc.fecha_cambio DESC, sc.id DESC
LIMIT 3;
";

$result = $conn->query($sql);
$items = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>System Changelog</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap si ya lo usas -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- FontAwesome si ya lo usas -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="assets/changelog.css">
</head>
<body class="changelog-body">

<div class="container-fluid changelog-wrapper">
    <div class="changelog-hero">
        <div class="changelog-kicker">Registry Feed</div>
        <h1 class="changelog-title">System Changelog</h1>
        <p class="changelog-subtitle">
            Registro interno de mejoras, correcciones, nuevos módulos y cambios relevantes del sistema.
        </p>
    </div>

    <div class="row">
        <!-- FORMULARIO -->
        <div class="col-lg-4 mb-4">
            <div class="changelog-form-card">
                <div class="card-header-custom">
                    <h3><i class="fas fa-pen-to-square mr-2"></i>Registrar cambio</h3>
                </div>

                <form id="formChangelog">
                    <div class="form-group">
                        <label>Módulo</label>
                        <input type="text" name="modulo" class="form-control custom-input" required maxlength="150" placeholder="Ej: Usuarios, Locales, Dashboard">
                    </div>

                    <div class="form-group">
                        <label>Tipo de gestión</label>
                        <select name="id_tipo_gestion" id="id_tipo_gestion" class="form-control custom-input" required>
                            <option value="">Cargando tipos...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Título</label>
                        <input type="text" name="titulo" class="form-control custom-input" required maxlength="200" placeholder="Ej: Se agregó carga masiva con subdivisión">
                    </div>

                    <div class="form-group">
                        <label>Descripción breve</label>
                        <textarea name="descripcion" rows="4" class="form-control custom-input" required placeholder="Describe brevemente el cambio realizado"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Responsable</label>
                        <select name="usuario_registro" id="usuario_registro" class="form-control custom-input">
                            <option value="">Cargando usuarios...</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Criticidad</label>
                        <select name="criticidad" class="form-control custom-input">
                            <option value="baja">Baja</option>
                            <option value="media" selected>Media</option>
                            <option value="alta">Alta</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado" class="form-control custom-input">
                            <option value="publicado" selected>Publicado</option>
                            <option value="borrador">Borrador</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-changelog-primary btn-block">
                        <i class="fas fa-save mr-2"></i>Guardar cambio
                    </button>
                </form>

                <div id="changelogFormMsg" class="mt-3"></div>
            </div>
        </div>

        <!-- TIMELINE -->
        <div class="col-lg-8 mb-4">
            <div class="changelog-timeline-card">
                <div class="timeline-header">
                    <h3>Últimos cambios registrados</h3>
                    <p>Se muestran inicialmente los últimos 3 registros publicados.</p>
                </div>

                <div id="timelineContainer" class="timeline">
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $index => $item): ?>
                            <?php
                                $sideClass = ($index % 2 === 0) ? 'left' : 'right';
                                $color = !empty($item['tipo_color']) ? $item['tipo_color'] : '#19d3ff';
                                $icono = !empty($item['tipo_icono']) ? $item['tipo_icono'] : 'fas fa-circle';
                            ?>
                            <div class="timeline-item <?php echo $sideClass; ?>">
                                <div class="timeline-point" style="box-shadow: 0 0 0 4px <?php echo htmlspecialchars($color); ?>22;">
                                    <span style="background: <?php echo htmlspecialchars($color); ?>;"></span>
                                </div>

                                <div class="timeline-card">
                                    <div class="timeline-badge" style="border-color: <?php echo htmlspecialchars($color); ?>; color: <?php echo htmlspecialchars($color); ?>;">
                                        <i class="<?php echo htmlspecialchars($icono); ?> mr-1"></i>
                                        <?php echo htmlspecialchars($item['tipo_nombre'] ?: 'CAMBIO'); ?>
                                    </div>

                                    <div class="timeline-date">
                                        <?php echo date('d.m.Y', strtotime($item['fecha_cambio'])); ?>
                                    </div>

                                    <h4 class="timeline-title"><?php echo htmlspecialchars($item['titulo']); ?></h4>

                                    <div class="timeline-module">
                                        <strong>Módulo:</strong> <?php echo htmlspecialchars($item['modulo']); ?>
                                    </div>

                                    <p class="timeline-desc"><?php echo nl2br(htmlspecialchars($item['descripcion'])); ?></p>

                                    <div class="timeline-meta">
                                        <span><strong>Responsable:</strong> <?php echo htmlspecialchars($item['nombreUsuario'] ?: 'No informado'); ?></span>
                                        <span class="criticidad criticidad-<?php echo htmlspecialchars($item['criticidad']); ?>">
                                            <?php echo ucfirst(htmlspecialchars($item['criticidad'])); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-changelog">
                            No hay cambios publicados aún.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="timeline-footer">
                    <div class="timeline-footer-label">End of current registry feed</div>
                    <button id="btnCargarMas" class="btn btn-load-more" data-offset="3">
                        Mostrar cambios anteriores
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- overlay -->
<div id="changelogOverlay" class="changelog-overlay" style="display:none;">
    <div class="changelog-overlay-box">
        <div class="spinner-border text-info mb-3" role="status"></div>
        <div class="overlay-title">Procesando solicitud</div>
        <div class="overlay-subtitle">Por favor espera un momento...</div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="assets/changelog.js"></script>
</body>
</html>