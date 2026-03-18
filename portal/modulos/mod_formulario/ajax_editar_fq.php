<?php
// ajax_editar_fq.php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo "<div class='modal-body'><p>Sesi¨®n expirada.</p></div>";
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$fq_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$formulario_id = isset($_GET['formulario_id']) ? (int)$_GET['formulario_id'] : 0;

if ($fq_id <= 0 || $formulario_id <= 0) {
    http_response_code(400);
    echo "<div class='modal-body'><p>Error: Par¨¢metros inv¨¢lidos.</p></div>";
    exit;
}

// -----------------------------------------------------------------------------
// Obtener datos del formularioQuestion validando que pertenezca al formulario
// -----------------------------------------------------------------------------
$stmt = $conn->prepare("
    SELECT
        fq.id,
        fq.id_usuario,
        u.usuario AS nombre_usuario,
        fq.id_local,
        l.nombre AS nombre_local,
        fq.material,
        fq.valor_propuesto,
        fq.valor,
        fq.fechaVisita,
        fq.observacion,
        fq.estado,
        fq.pregunta,
        f.id_division,
        f.id_empresa
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    LEFT JOIN usuario u ON fq.id_usuario = u.id
    LEFT JOIN local l ON fq.id_local = l.id
    WHERE fq.id = ?
      AND fq.id_formulario = ?
    LIMIT 1
");
$stmt->bind_param("ii", $fq_id, $formulario_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo "<div class='modal-body'><p>Error: Entrada no encontrada.</p></div>";
    exit;
}

$id_division_form = (int)($row['id_division'] ?? 0);
$id_empresa_form  = (int)($row['id_empresa'] ?? 0);
$empresa_sesion   = (int)($_SESSION['empresa_id'] ?? 0);

// -----------------------------------------------------------------------------
// Usuarios
// Puedes dejarlo abierto a todos o limitar por empresa
// -----------------------------------------------------------------------------
$usuarios = [];
$stmtUsuarios = $conn->prepare("
    SELECT id, usuario
    FROM usuario
    WHERE activo = 1
      AND id_empresa = ?
    ORDER BY usuario ASC
");
$stmtUsuarios->bind_param("i", $empresa_sesion);
$stmtUsuarios->execute();
$resUsuarios = $stmtUsuarios->get_result();

while ($rowUsuario = $resUsuarios->fetch_assoc()) {
    $usuarios[] = $rowUsuario;
}
$stmtUsuarios->close();

// -----------------------------------------------------------------------------
// Materiales
// Idealmente filtrar por divisi¨®n si aplica
// -----------------------------------------------------------------------------
$materiales = [];

if ($id_division_form > 0) {
    $stmtMateriales = $conn->prepare("
        SELECT id, nombre
        FROM material
        WHERE id_division = ?
        ORDER BY nombre ASC
    ");
    $stmtMateriales->bind_param("i", $id_division_form);
} else {
    $stmtMateriales = $conn->prepare("
        SELECT id, nombre
        FROM material
        ORDER BY nombre ASC
    ");
}

$stmtMateriales->execute();
$resMateriales = $stmtMateriales->get_result();

while ($rowMaterial = $resMateriales->fetch_assoc()) {
    $materiales[] = $rowMaterial;
}
$stmtMateriales->close();

// -----------------------------------------------------------------------------
// Render modal
// -----------------------------------------------------------------------------
?>
<div class="modal-header">
    <h5 class="modal-title" id="editarFQModalLabel">Editar entrada</h5>
    <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
        <span aria-hidden="true">&times;</span>
    </button>
</div>

<div class="modal-body">
    <form action="editar_formulario.php?id=<?= (int)$formulario_id ?>" method="post">
        <input type="hidden" name="update_fq" value="1">
        <input type="hidden" name="fq_id" value="<?= (int)$row['id'] ?>">
        <input type="hidden" name="active_tab" value="agregar-entradas">

        <div class="form-group">
            <label for="id_usuario">Usuario asignado:</label>
            <select id="id_usuario" name="id_usuario" class="form-control" required>
                <option value="">Seleccione un usuario</option>
                <?php foreach ($usuarios as $usuario): ?>
                    <option value="<?= (int)$usuario['id'] ?>" <?= ((int)$row['id_usuario'] === (int)$usuario['id']) ? 'selected' : '' ?>>
                        <?= h($usuario['usuario']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="material_id">Material:</label>
            <div class="input-group">
                <select id="material_id" name="material_id" class="form-control" required>
                    <option value="">Seleccione un material</option>
                    <?php foreach ($materiales as $mat): ?>
                        <option value="<?= (int)$mat['id'] ?>" <?= ($row['material'] === $mat['nombre']) ? 'selected' : '' ?>>
                            <?= h($mat['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="input-group-append">
                    <button type="button" class="btn btn-secondary" data-toggle="modal" data-target="#agregarMaterialModal">
                        Agregar material
                    </button>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label for="valor_propuesto">Valor propuesto:</label>
            <input
                type="text"
                id="valor_propuesto"
                name="valor_propuesto"
                class="form-control"
                value="<?= h($row['valor_propuesto']) ?>"
            >
        </div>

        <div class="form-group">
            <label for="valor">Valor:</label>
            <input
                type="text"
                id="valor"
                name="valor"
                class="form-control"
                value="<?= h($row['valor']) ?>"
            >
        </div>

        <div class="form-group">
            <label for="fechaVisita">Fecha de visita:</label>
            <input
                type="datetime-local"
                id="fechaVisita"
                name="fechaVisita"
                class="form-control"
                value="<?= !empty($row['fechaVisita']) ? h(date('Y-m-d\TH:i', strtotime((string)$row['fechaVisita']))) : '' ?>"
            >
        </div>

        <div class="form-group">
            <label for="observacion">Observaci¨®n:</label>
            <textarea id="observacion" name="observacion" class="form-control"><?= h($row['observacion']) ?></textarea>
        </div>

        <div class="form-group">
            <label for="estado">Estado:</label>
            <select id="estado" name="estado" class="form-control" required>
                <option value="0" <?= ((int)$row['estado'] === 0) ? 'selected' : '' ?>>En proceso</option>
                <option value="1" <?= ((int)$row['estado'] === 1) ? 'selected' : '' ?>>Completado</option>
                <option value="2" <?= ((int)$row['estado'] === 2) ? 'selected' : '' ?>>Cancelado</option>
            </select>
        </div>

        <div class="form-group">
            <label>Local:</label>
            <input type="text" class="form-control" value="<?= h($row['nombre_local']) ?>" readonly>
        </div>

        <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
            <button type="submit" class="btn btn-primary">Guardar cambios</button>
        </div>
    </form>
</div>