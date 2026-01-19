<?php
// eliminar_set_pregunta.php — Confirmación mejorada + manejo seguro de hijos + cleanup de imágenes + sort_order al final

session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit(); }

// DEBUG (apaga en prod)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/mod_formulario/sort_order_helpers.php';

$idQ   = isset($_GET['id'])    ? (int)$_GET['id']    : 0;
$idSet = isset($_GET['idSet']) ? (int)$_GET['idSet'] : 0;
$csrf = $_GET['csrf_token'] ?? '';

if ($idQ<=0 || $idSet<=0){ die("Parámetros inválidos."); }
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
  $_SESSION['error_sets'] = "Token CSRF inválido.";
  header("Location: gestionar_sets.php?idSet=".$idSet);
  exit();
}

/* ------------------------- Helpers ------------------------- */

function fetchQuestion(mysqli $conn, int $idQ, int $idSet): ?array {
  $st=$conn->prepare("SELECT * FROM question_set_questions WHERE id=? AND id_question_set=?");
  $st->bind_param("ii",$idQ,$idSet);
  $st->execute();
  $r=$st->get_result()->fetch_assoc();
  $st->close();
  return $r ?: null;
}
function fetchOptionsQ(mysqli $conn, int $idQ): array {
  $st=$conn->prepare("SELECT id, reference_image FROM question_set_options WHERE id_question_set_question=?");
  $st->bind_param("i",$idQ);
  $st->execute();
  $res=$st->get_result();
  $rows=[];
  while($row=$res->fetch_assoc()){ $rows[]=['id'=>(int)$row['id'], 'reference_image'=>$row['reference_image']??'']; }
  $st->close();
  return $rows;
}
function childrenByOptions(mysqli $conn, int $idSet, array $optIds): array {
  if (empty($optIds)) return [];
  $in    = implode(',', array_fill(0,count($optIds),'?'));
  $types = str_repeat('i', count($optIds));
  $sql="SELECT id FROM question_set_questions WHERE id_question_set=? AND id_dependency_option IN ($in)";
  $st=$conn->prepare($sql);
  $st->bind_param('i'.$types, $idSet, ...$optIds);
  $st->execute();
  $res=$st->get_result();
  $ids=[];
  while($r=$res->fetch_assoc()) $ids[]=(int)$r['id'];
  $st->close();
  return $ids;
}
/** Retorna IDs de todas las preguntas descendientes (no incluye root) */
function subtreeIds(mysqli $conn, int $idSet, int $rootQ): array {
  $all=[];
  $queue=[(int)$rootQ];
  while(!empty($queue)){
    $cur=array_shift($queue);
    $opts=fetchOptionsQ($conn,$cur);
    $optIds = array_column($opts,'id');
    $childs=childrenByOptions($conn,$idSet,$optIds);
    foreach($childs as $c){
      if(!in_array($c,$all,true)){ $all[]=$c; $queue[]=$c; }
    }
  }
  return $all;
}

/** Borra de forma segura un archivo dentro de /uploads/opciones/ */
function safe_unlink_option_image(?string $webPath): void {
  if (!$webPath) return;
  $webPath = trim($webPath);
  if ($webPath === '') return;
  // Solo permitimos ruta bajo /uploads/opciones/
  if (strpos($webPath, '/uploads/opciones/') !== 0) return;
  $abs = $_SERVER['DOCUMENT_ROOT'] . $webPath;
  if (is_file($abs)) @unlink($abs);
}

/** Elimina imágenes y opciones para un conjunto de preguntas */
function deleteOptionsAndImagesForQuestions(mysqli $conn, array $questionIds): void {
  if (empty($questionIds)) return;
  // Obtener imágenes
  $in = implode(',', array_fill(0, count($questionIds), '?'));
  $types = str_repeat('i', count($questionIds));
  $sqlSel = "SELECT reference_image FROM question_set_options WHERE id_question_set_question IN ($in)";
  $st = $conn->prepare($sqlSel);
  $st->bind_param($types, ...$questionIds);
  $st->execute();
  $res = $st->get_result();
  while($row = $res->fetch_assoc()){
    $ref = $row['reference_image'] ?? '';
    safe_unlink_option_image($ref);
  }
  $st->close();

  // Borrar opciones
  $sqlDel = "DELETE FROM question_set_options WHERE id_question_set_question IN ($in)";
  $st2 = $conn->prepare($sqlDel);
  $st2->bind_param($types, ...$questionIds);
  $st2->execute();
  $st2->close();
}

/** Elimina preguntas por IDs (mismo set) */
function deleteQuestionsByIds(mysqli $conn, int $idSet, array $questionIds): void {
  if (empty($questionIds)) return;
  $in = implode(',', array_fill(0, count($questionIds), '?'));
  $types = str_repeat('i', count($questionIds));
  $sql = "DELETE FROM question_set_questions WHERE id IN ($in) AND id_question_set=?";
  $st = $conn->prepare($sql);
  // bind dinámico (ids..., idSet)
  $bindTypes = $types . 'i';
  $params = array_merge($questionIds, [$idSet]);
  $refs = [];
  foreach ($params as $k => $v) { $params[$k] = (int)$v; $refs[$k] = &$params[$k]; }
  $st->bind_param($bindTypes, ...$refs);
  $st->execute();
  $st->close();
}

/* --------------------- Carga pregunta raíz --------------------- */

$preg = fetchQuestion($conn, $idQ, $idSet);
if(!$preg) die("Pregunta no encontrada.");

// Confirmación
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '0';
$mode    = isset($_GET['delete'])  ? $_GET['delete']  : '';

if ($confirm !== '1'){
  $opts = fetchOptionsQ($conn, $idQ);
  $desc = subtreeIds($conn, $idSet, $idQ); // ids descendientes
  ?>
  <!DOCTYPE html>
  <html lang="es">
  <head>
    <meta charset="utf-8">
    <title>Confirmar eliminación</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
  </head>
  <body class="p-3">
    <div class="container">
      <div class="alert alert-warning">
        <h4 class="alert-heading">¿Eliminar esta pregunta?</h4>
        <p><strong><?= htmlspecialchars($preg['question_text'],ENT_QUOTES) ?></strong></p>
        <?php if(!empty($desc)): ?>
          <p>Se encontraron <strong><?= count($desc) ?></strong> preguntas dependientes (en niveles inferiores).</p>
        <?php else: ?>
          <p>No tiene preguntas dependientes.</p>
        <?php endif; ?>
        <hr>
        <div class="mb-2">¿Qué deseas hacer?</div>
        <div class="d-flex flex-wrap">
          <a class="btn btn-danger mr-2 mb-2" href="eliminar_set_pregunta.php?id=<?= $idQ ?>&idSet=<?= $idSet ?>&confirm=1&delete=all&csrf_token=<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            Eliminar subárbol completo
          </a>
          <a class="btn btn-outline-danger mr-2 mb-2" href="eliminar_set_pregunta.php?id=<?= $idQ ?>&idSet=<?= $idSet ?>&confirm=1&delete=only&csrf_token=<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            Eliminar solo esta (reubicar hijos a raíz)
          </a>
          <a class="btn btn-secondary mb-2" href="gestionar_sets.php?idSet=<?= $idSet ?>">Cancelar</a>
        </div>
        <small class="text-muted d-block mt-3">
          * Si eliges <em>solo esta</em>, los hijos directos (dependientes de sus opciones) se moverán a la raíz del set (sin dependencia) y se
          ubicarán al final del cuestionario.
        </small>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit();
}

/* ----------------------- Ejecutar eliminación ----------------------- */

$conn->begin_transaction();
try {
  if ($mode === 'all'){
    // IDs a borrar: descendientes + raíz
    $desc = subtreeIds($conn, $idSet, $idQ);
    $toDelete = array_merge([$idQ], $desc);

    // Borrar imágenes + opciones de todo el subárbol
    deleteOptionsAndImagesForQuestions($conn, $toDelete);

    // Borrar preguntas
    deleteQuestionsByIds($conn, $idSet, $toDelete);

  } else { // 'only' => mover hijos directos a raíz y borrar solo la raíz

    // 1) Hijos directos (los que dependen de opciones de la raíz)
    $optsRows = fetchOptionsQ($conn, $idQ);
    $optIds   = array_column($optsRows, 'id');
    $children = !empty($optIds) ? childrenByOptions($conn, $idSet, $optIds) : [];

    if (!empty($children)){
      // 2) Calcular sort_order final para anexarlos al final
      $stMax = $conn->prepare("SELECT COALESCE(MAX(sort_order),0) FROM question_set_questions WHERE id_question_set=?");
      $stMax->bind_param("i",$idSet);
      $stMax->execute();
      $stMax->bind_result($maxSort);
      $stMax->fetch();
      $stMax->close();

      // 3) Mover a raíz y ubicar al final (uno a uno para fijar sort_order incremental)
      $stUpd = $conn->prepare("UPDATE question_set_questions SET id_dependency_option=NULL, sort_order=? WHERE id=? AND id_question_set=?");
      foreach ($children as $cid){
        $maxSort++;
        $stUpd->bind_param("iii", $maxSort, $cid, $idSet);
        $stUpd->execute();
      }
      $stUpd->close();
    }

    // 4) Borrar imágenes y opciones de la raíz
    deleteOptionsAndImagesForQuestions($conn, [$idQ]);

    // 5) Borrar la raíz
    $stDel = $conn->prepare("DELETE FROM question_set_questions WHERE id=? AND id_question_set=?");
    $stDel->bind_param("ii",$idQ,$idSet);
    $stDel->execute();
    $stDel->close();
  }

  normalizar_sort_order_set($conn, $idSet);
  $conn->commit();
  $_SESSION['success_sets'] = "Eliminación realizada correctamente.";
} catch (Exception $e){
  $conn->rollback();
  $_SESSION['error_sets'] = "Error al eliminar: ".$e->getMessage();
}

header("Location: gestionar_sets.php?idSet=".$idSet);
exit();