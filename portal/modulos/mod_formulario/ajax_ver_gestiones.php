<?php
declare(strict_types=1);

// ----------------------------------------------------
// 1) Salida limpia y configuración
// ----------------------------------------------------
while (ob_get_level()) {
    ob_end_clean();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session_data.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// ----------------------------------------------------
// 2) Helpers
// ----------------------------------------------------
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function flash(string $type, string $msg): void
{
    echo "<div class='alert alert-{$type}'>" . h($msg) . "</div>";
    exit;
}

function normalizeStoredUploadPath(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $url = preg_replace('#^https?://[^/]+#i', '', $url) ?: $url;

    if (strpos($url, '/visibility2/app/') === 0) {
        $url = substr($url, strlen('/visibility2/app/'));
    }

    $url = ltrim($url, '/');

    return $url;
}

function absoluteUploadPath(string $url): string
{
    $relative = normalizeStoredUploadPath($url);
    if ($relative === '') {
        return '';
    }

    return rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/visibility2/app/' . $relative;
}

function webUploadUrl(string $url): string
{
    $relative = normalizeStoredUploadPath($url);
    if ($relative === '') {
        return '';
    }

    return '/visibility2/app/' . $relative;
}

function ensureLogged(): void
{
    if (!isset($_SESSION['usuario_id'])) {
        http_response_code(403);
        echo "<div class='alert alert-danger'>Acceso denegado.</div>";
        exit;
    }
}

function validateImageUpload(array $file): bool
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }

    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return false;
    }

    $mime = mime_content_type($tmp);
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
}

function getFormularioInfo(mysqli $conn, int $formulario_id): ?array
{
    $stmt = $conn->prepare("
        SELECT id, id_division, id_empresa
        FROM formulario
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $formulario_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

// ----------------------------------------------------
// 3) Seguridad + parámetros
// ----------------------------------------------------
ensureLogged();

$formulario_id = isset($_REQUEST['formulario_id']) ? (int)$_REQUEST['formulario_id'] : 0;
$local_id      = isset($_REQUEST['local_id']) ? (int)$_REQUEST['local_id'] : 0;

if ($formulario_id <= 0 || $local_id <= 0) {
    flash('danger', 'Parámetros inválidos.');
}

$formulario = getFormularioInfo($conn, $formulario_id);
if (!$formulario) {
    flash('danger', 'Formulario no encontrado.');
}

$id_division = (int)$formulario['id_division'];
$id_empresa  = (int)$formulario['id_empresa'];

// ----------------------------------------------------
// 4) Acciones POST
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action      = trim((string)$_POST['action']);
    $target_user = (int)($_POST['user_id'] ?? 0);

    try {
        switch ($action) {

            case 'clear_responses':
                if ($target_user <= 0) {
                    flash('danger', 'Selecciona un ejecutor.');
                }

                $conn->begin_transaction();

                $stmt = $conn->prepare("
                    DELETE fqr
                    FROM form_question_responses fqr
                    INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
                    WHERE fq.id_formulario = ?
                      AND fqr.id_local = ?
                      AND fqr.id_usuario = ?
                ");
                $stmt->bind_param("iii", $formulario_id, $local_id, $target_user);
                $stmt->execute();
                $stmt->close();

                $stmt2 = $conn->prepare("
                    UPDATE formularioQuestion
                    SET countVisita = 0
                    WHERE id_formulario = ?
                      AND id_local = ?
                      AND id_usuario = ?
                ");
                $stmt2->bind_param("iii", $formulario_id, $local_id, $target_user);
                $stmt2->execute();
                $stmt2->close();

                $conn->commit();
                flash('success', 'Encuesta recargada y visitas reseteadas.');

            case 'reset_local':
                if ($target_user <= 0) {
                    flash('danger', 'Selecciona un ejecutor.');
                }

                $conn->begin_transaction();

                $stmt1 = $conn->prepare("
                    UPDATE formularioQuestion
                    SET countVisita = 0,
                        valor = NULL,
                        motivo = NULL,
                        observacion = '',
                        pregunta = NULL,
                        fechaVisita = NULL
                    WHERE id_formulario = ?
                      AND id_local = ?
                      AND id_usuario = ?
                ");
                $stmt1->bind_param("iii", $formulario_id, $local_id, $target_user);
                $stmt1->execute();
                $stmt1->close();

                $stmt2 = $conn->prepare("
                    DELETE fqr
                    FROM form_question_responses fqr
                    INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
                    WHERE fq.id_formulario = ?
                      AND fqr.id_local = ?
                      AND fqr.id_usuario = ?
                ");
                $stmt2->bind_param("iii", $formulario_id, $local_id, $target_user);
                $stmt2->execute();
                $stmt2->close();

                $conn->commit();
                flash('success', 'Gestión recargada.');

            case 'delete_impl':
                $impl_id = (int)($_POST['id'] ?? 0);
                if ($impl_id <= 0) {
                    flash('danger', 'ID inválido.');
                }

                $conn->begin_transaction();

                $stmtF = $conn->prepare("
                    SELECT id, url
                    FROM fotoVisita
                    WHERE id_formularioQuestion = ?
                ");
                $stmtF->bind_param("i", $impl_id);
                $stmtF->execute();
                $resF = $stmtF->get_result();
                $fotos = $resF->fetch_all(MYSQLI_ASSOC);
                $stmtF->close();

                $stmtD = $conn->prepare("
                    DELETE FROM fotoVisita
                    WHERE id_formularioQuestion = ?
                ");
                $stmtD->bind_param("i", $impl_id);
                $stmtD->execute();
                $stmtD->close();

                $stmtQ = $conn->prepare("
                    DELETE FROM formularioQuestion
                    WHERE id = ?
                      AND id_formulario = ?
                      AND id_local = ?
                ");
                $stmtQ->bind_param("iii", $impl_id, $formulario_id, $local_id);
                $stmtQ->execute();
                $ok = $stmtQ->affected_rows > 0;
                $stmtQ->close();

                $conn->commit();

                foreach ($fotos as $foto) {
                    $path = absoluteUploadPath((string)$foto['url']);
                    if ($path !== '' && file_exists($path)) {
                        @unlink($path);
                    }
                }

                echo $ok
                    ? "<div class='alert alert-success'>Implementación eliminada.</div>"
                    : "<div class='alert alert-danger'>No se encontró la implementación.</div>";
                exit;

            case 'delete_resp':
                $resp_id = (int)($_POST['id'] ?? 0);
                if ($resp_id <= 0) {
                    flash('danger', 'ID inválido.');
                }

                $stmtR = $conn->prepare("
                    DELETE fqr
                    FROM form_question_responses fqr
                    INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
                    WHERE fqr.id = ?
                      AND fq.id_formulario = ?
                      AND fqr.id_local = ?
                ");
                $stmtR->bind_param("iii", $resp_id, $formulario_id, $local_id);
                $stmtR->execute();
                $ok = $stmtR->affected_rows > 0;
                $stmtR->close();

                echo $ok
                    ? "<div class='alert alert-success'>Respuesta eliminada.</div>"
                    : "<div class='alert alert-danger'>No se encontró la respuesta.</div>";
                exit;

            case 'clear_material_keep_photos':
                $impl_id = (int)($_POST['id'] ?? 0);
                if ($impl_id <= 0) {
                    flash('danger', 'ID inválido.');
                }

                $stmtU = $conn->prepare("
                    UPDATE formularioQuestion
                    SET countVisita = 0,
                        valor = NULL,
                        motivo = NULL,
                        observacion = '',
                        pregunta = NULL,
                        fechaVisita = NULL
                    WHERE id = ?
                      AND id_formulario = ?
                      AND id_local = ?
                ");
                $stmtU->bind_param("iii", $impl_id, $formulario_id, $local_id);
                $stmtU->execute();
                $ok = $stmtU->affected_rows > 0;
                $stmtU->close();

                echo $ok
                    ? "<div class='alert alert-success'>Material recargado (fotos conservadas).</div>"
                    : "<div class='alert alert-danger'>No se encontró el material.</div>";
                exit;

            case 'clear_material':
                $impl_id = (int)($_POST['id'] ?? 0);
                if ($impl_id <= 0) {
                    flash('danger', 'ID inválido.');
                }

                $conn->begin_transaction();

                $stmtF = $conn->prepare("
                    SELECT id, url
                    FROM fotoVisita
                    WHERE id_formularioQuestion = ?
                ");
                $stmtF->bind_param("i", $impl_id);
                $stmtF->execute();
                $resF = $stmtF->get_result();
                $fotos = $resF->fetch_all(MYSQLI_ASSOC);
                $stmtF->close();

                $stmtU = $conn->prepare("
                    UPDATE formularioQuestion
                    SET countVisita = 0,
                        valor = NULL,
                        motivo = NULL,
                        observacion = '',
                        pregunta = NULL,
                        fechaVisita = NULL
                    WHERE id = ?
                      AND id_formulario = ?
                      AND id_local = ?
                ");
                $stmtU->bind_param("iii", $impl_id, $formulario_id, $local_id);
                $stmtU->execute();
                $ok = $stmtU->affected_rows >= 0;
                $stmtU->close();

                $stmtD = $conn->prepare("
                    DELETE FROM fotoVisita
                    WHERE id_formularioQuestion = ?
                ");
                $stmtD->bind_param("i", $impl_id);
                $stmtD->execute();
                $stmtD->close();

                $conn->commit();

                foreach ($fotos as $foto) {
                    $path = absoluteUploadPath((string)$foto['url']);
                    if ($path !== '' && file_exists($path)) {
                        @unlink($path);
                    }
                }

                echo $ok
                    ? "<div class='alert alert-success'>Material limpiado.</div>"
                    : "<div class='alert alert-danger'>Error al limpiar material.</div>";
                exit;

            case 'update_material':
                $impl_id  = (int)($_POST['id'] ?? 0);
                $material = trim((string)($_POST['material'] ?? ''));

                if ($impl_id <= 0 || $material === '') {
                    flash('danger', 'Datos inválidos.');
                }

                $stmtM = $conn->prepare("
                    UPDATE formularioQuestion
                    SET material = ?
                    WHERE id = ?
                      AND id_formulario = ?
                      AND id_local = ?
                ");
                $stmtM->bind_param("siii", $material, $impl_id, $formulario_id, $local_id);
                $stmtM->execute();
                $ok = $stmtM->affected_rows >= 0;
                $stmtM->close();

                echo $ok
                    ? "<div class='alert alert-success'>Material actualizado.</div>"
                    : "<div class='alert alert-danger'>Error al actualizar material.</div>";
                exit;

            case 'reset_impl_all':
                if ($target_user <= 0) {
                    flash('danger', 'Selecciona un ejecutor.');
                }

                $stmt = $conn->prepare("
                    UPDATE formularioQuestion
                    SET countVisita = 0,
                        valor = NULL,
                        motivo = NULL,
                        observacion = '',
                        pregunta = NULL,
                        fechaVisita = NULL
                    WHERE id_formulario = ?
                      AND id_local = ?
                      AND id_usuario = ?
                ");
                $stmt->bind_param("iii", $formulario_id, $local_id, $target_user);
                $stmt->execute();
                $ok = $stmt->affected_rows >= 0;
                $stmt->close();

                echo $ok
                    ? "<div class='alert alert-success'>Implementaciones recargadas.</div>"
                    : "<div class='alert alert-danger'>Error al recargar implementaciones.</div>";
                exit;

            case 'delete_photo':
                $photo_id = (int)($_POST['id'] ?? 0);
                if ($photo_id <= 0) {
                    flash('danger', 'ID de foto inválido.');
                }

                $stmtF = $conn->prepare("
                    SELECT fv.id, fv.url
                    FROM fotoVisita fv
                    WHERE fv.id = ?
                      AND fv.id_formulario = ?
                      AND fv.id_local = ?
                    LIMIT 1
                ");
                $stmtF->bind_param("iii", $photo_id, $formulario_id, $local_id);
                $stmtF->execute();
                $resF = $stmtF->get_result();
                $foto = $resF->fetch_assoc();
                $stmtF->close();

                if (!$foto) {
                    flash('danger', 'Foto no encontrada.');
                }

                $stmtD = $conn->prepare("
                    DELETE FROM fotoVisita
                    WHERE id = ?
                ");
                $stmtD->bind_param("i", $photo_id);
                $stmtD->execute();
                $ok = $stmtD->affected_rows > 0;
                $stmtD->close();

                if ($ok) {
                    $path = absoluteUploadPath((string)$foto['url']);
                    if ($path !== '' && file_exists($path)) {
                        @unlink($path);
                    }
                }

                echo $ok
                    ? "<div class='alert alert-success'>Foto eliminada.</div>"
                    : "<div class='alert alert-danger'>Error al eliminar foto.</div>";
                exit;

            case 'update_photo':
                $photo_id = (int)($_POST['id'] ?? 0);

                if ($photo_id <= 0 || !isset($_FILES['file']) || !validateImageUpload($_FILES['file'])) {
                    flash('danger', 'No se recibió archivo válido.');
                }

                $stmtF = $conn->prepare("
                    SELECT fv.id, fv.url
                    FROM fotoVisita fv
                    WHERE fv.id = ?
                      AND fv.id_formulario = ?
                      AND fv.id_local = ?
                    LIMIT 1
                ");
                $stmtF->bind_param("iii", $photo_id, $formulario_id, $local_id);
                $stmtF->execute();
                $resF = $stmtF->get_result();
                $old = $resF->fetch_assoc();
                $stmtF->close();

                if (!$old) {
                    flash('danger', 'Foto no encontrada.');
                }

                $dest = absoluteUploadPath((string)$old['url']);
                if ($dest === '') {
                    flash('danger', 'Ruta de foto inválida.');
                }

                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }

                if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                    @chmod($dest, 0644);
                    flash('success', 'Foto reemplazada correctamente.');
                }

                flash('danger', 'Error al mover el archivo.');

            case 'add_photo':
                $impl_id = (int)($_POST['id'] ?? 0);

                if ($impl_id <= 0 || !isset($_FILES['file']) || !validateImageUpload($_FILES['file'])) {
                    flash('danger', 'No se recibió un archivo válido.');
                }

                $stmtQ = $conn->prepare("
                    SELECT fq.id, fq.id_usuario, fq.material
                    FROM formularioQuestion fq
                    WHERE fq.id = ?
                      AND fq.id_formulario = ?
                      AND fq.id_local = ?
                    LIMIT 1
                ");
                $stmtQ->bind_param("iii", $impl_id, $formulario_id, $local_id);
                $stmtQ->execute();
                $resQ = $stmtQ->get_result();
                $impl = $resQ->fetch_assoc();
                $stmtQ->close();

                if (!$impl) {
                    flash('danger', 'Implementación no encontrada.');
                }

                $ejecutor   = (int)$impl['id_usuario'];
                $matName    = (string)$impl['material'];
                $material_id = 0;

                $stmtMid = $conn->prepare("
                    SELECT id
                    FROM material
                    WHERE nombre = ?
                    LIMIT 1
                ");
                $stmtMid->bind_param("s", $matName);
                $stmtMid->execute();
                $resMid = $stmtMid->get_result();
                $matRow = $resMid->fetch_assoc();
                $stmtMid->close();

                if ($matRow) {
                    $material_id = (int)$matRow['id'];
                }

                $fecha   = date('Y-m-d');
                $relDir  = "uploads/{$fecha}/material_{$material_id}";
                $fullDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . "/visibility2/app/{$relDir}";

                if (!is_dir($fullDir)) {
                    @mkdir($fullDir, 0755, true);
                }

                $ext  = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
                $ext  = $ext !== '' ? $ext : 'jpg';
                $name = "mat_" . uniqid('', true) . "." . $ext;
                $dest = "{$fullDir}/{$name}";

                if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                    flash('danger', 'Error al mover el archivo.');
                }

                @chmod($dest, 0644);

                $url = "{$relDir}/{$name}";

                // Buscar el visita_id más reciente (requerido NOT NULL en fotoVisita)
                $stmtV = $conn->prepare("
                    SELECT id FROM visita
                    WHERE id_formulario = ? AND id_local = ? AND id_usuario = ?
                    ORDER BY fecha_inicio DESC
                    LIMIT 1
                ");
                $stmtV->bind_param("iii", $formulario_id, $local_id, $ejecutor);
                $stmtV->execute();
                $resV = $stmtV->get_result()->fetch_assoc();
                $stmtV->close();
                $visita_id = $resV ? (int)$resV['id'] : 0;

                $stmtI = $conn->prepare("
                    INSERT INTO fotoVisita
                        (url, id_formularioQuestion, id_usuario, id_material, id_formulario, id_local, visita_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtI->bind_param(
                    "siiiiii",
                    $url,
                    $impl_id,
                    $ejecutor,
                    $material_id,
                    $formulario_id,
                    $local_id,
                    $visita_id
                );
                $ok = $stmtI->execute();
                $stmtI->close();

                echo $ok
                    ? "<div class='alert alert-success'>Foto añadida correctamente.</div>"
                    : "<div class='alert alert-danger'>Error al registrar la foto en BD.</div>";
                exit;

            default:
                flash('danger', 'Acción desconocida.');
        }
    } catch (Throwable $e) {
        if ($conn->errno) {
            // no-op
        }
        flash('danger', 'Ocurrió un error al procesar la acción.');
    }
}

// ----------------------------------------------------
// 5) Datos para renderizar HTML
// ----------------------------------------------------
$stmt = $conn->prepare("
    SELECT uid, usuario
    FROM (
        SELECT fq.id_usuario AS uid, u.usuario
        FROM formularioQuestion fq
        INNER JOIN usuario u ON u.id = fq.id_usuario
        WHERE fq.id_formulario = ?
          AND fq.id_local = ?

        UNION

        SELECT fqr.id_usuario AS uid, u2.usuario
        FROM form_question_responses fqr
        INNER JOIN form_questions fq2 ON fq2.id = fqr.id_form_question
        INNER JOIN usuario u2 ON u2.id = fqr.id_usuario
        WHERE fq2.id_formulario = ?
          AND fqr.id_local = ?
    ) tmp
    ORDER BY usuario ASC
");
$stmt->bind_param("iiii", $formulario_id, $local_id, $formulario_id, $local_id);
$stmt->execute();
$distinct_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selected_user = (int)($_REQUEST['user_id'] ?? ($distinct_users[0]['uid'] ?? 0));

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM formularioQuestion
    WHERE id_formulario = ?
      AND id_local = ?
      AND id_usuario = ?
");
$stmt->bind_param("iii", $formulario_id, $local_id, $selected_user);
$stmt->execute();
$stmt->bind_result($total_impl);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM form_question_responses fqr
    INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
    WHERE fq.id_formulario = ?
      AND fqr.id_local = ?
      AND fqr.id_usuario = ?
");
$stmt->bind_param("iii", $formulario_id, $local_id, $selected_user);
$stmt->execute();
$stmt->bind_result($total_resp);
$stmt->fetch();
$stmt->close();

$page_impl     = max(1, (int)($_GET['page_impl'] ?? 1));
$per_page_impl = max(5, (int)($_GET['per_page_impl'] ?? 10));
$offset_impl   = ($page_impl - 1) * $per_page_impl;

$page_resp     = max(1, (int)($_GET['page_resp'] ?? 1));
$per_page_resp = max(5, (int)($_GET['per_page_resp'] ?? 10));
$offset_resp   = ($page_resp - 1) * $per_page_resp;

$tab = (isset($_GET['tab']) && $_GET['tab'] === 'encuesta') ? 'encuesta' : 'impl';

// Implementaciones
$stmt = $conn->prepare("
    SELECT
        fq.id,
        u.usuario AS nombre_usuario,
        fq.material,
        fq.valor_propuesto,
        fq.valor,
        fq.fechaVisita,
        fq.observacion
    FROM formularioQuestion fq
    LEFT JOIN usuario u ON u.id = fq.id_usuario
    WHERE fq.id_formulario = ?
      AND fq.id_local = ?
      AND fq.id_usuario = ?
    ORDER BY fq.fechaVisita ASC, fq.id ASC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iiiii", $formulario_id, $local_id, $selected_user, $per_page_impl, $offset_impl);
$stmt->execute();
$rawImpl = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$implementaciones = [];
foreach ($rawImpl as $impl) {
    $stmtF = $conn->prepare("
        SELECT id, url
        FROM fotoVisita
        WHERE id_formularioQuestion = ?
        ORDER BY id ASC
    ");
    $stmtF->bind_param("i", $impl['id']);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    $fotos = [];
    while ($f = $resF->fetch_assoc()) {
        $fotos[] = $f;
    }
    $stmtF->close();

    $impl['fotos'] = $fotos;
    $implementaciones[] = $impl;
}

// Respuestas
$stmt = $conn->prepare("
    SELECT
        fqr.id,
        u.usuario AS nombre_usuario,
        fq.question_text,
        fqr.answer_text,
        fqr.created_at
    FROM form_question_responses fqr
    INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
    LEFT JOIN usuario u ON u.id = fqr.id_usuario
    WHERE fq.id_formulario = ?
      AND fqr.id_local = ?
      AND fqr.id_usuario = ?
    ORDER BY fqr.created_at ASC, fqr.id ASC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iiiii", $formulario_id, $local_id, $selected_user, $per_page_resp, $offset_resp);
$stmt->execute();
$respuestas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$uploadBaseUrl = '/visibility2/app/';
?>
<div class="modal-header bg-primary text-white">
  <h5 class="modal-title">
    Gestión PDV <small class="text-light">Local #<?= h($local_id) ?></small>
  </h5>
  <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
</div>

<div class="modal-body">
  <div id="actionAlerts"></div>

  <div class="form-inline mb-3">
    <label for="select_ejecutor" class="mr-2">Ejecutor:</label>
    <select id="select_ejecutor" class="form-control">
      <?php foreach ($distinct_users as $u): ?>
        <option value="<?= (int)$u['uid'] ?>" <?= ((int)$u['uid'] === $selected_user) ? 'selected' : '' ?>>
          <?= h($u['usuario']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <ul class="nav nav-tabs">
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'impl' ? 'active' : '' ?>" data-toggle="tab" href="#tab_impl">
        <i class="fas fa-tools"></i> Implementaciones
        <span class="badge badge-light"><?= (int)$total_impl ?></span>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === 'encuesta' ? 'active' : '' ?>" data-toggle="tab" href="#tab_encuesta">
        <i class="fas fa-question-circle"></i> Encuesta
        <span class="badge badge-light"><?= (int)$total_resp ?></span>
      </a>
    </li>
  </ul>

  <div class="tab-content mt-3">
    <div id="tab_impl" class="tab-pane fade <?= $tab === 'impl' ? 'show active' : '' ?>">
      <?php if (count($implementaciones) > 0): ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead class="thead-dark">
              <tr>
                <th>ID</th>
                <th>Material</th>
                <th>Propuesto</th>
                <th>Implementado</th>
                <th>Fecha Visita</th>
                <th>Fotos</th>
                <th>Obs.</th>
                <th class="text-center">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($implementaciones as $impl): ?>
              <tr data-id="<?= h($impl['id']) ?>">
                <td><?= h($impl['id']) ?></td>
                <td>
                  <div class="d-flex align-items-center">
                    <span class="material-text"><?= h($impl['material']) ?></span>
                    <input type="text" class="form-control form-control-sm ml-2 material-input"
                           value="<?= h($impl['material']) ?>"
                           style="display:none; width:120px;">
                    <button class="btn btn-sm btn-success ml-1 save-material" style="display:none;">
                      <i class="fas fa-save"></i>
                    </button>
                  </div>
                </td>
                <td><?= h($impl['valor_propuesto']) ?></td>
                <td><?= h($impl['valor'] ?? '—') ?></td>
                <td><?= h($impl['fechaVisita'] ?? '—') ?></td>
                <td>
                  <div class="d-flex flex-wrap">
                    <?php foreach ($impl['fotos'] as $foto):
                      $full = webUploadUrl((string)$foto['url']);
                      $photoId = (int)$foto['id'];
                    ?>
                      <div class="photo-thumb-wrapper position-relative mr-1 mb-1"
                           data-photo-id="<?= $photoId ?>"
                           style="width:48px;height:48px;">
                        <img src="<?= h($full) ?>"
                             class="photo-thumb rounded"
                             style="width:100%;height:100%;object-fit:cover;cursor:pointer;"
                             title="Ver foto">
                        <button class="btn btn-sm btn-danger delete-photo position-absolute"
                                style="top:2px; right:2px; padding:0 4px; line-height:1;"
                                title="Eliminar foto">×</button>
                        <button class="btn btn-sm btn-primary replace-photo position-absolute"
                                style="top:2px; left:2px; padding:0 4px; line-height:1;"
                                title="Reemplazar foto">
                          <i class="fa-solid fa-rotate-right"></i>
                        </button>
                      </div>
                    <?php endforeach; ?>

                    <div class="photo-thumb-wrapper position-relative mr-1 mb-1"
                         data-impl-id="<?= h($impl['id']) ?>"
                         style="width:48px;height:48px;">
                      <button class="btn btn-sm btn-success add-photo position-absolute"
                              style="top:2px; left:2px; padding:0 4px; line-height:1;"
                              title="Añadir foto para este material">
                        <i class="fas fa-plus"></i>
                      </button>
                    </div>
                  </div>
                </td>
                <td><?= nl2br(h($impl['observacion'])) ?></td>
                <td class="text-center">
                  <div class="btn-group btn-group-sm">
                    <button class="btn btn-danger delete-impl" title="Eliminar">
                      <i class="fas fa-trash-alt"></i>
                    </button>
                    <button class="btn btn-warning clear-material" title="Limpiar">
                      <i class="fas fa-eraser"></i>
                    </button>
                    <button class="btn btn-info edit-material" title="Editar">
                      <i class="fas fa-edit"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <nav aria-label="Impl paginación">
          <ul class="pagination justify-content-center">
            <li class="page-item <?= $page_impl <= 1 ? 'disabled' : '' ?>">
              <a class="page-link prev-impl" href="#" data-page="<?= $page_impl - 1 ?>" data-resp="<?= $page_resp ?>">
                &laquo; Anterior
              </a>
            </li>
            <li class="page-item disabled">
              <span class="page-link">
                Página <?= $page_impl ?> de <?= max(1, (int)ceil($total_impl / $per_page_impl)) ?>
              </span>
            </li>
            <li class="page-item <?= $page_impl >= max(1, (int)ceil($total_impl / $per_page_impl)) ? 'disabled' : '' ?>">
              <a class="page-link next-impl" href="#" data-page="<?= $page_impl + 1 ?>" data-resp="<?= $page_resp ?>">
                Siguiente &raquo;
              </a>
            </li>
          </ul>
        </nav>
      <?php else: ?>
        <p class="text-center text-muted">No hay implementaciones registradas para este ejecutor.</p>
      <?php endif; ?>
    </div>

    <div id="tab_encuesta" class="tab-pane fade <?= $tab === 'encuesta' ? 'show active' : '' ?>">
      <?php if (count($respuestas) > 0): ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead class="thead-dark">
              <tr>
                <th>ID</th>
                <th>Pregunta</th>
                <th>Respuesta</th>
                <th>Fecha</th>
                <th class="text-center">Acción</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($respuestas as $r): ?>
              <tr data-id="<?= h($r['id']) ?>">
                <td><?= h($r['id']) ?></td>
                <td><?= h($r['question_text']) ?></td>
                <td>
                  <?php
                    $ans = (string)($r['answer_text'] ?? '');
                    $isImg = preg_match('/\.(jpe?g|png|gif|webp)$/i', $ans);
                    if ($isImg) {
                        $url = webUploadUrl($ans);
                        echo '<img src="' . h($url) . '" class="photo-thumb rounded" style="width:48px;height:48px;cursor:pointer;">';
                    } else {
                        echo nl2br(h($ans));
                    }
                  ?>
                </td>
                <td><?= h($r['created_at']) ?></td>
                <td class="text-center">
                  <button class="btn btn-sm btn-danger delete-resp" title="Eliminar">
                    <i class="fas fa-trash-alt"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <nav aria-label="Encuesta paginación">
          <ul class="pagination justify-content-center">
            <li class="page-item <?= $page_resp <= 1 ? 'disabled' : '' ?>">
              <a class="page-link prev-resp" href="#" data-page="<?= $page_resp - 1 ?>" data-impl="<?= $page_impl ?>">
                &laquo; Anterior
              </a>
            </li>
            <li class="page-item disabled">
              <span class="page-link">
                Página <?= $page_resp ?> de <?= max(1, (int)ceil($total_resp / $per_page_resp)) ?>
              </span>
            </li>
            <li class="page-item <?= $page_resp >= max(1, (int)ceil($total_resp / $per_page_resp)) ? 'disabled' : '' ?>">
              <a class="page-link next-resp" href="#" data-page="<?= $page_resp + 1 ?>" data-impl="<?= $page_impl ?>">
                Siguiente &raquo;
              </a>
            </li>
          </ul>
        </nav>

        <div class="text-center mt-3">
          <button class="btn btn-outline-danger" id="clear-resps">
            <i class="fas fa-trash"></i> Borrar todas las respuestas
          </button>
        </div>
      <?php else: ?>
        <p class="text-center text-muted">No hay respuestas de encuesta para este ejecutor.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal-footer">
  <button class="btn btn-warning" id="reset-local">
    <i class="fas fa-sync"></i> Recargar toda la gestión
  </button>
  <button class="btn btn-warning ml-2" id="reset-materials">
    <i class="fas fa-eraser"></i> Recargar solo implementaciones
  </button>
  <button class="btn btn-warning ml-2" id="reset-encuesta">
    <i class="fas fa-trash"></i> Recargar solo encuesta
  </button>
  <button class="btn btn-secondary ml-auto" data-dismiss="modal">
    <i class="fas fa-times"></i> Cerrar
  </button>
</div>

<div class="modal fade" id="confirmActionModal" tabindex="-1" role="dialog" aria-labelledby="confirmActionLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h5 class="modal-title" id="confirmActionLabel">Confirmar acción</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="confirmActionCancel">Cancelar</button>
        <button type="button" class="btn btn-danger" id="confirmActionOk">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="photoModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content p-2 bg-dark">
      <img id="photoModalImg" src="" class="img-fluid rounded" />
    </div>
  </div>
</div>

<style>
.photo-thumb-wrapper {
  position: relative;
  overflow: hidden;
}
.photo-thumb-wrapper .loader {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 100;
}
</style>

<script>
var currentPageImpl = <?= (int)$page_impl ?>;
var currentPageResp = <?= (int)$page_resp ?>;
var currentTab = '<?= h($tab) ?>';

function showConfirm(msg, callback) {
  $('#confirmActionModal .modal-body').text(msg);
  $('#confirmActionOk, #confirmActionCancel').off('click');
  $('#confirmActionOk').on('click', function() {
    $('#confirmActionModal').modal('hide');
    callback(true);
  });
  $('#confirmActionCancel').on('click', function() {
    $('#confirmActionModal').modal('hide');
    callback(false);
  });
  $('#confirmActionModal').modal('show');
}

function flashAlert(html) {
  var $alert = $(html).appendTo('#actionAlerts').hide().fadeIn(200);
  setTimeout(function() {
    $alert.fadeOut(500, function() {
      $(this).remove();
    });
  }, 3000);
}

function loadGestiones(tab, pageImpl, pageResp) {
  currentTab = tab;
  currentPageImpl = pageImpl;
  currentPageResp = pageResp;

  $.get('ajax_ver_gestiones.php', {
    formulario_id: <?= (int)$formulario_id ?>,
    local_id: <?= (int)$local_id ?>,
    user_id: $('#select_ejecutor').val(),
    page_impl: pageImpl,
    page_resp: pageResp,
    tab: tab
  }, function(html) {
    $('#gestionesModalContent').html(html);
    $('.nav-tabs a[href="#tab_' + tab + '"]').tab('show');
  });
}

$(document).on('change', '#select_ejecutor', function() {
  loadGestiones('impl', 1, 1);
});

$(document).on('click', '.prev-impl, .next-impl', function(e) {
  e.preventDefault();
  if ($(this).closest('.page-item').hasClass('disabled')) return;
  loadGestiones('impl', $(this).data('page'), $(this).data('resp'));
});

$(document).on('click', '.prev-resp, .next-resp', function(e) {
  e.preventDefault();
  if ($(this).closest('.page-item').hasClass('disabled')) return;
  loadGestiones('encuesta', $(this).data('impl'), $(this).data('page'));
});

$(document).on('click', '#clear-resps', function() {
  showConfirm('¿Borrar TODAS las respuestas de este ejecutor?', function(ok) {
    if (!ok) return;
    $.post('ajax_ver_gestiones.php', {
      formulario_id: <?= (int)$formulario_id ?>,
      local_id: <?= (int)$local_id ?>,
      action: 'clear_responses',
      user_id: $('#select_ejecutor').val()
    }, function(html) {
      flashAlert(html);
      loadGestiones('encuesta', 1, 1);
    });
  });
});

$(document).on('click', '#reset-local', function() {
  showConfirm('¿Recargar TODA la gestión de este ejecutor?', function(ok) {
    if (!ok) return;
    $.post('ajax_ver_gestiones.php', {
      formulario_id: <?= (int)$formulario_id ?>,
      local_id: <?= (int)$local_id ?>,
      action: 'reset_local',
      user_id: $('#select_ejecutor').val()
    }, function(html) {
      flashAlert(html);
      if (html.indexOf('alert-success') !== -1) {
        setTimeout(function() { reloadAndReopenGestiones('impl'); }, 400);
      }
    });
  });
});

$(document).on('click', '#reset-materials', function() {
  showConfirm('¿Recargar SOLO las implementaciones de este ejecutor?', function(ok) {
    if (!ok) return;
    $.post('ajax_ver_gestiones.php', {
      formulario_id: <?= (int)$formulario_id ?>,
      local_id: <?= (int)$local_id ?>,
      action: 'reset_impl_all',
      user_id: $('#select_ejecutor').val()
    }, function(html) {
      flashAlert(html);
      if (html.indexOf('alert-success') !== -1) {
        setTimeout(function() { reloadAndReopenGestiones('impl'); }, 400);
      }
    });
  });
});

$(document).on('click', '#reset-encuesta', function() {
  showConfirm('¿Recargar SOLO las respuestas de encuesta de este ejecutor?', function(ok) {
    if (!ok) return;
    $.post('ajax_ver_gestiones.php', {
      formulario_id: <?= (int)$formulario_id ?>,
      local_id: <?= (int)$local_id ?>,
      action: 'clear_responses',
      user_id: $('#select_ejecutor').val()
    }, function(html) {
      flashAlert(html);
      if (html.indexOf('alert-success') !== -1) {
        setTimeout(function() { reloadAndReopenGestiones('encuesta'); }, 400);
      }
    });
  });
});

$(document).on('click', '.delete-impl', function() {
  var id = $(this).closest('tr').data('id');
  showConfirm('¿Eliminar esta implementación?', function(ok) {
    if (!ok) return;
    $.post('ajax_ver_gestiones.php', {
      formulario_id: <?= (int)$formulario_id ?>,
      local_id: <?= (int)$local_id ?>,
      action: 'delete_impl',
      id: id
    }, function(html) {
      flashAlert(html);
      loadGestiones(currentTab, currentPageImpl, currentPageResp);
    });
  });
});

$(document).on('click', '.clear-material', function() {
  var id = $(this).closest('tr').data('id');
  showConfirm('¿Borrar también las fotos? Aceptar = limpiar material y fotos. Cancelar = no hacer nada.', function(ok) {
    if (!ok) return;
    $.post('ajax_ver_gestiones.php', {
      formulario_id: <?= (int)$formulario_id ?>,
      local_id: <?= (int)$local_id ?>,
      action: 'clear_material',
      id: id
    }, function(html) {
      flashAlert(html);
      loadGestiones(currentTab, currentPageImpl, currentPageResp);
    });
  });
});

$(document).on('click', '.edit-material', function() {
  var $row = $(this).closest('tr');
  $row.find('.material-text').hide();
  $row.find('.material-input, .save-material').show();
});

$(document).on('click', '.save-material', function() {
  var $row = $(this).closest('tr'),
      id = $row.data('id'),
      mat = $row.find('.material-input').val();

  $.post('ajax_ver_gestiones.php', {
    formulario_id: <?= (int)$formulario_id ?>,
    local_id: <?= (int)$local_id ?>,
    action: 'update_material',
    id: id,
    material: mat
  }, function(html) {
    flashAlert(html);
    loadGestiones('impl', currentPageImpl, currentPageResp);
  });
});

$(document).on('click', '.delete-resp', function() {
  var id = $(this).closest('tr').data('id');
  showConfirm('¿Eliminar esta respuesta?', function(ok) {
    if (!ok) return;
    $.post('ajax_ver_gestiones.php', {
      formulario_id: <?= (int)$formulario_id ?>,
      local_id: <?= (int)$local_id ?>,
      action: 'delete_resp',
      id: id
    }, function(html) {
      flashAlert(html);
      loadGestiones('encuesta', currentPageImpl, currentPageResp);
    });
  });
});

$(document).on('click', '.photo-thumb', function() {
  $('#photoModalImg').attr('src', $(this).attr('src'));
  $('#photoModal').modal('show');
});

$(document).on('click', '.delete-photo', function(e) {
  e.stopPropagation();
  var $wrapper = $(this).closest('.photo-thumb-wrapper'),
      photoId = $wrapper.data('photo-id');

  showConfirm('¿Eliminar esta foto?', function(ok) {
    if (!ok) return;
    $.post('ajax_ver_gestiones.php', {
      formulario_id: <?= (int)$formulario_id ?>,
      local_id: <?= (int)$local_id ?>,
      action: 'delete_photo',
      id: photoId
    }, function(html) {
      flashAlert(html);
      $wrapper.remove();
    });
  });
});

$(document).off('click', '.replace-photo');

$(document).on('click', '.replace-photo', function(e) {
  e.stopPropagation();

  var $wrapper = $(this).closest('.photo-thumb-wrapper'),
      photoId = $wrapper.data('photo-id');

  var $input = $('<input type="file" accept="image/*" style="display:none">')
    .one('change', function() {
      var file = this.files[0];
      if (!file) return;

      var $loader = $(
        '<div class="loader">' +
          '<div class="spinner-border spinner-border-sm text-primary" role="status">' +
            '<span class="sr-only">Cargando...</span>' +
          '</div>' +
        '</div>'
      ).appendTo($wrapper);

      var fd = new FormData();
      fd.append('action', 'update_photo');
      fd.append('id', photoId);
      fd.append('formulario_id', <?= (int)$formulario_id ?>);
      fd.append('local_id', <?= (int)$local_id ?>);
      fd.append('file', file);

      $.ajax({
        url: 'ajax_ver_gestiones.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        success: function(html) {
          flashAlert(html);
          var reader = new FileReader();
          reader.onload = function(ev) {
            $wrapper.find('img.photo-thumb').attr('src', ev.target.result);
          };
          reader.readAsDataURL(file);
        },
        error: function() {
          flashAlert('<div class="alert alert-danger">Error al reemplazar foto.</div>');
        },
        complete: function() {
          $loader.remove();
        }
      });

      $input.remove();
    })
    .appendTo('body')
    .click();
});

$(document).on('click', '.add-photo', function(e) {
  e.stopPropagation();

  var $wrapper = $(this).closest('.photo-thumb-wrapper'),
      implId = $wrapper.data('impl-id');

  var $input = $('<input type="file" accept="image/*" style="display:none">')
    .one('change', function() {
      var file = this.files[0];
      if (!file) {
        $input.remove();
        return;
      }

      var $spinner = $('<div class="spinner-border spinner-border-sm text-primary loader" role="status"></div>');
      $wrapper.append($spinner);

      var fd = new FormData();
      fd.append('action', 'add_photo');
      fd.append('id', implId);
      fd.append('formulario_id', <?= (int)$formulario_id ?>);
      fd.append('local_id', <?= (int)$local_id ?>);
      fd.append('file', file);

      $.ajax({
        url: 'ajax_ver_gestiones.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false
      }).always(function() {
        $spinner.remove();
      }).done(function(html) {
        flashAlert(html);
        loadGestiones(currentTab, currentPageImpl, currentPageResp);
      }).fail(function() {
        flashAlert('<div class="alert alert-danger">Error al subir la foto.</div>');
      });

      $input.remove();
    })
    .appendTo('body')
    .click();
});

function reloadAndReopenGestiones(tabToShow) {
  const url = new URL(window.location.href);

  url.searchParams.set('active_tab', 'agregar-entradas');
  url.searchParams.set('open_modal', 'gestiones');
  url.searchParams.set('local_id', String(<?= (int)$local_id ?>));
  url.searchParams.set('user_id', $('#select_ejecutor').val() || '');
  url.searchParams.set('tab', tabToShow || 'impl');
  url.searchParams.set('page_impl', String(currentPageImpl || 1));
  url.searchParams.set('page_resp', String(currentPageResp || 1));

  window.location.href = url.toString();
}
</script>
<?php
$conn->close();
?>