<?php
// panel/api/dataset_core.php
require __DIR__.'/_db.php';

/**
 * build_dataset($mysqli, $payload)
 *  - Devuelve el mismo JSON que /api/data.php
 *  - Reutilizado por export_excel/pdf
 */
function build_dataset(mysqli $mysqli, array $payload): array {
    $mode            = (string)($payload['mode'] ?? 'global');
    $set_qids        = ints((array)($payload['set_qids'] ?? []));
    $form_qids       = ints((array)($payload['form_question_ids'] ?? []));
    $signatures      = (array)($payload['signatures'] ?? []); // opcional modo global ad-hoc
    $include_adhoc   = intval($payload['include_adhoc'] ?? 0) === 1;

    $division_ids    = ints((array)($payload['division_ids'] ?? []));
    $subdiv_ids      = ints((array)($payload['subdivision_ids'] ?? []));
    $campaign_ids    = ints((array)($payload['campaign_ids'] ?? []));
    $tipos_form      = ints((array)($payload['tipos'] ?? []));
    $distrito_ids    = ints((array)($payload['distrito_ids'] ?? []));
    $jefe_venta_ids  = ints((array)($payload['jefe_venta_ids'] ?? []));
    $usuario_ids     = ints((array)($payload['usuario_ids'] ?? []));
    $fecha_desde     = $payload['fecha_desde'] ?? null;
    $fecha_hasta     = $payload['fecha_hasta'] ?? null;
    $local_codigo    = trim((string)($payload['local_codigo'] ?? ''));

    $group_by        = (array)($payload['group_by'] ?? ['campania']);

    // Dims
    $dimCols = [];
    foreach ($group_by as $g) {
        switch ($g) {
            case 'division':     $dimCols['division']     = 'f.id_division'; break;
            case 'subdivision':  $dimCols['subdivision']  = 'f.id_subdivision'; break;
            case 'campania':     $dimCols['campania']     = 'f.id'; break;
            case 'distrito':     $dimCols['distrito']     = 'l.id_distrito'; break;
            case 'jefe_venta':   $dimCols['jefe_venta']   = 'l.id_jefe_venta'; break;
            case 'usuario':      $dimCols['usuario']      = 'u.id'; break;
            case 'local':        $dimCols['local']        = 'l.id'; break;
        }
    }
    $dimSelect = $dimGroup = $dimOrder = '';
    if ($dimCols) {
        $sel=[]; $grp=[]; $ord=[];
        foreach ($dimCols as $alias=>$col) { $sel[]="$col AS $alias"; $grp[]=$col; $ord[]=$alias; }
        $dimSelect = ', '.implode(', ',$sel);
        $dimGroup  = ', '.implode(', ',$grp);
        $dimOrder  = ', '.implode(', ',$ord);
    }

    // Filtros comunes
    $w = ['1=1']; $types=''; $args=[];
    if ($division_ids)   { $w[]='f.id_division IN ('.inClause($division_ids).')';     $types.=str_repeat('i',count($division_ids));  $args=array_merge($args,$division_ids); }
    if ($subdiv_ids)     { $w[]='f.id_subdivision IN ('.inClause($subdiv_ids).')';    $types.=str_repeat('i',count($subdiv_ids));    $args=array_merge($args,$subdiv_ids); }
    if ($campaign_ids)   { $w[]='f.id IN ('.inClause($campaign_ids).')';              $types.=str_repeat('i',count($campaign_ids));  $args=array_merge($args,$campaign_ids); }
    if ($tipos_form)     { $w[]='f.tipo IN ('.inClause($tipos_form).')';              $types.=str_repeat('i',count($tipos_form));    $args=array_merge($args,$tipos_form); }
    if ($distrito_ids)   { $w[]='l.id_distrito IN ('.inClause($distrito_ids).')';     $types.=str_repeat('i',count($distrito_ids));  $args=array_merge($args,$distrito_ids); }
    if ($jefe_venta_ids) { $w[]='l.id_jefe_venta IN ('.inClause($jefe_venta_ids).')'; $types.=str_repeat('i',count($jefe_venta_ids));$args=array_merge($args,$jefe_venta_ids); }
    if ($usuario_ids)    { $w[]='u.id IN ('.inClause($usuario_ids).')';               $types.=str_repeat('i',count($usuario_ids));   $args=array_merge($args,$usuario_ids); }
    if ($fecha_desde)    { $w[]='fqr.created_at >= ?';                                 $types.='s'; $args[]=$fecha_desde.' 00:00:00'; }
    if ($fecha_hasta)    { $w[]='fqr.created_at <= ?';                                 $types.='s'; $args[]=$fecha_hasta.' 23:59:59'; }
    if ($local_codigo !== '') {
        if (strpos($local_codigo,'%')!==false || strpos($local_codigo,'_')!==false) {
            $w[]='l.codigo LIKE ?'; $types.='s'; $args[]=$local_codigo;
        } else {
            $w[]='l.codigo = ?';     $types.='s'; $args[]=$local_codigo;
        }
    }
    $where = implode(' AND ', $w);

    // Labels (nombres legibles)
    $labels = [];
    $labels['division'] = keyBy($mysqli->query("SELECT id, nombre FROM division_empresa")->fetch_all(MYSQLI_ASSOC), 'id');
    $labels['subdivision'] = keyBy($mysqli->query("SELECT id, nombre FROM subdivision")->fetch_all(MYSQLI_ASSOC), 'id');
    $labels['campania'] = keyBy($mysqli->query("SELECT id, nombre FROM formulario")->fetch_all(MYSQLI_ASSOC), 'id');
    $labels['distrito'] = keyBy($mysqli->query("SELECT id, nombre_distrito AS nombre FROM distrito")->fetch_all(MYSQLI_ASSOC), 'id');
    $labels['jefe_venta'] = keyBy($mysqli->query("SELECT id, nombre FROM jefe_venta")->fetch_all(MYSQLI_ASSOC), 'id');
    $labels['usuario'] = keyBy($mysqli->query("SELECT id, CONCAT(nombre,' ',apellido) AS nombre FROM usuario")->fetch_all(MYSQLI_ASSOC), 'id');
    $labels['local'] = keyBy($mysqli->query("SELECT id, CONCAT(codigo,' - ',nombre) AS nombre FROM local")->fetch_all(MYSQLI_ASSOC), 'id');

    // ---- MODO GLOBAL ----
    if ($mode === 'global') {
        // 1) Opciones (qtypes 1/2/3)
        $rowsOpt = [];
        if ($set_qids) {
            $sqlOpt = "SELECT
                          fq.id_question_set_question AS set_qid,
                          fqo.id_question_set_option  AS option_set_id,
                          COUNT(*)                    AS cnt
                          $dimSelect
                       FROM form_question_responses fqr
                       JOIN form_questions fq               ON fq.id=fqr.id_form_question
                       LEFT JOIN form_question_options fqo   ON fqo.id=fqr.id_option
                       JOIN visita v ON v.id=fqr.visita_id
                       JOIN formulario f ON f.id=v.id_formulario
                       JOIN local l ON l.id=v.id_local
                       JOIN usuario u ON u.id=v.id_usuario
                       WHERE $where
                         AND fq.id_question_set_question IN (".inClause($set_qids).")
                         AND fq.id_question_type IN (1,2,3)
                       GROUP BY fq.id_question_set_question, fqo.id_question_set_option $dimGroup
                       ORDER BY fq.id_question_set_question $dimOrder";
            $stmt=$mysqli->prepare($sqlOpt);
            bindMany($stmt, $types.str_repeat('i', count($set_qids)), array_merge($args,$set_qids));
            $stmt->execute();
            $rowsOpt = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // 2) Numéricas (qtype 5)
        $rowsNum = [];
        if ($set_qids) {
            $sqlNum = "SELECT
                          fq.id_question_set_question AS set_qid,
                          COUNT(fqr.valor)            AS n,
                          AVG(fqr.valor)              AS avg_val,
                          SUM(fqr.valor)              AS sum_val,
                          MIN(fqr.valor)              AS min_val,
                          MAX(fqr.valor)              AS max_val
                          $dimSelect
                       FROM form_question_responses fqr
                       JOIN form_questions fq ON fq.id=fqr.id_form_question
                       JOIN visita v ON v.id=fqr.visita_id
                       JOIN formulario f ON f.id=v.id_formulario
                       JOIN local l ON l.id=v.id_local
                       JOIN usuario u ON u.id=v.id_usuario
                       WHERE $where
                         AND fq.id_question_set_question IN (".inClause($set_qids).")
                         AND fq.id_question_type = 5
                       GROUP BY fq.id_question_set_question $dimGroup
                       ORDER BY fq.id_question_set_question $dimOrder";
            $stmt=$mysqli->prepare($sqlNum);
            bindMany($stmt, $types.str_repeat('i', count($set_qids)), array_merge($args,$set_qids));
            $stmt->execute();
            $rowsNum = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // 3) (Opcional) Ad-hoc por firmas
        $rowsSigOpt = $rowsSigNum = [];
        if ($include_adhoc && $signatures) {
            // Opciones
            $ph = implode(',', array_fill(0, count($signatures), '?'));
            $sqlSO = "SELECT
                         fq.v_signature AS signature,
                         fqo.id_question_set_option AS option_set_id,
                         COUNT(*) AS cnt
                         $dimSelect
                      FROM form_question_responses fqr
                      JOIN form_questions fq ON fq.id=fqr.id_form_question
                      LEFT JOIN form_question_options fqo ON fqo.id=fqr.id_option
                      JOIN visita v ON v.id=fqr.visita_id
                      JOIN formulario f ON f.id=v.id_formulario
                      JOIN local l ON l.id=v.id_local
                      JOIN usuario u ON u.id=v.id_usuario
                      WHERE $where
                        AND fq.id_question_set_question IS NULL
                        AND fq.v_signature IN ($ph)
                        AND fq.id_question_type IN (1,2,3)
                      GROUP BY fq.v_signature, fqo.id_question_set_option $dimGroup
                      ORDER BY fq.v_signature $dimOrder";
            $stmt=$mysqli->prepare($sqlSO);
            bindMany($stmt, $types.str_repeat('s', count($signatures)), array_merge($args,$signatures));
            $stmt->execute();
            $rowsSigOpt = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Numéricas
            $sqlSN = "SELECT
                         fq.v_signature AS signature,
                         COUNT(fqr.valor) AS n,
                         AVG(fqr.valor)   AS avg_val,
                         SUM(fqr.valor)   AS sum_val,
                         MIN(fqr.valor)   AS min_val,
                         MAX(fqr.valor)   AS max_val
                         $dimSelect
                      FROM form_question_responses fqr
                      JOIN form_questions fq ON fq.id=fqr.id_form_question
                      JOIN visita v ON v.id=fqr.visita_id
                      JOIN formulario f ON f.id=v.id_formulario
                      JOIN local l ON l.id=v.id_local
                      JOIN usuario u ON u.id=v.id_usuario
                      WHERE $where
                        AND fq.id_question_set_question IS NULL
                        AND fq.v_signature IN ($ph)
                        AND fq.id_question_type = 5
                      GROUP BY fq.v_signature $dimGroup
                      ORDER BY fq.v_signature $dimOrder";
            $stmt=$mysqli->prepare($sqlSN);
            bindMany($stmt, $types.str_repeat('s', count($signatures)), array_merge($args,$signatures));
            $stmt->execute();
            $rowsSigNum = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Agrega labels legibles a cada fila de dims
        $rowsOpt = addDimLabels($rowsOpt, $labels);
        $rowsNum = addDimLabels($rowsNum, $labels);
        $rowsSigOpt = addDimLabels($rowsSigOpt, $labels);
        $rowsSigNum = addDimLabels($rowsSigNum, $labels);

        return [
            'meta' => [
                'mode'   => 'global',
                'dims'   => array_keys($dimCols),
                'adhoc'  => $include_adhoc,
            ],
            'option_counts'  => $rowsOpt,
            'numeric_stats'  => $rowsNum,
            'adhoc_option_counts' => $rowsSigOpt,
            'adhoc_numeric_stats' => $rowsSigNum
        ];
    }

    // ---- MODO CAMPAÑA ----
    if (!$form_qids) {
        return ['meta'=>['mode'=>'campaign','dims'=>array_keys($dimCols)], 'option_counts'=>[], 'numeric_stats'=>[]];
    }

    $rowsOpt = [];
    $sqlOpt = "SELECT
                 fq.id               AS form_question_id,
                 fqo.id              AS option_id,
                 COUNT(*)            AS cnt
                 $dimSelect
               FROM form_question_responses fqr
               JOIN form_questions fq ON fq.id=fqr.id_form_question
               LEFT JOIN form_question_options fqo ON fqo.id=fqr.id_option
               JOIN visita v ON v.id=fqr.visita_id
               JOIN formulario f ON f.id=v.id_formulario
               JOIN local l ON l.id=v.id_local
               JOIN usuario u ON u.id=v.id_usuario
               WHERE $where
                 AND fq.id IN (".inClause($form_qids).")
                 AND fq.id_question_type IN (1,2,3)
               GROUP BY fq.id, fqo.id $dimGroup
               ORDER BY fq.id $dimOrder";
    $stmt=$mysqli->prepare($sqlOpt);
    bindMany($stmt, $types.str_repeat('i',count($form_qids)), array_merge($args,$form_qids));
    $stmt->execute(); $rowsOpt = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $rowsNum = [];
    $sqlNum = "SELECT
                 fq.id            AS form_question_id,
                 COUNT(fqr.valor) AS n,
                 AVG(fqr.valor)   AS avg_val,
                 SUM(fqr.valor)   AS sum_val,
                 MIN(fqr.valor)   AS min_val,
                 MAX(fqr.valor)   AS max_val
                 $dimSelect
               FROM form_question_responses fqr
               JOIN form_questions fq ON fq.id=fqr.id_form_question
               JOIN visita v ON v.id=fqr.visita_id
               JOIN formulario f ON f.id=v.id_formulario
               JOIN local l ON l.id=v.id_local
               JOIN usuario u ON u.id=v.id_usuario
               WHERE $where
                 AND fq.id IN (".inClause($form_qids).")
                 AND fq.id_question_type = 5
               GROUP BY fq.id $dimGroup
               ORDER BY fq.id $dimOrder";
    $stmt=$mysqli->prepare($sqlNum);
    bindMany($stmt, $types.str_repeat('i',count($form_qids)), array_merge($args,$form_qids));
    $stmt->execute(); $rowsNum = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $rowsOpt = addDimLabels($rowsOpt, $labels);
    $rowsNum = addDimLabels($rowsNum, $labels);

    return [
        'meta'=>['mode'=>'campaign','dims'=>array_keys($dimCols)],
        'option_counts'=>$rowsOpt,
        'numeric_stats'=>$rowsNum
    ];
}

function keyBy(array $rows, string $key): array {
    $out = [];
    foreach ($rows as $r) { $out[$r[$key]] = $r; }
    return $out;
}
function addDimLabels(array $rows, array $labels): array {
    if (!$rows) return $rows;
    $dims = ['division','subdivision','campania','distrito','jefe_venta','usuario','local'];
    foreach ($rows as &$r) {
        foreach ($dims as $d) {
            if (array_key_exists($d, $r)) {
                $id = $r[$d];
                $r[$d.'_name'] = $labels[$d][$id]['nombre'] ?? (string)$id;
            }
        }
    }
    return $rows;
}
