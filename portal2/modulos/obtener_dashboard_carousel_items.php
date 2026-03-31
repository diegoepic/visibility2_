<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$empresa_id  = intval($_GET['empresa_id'] ?? 0);
$division_id = intval($_GET['division_id'] ?? 0);

$sql = "
    SELECT c.*, e.nombre AS empresa_nombre, d.nombre AS division_nombre
    FROM dashboard_carousel_items c
    INNER JOIN empresa e ON e.id = c.id_empresa
    INNER JOIN division_empresa d ON d.id = c.id_division
    WHERE 1=1
";



$sql .= " ORDER BY c.orden ASC, c.id ASC";

$res = $conn->query($sql);

if (!$res) {
    echo '<div class="alert alert-danger mb-0">Error al cargar items: ' . htmlspecialchars($conn->error) . '</div>';
    exit;
}
?>

<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead>
            <tr>
                <th style="width:90px;">Imagen</th>
                <th>Título</th>
                <th>Empresa</th>
                <th>División</th>
                <th>Orden</th>
                <th>Estado</th>
                <th>Destino</th>
                <th style="width:140px;">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($res->num_rows > 0): ?>
                <?php while ($row = $res->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <img src="<?= htmlspecialchars($row['image_url']) ?>"
                                 alt="img"
                                 style="width:56px;height:56px;object-fit:cover;border-radius:12px;border:1px solid #e5e7eb;">
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($row['titulo']) ?></strong>
                            <?php if (!empty($row['subtitulo'])): ?>
                                <div class="text-muted small"><?= htmlspecialchars($row['subtitulo']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['empresa_nombre']) ?></td>
                        <td><?= htmlspecialchars($row['division_nombre']) ?></td>
                        <td><span class="badge badge-light"><?= (int)$row['orden'] ?></span></td>
                        <td>
                            <?php if ((int)$row['is_active'] === 1): ?>
                                <span class="badge badge-success">Activo</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td style="max-width:220px;">
                            <div class="text-truncate"><?= htmlspecialchars($row['target_url']) ?></div>
                        </td>
                        <td>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary mr-1 btn-editar-carousel"
                                data-id="<?= (int)$row['id'] ?>"
                                data-empresa="<?= (int)$row['id_empresa'] ?>"
                                data-division="<?= (int)$row['id_division'] ?>"
                                data-titulo="<?= htmlspecialchars($row['titulo'], ENT_QUOTES, 'UTF-8') ?>"
                                data-subtitulo="<?= htmlspecialchars($row['subtitulo'], ENT_QUOTES, 'UTF-8') ?>"
                                data-target="<?= htmlspecialchars($row['target_url'], ENT_QUOTES, 'UTF-8') ?>"
                                data-orden="<?= (int)$row['orden'] ?>"
                                data-active="<?= (int)$row['is_active'] ?>"
                                data-image="<?= htmlspecialchars($row['image_url'], ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <i class="fas fa-edit"></i>
                            </button>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger btn-eliminar-carousel"
                                data-id="<?= (int)$row['id'] ?>"
                            >
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">No hay items registrados.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>