<?php
declare(strict_types=1);

namespace PanelEncuesta\Controllers;

use PanelEncuesta\Services\FilterService;
use PanelEncuesta\ValueObjects\FilterParams;

/**
 * Maneja ajax_locales_geo.php — retorna locales con coordenadas para el mapa.
 */
class GeoController
{
    private const MAX_LOCALES = 2000;

    public function __construct(private \mysqli $conn) {}

    public function handle(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Sesión expirada']);
            return;
        }

        $csrf = is_string($_GET['csrf_token'] ?? null) ? $_GET['csrf_token'] : '';
        if (!panel_encuesta_validate_csrf($csrf)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Token CSRF inválido.']);
            return;
        }

        $fp    = FilterParams::fromRequest($_GET, $_SESSION, [
            'enforce_date_fallback'   => true,
            'default_range_days'      => 7,
            'max_range_days_no_scope' => 31,
            'max_qfilters'            => 5,
            'max_qfilter_values'      => 50,
        ]);
        $fs    = new FilterService();
        $where = $fs->build($fp);

        $sql = "
            SELECT
                l.id,
                l.codigo,
                l.nombre,
                l.lat,
                l.lng,
                l.direccion,
                c.nombre AS cadena,
                jv.nombre AS jefe_venta,
                COUNT(DISTINCT fqr.visita_id) AS visitas,
                MAX(COALESCE(v.fecha_fin, fqr.created_at)) AS ultima_visita
            FROM form_question_responses fqr
            JOIN form_questions fq   ON fq.id  = fqr.id_form_question
            JOIN formulario f        ON f.id   = fq.id_formulario
            JOIN local l             ON l.id   = fqr.id_local
            LEFT JOIN cadena c       ON c.id   = l.id_cadena
            LEFT JOIN distrito d     ON d.id   = l.id_distrito
            LEFT JOIN jefe_venta jv  ON jv.id  = l.id_jefe_venta
            JOIN usuario u           ON u.id   = fqr.id_usuario
            LEFT JOIN form_question_options o ON o.id = fqr.id_option
            JOIN visita v            ON v.id   = fqr.visita_id
            " . $where->sql . "
              AND l.lat  IS NOT NULL AND l.lat  <> 0
              AND l.lng  IS NOT NULL AND l.lng  <> 0
            GROUP BY l.id, l.codigo, l.nombre, l.lat, l.lng, l.direccion, c.nombre, jv.nombre
            ORDER BY ultima_visita DESC
            LIMIT " . self::MAX_LOCALES;

        $st = $this->conn->prepare($sql);
        if ($where->types) {
            $st->bind_param($where->types, ...$where->params);
        }
        $st->execute();
        $rs   = $st->get_result();
        $rows = [];
        while ($r = $rs->fetch_assoc()) {
            $rows[] = [
                'id'           => (int)$r['id'],
                'codigo'       => $r['codigo'],
                'nombre'       => $r['nombre'],
                'lat'          => (float)$r['lat'],
                'lng'          => (float)$r['lng'],
                'direccion'    => $r['direccion'],
                'cadena'       => $r['cadena'],
                'jefe_venta'   => $r['jefe_venta'],
                'visitas'      => (int)$r['visitas'],
                'ultima_visita' => $r['ultima_visita'],
            ];
        }
        $st->close();

        echo json_encode(['status' => 'ok', 'data' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
