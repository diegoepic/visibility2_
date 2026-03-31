<?php
// eliminar_form_pregunta.php — Confirmación + borrado seguro (subárbol o solo esta) para FORMULARIOS

session_start();
if (!isset($_SESSION['usuario_id'])) { header("Location: login.php"); exit(); }

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
include_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

$idQ   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$idFrm = isset($_GET['id_formulario']) ? (int)$_GET['id_formulario'] : 0;

if ($idQ<=0 || $idFrm<=0) { die("Parámetros inválidos."); }

// ---------- Helpers ----------
function fetchQuestion($conn, $idQ, $idFrm){
  $st = $conn->prepare("SELECT * FROM form_questions WHERE id=? AND id_formulario=?");
  $st->bind_param("ii", $idQ, $idFrm);
  $st->execute();
  $r = $st->get_result()->fetch_assoc();
  $st->close();
  return $r ?: null;
}

function fetchOptionsQ($conn, $idQ){
  $st = $conn->prepare("SELECT id FROM form_question_options WHERE id_form_question=?");
  $st->bind_param("i", $idQ);
  $st->execute();
  $res = $st->get_result();
  $ids = [];
  while($row=$res->fetch_assoc()){ $ids[]=(int)$row['id']; }
  $st->close();
  return $ids;
}

/**
 * Devuelve IDs de preguntas hijas que dependan de cualquiera de las opciones $optIds
 * IMPORTANTE: evita el error "Cannot use positional argument after argument unpacking"
 * usando SIEMPRE ...$params como ÚLTIMO argumento (ningún posicional después).
 */
function childrenByOptions($conn, $idFrm, $optIds){
  if (empty($optIds)) return [];
  $in      = implode(',', array_fill(0, count($optIds), '?'));
  $types   = 'i' . str_repeat('i', count($optIds));
  $sql = "SELECT id FROM form_questions WHERE id_formulario=? AND id_dependency_option IN ($in)";
  $st  = $conn->prepare($sql);
  // Armar un SOLO arreglo con todos los params
  $params = array_merge([$idFrm], array_map('intval', $optIds));
  $st->bind_param($types, ...$params);
  $st->execute();
  $res = $st->get_result();
  $ids = [];
  while($r=$res->fetch_assoc()){ $ids[]=(int)$r['id']; }
  $st->close();
  return $ids;
}

/** Recorre en amplitud para obtener TODO el subárbol de descendientes (no incluye la raíz) */
function subtreeIds($conn, $idFrm, $rootQ){
  $all   = [];
  $queue = [ (int)$rootQ ];
  while(!empty($queue)){
    $cur   = array_shift($queue);
    $opts  = fetchOptionsQ($conn, $cur);
    $child = childrenByOptions($conn, $idFrm, $opts);
    foreach($child as $c){
      if(!in_array($c, $all, true)){ $all[]=$c; $queue[]=$c; }
    }
  }
  return $all;
}

/** Reubica hijos directos de una pregunta a raíz (id_dependency_option = NULL) */
function reattachDirectChildrenToRoot($conn, $idFrm, $optIds){
  if (empty($optIds)) return;
  $in    = implode(',', array_fill(0, count($optIds), '?'));
  $types = 'i' . str_repeat('i', count($optIds));
  $sql   = "UPDATE form_questions SET id_dependency_option=NULL WHERE id_formulario=? AND id_dependency_option IN ($in)";
  $st    = $conn->prepare($sql);
  $params = array_merge([$idFrm], array_map('intval', $optIds));
  $st->bind_param($types, ...$params);
  $st->execute();
  $st->close();
}

/** Elimina respuestas ligadas a una lista de preguntas */
function deleteResponsesOfQuestions($conn, $questionIds){
  if (empty($questionIds)) return;
  // IMPORTANTE: Sanitizamos y embebemos por ser enteros (más simple para DELETE masivo)
  $ids = implode(',', array_map('intval', $questionIds));
  // Si existe la tabla de respuestas por pregunta:
  // (si tu esquema difiere, ajústalo aquí)
  $conn->query("DELETE FROM form_question_responses WHERE id_form_question IN ($ids)");
}

/** Elimina opciones de una lista de preguntas (y potencialmente sus respuestas por opción, si existieran) */
function deleteOptionsOfQuestions($conn, $questionIds){
  if (empty($questionIds)) return;
  $ids = implode(',', array_map('intval', $questionIds));

  // Si tienes respuestas por opción en otra tabla, borra primero ahí (ajusta nombre/columna si aplica)
  // $conn->query("DELETE FROM form_question_option_responses WHERE id_form_question_option IN (SELECT id FROM form_question_options WHERE id_form_question IN ($ids))");

  // Borrar opciones
  $conn->query("DELETE FROM form_question_options WHERE id_form_question IN ($ids)");
}

// ---------- Carga pregunta ----------
$preg = fetchQuestion($conn, $idQ, $idFrm);
if(!$preg) die("Pregunta no encontrada.");

// ---------- Confirmación ----------
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '0';
$mode    = isset($_GET['delete']) ? $_GET['delete'] : '';

if ($confirm!=='1'){
  // Precalcular descendientes para informar
  $desc = subtreeIds($conn, $idFrm, $idQ); // ids descendientes (no incluye root)
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
        <h4 class="alert-heading">¿Eliminar esta pregunta del formulario?</h4>
        <p><strong><?= htmlspecialchars($preg['question_text'],ENT_QUOTES) ?></strong></p>
        <?php if(!empty($desc)): ?>
          <p>Se encontraron <strong><?= count($desc) ?></strong> preguntas dependientes (en niveles inferiores) dentro del formulario.</p>
        <?php else: ?>
          <p>No tiene preguntas dependientes.</p>
        <?php endif; ?>
        <hr>
        <div class="mb-2">¿Qué deseas hacer?</div>
        <div class="d-flex flex-wrap">
          <a class="btn btn-danger mr-2 mb-2"
             href="eliminar_form_pregunta.php?id=<?= $idQ ?>&id_formulario=<?= $idFrm ?>&confirm=1&delete=all">
            Eliminar subárbol completo
          </a>
          <a class="btn btn-outline-danger mr-2 mb-2"
             href="eliminar_form_pregunta.php?id=<?= $idQ ?>&id_formulario=<?= $idFrm ?>&confirm=1&delete=only">
            Eliminar solo esta (mover hijos a raíz)
          </a>
          <a class="btn btn-secondary mb-2" href="gestionar_preguntas.php?id_formulario=<?= $idFrm ?>">Cancelar</a>
        </div>
        <small class="text-muted d-block mt-3">
          * “Solo esta” moverá sus hijos directos a la raíz del formulario (sin dependencia), manteniendo el flujo válido.
        </small>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit();
}

// ---------- Ejecutar eliminación ----------
$conn->begin_transaction();
try{
  if ($mode==='all'){
    // 1) IDs del subárbol (descendientes) + la raíz
    $desc = subtreeIds($conn, $idFrm, $idQ);
    $allQ = array_merge([$idQ], $desc);
    $allQ = array_values(array_unique(array_map('intval', $allQ)));

    if (!empty($allQ)){
      // 2) Primero borrar respuestas ligadas a esas preguntas
      deleteResponsesOfQuestions($conn, $allQ);

      // 3) Borrar opciones de todas esas preguntas
      deleteOptionsOfQuestions($conn, $allQ);

      // 4) Finalmente, borrar las preguntas
      $ids = implode(',', $allQ);
      $conn->query("DELETE FROM form_questions WHERE id IN ($ids) AND id_formulario=".$idFrm);
    }

  } else { // only
    // 1) Reubicar hijos directos a raíz
    $opts = fetchOptionsQ($conn, $idQ);
    if (!empty($opts)){
      reattachDirectChildrenToRoot($conn, $idFrm, $opts);
    }

    // 2) Borrar respuestas de la pregunta actual
    deleteResponsesOfQuestions($conn, [$idQ]);

    // 3) Borrar opciones de la pregunta actual
    deleteOptionsOfQuestions($conn, [$idQ]);

    // 4) Borrar la pregunta
    $st=$conn->prepare("DELETE FROM form_questions WHERE id=? AND id_formulario=?");
    $st->bind_param("ii", $idQ, $idFrm);
    $st->execute();
    $st->close();
  }

  $conn->commit();
  $_SESSION['success'] = "Eliminación realizada correctamente.";
} catch(Throwable $e){
  $conn->rollback();
  $_SESSION['error'] = "Error al eliminar: ".$e->getMessage();
}

header("Location: gestionar_preguntas.php?id_formulario=".$idFrm);
exit();
