<?php
// panel/api/options.php
require __DIR__.'/_db.php';

$mode = (string) jread('mode','global');

if ($mode==='global') {
    $set_qids = ints((array) jread('set_qids', []));
    if (!$set_qids) ok(['items'=>[]]);

    $sql = "SELECT
              qso.id                        AS option_set_id,
              qso.id_question_set_question  AS set_qid,
              qso.option_text               AS option_text,
              qso.sort_order
            FROM question_set_options qso
            WHERE qso.id_question_set_question IN (".inClause($set_qids).")
            ORDER BY qso.id_question_set_question, qso.sort_order, qso.id";
    $stmt=$mysqli->prepare($sql);
    bindMany($stmt, str_repeat('i', count($set_qids)), $set_qids);
    $stmt->execute();
    $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    ok(['items'=>$rows]);
}

// campaign
$form_qids = ints((array) jread('form_question_ids', []));
if (!$form_qids) ok(['items'=>[]]);

$sql = "SELECT
          fqo.id                    AS option_id,
          fqo.id_form_question      AS form_question_id,
          fqo.id_question_set_option AS option_set_id,
          fqo.option_text,
          fqo.sort_order
        FROM form_question_options fqo
        WHERE fqo.id_form_question IN (".inClause($form_qids).")
        ORDER BY fqo.id_form_question, fqo.sort_order, fqo.id";
$stmt=$mysqli->prepare($sql);
bindMany($stmt, str_repeat('i', count($form_qids)), $form_qids);
$stmt->execute();
$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

ok(['items'=>$rows]);
