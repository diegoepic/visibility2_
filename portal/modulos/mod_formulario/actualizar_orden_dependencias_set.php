<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { http_response_code(403); echo "No autorizado."; exit(); }


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'].'/visibility2/portal/modulos/session_data.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST'){ http_response_code(405); echo "Método inválido."; exit(); }

$idSet = isset($_POST['idSet']) ? (int)$_POST['idSet'] : 0;
$json  = $_POST['data'] ?? '';
$rows  = json_decode($json, true);

if ($idSet <= 0 || !is_array($rows)){ http_response_code(400); echo "Parámetros inválidos."; exit(); }
if (empty($rows)){ echo "Nada que actualizar."; exit(); }

// -------- Helpers locales --------

/** Obtiene pares (id => id_dependency_option) para todas las preguntas del set */
function fetchSetDeps(mysqli $conn, int $idSet): array {
  $st = $conn->prepare("SELECT id, id_dependency_option FROM question_set_questions WHERE id_question_set=?");
  $st->bind_param("i", $idSet);
  $st->execute();
  $res = $st->get_result();
  $map = [];
  while($r = $res->fetch_assoc()){
    $map[(int)$r['id']] = isset($r['id_dependency_option']) ? (int)$r['id_dependency_option'] : null;
  }
  $st->close();
  return $map; // childQuestionId => optionId|null
}

/** Mapea todas las opciones del set: id_opcion => id_pregunta_padre */
function fetchValidOptions(mysqli $conn, int $idSet): array {
  $st = $conn->prepare("
    SELECT o.id, o.id_question_set_question
    FROM question_set_options o
    JOIN question_set_questions q ON q.id = o.id_question_set_question
    WHERE q.id_question_set = ?
  ");
  $st->bind_param("i", $idSet);
  $st->execute();
  $res = $st->get_result();
  $map = [];
  while($r = $res->fetch_assoc()){
    $map[(int)$r['id']] = (int)$r['id_question_set_question'];
  }
  $st->close();
  return $map; // optionId => parentQuestionId
}

/** Detección de ciclos en grafo childQuestion -> parentQuestion */
function has_cycle_full(array $parentOf): bool {
  // parentOf: childQ => parentQ|null
  $WHITE = 0; $GRAY = 1; $BLACK = 2;
  $color = [];
  foreach ($parentOf as $k => $_) $color[$k] = $WHITE;

  $visit = function($u) use (&$visit, &$color, $parentOf, $WHITE, $GRAY, $BLACK){
    if (!array_key_exists($u, $color)) $color[$u] = $WHITE;
    if ($color[$u] === $GRAY) return true;   // back-edge => ciclo
    if ($color[$u] === $BLACK) return false; // ya procesado
    $color[$u] = $GRAY;
    $p = $parentOf[$u] ?? null;
    if ($p !== null){
      if ($visit($p)) return true;
    }
    $color[$u] = $BLACK;
    return false;
  };

  foreach ($parentOf as $node => $_){
    if ($visit($node)) return true;
  }
  return false;
}

// -------- Validaciones de entrada --------

// a) IDs de preguntas en payload (deduplicar)
$qIds = array_map(static fn($r)=> (int)($r['id'] ?? 0), $rows);
if (in_array(0, $qIds, true)){ http_response_code(400); echo "Fila sin 'id' de pregunta válido."; exit(); }
$qIds = array_values(array_unique($qIds));


// b) Verificar pertenencia de TODAS las preguntas del payload al set
$in    = implode(',', array_fill(0, count($qIds), '?'));
$types = str_repeat('i', count($qIds));
$params = array_merge([$idSet], $qIds);
$refs = [];
foreach ($params as $k => $v) { $params[$k] = (int)$v; $refs[$k] = &$params[$k]; }

$stmt = $conn->prepare("SELECT id FROM question_set_questions WHERE id_question_set=? AND id IN ($in)");
$stmt->bind_param("i".$types, ...$refs);
$stmt->execute();
$res = $stmt->get_result();
$found = [];
while($r = $res->fetch_assoc()) $found[] = (int)$r['id'];
$stmt->close();

$missing = array_diff($qIds, $found);
if (!empty($missing)){ http_response_code(400); echo "IDs de preguntas no pertenecen al set."; exit(); }

// c) Cargar mapa de opciones válidas (del set)
$validOpt = fetchValidOptions($conn, $idSet);

// d) Construir parentOf global del set con datos actuales
$currentDepOptByQ = fetchSetDeps($conn, $idSet); // childQ => optionId|null
$parentOf = []; // childQ => parentQ|null (actual)
foreach ($currentDepOptByQ as $childQ => $optId) {
  if ($optId !== null){
    if (!isset($validOpt[$optId])) {
      // datos "sucios": opción no existe/ya no pertenece; trátalo como NULL para no romper validación
      $parentOf[$childQ] = null;
    } else {
      $parentOf[$childQ] = (int)$validOpt[$optId];
    }
  } else {
    $parentOf[$childQ] = null;
  }
}

// e) Superponer cambios propuestos (solo para las preguntas del payload)
foreach ($rows as $r){
  $child = (int)$r['id'];
  $dep   = array_key_exists('dep_option_id', $r) ? $r['dep_option_id'] : null;

  if ($dep === null || $dep === '' ) {
    // Se propone raíz (sin dependencia)
    $parentOf[$child] = null;
  } else {
    $dep = (int)$dep;
    if (!isset($validOpt[$dep])){ http_response_code(400); echo "Opción disparadora inválida para una pregunta (id_opción: $dep)."; exit(); }
    $parentOf[$child] = (int)$validOpt[$dep];
  }
}

// f) Prevención básica: no depender de sí mismo
foreach ($parentOf as $childQ => $parentQ){
  if ($parentQ !== null && $childQ === $parentQ){
    http_response_code(400);
    echo "Dependencia inválida: una pregunta no puede depender de una opción de sí misma.";
    exit();
  }
}

// g) Anti-ciclos global (considerando TODO el set)
if (has_cycle_full($parentOf)){
  http_response_code(400);
  echo "La estructura propuesta contiene un ciclo de dependencias.";
  exit();
}

// -------- Persistencia --------

$conn->begin_transaction();
try{
  // Statements reusables (con/sin dependencia)
  $upWithDep = $conn->prepare("UPDATE question_set_questions SET sort_order=?, id_dependency_option=? WHERE id=? AND id_question_set=?");
  $upNoDep   = $conn->prepare("UPDATE question_set_questions SET sort_order=?, id_dependency_option=NULL WHERE id=? AND id_question_set=?");

  foreach ($rows as $r){
    $id   = (int)$r['id'];
    if (!isset($r['sort_order'])){ throw new Exception("Fila sin 'sort_order' para pregunta $id."); }
    $sort = (int)$r['sort_order'];
    $dep  = array_key_exists('dep_option_id', $r) ? $r['dep_option_id'] : null;

    if ($dep === null || $dep === ''){
      // sin dependencia => NULL explícito
      $upNoDep->bind_param("iii", $sort, $id, $idSet);
      $upNoDep->execute();
    } else {
      $dep = (int)$dep;
      // seguridad extra: validar opción del mismo set (de nuevo, por si payload mutó entre validación y update)
      if (!isset($validOpt[$dep])){ throw new Exception("Opción disparadora inválida durante actualización (id_opción: $dep)."); }
      $upWithDep->bind_param("iiii", $sort, $dep, $id, $idSet);
      $upWithDep->execute();
    }
  }

  $upWithDep->close();
  $upNoDep->close();

  $conn->commit();
  echo "Estructura actualizada correctamente.";
} catch(Exception $e){
  $conn->rollback();
  http_response_code(500);
  echo "Error al actualizar: ".$e->getMessage();
}
