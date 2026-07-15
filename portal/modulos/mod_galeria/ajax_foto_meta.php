<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

$empresa_id    = (int)($_SESSION['empresa_id'] ?? 0);
$resp_id       = (int)($_GET['resp_id']        ?? 0);
$formulario_id = (int)($_GET['id']             ?? 0);

if (!$empresa_id || !$resp_id || !$formulario_id) {
    echo json_encode(['ok' => false]);
    exit;
}

mysqli_set_charset($conn, 'utf8mb4');
$conn->query("SET time_zone = 'America/Santiago'");


// dup_flag puede no existir aún (BBDD sin la migración 13_fotos_duplicadas_encuesta.sql) → seleccionar 0.
$fqpmHasDup = false;
try { $cdup = $conn->query("SHOW COLUMNS FROM form_question_photo_meta LIKE 'dup_flag'"); $fqpmHasDup = ($cdup && $cdup->num_rows > 0); if ($cdup) { $cdup->close(); } } catch (Throwable $e) { $fqpmHasDup = false; }
$dupSel = $fqpmHasDup ? 'm.dup_flag' : '0 AS dup_flag';

$stmt = $conn->prepare("
    SELECT
        {$dupSel},
        m.created_at          AS subida_at,
        m.capture_source,
        m.exif_datetime,
        m.exif_lat,
        m.exif_lng,
        m.exif_altitude,
        m.exif_img_direction,
        m.exif_make,
        m.exif_model,
        m.exif_software,
        m.exif_lens_model,
        m.exif_fnumber,
        m.exif_exposure_time,
        m.exif_iso,
        m.exif_focal_length,
        m.exif_orientation,
        JSON_UNQUOTE(JSON_EXTRACT(m.meta_json, '$.sha1')) AS sha1
    FROM form_question_photo_meta m
    JOIN form_question_responses r  ON r.id  = m.resp_id
    JOIN form_questions fq          ON fq.id = r.id_form_question
    JOIN formulario f               ON f.id  = fq.id_formulario
    WHERE m.resp_id       = ?
      AND fq.id_formulario = ?
      AND f.id_empresa     = ?
    LIMIT 1
");

if (!$stmt) {
    echo json_encode(['ok' => false]);
    exit;
}

$stmt->bind_param('iii', $resp_id, $formulario_id, $empresa_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true, 'meta' => $row]);
