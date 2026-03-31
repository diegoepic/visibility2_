<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

ini_set('memory_limit', '1024M');
set_time_limit(0);

$empresa_id = intval($_SESSION['empresa_id'] ?? 0);
if ($empresa_id <= 0) {
  http_response_code(403);
  exit('Acceso inválido.');
}

$DOCROOT_APP = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/visibility2/app/';
$BASE_URL    = 'https://visibility.cl/visibility2/app/';

function isAbsoluteUrl(string $u): bool { return (bool)preg_match('#^https?://#i', $u); }
function normalizeUrl(string $u, string $base): string {
  if (isAbsoluteUrl($u)) return $u;
  $u = ltrim($u, '/');
  return rtrim($base, '/') . '/' . $u;
}
function sanitizeFilename(string $s, int $max = 180): string {
  $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
  $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', $s);
  $s = trim($s, '._-');
  if ($s === '') $s = 'file';
  return mb_substr($s, 0, $max);
}
function addUnique(&$set, string $name): string {
  $base = $name; $i = 2;
  while (isset($set[$name])) { $dot = strrpos($base, '.'); 
    if ($dot !== false) $name = substr($base,0,$dot) . "-$i" . substr($base,$dot);
    else $name = $base . "-$i";
    $i++;
  }
  $set[$name] = true;
  return $name;
}
function fetchToTemp(string $url): ?string {
  $tmp = tempnam(sys_get_temp_dir(), 'dl_');
  $fp  = fopen($tmp, 'w');
  if (!$fp) return null;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_FILE           => $fp,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT      => 'VisibilityZip/1.0',
    CURLOPT_FAILONERROR    => true,
  ]);
  $ok = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  fclose($fp);
  if (!$ok || $code >= 400) {
    @unlink($tmp);
    error_log("fetchToTemp fail [$code]: $url - $err");
    return null;
  }
  return $tmp;
}
function parseFirstUrlFromObservacion(?string $obs): ?string {
  if (!$obs) return null;
  // Busca la primera URL http(s) en el texto (robusto)
  if (preg_match('#https?://[^\s<>"\']+#i', $obs, $m)) return $m[0];
  // Variante: después de "Foto:" (con/ sin espacio)
  if (preg_match('/Foto:\s*(\S+)/i', $obs, $m)) return $m[1];
  return null;
}
function checkFormularioEmpresa(mysqli $conn, int $form_id, int $empresa_id): void {
  $q = $conn->prepare("SELECT 1 FROM formulario WHERE id = ? AND id_empresa = ? LIMIT 1");
  $q->bind_param("ii", $form_id, $empresa_id);
  $q->execute();
  if (!$q->get_result()->fetch_row()) {
    http_response_code(403);
    exit('Formulario no pertenece a tu empresa.');
  }
  $q->close();
}

// -------- Helpers filtros fecha (index-friendly) --------
function dateStart(?string $d): ?string { return $d !== '' ? $d . ' 00:00:00' : null; }
function dateEnd(?string $d): ?string   { return $d !== '' ? $d . ' 23:59:59' : null; }

// -------------------------------------------------------------
// Ramas: descarga total (GET action=all) o seleccionadas (POST)
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'all') {
  $formulario_id = intval($_GET['id']          ?? 0);
  $view          = $_GET['view']               ?? 'implementacion';
  $start_date    = trim($_GET['start_date']    ?? '');
  $end_date      = trim($_GET['end_date']      ?? '');
  $user_id       = intval($_GET['user_id']     ?? 0);
  $material_id   = intval($_GET['material_id'] ?? 0);
  $local_code    = trim($_GET['local_code']    ?? '');
  $id_question   = intval($_GET['id_question'] ?? 0);
  $modeParam     = $_GET['mode']               ?? null; // opcional (gv|legacy|hybrid) desde la UI

  if ($formulario_id <= 0) { http_response_code(400); exit('ID inválido'); }
  checkFormularioEmpresa($conn, $formulario_id, $empresa_id);

  // Detecta modo si no viene por GET (igual que en la galería)
  $mode = 'gv';
  if ($view === 'implementacion') {
    $gvCount = 0; $legacyOnly = 0;

    $q1 = $conn->prepare("
      SELECT COUNT(*) FROM gestion_visita gv
      JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
      WHERE gv.id_formulario = ?
    ");
    $q1->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);
    $q1->execute(); $q1->bind_result($gvCount); $q1->fetch(); $q1->close();

    $q2 = $conn->prepare("
      SELECT COUNT(*) FROM (
        SELECT fq.id
        FROM formularioQuestion fq
        JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
        JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        WHERE fq.id_formulario = ?
          AND NOT EXISTS (
            SELECT 1 FROM gestion_visita gv2
            WHERE gv2.id_formulario = fq.id_formulario
              AND gv2.id_formularioQuestion = fq.id
          )
        GROUP BY fq.id
      ) t
    ");
    $q2->bind_param("iii", $formulario_id, $empresa_id, $formulario_id);
    $q2->execute(); $q2->bind_result($legacyOnly); $q2->fetch(); $q2->close();

    if ($modeParam === 'gv' || $modeParam === 'legacy' || $modeParam === 'hybrid') {
      $mode = $modeParam;
    } else {
      $mode = ($gvCount > 0 && $legacyOnly > 0) ? 'hybrid' : (($gvCount > 0) ? 'gv' : 'legacy');
    }
  }

  $rows = [];

  if ($view === 'implementacion') {
    // -------- SELECT A: GV --------
    $sqlA = "
      SELECT fv.url AS url, u.usuario AS usuario, COALESCE(m.nombre, fq.material, '—') AS material, l.codigo AS codigo
      FROM gestion_visita gv
      JOIN formulario f ON f.id = gv.id_formulario AND f.id = ? AND f.id_empresa = ?
      JOIN usuario u ON u.id = gv.id_usuario
      JOIN local l   ON l.id = gv.id_local
      LEFT JOIN fotoVisita fv
        ON fv.visita_id = gv.visita_id
       AND (fv.id_material = gv.id_material OR fv.id_formularioQuestion = gv.id_formularioQuestion)
      LEFT JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
      LEFT JOIN material m            ON m.id = COALESCE(fv.id_material, gv.id_material)
      WHERE gv.id_formulario = ?
    ";
    $typesA  = "iii";
    $paramsA = [$formulario_id, $empresa_id, $formulario_id];
    if ($start_date !== '') { $sqlA .= " AND gv.fecha_visita >= ?"; $typesA .= "s"; $paramsA[] = dateStart($start_date); }
    if ($end_date   !== '') { $sqlA .= " AND gv.fecha_visita <= ?"; $typesA .= "s"; $paramsA[] = dateEnd($end_date); }
    if ($user_id > 0)       { $sqlA .= " AND gv.id_usuario = ?";    $typesA .= "i"; $paramsA[] = $user_id; }
    if ($local_code !== '') { $sqlA .= " AND l.codigo = ?";         $typesA .= "s"; $paramsA[] = $local_code; }
    if ($material_id > 0)   { $sqlA .= " AND COALESCE(fv.id_material, gv.id_material) = ?"; $typesA .= "i"; $paramsA[] = $material_id; }

    // -------- SELECT B: LEGACY-ONLY --------
    $sqlB = "
      SELECT fv.url AS url, u.usuario AS usuario, COALESCE(m.nombre, fq.material, '—') AS material, l.codigo AS codigo
      FROM formularioQuestion fq
      JOIN formulario f ON f.id = fq.id_formulario AND f.id = ? AND f.id_empresa = ?
      JOIN usuario u ON u.id = fq.id_usuario
      JOIN local l   ON l.id = fq.id_local
      JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
      LEFT JOIN material m ON m.id = fv.id_material
      WHERE fq.id_formulario = ?
        AND NOT EXISTS (
          SELECT 1 FROM gestion_visita gv2
          WHERE gv2.id_formulario = fq.id_formulario
            AND gv2.id_formularioQuestion = fq.id
        )
    ";
    $typesB  = "iii";
    $paramsB = [$formulario_id, $empresa_id, $formulario_id];
    if ($start_date !== '') { $sqlB .= " AND fq.fechaVisita >= ?"; $typesB .= "s"; $paramsB[] = dateStart($start_date); }
    if ($end_date   !== '') { $sqlB .= " AND fq.fechaVisita <= ?"; $typesB .= "s"; $paramsB[] = dateEnd($end_date); }
    if ($user_id > 0)       { $sqlB .= " AND fq.id_usuario = ?";   $typesB .= "i"; $paramsB[] = $user_id; }
    if ($local_code !== '') { $sqlB .= " AND l.codigo = ?";        $typesB .= "s"; $paramsB[] = $local_code; }
    if ($material_id > 0)   { $sqlB .= " AND COALESCE(fv.id_material,0) = ?"; $typesB .= "i"; $paramsB[] = $material_id; }

    if ($mode === 'gv' || $mode === 'hybrid') {
      $s = $conn->prepare($sqlA); $s->bind_param($typesA, ...$paramsA); $s->execute();
      $rows = array_merge($rows, $s->get_result()->fetch_all(MYSQLI_ASSOC)); $s->close();
    }
    if ($mode === 'legacy' || $mode === 'hybrid') {
      $s = $conn->prepare($sqlB); $s->bind_param($typesB, ...$paramsB); $s->execute();
      $rows = array_merge($rows, $s->get_result()->fetch_all(MYSQLI_ASSOC)); $s->close();
    }
  }
  elseif ($view === 'encuesta') {
    $sql = "
      SELECT fqr.answer_text AS url, u.usuario AS usuario, fq.question_text AS pregunta, l.codigo AS codigo
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      JOIN usuario u         ON u.id = fqr.id_usuario
      JOIN local l           ON l.id = fqr.id_local
      WHERE fq.id_formulario = ?
        AND fq.id_question_type = 7
    ";
    $types = "i"; $params = [$formulario_id];
    if ($start_date !== '') { $sql .= " AND fqr.created_at >= ?"; $types .= "s"; $params[] = dateStart($start_date); }
    if ($end_date   !== '') { $sql .= " AND fqr.created_at <= ?"; $types .= "s"; $params[] = dateEnd($end_date); }
    if ($user_id > 0)       { $sql .= " AND fqr.id_usuario = ?";  $types .= "i"; $params[] = $user_id; }
    if ($local_code !== '') { $sql .= " AND l.codigo = ?";        $types .= "s"; $params[] = $local_code; }
    if ($id_question > 0)   { $sql .= " AND fq.id = ?";           $types .= "i"; $params[] = $id_question; }
    $s = $conn->prepare($sql); $s->bind_param($types, ...$params); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
  }
  elseif ($view === 'locales_no_visitados') {
    $sql = "
      SELECT fq.observacion, u.usuario, l.codigo
      FROM formularioQuestion fq
      JOIN usuario u ON u.id = fq.id_usuario
      JOIN local l   ON l.id = fq.id_local
      WHERE fq.id_formulario = ?
        AND (fq.observacion LIKE '%local_cerrado%' OR fq.observacion LIKE '%local_no_existe%')
    ";
    $types = "i"; $params = [$formulario_id];
    if ($start_date !== '') { $sql .= " AND fq.fechaVisita >= ?"; $types .= "s"; $params[] = dateStart($start_date); }
    if ($end_date   !== '') { $sql .= " AND fq.fechaVisita <= ?"; $types .= "s"; $params[] = dateEnd($end_date); }
    if ($local_code !== '') { $sql .= " AND l.codigo = ?";        $types .= "s"; $params[] = $local_code; }
    $s = $conn->prepare($sql); $s->bind_param($types, ...$params); $s->execute();
    $rowsRaw = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
    // Extrae URL de observación en PHP (más robusto)
    $rows = [];
    foreach ($rowsRaw as $r) {
      $u = parseFirstUrlFromObservacion($r['observacion'] ?? '');
      if ($u) { $rows[] = ['url' => $u, 'usuario' => $r['usuario'], 'codigo' => $r['codigo']]; }
    }
  }
  else { // genérica (id_local=0)
    $sql = "
      SELECT fqr.answer_text AS url, u.usuario AS usuario, '' AS pregunta, '' AS codigo
      FROM form_question_responses fqr
      JOIN form_questions fq ON fq.id = fqr.id_form_question
      JOIN usuario u         ON u.id = fqr.id_usuario
      WHERE fq.id_formulario = ?
        AND fq.id_question_type = 7
        AND fqr.id_local = 0
    ";
    $types = "i"; $params = [$formulario_id];
    if ($start_date !== '') { $sql .= " AND fqr.created_at >= ?"; $types .= "s"; $params[] = dateStart($start_date); }
    if ($end_date   !== '') { $sql .= " AND fqr.created_at <= ?"; $types .= "s"; $params[] = dateEnd($end_date); }
    $s = $conn->prepare($sql); $s->bind_param($types, ...$params); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
  }

  // --- Construir listado final de archivos ---
  $files = [];
  $seen  = [];
  foreach ($rows as $r) {
    $url     = normalizeUrl($r['url'] ?? '', $BASE_URL);
    if (!$url) continue;
    $usuario = sanitizeFilename($r['usuario'] ?? 'user');
    $codigo  = sanitizeFilename($r['codigo']  ?? 'local');
    $prefix  = $usuario . '_' . $codigo;

    if ($view === 'implementacion') {
      $mat = sanitizeFilename($r['material'] ?? 'material');
      $prefix = $usuario . '_' . $mat . '_' . $codigo;
    } elseif ($view === 'encuesta') {
      $pre = sanitizeFilename($r['pregunta'] ?? 'encuesta');
      $prefix = $usuario . '_' . $pre . '_' . $codigo;
    }

    $basename = basename(parse_url($url, PHP_URL_PATH) ?? 'foto.jpg');
    $filename = addUnique($seen, sanitizeFilename($prefix . '_' . $basename));
    $files[]  = ['url' => $url, 'filename' => $filename];
  }

  // --- Crear ZIP ---
  $zipPath = tempnam(sys_get_temp_dir(), 'zip_');
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500); exit('No se pudo crear el ZIP.');
  }

  foreach ($files as $f) {
    $u = $f['url']; $name = $f['filename'];
    $added = false;

    // 1) ¿URL local bajo BASE_URL?
    if (stripos($u, $BASE_URL) === 0) {
      $rel = ltrim(substr($u, strlen($BASE_URL)), '/');
      $loc = $DOCROOT_APP . $rel;
      if (is_file($loc)) { $zip->addFile($loc, $name); $added = true; }
    }

    // 2) Si no, intenta remoto
    if (!$added && isAbsoluteUrl($u)) {
      $tmp = fetchToTemp($u);
      if ($tmp && is_file($tmp)) { $zip->addFile($tmp, $name); $added = true; }
      // Nota: borraremos luego todos los tmp remotos
      $f['_tmp'] = $tmp;
    }

    if (!$added) {
      error_log("No se pudo agregar: $u");
    }
  }
  $zip->close();

  // Output
  header('Content-Type: application/zip');
  header('X-Content-Type-Options: nosniff');
  header('Content-Disposition: attachment; filename="fotos_todas.zip"');
  header('Content-Length: ' . filesize($zipPath));
  readfile($zipPath);
  @unlink($zipPath);
  // limpia temporales remotos
  foreach ($files as $f) { if (!empty($f['_tmp'])) @unlink($f['_tmp']); }
  exit;
}

// -------------------------- Seleccionadas (POST) ----------------------------
if (empty($_POST['jsonFotos'])) { http_response_code(400); exit('No se recibieron fotos.'); }
if ($empresa_id <= 0) { http_response_code(403); exit('Acceso inválido.'); }
// Sugerido: validar CSRF token (agregarlo en la UI)

$fotos = json_decode($_POST['jsonFotos'], true);
if (!is_array($fotos) || !$fotos) { http_response_code(400); exit('Lista de fotos inválida.'); }

$zipPath = tempnam(sys_get_temp_dir(), 'zip_');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('No se pudo crear el ZIP.'); }

$seen = [];
foreach ($fotos as $f) {
  $url  = normalizeUrl($f['url'] ?? '', $BASE_URL);
  $name = addUnique($seen, sanitizeFilename($f['filename'] ?? ('foto_' . uniqid() . '.jpg')));
  $added = false;

  if (stripos($url, $BASE_URL) === 0) {
    $rel = ltrim(substr($url, strlen($BASE_URL)), '/');
    $loc = $DOCROOT_APP . $rel;
    if (is_file($loc)) { $zip->addFile($loc, $name); $added = true; }
  }
  if (!$added && isAbsoluteUrl($url)) {
    $tmp = fetchToTemp($url);
    if ($tmp && is_file($tmp)) { $zip->addFile($tmp, $name); $added = true; }
    $f['_tmp'] = $tmp;
  }
  if (!$added) { error_log("No encontrado: $url"); }
}
$zip->close();

header('Content-Type: application/zip');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="fotos_seleccionadas.zip"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
@unlink($zipPath);
exit;
