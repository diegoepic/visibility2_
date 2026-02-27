<?php
declare(strict_types=1);

namespace PanelEncuesta\Controllers;

use PanelEncuesta\Repositories\LookupRepository;
use PanelEncuesta\Repositories\PreguntaRepository;

/**
 * Maneja todos los endpoints ajax_*.php de catálogos/lookups:
 *  - ajax_campanas_por_div_sub.php  → handleCampanas()
 *  - ajax_distritos_por_division.php → handleDistritos()
 *  - ajax_jefes_por_division.php    → handleJefes()
 *  - ajax_subdivisiones.php         → handleSubdivisiones()
 *  - ajax_preguntas_lookup.php      → handlePreguntas()
 *  - ajax_pregunta_meta.php         → handlePreguntaMeta()
 *  - ajax_detalle_local_panel.php   → handleDetalleLocal()
 */
class LookupController
{
    public function __construct(private \mysqli $conn) {}

    // ------------------------------------------------------------------
    // Campañas / formularios
    // ------------------------------------------------------------------

    public function handleCampanas(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Sesión expirada']); return;
        }

        $empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $is_mc      = ($user_div === 1);

        $division    = (int)($_GET['division']    ?? 0);
        $subdivision = (int)($_GET['subdivision'] ?? 0);
        $tipo        = (int)($_GET['tipo']        ?? 0);

        if (!$is_mc) { $division = $user_div; }

        $repo = new LookupRepository($this->conn);
        $out  = $repo->getCampanas($empresa_id, $division, $subdivision, $tipo, $is_mc);
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Distritos
    // ------------------------------------------------------------------

    public function handleDistritos(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Sesión expirada']); return;
        }

        $empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $is_mc      = ($user_div === 1);

        $div = (int)($_GET['division'] ?? 0);
        if (!$is_mc) { $div = $user_div; }

        if ($div <= 0) { echo json_encode([]); return; }

        $repo = new LookupRepository($this->conn);
        echo json_encode($repo->getDistritos($div, $empresa_id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Jefes de venta
    // ------------------------------------------------------------------

    public function handleJefes(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Sesión expirada']); return;
        }

        $empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $is_mc      = ($user_div === 1);

        $div = (int)($_GET['division'] ?? 0);
        if (!$is_mc) { $div = $user_div; }

        if ($div <= 0) { echo json_encode([]); return; }

        $repo = new LookupRepository($this->conn);
        echo json_encode($repo->getJefes($div, $empresa_id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Subdivisiones
    // ------------------------------------------------------------------

    public function handleSubdivisiones(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Sesión expirada']); return;
        }

        $empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $is_mc      = ($user_div === 1);

        $division = (int)($_GET['division'] ?? 0);
        if (!$is_mc) { $division = $user_div; }

        if ($division <= 0) { echo json_encode([]); return; }

        $repo = new LookupRepository($this->conn);
        echo json_encode($repo->getSubdivisiones($division, $empresa_id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Preguntas lookup (Select2)
    // ------------------------------------------------------------------

    public function handlePreguntas(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            panel_encuesta_json_response('error', [], 'Sesión expirada', 'session_expired'); return;
        }

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);

        $csrf = is_string($_GET['csrf_token'] ?? null) ? $_GET['csrf_token'] : '';
        if (!panel_encuesta_validate_csrf($csrf)) {
            http_response_code(403);
            panel_encuesta_json_response('error', [], 'Token CSRF inválido.', 'csrf_invalid', $debugId); return;
        }

        $empresa_id  = (int)($_SESSION['empresa_id']  ?? 0);
        $user_div    = (int)($_SESSION['division_id'] ?? 0);
        $is_mc       = ($user_div === 1);

        $q           = trim($_GET['q']           ?? '');
        $division    = (int)($_GET['division']   ?? 0);
        $subdivision = (int)($_GET['subdivision']?? 0);
        $tipo        = (int)($_GET['tipo']        ?? 0);
        $form_id     = (int)($_GET['form_id']    ?? 0);

        $repo = new PreguntaRepository($this->conn);
        $out  = $repo->lookup($empresa_id, $user_div, $is_mc, $division, $subdivision, $tipo, $form_id, $q);

        echo json_encode([
            'status' => 'ok', 'data' => $out, 'message' => '',
            'error_code' => null, 'debug_id' => $debugId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Pregunta meta (tipo + opciones por modo)
    // ------------------------------------------------------------------

    public function handlePreguntaMeta(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            panel_encuesta_json_response('error', [], 'Sesión expirada', 'session_expired'); return;
        }

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);

        $csrf = is_string($_GET['csrf_token'] ?? null) ? $_GET['csrf_token'] : '';
        if (!panel_encuesta_validate_csrf($csrf)) {
            http_response_code(403);
            panel_encuesta_json_response('error', [], 'Token CSRF inválido.', 'csrf_invalid', $debugId); return;
        }

        $empresa_id  = (int)($_SESSION['empresa_id']  ?? 0);
        $user_div    = (int)($_SESSION['division_id'] ?? 0);
        $is_mc       = ($user_div === 1);

        $mode        = strtolower(trim($_GET['mode']  ?? 'exact'));
        $idParam     = $_GET['id'] ?? null;
        $division    = (int)($_GET['division']    ?? 0);
        $subdivision = (int)($_GET['subdivision'] ?? 0);
        $tipo_scope  = (int)($_GET['tipo']        ?? 0);
        $form_id     = (int)($_GET['form_id']     ?? 0);

        if (!in_array($mode, ['exact', 'set', 'vset'], true)) {
            http_response_code(400);
            panel_encuesta_json_response('error', [], 'mode inválido', 'invalid_mode', $debugId); return;
        }
        if ($mode !== 'vset' && (int)$idParam <= 0) {
            http_response_code(400);
            panel_encuesta_json_response('error', [], 'id inválido', 'invalid_id', $debugId); return;
        }
        if ($mode === 'vset') {
            $idParam = strtolower(trim((string)$idParam));
            if (!preg_match('/^[a-f0-9]{32}$/', $idParam)) {
                http_response_code(400);
                panel_encuesta_json_response('error', [], 'hash inválido', 'invalid_hash', $debugId); return;
            }
        }

        $repo = new PreguntaRepository($this->conn);
        $out  = $repo->getMeta(
            $empresa_id, $user_div, $is_mc,
            $division, $subdivision, $tipo_scope, $form_id,
            $mode, (string)$idParam
        );

        if (!empty($out['_not_found'])) {
            unset($out['_not_found']);
            http_response_code(404);
            panel_encuesta_json_response('error', [], 'No encontrado', 'not_found', $debugId); return;
        }

        echo json_encode([
            'status' => 'ok', 'data' => $out, 'message' => '',
            'error_code' => null, 'debug_id' => $debugId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------
    // Detalle de local (modal)
    // ------------------------------------------------------------------

    public function handleDetalleLocal(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Sesión expirada']); return;
        }

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);

        $csrf = is_string($_GET['csrf_token'] ?? null) ? $_GET['csrf_token'] : '';
        if (!panel_encuesta_validate_csrf($csrf)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Token CSRF inválido.', 'debug_id' => $debugId]); return;
        }

        $empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
        $local_id   = (int)($_GET['local_id'] ?? 0);
        $form_id    = (int)($_GET['form_id']  ?? 0);

        if ($local_id <= 0 || $form_id <= 0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'local_id y form_id son requeridos.', 'debug_id' => $debugId]); return;
        }

        $modelPath = $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_formulario/models/DetalleLocalModel.php';
        if (!file_exists($modelPath)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'DetalleLocalModel no encontrado.', 'debug_id' => $debugId]); return;
        }
        require_once $modelPath;

        $t0 = microtime(true);
        try {
            $model   = new \DetalleLocalModel($this->conn);
            $detalle = $model->getDetalle($empresa_id, $form_id, $local_id);
            header('X-QueryTime-ms: ' . round((microtime(true) - $t0) * 1000, 1));
            echo json_encode([
                'status' => 'ok', 'data' => $detalle, 'debug_id' => $debugId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error interno: ' . $e->getMessage(), 'debug_id' => $debugId], JSON_UNESCAPED_UNICODE);
        }
    }
}
