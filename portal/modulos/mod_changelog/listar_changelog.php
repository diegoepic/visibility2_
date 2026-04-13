<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

function renderTimelineItems($items) {
    $html = '';

    foreach ($items as $index => $item) {
        $sideClass = ($index % 2 === 0) ? 'left' : 'right';
        $color = !empty($item['tipo_color']) ? $item['tipo_color'] : '#19d3ff';
        $icono = !empty($item['tipo_icono']) ? $item['tipo_icono'] : 'fas fa-circle';

        $html .= '
        <div class="timeline-item ' . $sideClass . '">
            <div class="timeline-point" style="box-shadow: 0 0 0 4px ' . htmlspecialchars($color) . '22;">
                <span style="background: ' . htmlspecialchars($color) . ';"></span>
            </div>

            <div class="timeline-card">
                <div class="timeline-badge" style="border-color: ' . htmlspecialchars($color) . '; color: ' . htmlspecialchars($color) . ';">
                    <i class="' . htmlspecialchars($icono) . ' mr-1"></i>
                    ' . htmlspecialchars($item['tipo_nombre'] ?: 'CAMBIO') . '
                </div>

                <div class="timeline-date">' . date('d.m.Y', strtotime($item['fecha_cambio'])) . '</div>

                <h4 class="timeline-title">' . htmlspecialchars($item['titulo']) . '</h4>

                <div class="timeline-module">
                    <strong>Módulo:</strong> ' . htmlspecialchars($item['modulo']) . '
                </div>

                <p class="timeline-desc">' . nl2br(htmlspecialchars($item['descripcion'])) . '</p>

                <div class="timeline-meta">
                    <span><strong>Responsable:</strong> ' . htmlspecialchars($item['usuario_registro'] ?: 'No informado') . '</span>
                    <span class="criticidad criticidad-' . htmlspecialchars($item['criticidad']) . '">
                        ' . ucfirst(htmlspecialchars($item['criticidad'])) . '
                    </span>
                </div>
            </div>
        </div>';
    }

    return $html;
}

$sql = "
    SELECT 
        sc.id,
        sc.modulo,
        sc.titulo,
        sc.descripcion,
        sc.fecha_cambio,
        sc.usuario_registro,
        sc.criticidad,
        sc.estado,
        sct.nombre AS tipo_nombre,
        sct.color AS tipo_color,
        sct.icono AS tipo_icono
    FROM system_changelog sc
    LEFT JOIN system_changelog_types sct 
        ON sct.id = sc.id_tipo_gestion
    WHERE sc.deleted_at IS NULL
      AND sc.estado = 'publicado'
    ORDER BY sc.fecha_cambio DESC, sc.id DESC
    LIMIT 3
";

$result = $conn->query($sql);
$items = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
}

echo renderTimelineItems($items);