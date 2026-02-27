<?php
declare(strict_types=1);

namespace PanelEncuesta\Controllers;

use PanelEncuesta\Repositories\StatsRepository;
use PanelEncuesta\Services\StatsService;

/**
 * Maneja ajax_pregunta_stats.php — estadísticas agregadas de una pregunta.
 */
class StatsController
{
    private const DEFAULT_RANGE = 7;

    public function __construct(private \mysqli $conn) {}

    public function handle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        // Sesión
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            panel_encuesta_json_response('error', [], 'Sesión expirada', 'session_expired');
            return;
        }

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);
        $t0 = microtime(true);

        $empresa_id = (int)($_SESSION['empresa_id'] ?? 0);
        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $is_mc      = ($user_div === 1);

        // CSRF
        $csrf = is_string($_GET['csrf_token'] ?? null) ? $_GET['csrf_token'] : '';
        if (!panel_encuesta_validate_csrf($csrf)) {
            http_response_code(403);
            panel_encuesta_json_response('error', [], 'Token CSRF inválido.', 'csrf_invalid', $debugId);
            return;
        }

        // Parámetros de pregunta
        $mode_raw = $_GET['mode'] ?? '';
        $mode     = in_array($mode_raw, ['exact', 'set', 'vset'], true) ? $mode_raw : 'exact';
        $qid_raw  = $_GET['id'] ?? '';
        $tipo     = (int)($_GET['tipo'] ?? 0);

        // Parámetros de filtro
        $division    = (int)($_GET['division']   ?? 0);
        $subdivision = (int)($_GET['subdivision']?? 0);
        $form_id     = (int)($_GET['form_id']    ?? 0);
        $clase_tipo  = (int)($_GET['clase_tipo'] ?? 0);
        $desde       = trim($_GET['desde'] ?? '');
        $hasta       = trim($_GET['hasta'] ?? '');
        $distrito    = (int)($_GET['distrito']   ?? 0);
        $jv          = (int)($_GET['jv']         ?? 0);
        $usuario     = (int)($_GET['usuario']    ?? 0);
        $codigo      = trim($_GET['codigo'] ?? '');

        // Validar ID
        if ($mode !== 'vset' && (int)$qid_raw <= 0) {
            http_response_code(400);
            panel_encuesta_json_response('error', [], 'id inválido', 'invalid_id', $debugId);
            return;
        }
        if ($mode === 'vset') {
            $qid_raw = strtolower(trim((string)$qid_raw));
            if (!preg_match('/^[a-f0-9]{32}$/', $qid_raw)) {
                http_response_code(400);
                panel_encuesta_json_response('error', [], 'hash inválido', 'invalid_hash', $debugId);
                return;
            }
        }

        // Fallback de fechas
        $appliedDefaultRange = false;
        if ($desde === '' && $hasta === '' && $form_id === 0) {
            $hasta = date('Y-m-d');
            $desde = date('Y-m-d', strtotime('-' . (self::DEFAULT_RANGE - 1) . ' days'));
            $appliedDefaultRange = true;
        }
        $desdeFull = $desde ? ($desde . ' 00:00:00') : null;
        $hastaFull = $hasta ? (date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00') : null;

        // Construir WHERE base
        $where  = [];
        $types  = '';
        $params = [];

        $where[] = 'f.id_empresa=?'; $types .= 'i'; $params[] = $empresa_id;
        $where[] = 'f.deleted_at IS NULL';
        $where[] = 'fq.deleted_at IS NULL';

        if ($is_mc) {
            if ($division > 0) { $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $division; }
        } else {
            $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $user_div;
        }

        if ($subdivision > 0) { $where[] = 'f.id_subdivision=?'; $types .= 'i'; $params[] = $subdivision; }
        if ($form_id > 0)     { $where[] = 'f.id=?';             $types .= 'i'; $params[] = $form_id; }
        if (in_array($clase_tipo, [1, 3], true)) { $where[] = 'f.tipo=?'; $types .= 'i'; $params[] = $clase_tipo; }

        // Solo visitas finalizadas para stats (consistencia de datos)
        $where[] = 'v.fecha_fin IS NOT NULL';

        if ($desdeFull) { $where[] = 'fqr.created_at >= ?'; $types .= 's'; $params[] = $desdeFull; }
        if ($hastaFull) { $where[] = 'fqr.created_at < ?';  $types .= 's'; $params[] = $hastaFull; }
        if ($distrito > 0) { $where[] = 'l.id_distrito=?';   $types .= 'i'; $params[] = $distrito; }
        if ($jv > 0)       { $where[] = 'l.id_jefe_venta=?'; $types .= 'i'; $params[] = $jv; }
        if ($usuario > 0)  { $where[] = 'u.id=?';            $types .= 'i'; $params[] = $usuario; }
        if ($codigo !== '') { $where[] = 'l.codigo=?';        $types .= 's'; $params[] = $codigo; }

        $whereSqlBase = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Agregar scope de pregunta
        [$whereSql, $typesQ, $paramsQ] = StatsService::appendQuestionScope(
            $whereSqlBase, $types, $params, $mode, $qid_raw
        );

        // Computar stats
        $service = new StatsService(new StatsRepository($this->conn));
        $out     = $service->compute($tipo, $whereSql, $typesQ, $paramsQ);
        $out['meta'] = [
            'default_range' => [
                'applied' => $appliedDefaultRange ? 1 : 0,
                'days'    => self::DEFAULT_RANGE,
            ],
        ];

        $ms = (microtime(true) - $t0) * 1000;
        header('X-QueryTime-ms: ' . number_format($ms, 1, '.', ''));

        echo json_encode([
            'status'     => 'ok',
            'data'       => $out,
            'message'    => '',
            'error_code' => null,
            'debug_id'   => $debugId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
