<?php
declare(strict_types=1);

namespace PanelEncuesta\Repositories;

/**
 * Preguntas: lookup para Select2 + metadata por modo (exact/set/vset).
 * Extrae la lógica de ajax_preguntas_lookup.php y ajax_pregunta_meta.php.
 */
class PreguntaRepository
{
    public function __construct(private \mysqli $conn) {}

    // ------------------------------------------------------------------
    // Lookup (Select2 autocomplete)
    // ------------------------------------------------------------------

    /**
     * Busca preguntas para el Select2 del panel.
     * Retorna hasta 50 resultados (set + vset).
     *
     * @param int    $empresaId
     * @param int    $userDiv      0 si is_mc sin división seleccionada
     * @param bool   $isMc
     * @param int    $division     (solo relevante si is_mc)
     * @param int    $subdivision
     * @param int    $tipo         0 = (1,3)
     * @param int    $formId       0 = global
     * @param string $q            búsqueda libre
     */
    public function lookup(
        int $empresaId,
        int $userDiv,
        bool $isMc,
        int $division,
        int $subdivision,
        int $tipo,
        int $formId,
        string $q
    ): array {
        $where  = [];
        $types  = '';
        $params = [];

        $where[]  = 'f.id_empresa=?';
        $types   .= 'i';
        $params[] = $empresaId;

        $where[] = 'f.deleted_at IS NULL';
        $where[] = 'fq.deleted_at IS NULL';

        if ($isMc) {
            if ($division > 0) { $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $division; }
        } else {
            $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $userDiv;
        }

        if ($subdivision > 0) { $where[] = 'f.id_subdivision=?'; $types .= 'i'; $params[] = $subdivision; }

        if (in_array($tipo, [1, 3], true)) {
            $where[] = 'f.tipo=?'; $types .= 'i'; $params[] = $tipo;
        } else {
            $where[] = 'f.tipo IN (1,3)';
        }

        $global = ($formId === 0);
        if (!$global) {
            $where[] = 'f.id=?'; $types .= 'i'; $params[] = $formId;
        }

        // Filtro por tipo en texto: "type:foto|num|texto|single|multi|bool"
        $typeFilter = null;
        if ($q !== '') {
            if (preg_match('~(?:type|tipo):(foto|num|texto|single|multi|bool)~i', $q, $m)) {
                $map        = ['foto' => 7, 'num' => 5, 'texto' => 4, 'single' => 2, 'multi' => 3, 'bool' => 1];
                $typeFilter = $map[strtolower($m[1])] ?? null;
                $q          = trim(str_replace($m[0], '', $q));
            }
        }
        if ($typeFilter !== null) {
            $where[] = 'fq.id_question_type=?'; $types .= 'i'; $params[] = $typeFilter;
        }
        if ($q !== '') {
            $qNorm   = mb_strtolower($q, 'UTF-8');
            $where[] = '(LOWER(fq.question_text) LIKE ? OR EXISTS (
                SELECT 1 FROM question_set_questions qsq2
                WHERE qsq2.id = fq.id_question_set_question
                AND LOWER(qsq2.question_text) LIKE ?
            ))';
            $types  .= 'ss';
            $params[] = '%' . $qNorm . '%';
            $params[] = '%' . $qNorm . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        if ($global) {
            $sql = "
              SELECT CAST(qsq.id AS CHAR) AS id, COALESCE(qsq.question_text, MIN(fq.question_text)) AS text,
                     NULL AS campana, COUNT(fqr.id) AS cnt,
                     (SELECT fq2.id_question_type FROM form_questions fq2
                      WHERE fq2.id_question_set_question=qsq.id AND fq2.deleted_at IS NULL
                      GROUP BY fq2.id_question_type ORDER BY COUNT(*) DESC LIMIT 1) AS tipo, 'set' AS mode
              FROM form_questions fq
              JOIN formulario f ON f.id=fq.id_formulario
              JOIN question_set_questions qsq ON qsq.id=fq.id_question_set_question
              LEFT JOIN form_question_responses fqr ON fqr.id_form_question=fq.id
              $whereSql AND fq.id_question_set_question IS NOT NULL
              GROUP BY qsq.id
              UNION ALL
              SELECT CONCAT('v:', fq.v_signature) AS id, MIN(fq.question_text) AS text,
                     NULL AS campana, COUNT(fqr.id) AS cnt,
                     (SELECT fq2.id_question_type FROM form_questions fq2
                      WHERE fq2.v_signature=fq.v_signature AND fq2.id_question_set_question IS NULL AND fq2.deleted_at IS NULL
                      GROUP BY fq2.id_question_type ORDER BY COUNT(*) DESC LIMIT 1) AS tipo, 'vset' AS mode
              FROM form_questions fq
              JOIN formulario f ON f.id=fq.id_formulario
              LEFT JOIN form_question_responses fqr ON fqr.id_form_question=fq.id
              $whereSql AND fq.id_question_set_question IS NULL
              GROUP BY fq.v_signature
              ORDER BY cnt DESC, text LIMIT 50
            ";
        } else {
            $sql = "
              SELECT CAST(qsq.id AS CHAR) AS id, COALESCE(qsq.question_text, MIN(fq.question_text)) AS text,
                     f.nombre AS campana, COUNT(fqr.id) AS cnt,
                     (SELECT fq2.id_question_type FROM form_questions fq2
                      WHERE fq2.id_question_set_question=qsq.id AND fq2.deleted_at IS NULL
                      GROUP BY fq2.id_question_type ORDER BY COUNT(*) DESC LIMIT 1) AS tipo, 'set' AS mode
              FROM form_questions fq
              JOIN formulario f ON f.id=fq.id_formulario
              JOIN question_set_questions qsq ON qsq.id=fq.id_question_set_question
              LEFT JOIN form_question_responses fqr ON fqr.id_form_question=fq.id
              $whereSql AND fq.id_question_set_question IS NOT NULL
              GROUP BY qsq.id, f.id
              UNION ALL
              SELECT CONCAT('v:', fq.v_signature) AS id, MIN(fq.question_text) AS text,
                     f.nombre AS campana, COUNT(fqr.id) AS cnt,
                     (SELECT fq2.id_question_type FROM form_questions fq2
                      WHERE fq2.v_signature=fq.v_signature AND fq2.id_question_set_question IS NULL AND fq2.deleted_at IS NULL
                      GROUP BY fq2.id_question_type ORDER BY COUNT(*) DESC LIMIT 1) AS tipo, 'vset' AS mode
              FROM form_questions fq
              JOIN formulario f ON f.id=fq.id_formulario
              LEFT JOIN form_question_responses fqr ON fqr.id_form_question=fq.id
              $whereSql AND fq.id_question_set_question IS NULL
              GROUP BY fq.v_signature, f.id
              ORDER BY cnt DESC, text LIMIT 50
            ";
        }

        // UNION duplica WHERE, so duplicate params
        $typesBind  = $types . $types;
        $paramsBind = array_merge($params, $params);

        $st = $this->conn->prepare($sql);
        if ($typesBind) {
            $st->bind_param($typesBind, ...$paramsBind);
        }
        $st->execute();
        $rs  = $st->get_result();
        $out = [];
        while ($r = $rs->fetch_assoc()) {
            $out[] = [
                'id'      => $r['id'],
                'text'    => $r['text'],
                'campana' => $r['campana'] ?? null,
                'count'   => (int)($r['cnt'] ?? 0),
                'tipo'    => isset($r['tipo']) ? (int)$r['tipo'] : null,
                'mode'    => $r['mode'],
            ];
        }
        $st->close();
        return $out;
    }

    // ------------------------------------------------------------------
    // Meta (tipo + opciones) por modo
    // ------------------------------------------------------------------

    public function getMeta(
        int $empresaId,
        int $userDiv,
        bool $isMc,
        int $division,
        int $subdivision,
        int $tipoScope,
        int $formId,
        string $mode,
        string $idParam
    ): array {
        $where  = [];
        $types  = '';
        $params = [];

        $where[]  = 'f.id_empresa=?'; $types .= 'i'; $params[] = $empresaId;

        if ($isMc) {
            if ($division > 0) { $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $division; }
        } else {
            $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $userDiv;
        }

        if ($subdivision > 0) { $where[] = 'f.id_subdivision=?'; $types .= 'i'; $params[] = $subdivision; }

        if (in_array($tipoScope, [1, 3], true)) {
            $where[] = 'f.tipo=?'; $types .= 'i'; $params[] = $tipoScope;
        } else {
            $where[] = 'f.tipo IN (1,3)';
        }

        if ($formId > 0 && $mode === 'exact') {
            $where[] = 'f.id=?'; $types .= 'i'; $params[] = $formId;
        }

        $whereSql = $where ? (' AND ' . implode(' AND ', $where)) : '';

        $out = [
            'mode'        => $mode,
            'id'          => null,
            'tipo'        => null,
            'tipo_texto'  => null,
            'has_options' => false,
            'options'     => [],
            'supports'    => ['text' => false, 'numeric' => false, 'photo' => false],
        ];

        if ($mode === 'vset') {
            return $this->getMetaVset($out, $idParam, $types, $params, $whereSql);
        }
        if ($mode === 'exact') {
            return $this->getMetaExact($out, (int)$idParam, $types, $params, $whereSql);
        }
        if ($mode === 'set') {
            return $this->getMetaSet($out, (int)$idParam, $types, $params, $whereSql);
        }

        return $out;
    }

    // ---- private meta helpers ----

    private function getMetaVset(array $out, string $hash, string $types, array $params, string $whereSql): array
    {
        $sql = "
          SELECT fq.id_question_type AS tipo, COUNT(*) c
            FROM form_questions fq JOIN formulario f ON f.id=fq.id_formulario
           WHERE (fq.id_question_set_question IS NULL OR fq.id_question_set_question=0)
             AND fq.v_signature=? $whereSql
           GROUP BY fq.id_question_type ORDER BY c DESC LIMIT 1
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('s' . $types, $hash, ...$params);
        $st->execute();
        $rs  = $st->get_result();
        $row = $rs->fetch_assoc();
        $st->close();
        $tipo = (int)($row['tipo'] ?? 4);

        $opts = [];
        $sql  = "
          SELECT DISTINCT fo.option_text AS text
            FROM form_questions fq JOIN formulario f ON f.id=fq.id_formulario
            JOIN form_question_options fo ON fo.id_form_question=fq.id
           WHERE (fq.id_question_set_question IS NULL OR fq.id_question_set_question=0)
             AND fq.v_signature=? $whereSql
           ORDER BY text
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('s' . $types, $hash, ...$params);
        $st->execute();
        $rs = $st->get_result();
        while ($o = $rs->fetch_assoc()) {
            if ($o['text'] !== '') {
                $opts[] = ['id' => null, 'text' => $o['text']];
            }
        }
        $st->close();

        if (empty($opts) && $tipo === 1) {
            $opts = [['id' => 1, 'text' => 'Sí'], ['id' => 0, 'text' => 'No']];
        }

        $out['id']                 = $hash;
        $out['tipo']               = $tipo;
        $out['tipo_texto']         = $this->tipoTexto($tipo);
        $out['supports']['text']   = ($tipo === 4);
        $out['supports']['numeric']= ($tipo === 5);
        $out['supports']['photo']  = ($tipo === 7);
        $out['has_options']        = count($opts) > 0;
        $out['options']            = $opts;
        return $out;
    }

    private function getMetaExact(array $out, int $id, string $types, array $params, string $whereSql): array
    {
        $sql = "
          SELECT fq.id, fq.id_question_type AS tipo, fq.id_question_set_question AS qset_id
            FROM form_questions fq JOIN formulario f ON f.id=fq.id_formulario
           WHERE fq.id=? $whereSql LIMIT 1
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('i' . $types, $id, ...$params);
        $st->execute();
        $rs   = $st->get_result();
        $qrow = $rs->fetch_assoc();
        $st->close();

        if (!$qrow) {
            return array_merge($out, ['_not_found' => true]);
        }

        $out['id']                   = (int)$id;
        $out['tipo']                 = (int)$qrow['tipo'];
        $out['tipo_texto']           = $this->tipoTexto($out['tipo']);
        $out['supports']['text']     = ($out['tipo'] === 4);
        $out['supports']['numeric']  = ($out['tipo'] === 5);
        $out['supports']['photo']    = ($out['tipo'] === 7);

        $qset_id    = (int)($qrow['qset_id'] ?? 0);
        $gotOptions = false;
        if ($qset_id > 0) {
            $st = $this->conn->prepare("SELECT id, option_text AS text FROM question_set_options WHERE id_question_set_question=? ORDER BY sort_order, id");
            $st->bind_param('i', $qset_id);
            $st->execute();
            $rs = $st->get_result();
            while ($o = $rs->fetch_assoc()) {
                $out['options'][] = ['id' => (int)$o['id'], 'text' => $o['text']];
            }
            $st->close();
            $gotOptions = count($out['options']) > 0;
        }
        if (!$gotOptions) {
            $st = $this->conn->prepare("SELECT id, option_text AS text FROM form_question_options WHERE id_form_question=? ORDER BY sort_order, id");
            $st->bind_param('i', $id);
            $st->execute();
            $rs = $st->get_result();
            while ($o = $rs->fetch_assoc()) {
                $out['options'][] = ['id' => (int)$o['id'], 'text' => $o['text']];
            }
            $st->close();
        }
        if (empty($out['options']) && $out['tipo'] === 1) {
            $out['options'] = [['id' => 1, 'text' => 'Sí'], ['id' => 0, 'text' => 'No']];
        }
        $out['has_options'] = count($out['options']) > 0;
        return $out;
    }

    private function getMetaSet(array $out, int $id, string $types, array $params, string $whereSql): array
    {
        $sql = "
          SELECT fq.id_question_set_question AS qset_id, fq.id_question_type AS tipo
            FROM form_questions fq JOIN formulario f ON f.id=fq.id_formulario
           WHERE fq.id_question_set_question=? $whereSql LIMIT 1
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('i' . $types, $id, ...$params);
        $st->execute();
        $rs  = $st->get_result();
        $any = $rs->fetch_assoc();
        $st->close();

        if (!$any) {
            return array_merge($out, ['_not_found' => true]);
        }

        $sql = "
          SELECT fq.id_question_type AS tipo, COUNT(*) c
            FROM form_questions fq JOIN formulario f ON f.id=fq.id_formulario
           WHERE fq.id_question_set_question=? $whereSql
           GROUP BY fq.id_question_type ORDER BY c DESC LIMIT 1
        ";
        $st = $this->conn->prepare($sql);
        $st->bind_param('i' . $types, $id, ...$params);
        $st->execute();
        $rs      = $st->get_result();
        $rowTipo = $rs->fetch_assoc();
        $st->close();

        $out['id']                  = (int)$id;
        $out['tipo']                = (int)($rowTipo['tipo'] ?? $any['tipo'] ?? 4);
        $out['tipo_texto']          = $this->tipoTexto($out['tipo']);
        $out['supports']['text']    = ($out['tipo'] === 4);
        $out['supports']['numeric'] = ($out['tipo'] === 5);
        $out['supports']['photo']   = ($out['tipo'] === 7);

        $st = $this->conn->prepare("SELECT id, option_text AS text FROM question_set_options WHERE id_question_set_question=? ORDER BY sort_order, id");
        $st->bind_param('i', $id);
        $st->execute();
        $rs = $st->get_result();
        while ($o = $rs->fetch_assoc()) {
            $out['options'][] = ['id' => (int)$o['id'], 'text' => $o['text']];
        }
        $st->close();

        if (empty($out['options']) && $out['tipo'] === 1) {
            $out['options'] = [['id' => 1, 'text' => 'Sí'], ['id' => 0, 'text' => 'No']];
        }
        $out['has_options'] = count($out['options']) > 0;
        return $out;
    }

    // ------------------------------------------------------------------
    // Factory preset resolution
    // ------------------------------------------------------------------

    /**
     * Resuelve los items de un factory preset usando el scope definido en el preset
     * (no el estado actual del formulario), pre-fetcheando metadata desde el servidor.
     *
     * Items con '_not_found' son omitidos silenciosamente.
     * Items resueltos incluyen '_resolved' => true para que JS los aplique sin AJAX.
     *
     * @param array $items       [{mode, qset_id, label}]
     * @param int   $empresaId
     * @param int   $userDiv     División del usuario (de sesión)
     * @param bool  $isMc
     * @param int   $division    División seleccionada (relevante para MC; 0 = sin filtro)
     * @param array $scope       ['subdivision' => int, 'tipo' => int, 'form_id' => int]
     * @return array Resolved items con metadata embebida
     */
    public function resolvePresetItems(
        array $items,
        int $empresaId,
        int $userDiv,
        bool $isMc,
        int $division,
        array $scope
    ): array {
        // Construir WHERE idéntico a getMeta() para mode='set'
        $where  = [];
        $types  = '';
        $params = [];

        $where[]  = 'f.id_empresa=?';   $types .= 'i'; $params[] = $empresaId;
        $where[]  = 'f.deleted_at IS NULL';
        $where[]  = 'fq.deleted_at IS NULL';

        if ($isMc) {
            if ($division > 0) { $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $division; }
        } else {
            $where[] = 'f.id_division=?'; $types .= 'i'; $params[] = $userDiv;
        }

        $sub = (int)($scope['subdivision'] ?? 0);
        if ($sub > 0) {
            $where[] = 'f.id_subdivision=?'; $types .= 'i'; $params[] = $sub;
        }

        $tipoScope = (int)($scope['tipo'] ?? 0);
        if (in_array($tipoScope, [1, 3], true)) {
            $where[] = 'f.tipo=?'; $types .= 'i'; $params[] = $tipoScope;
        } else {
            $where[] = 'f.tipo IN (1,3)';
        }

        $whereSql = ' AND ' . implode(' AND ', $where);

        $resolved = [];
        foreach ($items as $item) {
            if (($item['mode'] ?? '') !== 'set' || !isset($item['qset_id'])) {
                continue;
            }

            $out = [
                'mode'        => 'set',
                'id'          => null,
                'tipo'        => null,
                'tipo_texto'  => null,
                'has_options' => false,
                'options'     => [],
                'supports'    => ['text' => false, 'numeric' => false, 'photo' => false],
            ];

            $meta = $this->getMetaSet($out, (int)$item['qset_id'], $types, $params, $whereSql);

            if (isset($meta['_not_found'])) {
                continue; // pregunta no existe en este scope → omitir
            }

            $resolved[] = [
                'mode'        => 'set',
                'id'          => $meta['id'],
                'label'       => $item['label'] ?? ('Pregunta ' . $item['qset_id']),
                'tipo'        => $meta['tipo'],
                'tipo_texto'  => $meta['tipo_texto'],
                'has_options' => $meta['has_options'],
                'options'     => $meta['options'],
                '_resolved'   => true,
            ];
        }

        return $resolved;
    }

    private function tipoTexto(int $t): string
    {
        return [
            1 => 'Sí/No', 2 => 'Selección única', 3 => 'Selección múltiple',
            4 => 'Texto', 5 => 'Numérico', 6 => 'Fecha', 7 => 'Foto',
        ][$t] ?? 'Otro';
    }
}
