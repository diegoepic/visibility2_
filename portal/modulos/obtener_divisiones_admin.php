<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

$sql = "
    SELECT 
        d.id,
        d.nombre,
        d.id_empresa,
        d.image_url,
        d.estado,
        e.nombre AS empresa_nombre
    FROM division_empresa d
    INNER JOIN empresa e ON e.id = d.id_empresa
    ORDER BY e.nombre ASC, d.nombre ASC
";

$res = $conn->query($sql);

if (!$res) {
    echo '<div class="alert alert-danger mb-0">Error al cargar divisiones: ' . htmlspecialchars($conn->error) . '</div>';
    exit;
}
?>

<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead>
            <tr>
                <th style="width:90px;">Logo</th>
                <th>División</th>
                <th>Empresa</th>
                <th>Estado</th>
                <th style="width:140px;">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($res->num_rows > 0): ?>
                <?php while ($row = $res->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <?php if (!empty($row['image_url'])): ?>
                                <img src="<?php echo htmlspecialchars($row['image_url']); ?>"
                                     alt="logo"
                                     style="width:56px;height:56px;object-fit:contain;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:6px;">
                            <?php else: ?>
                                <div style="width:56px;height:56px;border-radius:12px;background:#f3f4f6;border:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;color:#9ca3af;">
                                    <i class="fas fa-image"></i>
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <strong><?php echo htmlspecialchars($row['nombre']); ?></strong>
                        </td>

                        <td>
                            <span class="badge badge-light" style="font-size:.82rem;padding:8px 10px;border:1px solid #e5e7eb;">
                                <?php echo htmlspecialchars($row['empresa_nombre']); ?>
                            </span>
                        </td>

                        <td>
                            <?php if ((int)$row['estado'] === 1): ?>
                                <span class="badge badge-success" style="padding:8px 10px;">Activo</span>
                            <?php else: ?>
                                <span class="badge badge-secondary" style="padding:8px 10px;">Inactivo</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-primary mr-1 btn-editar-division"
                                data-id="<?php echo (int)$row['id']; ?>"
                                data-nombre="<?php echo htmlspecialchars($row['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-id_empresa="<?php echo (int)$row['id_empresa']; ?>"
                                data-image_url="<?php echo htmlspecialchars($row['image_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-estado="<?php echo (int)$row['estado']; ?>"
                            >
                                <i class="fas fa-edit"></i>
                            </button>

                            <button class="btn btn-sm btn-outline-danger" disabled>
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-4">No hay divisiones registradas.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>