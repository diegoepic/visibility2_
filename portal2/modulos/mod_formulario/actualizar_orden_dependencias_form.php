<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(401); exit("Sesión expirada"); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$idForm = isset($_POST['idForm']) ? (int)$_POST['idForm'] : 0;
$json   = $_POST['data'] ?? '[]';
if ($idForm<=0) { http_response_code(400); exit("Formulario inválido"); }

$data = json_decode($json, true);
if (!is_array($data)) { http_response_code(400); exit("JSON inválido"); }
if (empty($data)) { http_response_code(400); exit("Sin datos"); }

// ---------- Cargar mapa opción -> pregunta (validando pertenencia al formulario)
$depIds = array_values(array_unique(array_filter(array_map(function($r){
  return isset($r['dep_option_id']) ? (int)$r['dep_option_id'] : 0;
}, $data))));

$optToQuestion = [];
if (!empty($depIds)) {
  $in    = implode(',', array_fill(0, count($depIds), '?'));
  $types = str_repeat('i', count($depIds));
  $sql   = "SELECT o.id AS opt_id, q.id AS q_id, q.id_formulario AS fid
            FROM form_question_options o
            JOIN form_questions q ON q.id = o.id_form_question
            WHERE o.id IN ($in)";
  $st = $conn->prepare($sql);
  $st->bind_param($types, ...$depIds);
  $st->execute();
  $rs = $st->get_result();
  while($r = $rs->fetch_assoc()){
    if ((int)$r['fid'] !== $idForm) { http_response_code(400); exit("Opción de dependencia fuera del formulario"); }
    $optToQuestion[(int)$r['opt_id']] = (int)$r['q_id'];
  }
  $st->close();
}

// ---------- Validar que TODAS las preguntas del payload pertenecen al formulario
$qIds = array_values(array_unique(array_map(fn($r)=>(int)$r['id'], $data)));
if (!empty($qIds)) {
  $in    = implode(',', array_fill(0, count($qIds), '?'));
  $types = str_repeat('i', count($qIds));
  // Reordenamos la condición para poder bindear [idForm, ...$qIds] sin violar la regla de unpacking
  $sql   = "SELECT id FROM form_questions WHERE id_formulario=? AND id IN ($in)";
  $st = $conn->prepare($sql);
  $types2 = 'i' . $types;
  $params = array_merge([$idForm], $qIds);     // <- un solo array de parámetros
  $st->bind_param($types2, ...$params);       // <- sin posicionales después del unpack
  $st->execute();
  $found = [];
  $rs = $st->get_result();
  while($r=$rs->fetch_assoc()){ $found[]=(int)$r['id']; }
  $st->close();
  if (count($found) !== count($qIds)) { http_response_code(400); exit("Pregunta fuera del formulario"); }
}

// ---------- Construir grafo propuesto (parentQ -> childQ) y detectar ciclos
$edges   = [];     // parent -> [childs...]
$parents = [];     // child -> parent
foreach ($data as $row) {
  $child  = (int)$row['id'];
  $depOpt = isset($row['dep_option_id']) && $row['dep_option_id'] ? (int)$row['dep_option_id'] : null;
  $parentQ = $depOpt ? ($optToQuestion[$depOpt] ?? null) : null;
  if ($parentQ) {
    $edges[$parentQ] = $edges[$parentQ] ?? [];
    $edges[$parentQ][] = $child;
    $parents[$child] = $parentQ;
  } else {
    $parents[$child] = null;
  }
}

// DFS cycle detection
$VISITING=1; $VISITED=2; $state=[];
function dfsHasCycle($u, &$edges, &$state){
  $state[$u]=1; // VISITING
  if (!empty($edges[$u])) {
    foreach($edges[$u] as $v){
      if (!isset($state[$v])) { if (dfsHasCycle($v,$edges,$state)) return true; }
      else if ($state[$v]===1) return true;
    }
  }
  $state[$u]=2; // VISITED
  return false;
}
foreach ($qIds as $node){
  if (!isset($state[$node])){
    if (dfsHasCycle($node,$edges,$state)) { http_response_code(400); exit("Estructura inválida: ciclo detectado"); }
  }
}

// ---------- Guardar
$conn->begin_transaction();
try {
  // Dos statements: uno con dependencia NULL y otro con param para la dependencia
  $stSet  = $conn->prepare("UPDATE form_questions SET sort_order=?, id_dependency_option=?   WHERE id=? AND id_formulario=?");
  $stNull = $conn->prepare("UPDATE form_questions SET sort_order=?, id_dependency_option=NULL WHERE id=? AND id_formulario=?");

  foreach ($data as $row) {
    $qid  = (int)$row['id'];
    $sort = (int)$row['sort_order'];
    $dep  = isset($row['dep_option_id']) && $row['dep_option_id'] ? (int)$row['dep_option_id'] : null;

    if (is_null($dep)) {
      // id_dependency_option = NULL
      $stNull->bind_param("iii", $sort, $qid, $idForm);
      $stNull->execute();
    } else {
      // id_dependency_option = ?
      $stSet->bind_param("iiii", $sort, $dep, $qid, $idForm);
      $stSet->execute();
    }
  }

  $stSet->close();
  $stNull->close();

  $conn->commit();
  echo "Estructura guardada correctamente.";
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo "Error al guardar: ".htmlspecialchars($e->getMessage(),ENT_QUOTES);
}
