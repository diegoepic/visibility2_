<?php

function normalizar_sort_order_set(mysqli $conn, int $idSet, array $preferredOrder = []): array {
  $st = $conn->prepare("SELECT id, sort_order, id_dependency_option FROM question_set_questions WHERE id_question_set=?");
  $st->bind_param("i", $idSet);
  $st->execute();
  $res = $st->get_result();
  $questions = [];
  while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $questions[$id] = [
      'sort_order' => (int)$row['sort_order'],
      'dep_option' => isset($row['id_dependency_option']) ? (int)$row['id_dependency_option'] : null,
    ];
  }
  $st->close();

  if (empty($questions)) {
    return [];
  }

  $st = $conn->prepare("
    SELECT o.id, o.id_question_set_question
    FROM question_set_options o
    JOIN question_set_questions q ON q.id = o.id_question_set_question
    WHERE q.id_question_set = ?
  ");
  $st->bind_param("i", $idSet);
  $st->execute();
  $res = $st->get_result();
  $optionParents = [];
  while ($row = $res->fetch_assoc()) {
    $optionParents[(int)$row['id']] = (int)$row['id_question_set_question'];
  }
  $st->close();

  $parentOf = [];
  $clearedDeps = [];
  foreach ($questions as $qid => $data) {
    $depOpt = $data['dep_option'];
    if ($depOpt !== null && $depOpt !== 0) {
      if (isset($optionParents[$depOpt])) {
        $parentOf[$qid] = $optionParents[$depOpt];
      } else {
        $parentOf[$qid] = null;
        $clearedDeps[$qid] = true;
      }
    } else {
      $parentOf[$qid] = null;
    }
  }

  if (!empty($clearedDeps)) {
    $ids = array_keys($clearedDeps);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $params = array_merge([$idSet], $ids);
    $refs = [];
    foreach ($params as $k => $v) { $params[$k] = (int)$v; $refs[$k] = &$params[$k]; }
    $st = $conn->prepare("UPDATE question_set_questions SET id_dependency_option=NULL WHERE id_question_set=? AND id IN ($in)");
    $st->bind_param("i".$types, ...$refs);
    $st->execute();
    $st->close();
  }

  $sortKey = [];
  foreach ($questions as $qid => $data) {
    if (array_key_exists($qid, $preferredOrder)) {
      $sortKey[$qid] = (int)$preferredOrder[$qid];
    } else {
      $sortKey[$qid] = ((int)$data['sort_order'] * 100000) + $qid;
    }
  }

  $childrenByParent = [];
  foreach ($parentOf as $child => $parent) {
    $key = $parent ?? 0;
    $childrenByParent[$key][] = $child;
  }

  $sortFn = function($a, $b) use ($sortKey) {
    $ka = $sortKey[$a] ?? 0;
    $kb = $sortKey[$b] ?? 0;
    if ($ka === $kb) {
      return $a <=> $b;
    }
    return $ka <=> $kb;
  };

  foreach ($childrenByParent as $key => $list) {
    usort($list, $sortFn);
    $childrenByParent[$key] = $list;
  }

  $ordered = [];
  $visited = [];
  $walk = function($node) use (&$walk, &$ordered, &$visited, $childrenByParent) {
    if (isset($visited[$node])) return;
    $visited[$node] = true;
    $ordered[] = $node;
    foreach ($childrenByParent[$node] ?? [] as $child) {
      $walk($child);
    }
  };

  foreach ($childrenByParent[0] ?? [] as $root) {
    $walk($root);
  }

  $remaining = array_diff(array_keys($questions), array_keys($visited));
  if (!empty($remaining)) {
    usort($remaining, $sortFn);
    foreach ($remaining as $id) {
      $walk($id);
    }
  }

  $order = 1;
  $st = $conn->prepare("UPDATE question_set_questions SET sort_order=? WHERE id=? AND id_question_set=?");
  foreach ($ordered as $id) {
    $st->bind_param("iii", $order, $id, $idSet);
    $st->execute();
    $order++;
  }
  $st->close();

  return array_keys($clearedDeps);
}