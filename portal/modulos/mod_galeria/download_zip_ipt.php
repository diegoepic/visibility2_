<?php
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

ini_set('memory_limit', '1024M');
set_time_limit(0);

$empresaId    = (int)($_SESSION['empresa_id'] ?? 0);
$divisionLogin = (int)($_SESSION['division_id'] ?? 0);

if ($empresaId <= 0) {
    http_response_code(403);
    exit('Acceso inválido.');
}

if (($_GET['action'] ?? '') !== 'all_ipt' || ($_GET['filtrar'] ?? '') !== '1') {
    http_response_code(400);
    exit('Primero debes aplicar los filtros de la galería.');
}

$view = in_array($_GET['view'] ?? '', ['implementacion', 'encuesta'], true)
    ? $_GET['view']
    : 'implementacion';

$filters = [
    'division'     => (int)($_GET['division'] ?? $divisionLogin),
    'subdivision'  => (int)($_GET['subdivision'] ?? 0),
    'region'       => (int)($_GET['region'] ?? 0),
    'comuna'       => (int)($_GET['comuna'] ?? 0),
    'zona'         => (int)($_GET['zona'] ?? 0),
    'distrito'     => (int)($_GET['distrito'] ?? 0),
    'usuario'      => (int)($_GET['usuario'] ?? 0),
    'jefe_venta'   => (int)($_GET['jefe_venta'] ?? 0),
    'codigo_local' => trim((string)($_GET['codigo_local'] ?? '')),
    'material'     => trim((string)($_GET['material'] ?? '')),
    'pregunta'     => trim((string)($_GET['pregunta'] ?? '')),
    'start_date'   => trim((string)($_GET['start_date'] ?? '')),
    'end_date'     => trim((string)($_GET['end_date'] ?? '')),
];

// Sólo MC puede abrir el filtro a otras divisiones.
if ($divisionLogin !== 1 && $divisionLogin > 0) {
    $filters['division'] = $divisionLogin;
}

foreach (['start_date', 'end_date'] as $dateKey) {
    if ($filters[$dateKey] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateKey])) {
        http_response_code(400);
        exit('Fecha inválida.');
    }
}

function iptBindAndFetch(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Galería IPT ZIP prepare: ' . $conn->error);
        http_response_code(500);
        exit('No fue posible preparar la descarga.');
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        error_log('Galería IPT ZIP execute: ' . $stmt->error);
        $stmt->close();
        http_response_code(500);
        exit('No fue posible consultar las fotos.');
    }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function iptAddCommonFilters(string &$sql, string &$types, array &$params, array $f, string $dateColumn, string $userColumn): void
{
    if ($f['division'] > 0)    { $sql .= ' AND f.id_division = ?';    $types .= 'i'; $params[] = $f['division']; }
    if ($f['subdivision'] > 0) { $sql .= ' AND f.id_subdivision = ?'; $types .= 'i'; $params[] = $f['subdivision']; }
    if ($f['region'] > 0)      { $sql .= ' AND l.id_comuna IN (SELECT id FROM comuna WHERE id_region = ?)'; $types .= 'i'; $params[] = $f['region']; }
    if ($f['comuna'] > 0)      { $sql .= ' AND l.id_comuna = ?';      $types .= 'i'; $params[] = $f['comuna']; }
    if ($f['zona'] > 0)        { $sql .= ' AND l.id_zona = ?';        $types .= 'i'; $params[] = $f['zona']; }
    if ($f['distrito'] > 0)    { $sql .= ' AND l.id_distrito = ?';    $types .= 'i'; $params[] = $f['distrito']; }
    if ($f['jefe_venta'] > 0)  { $sql .= ' AND l.id_jefe_venta = ?';  $types .= 'i'; $params[] = $f['jefe_venta']; }
    if ($f['usuario'] > 0)     { $sql .= " AND {$userColumn} = ?";    $types .= 'i'; $params[] = $f['usuario']; }
    if ($f['codigo_local'] !== '') { $sql .= ' AND l.codigo = ?';     $types .= 's'; $params[] = $f['codigo_local']; }
    if ($f['start_date'] !== '')   { $sql .= " AND {$dateColumn} >= ?"; $types .= 's'; $params[] = $f['start_date'] . ' 00:00:00'; }
    if ($f['end_date'] !== '')     { $sql .= " AND {$dateColumn} <= ?"; $types .= 's'; $params[] = $f['end_date'] . ' 23:59:59'; }
}

function iptNormalizeUrl(string $url, string $baseUrl): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

function iptSanitizeFilename(string $value, int $max = 180): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $ascii === false ? $value : $ascii;
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
    $value = trim((string)$value, '._-');
    if ($value === '') $value = 'foto';
    return mb_substr($value, 0, $max);
}

function iptUniqueFilename(array &$used, string $name): string
{
    $original = $name;
    $number = 2;
    while (isset($used[$name])) {
        $dot = strrpos($original, '.');
        $name = $dot === false
            ? $original . '-' . $number
            : substr($original, 0, $dot) . '-' . $number . substr($original, $dot);
        $number++;
    }
    $used[$name] = true;
    return $name;
}

function iptFetchRemote(string $url): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'galeria_ipt_');
    if ($tmp === false) return null;

    $fp = fopen($tmp, 'wb');
    if (!$fp) { @unlink($tmp); return null; }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Visibility-Galeria/1.0',
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $status >= 400 || filesize($tmp) === 0) {
        @unlink($tmp);
        return null;
    }
    return $tmp;
}

$rows = [];

if ($view === 'implementacion') {
    $sqlGv = "
        SELECT DISTINCT fv.id AS photo_id, fv.url, u.usuario,
               l.codigo, l.direccion,
               COALESCE(m.nombre, fq.material, 'material') AS etiqueta
        FROM gestion_visita gv
        JOIN formulario f ON f.id = gv.id_formulario AND f.id_empresa = ?
        JOIN usuario u ON u.id = gv.id_usuario
        JOIN local l ON l.id = gv.id_local
        JOIN fotoVisita fv
          ON fv.visita_id = gv.visita_id
         AND (fv.id_material = gv.id_material OR fv.id_formularioQuestion = gv.id_formularioQuestion)
        LEFT JOIN formularioQuestion fq ON fq.id = gv.id_formularioQuestion
        LEFT JOIN material m ON m.id = COALESCE(fv.id_material, gv.id_material)
        WHERE COALESCE(TRIM(fv.url), '') <> ''
    ";
    $typesGv = 'i';
    $paramsGv = [$empresaId];
    iptAddCommonFilters($sqlGv, $typesGv, $paramsGv, $filters, 'gv.fecha_visita', 'gv.id_usuario');
    if ($filters['material'] !== '') {
        $sqlGv .= " AND UPPER(TRIM(COALESCE(m.nombre, fq.material, ''))) = UPPER(TRIM(?))";
        $typesGv .= 's';
        $paramsGv[] = $filters['material'];
    }
    $rows = iptBindAndFetch($conn, $sqlGv, $typesGv, $paramsGv);

    $sqlLegacy = "
        SELECT DISTINCT fv.id AS photo_id, fv.url, u.usuario,
               l.codigo, l.direccion,
               COALESCE(m.nombre, fq.material, 'material') AS etiqueta
        FROM formularioQuestion fq
        JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?
        JOIN usuario u ON u.id = fq.id_usuario
        JOIN local l ON l.id = fq.id_local
        JOIN fotoVisita fv ON fv.id_formularioQuestion = fq.id
        LEFT JOIN material m ON m.id = fv.id_material
        WHERE COALESCE(TRIM(fv.url), '') <> ''
          AND NOT EXISTS (
              SELECT 1 FROM gestion_visita gv2
              WHERE gv2.id_formulario = fq.id_formulario
                AND gv2.id_formularioQuestion = fq.id
          )
    ";
    $typesLegacy = 'i';
    $paramsLegacy = [$empresaId];
    iptAddCommonFilters($sqlLegacy, $typesLegacy, $paramsLegacy, $filters, 'fq.fechaVisita', 'fq.id_usuario');
    if ($filters['material'] !== '') {
        $sqlLegacy .= " AND UPPER(TRIM(COALESCE(m.nombre, fq.material, ''))) = UPPER(TRIM(?))";
        $typesLegacy .= 's';
        $paramsLegacy[] = $filters['material'];
    }
    $rows = array_merge($rows, iptBindAndFetch($conn, $sqlLegacy, $typesLegacy, $paramsLegacy));
} else {
    $sql = "
        SELECT DISTINCT fqr.id AS photo_id, fqr.answer_text AS url, u.usuario,
               l.codigo, l.direccion, fq.question_text AS etiqueta
        FROM form_question_responses fqr
        JOIN form_questions fq ON fq.id = fqr.id_form_question
        JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?
        JOIN usuario u ON u.id = fqr.id_usuario
        JOIN local l ON l.id = fqr.id_local
        WHERE fq.id_question_type = 7
          AND fqr.id_local <> 0
          AND COALESCE(TRIM(fqr.answer_text), '') <> ''
    ";
    $types = 'i';
    $params = [$empresaId];
    iptAddCommonFilters($sql, $types, $params, $filters, 'fqr.created_at', 'fqr.id_usuario');
    if ($filters['pregunta'] !== '') {
        $sql .= ' AND UPPER(TRIM(fq.question_text)) = UPPER(TRIM(?))';
        $types .= 's';
        $params[] = $filters['pregunta'];
    }
    $rows = iptBindAndFetch($conn, $sql, $types, $params);
}

$baseUrl = 'https://visibility.cl/visibility2/app/';
$localRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/visibility2/app/';
$zipPath = tempnam(sys_get_temp_dir(), 'galeria_ipt_zip_');
$zip = new ZipArchive();

if ($zipPath === false || $zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('No se pudo crear el ZIP.');
}

$usedNames = [];
$remoteTemps = [];
$addedCount = 0;

foreach ($rows as $row) {
    foreach (preg_split('/\s*\|\|\s*/', (string)($row['url'] ?? '')) as $rawUrl) {
        $url = iptNormalizeUrl($rawUrl, $baseUrl);
        if ($url === '') continue;

        $path = parse_url($url, PHP_URL_PATH) ?: 'foto.jpg';
        $basename = basename($path) ?: 'foto.jpg';
        $prefix = implode('_', [
            $row['direccion'] ?? 'sin_direccion',
            $row['usuario'] ?? 'usuario',
            $row['etiqueta'] ?? ($view === 'encuesta' ? 'encuesta' : 'material'),
            $row['codigo'] ?? 'local',
        ]);
        $filename = iptUniqueFilename($usedNames, iptSanitizeFilename($prefix . '_' . $basename));
        $added = false;

        if (stripos($url, $baseUrl) === 0) {
            $relative = ltrim(substr($url, strlen($baseUrl)), '/');
            $localPath = $localRoot . $relative;
            if (is_file($localPath)) {
                $added = $zip->addFile($localPath, $filename);
            }
        }

        if (!$added && preg_match('#^https?://#i', $url)) {
            $tmp = iptFetchRemote($url);
            if ($tmp !== null) {
                $added = $zip->addFile($tmp, $filename);
                $remoteTemps[] = $tmp;
            }
        }

        if ($added) {
            $addedCount++;
        } else {
            error_log('Galería IPT: no se pudo agregar ' . $url);
        }
    }
}

$zip->close();

if ($addedCount === 0) {
    @unlink($zipPath);
    foreach ($remoteTemps as $tmp) @unlink($tmp);
    http_response_code(404);
    exit('No se encontraron fotos para los filtros aplicados.');
}

$filename = 'fotos_filtradas_' . date('Y-m-d_His') . '.zip';
header('Content-Type: application/zip');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);

@unlink($zipPath);
foreach ($remoteTemps as $tmp) @unlink($tmp);
exit;
