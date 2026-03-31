<?php
declare(strict_types=1);

namespace PanelEncuesta\Controllers;

use PanelEncuesta\Repositories\ResponseRepository;
use PanelEncuesta\Services\FilterService;
use PanelEncuesta\ValueObjects\FilterParams;

/**
 * Maneja panel_encuesta_data.php — datos paginados del panel.
 */
class DataController
{
    private const MAX_TOTAL_ROWS   = 30000;
    private const COUNT_LIMIT_ROWS = 30001;
    private const DEFAULT_RANGE    = 7;
    private const MAX_RANGE_NO_SCOPE = 31;
    private const MAX_QFILTERS     = 5;
    private const MAX_QFILTER_VALS = 50;

    public function __construct(private \mysqli $conn) {}

    public function handle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        // 1. Sesión
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            panel_encuesta_json_response('error', [], 'Sesión expirada', 'session_expired');
            return;
        }

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);
        $t0 = microtime(true);

        // 2. CSRF
        $csrf = is_string($_GET['csrf_token'] ?? null) ? $_GET['csrf_token'] : '';
        if (!panel_encuesta_validate_csrf($csrf)) {
            http_response_code(403);
            panel_encuesta_json_response('error', [], 'Token CSRF inválido.', 'csrf_invalid', $debugId);
            return;
        }

        // 3. Params
        $fp = FilterParams::fromRequest($_GET, $_SESSION, [
            'enforce_date_fallback'   => true,
            'default_range_days'      => self::DEFAULT_RANGE,
            'max_range_days_no_scope' => self::MAX_RANGE_NO_SCOPE,
            'max_qfilters'            => self::MAX_QFILTERS,
            'max_qfilter_values'      => self::MAX_QFILTER_VALS,
        ]);

        // 4. WHERE
        $fs    = new FilterService();
        $where = $fs->build($fp);

        $meta = $where->meta;
        $appliedDefaultRange = (bool)$meta['applied_30d_default'];
        $rangeRiskyNoScope   = (bool)$meta['range_risky_no_scope'];

        // 5. Data
        $repo  = new ResponseRepository($this->conn);
        $rows  = $repo->fetchPage($where, $fp->limit, $fp->offset);

        // 6. COUNT capped
        $countLimited = $repo->countCapped($where, self::COUNT_LIMIT_ROWS);
        $total        = min($countLimited, self::MAX_TOTAL_ROWS);
        $truncated    = ($countLimited > self::MAX_TOTAL_ROWS);

        // 7. Facets + resumen únicos (solo si se piden y hay datos)
        $facets = null;
        $uniq   = null;
        if ($fp->wantFacets && $total > 0) {
            $facets = $repo->fetchFacets($where);
            $uniq   = $repo->fetchUniqueSummary($where);
        }

        // 8. Timing
        $ms = (microtime(true) - $t0) * 1000;
        header('X-QueryTime-ms: ' . number_format($ms, 1, '.', ''));

        // 9. Log
        if (function_exists('log_panel_encuesta_query')) {
            try {
                log_panel_encuesta_query($this->conn, 'panel', $total, [
                    'duration_sec'        => ($ms / 1000),
                    'has_qfilters'        => $meta['has_qfilters'],
                    'applied_30d_default' => $appliedDefaultRange ? 1 : 0,
                    'from'                => $meta['from'],
                    'to'                  => $meta['to'],
                ]);
            } catch (\Throwable) {}
        }

        // 10. Respuesta
        echo json_encode([
            'status'     => 'ok',
            'data'       => $rows,
            'total'      => $total,
            'page'       => $fp->page,
            'per_page'   => $fp->limit,
            'facets'     => $facets,
            'message'    => '',
            'error_code' => null,
            'debug_id'   => $debugId,
            'meta'       => [
                'count_limit_rows' => self::COUNT_LIMIT_ROWS,
                'max_total_rows'   => self::MAX_TOTAL_ROWS,
                'truncated_total'  => $truncated ? 1 : 0,
                'default_range'    => [
                    'applied' => $appliedDefaultRange ? 1 : 0,
                    'days'    => self::DEFAULT_RANGE,
                ],
                'range' => [
                    'days'              => $meta['range_days'],
                    'has_scope'         => ($meta['has_scope'] ?? false) ? 1 : 0,
                    'risky_no_scope'    => $rangeRiskyNoScope ? 1 : 0,
                    'max_days_no_scope' => self::MAX_RANGE_NO_SCOPE,
                ],
                'qfilters_match' => $meta['qfilters_match'] ?? 'all',
                'uniq'           => $uniq,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
