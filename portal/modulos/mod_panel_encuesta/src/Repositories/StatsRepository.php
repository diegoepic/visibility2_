<?php
declare(strict_types=1);

namespace PanelEncuesta\Repositories;

/**
 * Queries de estadísticas de preguntas por tipo.
 * Extrae la lógica de ajax_pregunta_stats.php.
 *
 * Nota: cada método recibe $whereSql/$types/$params ya construidos
 * (incluyendo el filtro de pregunta por modo exact/set/vset).
 */
class StatsRepository
{
    private const BASE_FROM = "
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      JOIN formulario f      ON f.id  = fq.id_formulario
      JOIN local l           ON l.id  = fqr.id_local
      JOIN usuario u         ON u.id  = fqr.id_usuario
      JOIN visita v          ON v.id  = fqr.visita_id
    LEFT JOIN form_question_options o ON o.id = fqr.id_option
    ";

    public function __construct(private \mysqli $conn) {}

    /**
     * Tipo 1 — Sí/No
     */
    public function fetchBinary(string $whereSql, string $types, array $params): array
    {
        $label_ci = "LOWER(TRIM(COALESCE(o.option_text, fqr.answer_text))) COLLATE utf8_general_ci";
        $sql = "
          SELECT
            SUM(CASE WHEN ($label_ci='si' OR fqr.valor=1) THEN 1 ELSE 0 END) AS si_cnt,
            SUM(CASE WHEN ($label_ci='no' OR fqr.valor=0) THEN 1 ELSE 0 END) AS no_cnt,
            COUNT(*) AS total
          " . self::BASE_FROM . " $whereSql
        ";
        $st = $this->conn->prepare($sql);
        if ($types) $st->bind_param($types, ...$params);
        $st->execute();
        $st->bind_result($si, $no, $tot);
        $st->fetch();
        $st->close();
        return [
            'total'   => (int)$tot,
            'buckets' => [
                ['key' => '1', 'label' => 'Sí', 'count' => (int)$si],
                ['key' => '0', 'label' => 'No', 'count' => (int)$no],
            ],
            'numeric' => null,
        ];
    }

    /**
     * Tipos 2/3 — Única/Múltiple: agrupa por texto normalizado
     */
    public function fetchOptions(string $whereSql, string $types, array $params): array
    {
        $label_raw = "TRIM(COALESCE(o.option_text, fqr.answer_text))";
        $key_norm  = "LOWER($label_raw) COLLATE utf8_general_ci";
        $sql = "
          SELECT label, cnt FROM (
            SELECT MIN(lbl) AS label, COUNT(*) AS cnt FROM (
              SELECT $key_norm AS k, $label_raw AS lbl
              " . self::BASE_FROM . " $whereSql
                AND $label_raw IS NOT NULL AND $label_raw <> ''
            ) t GROUP BY k
          ) agg ORDER BY cnt DESC, label LIMIT 100
        ";
        $st = $this->conn->prepare($sql);
        if ($types) $st->bind_param($types, ...$params);
        $st->execute();
        $rs  = $st->get_result();
        $b   = [];
        $sum = 0;
        while ($r = $rs->fetch_assoc()) {
            $cnt = (int)$r['cnt'];
            $b[] = ['key' => $r['label'], 'label' => $r['label'], 'count' => $cnt];
            $sum += $cnt;
        }
        $st->close();
        return ['total' => $sum, 'buckets' => $b, 'numeric' => null];
    }

    /**
     * Tipo 5 — Numérico
     */
    public function fetchNumeric(string $whereSql, string $types, array $params): array
    {
        $sql = "
          SELECT COUNT(*) AS cnt, MIN(valor) AS vmin, MAX(valor) AS vmax, AVG(valor) AS vavg
          " . self::BASE_FROM . " $whereSql AND fqr.valor IS NOT NULL
        ";
        $st = $this->conn->prepare($sql);
        if ($types) $st->bind_param($types, ...$params);
        $st->execute();
        $st->bind_result($cnt, $vmin, $vmax, $vavg);
        $st->fetch();
        $st->close();
        return [
            'total'   => (int)$cnt,
            'buckets' => [],
            'numeric' => [
                'count' => (int)$cnt,
                'min'   => $vmin !== null ? (float)$vmin : null,
                'max'   => $vmax !== null ? (float)$vmax : null,
                'avg'   => $vavg !== null ? (float)$vavg : null,
            ],
        ];
    }

    /**
     * Tipo 7 — Foto: con/sin foto
     */
    public function fetchPhoto(string $whereSql, string $types, array $params): array
    {
        $sql = "
          SELECT
            SUM(CASE WHEN (fqr.answer_text IS NOT NULL AND fqr.answer_text<>'') THEN 1 ELSE 0 END) AS con_foto,
            SUM(CASE WHEN (fqr.answer_text IS NULL OR fqr.answer_text='') THEN 1 ELSE 0 END)       AS sin_foto,
            COUNT(*) AS total
          " . self::BASE_FROM . " $whereSql
        ";
        $st = $this->conn->prepare($sql);
        if ($types) $st->bind_param($types, ...$params);
        $st->execute();
        $st->bind_result($cf, $sf, $tot);
        $st->fetch();
        $st->close();
        return [
            'total'   => (int)$tot,
            'buckets' => [
                ['key' => '1', 'label' => 'Con foto', 'count' => (int)$cf],
                ['key' => '0', 'label' => 'Sin foto', 'count' => (int)$sf],
            ],
            'numeric' => null,
        ];
    }

    /**
     * Fallback — total simple para tipos no reconocidos
     */
    public function fetchFallback(string $whereSql, string $types, array $params): array
    {
        $sql = "SELECT COUNT(*) AS cnt " . self::BASE_FROM . " $whereSql";
        $st  = $this->conn->prepare($sql);
        if ($types) $st->bind_param($types, ...$params);
        $st->execute();
        $st->bind_result($cnt);
        $st->fetch();
        $st->close();
        return ['total' => (int)$cnt, 'buckets' => [], 'numeric' => null];
    }
}
