<?php
// panel/api/questions.php
require __DIR__.'/_db.php';

/*
  Parámetros:
  - mode: 'global' | 'campaign'
  - include_adhoc: 0/1 (global)
  - campaign_id: int (para mode=campaign) o usa campaign_ids[]
  - division_ids[], subdivision_ids[], campaign_ids[], tipos[]
  - qtypes[] (filtro)
  - search (string)
*/

$mode          = (string) jread('mode', 'global');
$include_adhoc = intval(jread('include_adhoc', 0)) === 1;
$campaign_id   = intval(jread('campaign_id', 0));

$division_ids  = ints((array) jread('division_ids', []));
$subdiv_ids    = ints((array) jread('subdivision_ids', []));
$campaign_ids  = ints((array) jread('campaign_ids', []));
$tipos_form    = ints((array) jread('tipos', []));
$qtypes        = ints((array) jread('qtypes', []));
$search        = trim((string) jread('search', ''));

$w = ['1=1']; $types=''; $args=[];
if ($division_ids) { $w[]='f.id_division IN ('.inClause($division_ids).')'; $types.=str_repeat('i',count($division_ids)); $args=array_merge($args,$division_ids); }
if ($subdiv_ids)   { $w[]='f.id_subdivision IN ('.inClause($subdiv_ids).')'; $types.=str_repeat('i',count($subdiv_ids)); $args=array_merge($args,$subdiv_ids); }
if ($campaign_ids) { $w[]='f.id IN ('.inClause($campaign_ids).')'; $types.=str_repeat('i',count($campaign_ids)); $args=array_merge($args,$campaign_ids); }
if ($tipos_form)   { $w[]='f.tipo IN ('.inClause($tipos_form).')'; $types.=str_repeat('i',count($tipos_form)); $args=array_merge($args,$tipos_form); }
if ($qtypes)       { $w[]='fq.id_question_type IN ('.inClause($qtypes).')'; $types.=str_repeat('i',count($qtypes)); $args=array_merge($args,$qtypes); }
if ($search!=='')  { $w[]='fq.question_text LIKE ?'; $types.='s'; $args[]='%'.$search.'%'; }
$where = implode(' AND ', $w);

if ($mode === 'campaign') {
    $ids = $campaign_id>0 ? [$campaign_id] : $campaign_ids;
    if (!$ids) fail('Debes indicar una campaña (campaign_id o campaign_ids[])');
    $w2 = $where . ' AND f.id IN ('.inClause($ids).')';
    $types2 = $types . str_repeat('i', count($ids));
    $args2 = array_merge($args, $ids);

    $sql = "SELECT
              fq.id               AS form_question_id,
              fq.question_text    AS label,
              fq.id_question_type AS qtype,
              fq.id_question_set_question AS set_qid,
              fq.is_required,
              fq.is_valued,
              fq.id_dependency_option,
              fq.sort_order
            FROM form_questions fq
            JOIN formulario f ON f.id=fq.id_formulario
            WHERE $w2
            ORDER BY f.id, fq.sort_order, fq.id";
    $stmt=$mysqli->prepare($sql); bindMany($stmt, $types2, $args2); $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    ok(['mode'=>'campaign','items'=>$rows]);
}

$sql = "SELECT
          fq.id_question_set_question AS set_qid,
          MIN(fq.question_text)       AS sample_text,
          MIN(fq.id_question_type)    AS qtype,
          COUNT(DISTINCT fq.id_formulario) AS n_campanias
        FROM form_questions fq
        JOIN formulario f ON f.id=fq.id_formulario
        WHERE $where AND fq.id_question_set_question IS NOT NULL
        GROUP BY fq.id_question_set_question
        ORDER BY sample_text";
$stmt=$mysqli->prepare($sql); if($types) bindMany($stmt, $types, $args); $stmt->execute();
$canon = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

$adhoc = [];
if ($include_adhoc) {
    $sql2 = "SELECT
              fq.v_signature                 AS signature,
              MIN(fq.question_text)          AS sample_text,
              MIN(fq.id_question_type)       AS qtype,
              COUNT(DISTINCT fq.id_formulario) AS n_campanias
            FROM form_questions fq
            JOIN formulario f ON f.id=fq.id_formulario
            WHERE $where AND fq.id_question_set_question IS NULL
            GROUP BY fq.v_signature
            ORDER BY sample_text";
    $stmt=$mysqli->prepare($sql2); if($types) bindMany($stmt, $types, $args); $stmt->execute();
    $adhoc = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
}

ok(['mode'=>'global','canonicos'=>$canon,'adhoc'=>$adhoc]);
