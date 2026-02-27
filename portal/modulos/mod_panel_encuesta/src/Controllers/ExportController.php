<?php
declare(strict_types=1);

namespace PanelEncuesta\Controllers;

use PanelEncuesta\Services\FilterService;
use PanelEncuesta\ValueObjects\FilterParams;
use PanelEncuesta\Repositories\ResponseRepository;

/**
 * Maneja todos los endpoints de exportación:
 *  - export_csv_panel_encuesta.php       → handleCsv()
 *  - export_pdf_panel_encuesta.php       → handlePdf()
 *  - export_pdf_panel_encuesta_fotos.php → handlePdfFotos()
 *  - export_zip_fotos_panel_encuesta.php → handleZip()
 */
class ExportController
{
    public function __construct(private \mysqli $conn) {}

    // ------------------------------------------------------------------
    // CSV
    // ------------------------------------------------------------------

    public function handleCsv(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401); header('Content-Type: text/plain; charset=UTF-8'); exit("Sesión expirada");
        }

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);

        $SRC = $_POST ?: $_GET;
        $csrf = $SRC['csrf_token'] ?? '';
        if (!panel_encuesta_validate_csrf(is_string($csrf) ? $csrf : '')) {
            http_response_code(403); header('Content-Type: text/plain'); exit('Token CSRF inválido.');
        }
        if (!panel_encuesta_check_export_rate('csv', 3, 15)) {
            http_response_code(429); header('Content-Type: text/plain'); exit('Demasiadas solicitudes. Espera unos segundos.');
        }

        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $empresa_id = (int)($_SESSION['empresa_id']  ?? 0);

        [$whereSql, $types, $params, $metaFilters] = build_panel_encuesta_filters(
            $empresa_id, $user_div, $SRC,
            ['foto_only' => false, 'enforce_date_fallback' => true]
        );

        if (!empty($metaFilters['range_risky_no_scope'])) {
            http_response_code(400); header('Content-Type: text/plain');
            exit('Rango demasiado amplio sin filtros adicionales. Acota fechas o selecciona campaña.');
        }

        $rawMode     = isset($SRC['raw']) && (int)$SRC['raw'] === 1;
        $exportLimit = 50000;

        $fname = 'panel_encuesta_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8

        $t0 = microtime(true);

        if ($rawMode) {
            $this->streamCsvRaw($out, $whereSql, $types, $params, $exportLimit);
        } else {
            $this->streamCsvGrouped($out, $whereSql, $types, $params, $exportLimit);
        }

        fclose($out);
        $duration = microtime(true) - $t0;
        if (function_exists('log_panel_encuesta_query')) {
            log_panel_encuesta_query($this->conn, $rawMode ? 'csv_raw' : 'csv', 0, array_merge($metaFilters, ['duration_sec' => $duration]));
        }
    }

    // ------------------------------------------------------------------
    // PDF (fotos, export_pdf_panel_encuesta.php)
    // ------------------------------------------------------------------

    public function handlePdf(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401); exit("Sesión expirada");
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);

        $SRC = $_POST ?: $_GET;
        $csrf = $SRC['csrf_token'] ?? '';
        if (!panel_encuesta_validate_csrf(is_string($csrf) ? $csrf : '')) {
            http_response_code(403); header('Content-Type: text/plain'); exit('Token CSRF inválido.');
        }
        if (!panel_encuesta_check_export_rate('pdf', 2, 20)) {
            http_response_code(429); header('Content-Type: text/plain'); exit('Demasiadas solicitudes de exportación PDF.');
        }

        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $empresa_id = (int)($_SESSION['empresa_id']  ?? 0);
        $output     = in_array($SRC['output'] ?? 'pdf', ['pdf', 'html'], true) ? ($SRC['output'] ?? 'pdf') : 'pdf';

        [$whereSql, $types, $params, $metaFilters] = build_panel_encuesta_filters(
            $empresa_id, $user_div, $SRC,
            ['foto_only' => true, 'enforce_date_fallback' => true]
        );

        if (!empty($metaFilters['range_risky_no_scope'])) {
            http_response_code(400); header('Content-Type: text/plain');
            exit('Rango demasiado amplio sin filtros adicionales.');
        }

        $maxRows = ($output === 'pdf') ? 800 : 4000;

        $sql = "
          SELECT
            fqr.visita_id, fq.id AS pregunta_id,
            DATE_FORMAT(fqr.created_at,'%d/%m/%Y %H:%i:%s') AS fecha_subida,
            COALESCE(pm.foto_url, o.option_text, fqr.answer_text) AS foto_url,
            l.codigo AS local_codigo, l.nombre AS local_nombre, l.direccion,
            c.comuna AS comuna, cad.nombre AS cadena, jv.nombre AS jefe_venta, u.usuario
          FROM form_question_responses fqr
          JOIN form_questions fq  ON fq.id = fqr.id_form_question
          JOIN formulario f       ON f.id  = fq.id_formulario
          JOIN local l            ON l.id  = fqr.id_local
          LEFT JOIN comuna c      ON c.id  = l.id_comuna
          LEFT JOIN cadena cad    ON cad.id = l.id_cadena
          LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
          JOIN usuario u          ON u.id  = fqr.id_usuario
          LEFT JOIN form_question_options o ON o.id = fqr.id_option
          LEFT JOIN form_question_photo_meta pm ON pm.resp_id = fqr.id
          $whereSql
          ORDER BY fqr.created_at DESC, fqr.id DESC
          LIMIT $maxRows
        ";

        $st = $this->conn->prepare($sql);
        if ($types) { $st->bind_param($types, ...$params); }
        $t0 = microtime(true);
        $st->execute();
        $rs = $st->get_result();

        $groups    = [];
        $rowsCount = 0;
        while ($r = $rs->fetch_assoc()) {
            $key = ($r['visita_id'] ?? '0') . '|' . ($r['pregunta_id'] ?? '0');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'fotos' => [], 'local_codigo' => $r['local_codigo'],
                    'local_nombre' => $r['local_nombre'], 'direccion' => $r['direccion'],
                    'comuna' => $r['comuna'], 'cadena' => $r['cadena'],
                    'jefe_venta' => $r['jefe_venta'], 'usuario' => $r['usuario'],
                    'fecha_subida' => $r['fecha_subida'],
                ];
            }
            $u = panel_encuesta_resolve_photo_url($r['foto_url'] ?? null);
            if ($u !== null && $u !== '') {
                $h = md5($u);
                if (!isset($groups[$key]['_seen'][$h])) {
                    $groups[$key]['fotos'][]        = $u;
                    $groups[$key]['_seen'][$h]       = true;
                }
            }
            $rowsCount++;
        }
        $st->close();

        $rows        = array_values($groups);
        $isTruncated = ($rowsCount >= $maxRows);
        if ($isTruncated) {
            @header('X-Export-Truncated: 1');
            @header('X-Export-MaxRows: ' . $maxRows);
        }

        if (function_exists('log_panel_encuesta_query')) {
            log_panel_encuesta_query($this->conn, 'pdf_fotos', $rowsCount, array_merge($metaFilters, [
                'duration_sec' => microtime(true) - $t0, 'rows' => $rowsCount,
            ]));
        }

        if ($output === 'html') {
            $this->renderPdfHtml($rows, $maxRows, $isTruncated);
            return;
        }

        $pdfHtml = $this->buildPdfHtml($rows, $maxRows, $isTruncated);
        $this->streamPdf($pdfHtml, 'panel_fotos_' . date('Ymd_His') . '.pdf', $debugId ?? '');
    }

    // ------------------------------------------------------------------
    // PDF Fotos (export_pdf_panel_encuesta_fotos.php — data URI thumbnails)
    // ------------------------------------------------------------------

    public function handlePdfFotos(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401); header('Content-Type: text/plain'); exit("Sesión expirada");
        }

        @ini_set('memory_limit', '768M');
        @set_time_limit(120);

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);

        $SRC = $_POST ?: $_GET;
        $csrf = $SRC['csrf_token'] ?? '';
        if (!panel_encuesta_validate_csrf(is_string($csrf) ? $csrf : '')) {
            http_response_code(403); header('Content-Type: text/plain'); exit('Token CSRF inválido.');
        }
        if (!panel_encuesta_check_export_rate('pdf_fotos', 2, 20)) {
            http_response_code(429); header('Content-Type: text/plain'); exit('Demasiadas solicitudes.');
        }

        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $empresa_id = (int)($_SESSION['empresa_id']  ?? 0);

        [$whereSql, $types, $params, $metaFilters] = build_panel_encuesta_filters(
            $empresa_id, $user_div, $SRC,
            ['foto_only' => true, 'enforce_date_fallback' => true]
        );

        if (!empty($metaFilters['range_risky_no_scope'])) {
            http_response_code(400); header('Content-Type: text/plain');
            exit('Rango demasiado amplio sin filtros adicionales.');
        }

        $MAX_ROWS = 250;
        $sql = "
          SELECT fqr.id AS resp_id,
            CASE WHEN fqr.answer_text IS NOT NULL AND fqr.answer_text<>'' THEN fqr.answer_text
                 WHEN o.option_text IS NOT NULL AND o.option_text<>'' THEN o.option_text
                 ELSE NULL END AS foto_url,
            DATE_FORMAT(fqr.created_at,'%d/%m/%Y %H:%i:%s') AS fecha_foto,
            l.codigo AS local_codigo, l.nombre AS local_nombre, l.direccion,
            cm.comuna, cad.nombre AS cadena, jv.nombre AS jefe_venta,
            ve.nombre_vendedor AS vendedor_nombre, u.usuario
          FROM form_question_responses fqr
          JOIN form_questions fq       ON fq.id  = fqr.id_form_question
          JOIN formulario f            ON f.id   = fq.id_formulario
          JOIN local l                 ON l.id   = fqr.id_local
          LEFT JOIN comuna cm          ON cm.id  = l.id_comuna
          LEFT JOIN cadena cad         ON cad.id = l.id_cadena
          LEFT JOIN distrito d         ON d.id   = l.id_distrito
          LEFT JOIN jefe_venta jv      ON jv.id  = l.id_jefe_venta
          LEFT JOIN vendedor ve        ON ve.id  = l.id_vendedor
          JOIN usuario u               ON u.id   = fqr.id_usuario
          JOIN visita v                ON v.id   = fqr.visita_id
          LEFT JOIN form_question_options o ON o.id = fqr.id_option
          $whereSql
          ORDER BY fqr.created_at DESC, fqr.id DESC
          LIMIT $MAX_ROWS
        ";

        $t0 = microtime(true);
        $st = $this->conn->prepare($sql);
        if ($types) { $st->bind_param($types, ...$params); }
        $st->execute();
        $rs = $st->get_result();

        $rows     = [];
        $rowCount = 0;
        while ($r = $rs->fetch_assoc()) {
            $fotoUrl = $r['foto_url'] ?? null;
            if (!$fotoUrl) continue;
            $resolvedUrl  = panel_encuesta_resolve_photo_url($fotoUrl);
            $thumbDataUri = $this->buildThumbDataUri($resolvedUrl ?? $fotoUrl);
            $rows[] = [
                'thumb'        => $thumbDataUri,
                'link'         => $resolvedUrl,
                'local_codigo' => (string)($r['local_codigo'] ?? ''),
                'local_nombre' => (string)($r['local_nombre'] ?? ''),
                'direccion'    => (string)($r['direccion']    ?? ''),
                'comuna'       => (string)($r['comuna']       ?? ''),
                'cadena'       => (string)($r['cadena']       ?? ''),
                'jefe_venta'   => (string)($r['jefe_venta']   ?? ''),
                'vendedor'     => (string)($r['vendedor_nombre'] ?? ''),
                'usuario'      => (string)($r['usuario']      ?? ''),
                'fecha_foto'   => (string)($r['fecha_foto']   ?? ''),
            ];
            $rowCount++;
        }
        $st->close();

        $isTruncated = ($rowCount >= $MAX_ROWS);
        if (function_exists('log_panel_encuesta_query')) {
            log_panel_encuesta_query($this->conn, 'pdf_fotos_inline', $rowCount, array_merge($metaFilters, [
                'duration_sec' => microtime(true) - $t0, 'rows' => $rowCount, 'truncated' => $isTruncated,
            ]));
        }

        if ($rowCount === 0) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo "No se encontraron fotos con los filtros seleccionados."; return;
        }

        $html = $this->buildPdfFotosHtml($rows, $MAX_ROWS, $isTruncated);
        $this->streamPdf($html, 'panel_encuesta_fotos_' . date('Ymd_His') . '.pdf', $debugId ?? '', 144);
    }

    // ------------------------------------------------------------------
    // ZIP Fotos
    // ------------------------------------------------------------------

    public function handleZip(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            http_response_code(401); header('Content-Type: text/plain'); exit("Sesión expirada");
        }

        @ini_set('memory_limit', '256M');
        @set_time_limit(180);

        $debugId = panel_encuesta_request_id();
        header('X-Request-Id: ' . $debugId);

        if (!class_exists('ZipArchive')) {
            http_response_code(500); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'ZipArchive no disponible.']); return;
        }

        $SRC = $_POST ?: $_GET;
        $csrf = $SRC['csrf_token'] ?? '';
        if (!panel_encuesta_validate_csrf(is_string($csrf) ? $csrf : '')) {
            http_response_code(403); header('Content-Type: text/plain'); exit('Token CSRF inválido.');
        }
        if (!panel_encuesta_check_export_rate('zip_fotos', 2, 20)) {
            http_response_code(429); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Demasiadas solicitudes.']); return;
        }

        $user_div   = (int)($_SESSION['division_id'] ?? 0);
        $empresa_id = (int)($_SESSION['empresa_id']  ?? 0);

        [$whereSql, $types, $params, $metaFilters] = build_panel_encuesta_filters(
            $empresa_id, $user_div, $SRC,
            ['foto_only' => true, 'enforce_date_fallback' => true]
        );

        if (!empty($metaFilters['range_risky_no_scope'])) {
            http_response_code(400); header('Content-Type: text/plain');
            exit('Rango demasiado amplio sin filtros adicionales.');
        }

        $MAX_FOTOS = 500;
        $sql = "
          SELECT fqr.id AS resp_id, fq.id AS pregunta_id,
            COALESCE(pm.foto_url, o.option_text, fqr.answer_text) AS foto_raw,
            DATE_FORMAT(COALESCE(v.fecha_fin, fqr.created_at), '%Y%m%d_%H%i%s') AS fecha_slug,
            l.codigo AS local_codigo, l.nombre AS local_nombre
          FROM form_question_responses fqr
          JOIN form_questions fq ON fq.id = fqr.id_form_question
          JOIN formulario f      ON f.id  = fq.id_formulario
          JOIN local l           ON l.id  = fqr.id_local
          JOIN usuario u         ON u.id  = fqr.id_usuario
          JOIN visita v          ON v.id  = fqr.visita_id
          LEFT JOIN form_question_options o  ON o.id  = fqr.id_option
          LEFT JOIN form_question_photo_meta pm ON pm.resp_id = fqr.id
          $whereSql
          ORDER BY fqr.created_at DESC, fqr.id DESC
          LIMIT $MAX_FOTOS
        ";

        $t0 = microtime(true);
        $st = $this->conn->prepare($sql);
        if ($types) { $st->bind_param($types, ...$params); }
        $st->execute();
        $rs = $st->get_result();

        $tmpZip = tempnam(sys_get_temp_dir(), 'panel_zip_');
        $zip    = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            http_response_code(500); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'No se pudo crear el ZIP temporal.']); return;
        }

        $fotosAdded = 0;
        $seenHashes = [];
        while ($r = $rs->fetch_assoc()) {
            $fotoRaw = $r['foto_raw'] ?? null;
            if (!$fotoRaw) continue;
            $fsPath = panel_encuesta_photo_fs_path($fotoRaw);
            if (!$fsPath || !is_file($fsPath)) continue;
            $hash = md5($fsPath);
            if (isset($seenHashes[$hash])) continue;
            $seenHashes[$hash] = true;
            $localDir = $this->zipSafeName(($r['local_codigo'] ?? 'sin_codigo') . '_' . ($r['local_nombre'] ?? 'sin_nombre'));
            $ext      = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION)) ?: 'jpg';
            $fileName = $this->zipSafeName(($r['fecha_slug'] ?? 'sin_fecha') . '_p' . (int)$r['pregunta_id']) . '.' . $ext;
            $zip->addFile($fsPath, $localDir . '/' . $fileName);
            $fotosAdded++;
        }
        $st->close();
        $zip->close();

        $duration = microtime(true) - $t0;
        if (function_exists('log_panel_encuesta_query')) {
            log_panel_encuesta_query($this->conn, 'zip_fotos', $fotosAdded, array_merge($metaFilters, ['duration_sec' => $duration]));
        }

        if ($fotosAdded === 0) {
            @unlink($tmpZip);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'empty', 'message' => 'No se encontraron fotos con los filtros seleccionados.']); return;
        }

        $filename = 'panel_fotos_' . date('Ymd_His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmpZip));
        header('Cache-Control: no-store');
        readfile($tmpZip);
        @unlink($tmpZip);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function streamCsvRaw($fh, string $whereSql, string $types, array $params, int $limit): void
    {
        $sql = "
          SELECT fqr.id AS resp_id, v.id AS visita_id,
            CASE WHEN v.fecha_fin IS NOT NULL THEN DATE_FORMAT(v.fecha_fin,'%d/%m/%Y %H:%i:%s')
                 ELSE DATE_FORMAT(fqr.created_at,'%d/%m/%Y %H:%i:%s') END AS fecha,
            DATE_FORMAT(fqr.created_at,'%d/%m/%Y %H:%i:%s') AS fecha_respuesta,
            f.nombre AS campana, fq.id AS pregunta_id, fq.question_text AS pregunta,
            fq.id_question_type AS tipo,
            COALESCE(o.option_text, fqr.answer_text) AS respuesta, fqr.valor,
            l.codigo AS local_codigo, l.nombre AS local_nombre, l.direccion,
            c.nombre AS cadena, d.nombre_distrito AS distrito, jv.nombre AS jefe_venta, u.usuario
          FROM form_question_responses fqr
          JOIN form_questions fq  ON fq.id = fqr.id_form_question
          JOIN formulario f       ON f.id  = fq.id_formulario
          JOIN local l            ON l.id  = fqr.id_local
          LEFT JOIN cadena c      ON c.id  = l.id_cadena
          LEFT JOIN distrito d    ON d.id  = l.id_distrito
          LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
          JOIN usuario u          ON u.id  = fqr.id_usuario
          LEFT JOIN form_question_options o ON o.id = fqr.id_option
          JOIN visita v           ON v.id  = fqr.visita_id
          $whereSql
          ORDER BY COALESCE(v.fecha_fin, fqr.created_at) DESC, fqr.visita_id DESC, fq.id, fqr.id DESC
          LIMIT $limit
        ";
        $st = $this->conn->prepare($sql);
        if ($types) { $st->bind_param($types, ...$params); }
        $st->execute();
        $rs = $st->get_result();
        fputcsv($fh, ['ID Respuesta', 'ID Visita', 'Fecha Visita', 'Fecha Respuesta', 'Campaña',
            'ID Pregunta', 'Pregunta', 'Tipo', 'Respuesta', 'Valor numérico', 'Cód. Local',
            'Local', 'Dirección', 'Cadena', 'Distrito', 'Jefe de venta', 'Usuario']);
        $tipoNombres = [1=>'Sí/No',2=>'Única',3=>'Múltiple',4=>'Texto',5=>'Numérico',6=>'Fecha',7=>'Foto'];
        while ($r = $rs->fetch_assoc()) {
            $resp = $r['respuesta'] ?? '';
            if ((int)$r['tipo'] === 7 && $resp !== '') {
                $resp = $this->makeAbsUrl($resp);
            }
            fputcsv($fh, array_map([$this, 'csvSafe'], [
                $r['resp_id'], $r['visita_id'], $r['fecha'], $r['fecha_respuesta'],
                $r['campana'], $r['pregunta_id'], $r['pregunta'],
                $tipoNombres[(int)$r['tipo']] ?? (string)$r['tipo'], $resp, $r['valor'] ?? '',
                $r['local_codigo'], $r['local_nombre'], $r['direccion'],
                $r['cadena'], $r['distrito'], $r['jefe_venta'], $r['usuario'],
            ]));
        }
        $st->close();
    }

    private function streamCsvGrouped($fh, string $whereSql, string $types, array $params, int $limit): void
    {
        $sql = "
          SELECT v.id AS visita_id, fqr.id_local, fq.id AS pregunta_id,
            CASE WHEN v.fecha_fin IS NOT NULL THEN DATE_FORMAT(v.fecha_fin,'%d/%m/%Y %H:%i:%s')
                 ELSE DATE_FORMAT(fqr.created_at,'%d/%m/%Y %H:%i:%s') END AS fecha,
            f.nombre AS campana, fq.question_text AS pregunta, fq.id_question_type AS tipo,
            COALESCE(o.option_text, fqr.answer_text) AS respuesta, fqr.valor,
            l.codigo AS local_codigo, l.nombre AS local_nombre, l.direccion,
            c.nombre AS cadena, d.nombre_distrito AS distrito, jv.nombre AS jefe_venta, u.usuario
          FROM form_question_responses fqr
          JOIN form_questions fq  ON fq.id = fqr.id_form_question
          JOIN formulario f       ON f.id  = fq.id_formulario
          JOIN local l            ON l.id  = fqr.id_local
          LEFT JOIN cadena c      ON c.id  = l.id_cadena
          LEFT JOIN distrito d    ON d.id  = l.id_distrito
          LEFT JOIN jefe_venta jv ON jv.id = l.id_jefe_venta
          JOIN usuario u          ON u.id  = fqr.id_usuario
          LEFT JOIN form_question_options o ON o.id = fqr.id_option
          JOIN visita v           ON v.id  = fqr.visita_id
          $whereSql
          ORDER BY COALESCE(v.fecha_fin, fqr.created_at) DESC, fqr.visita_id DESC,
            CASE WHEN fq.id_question_type=7 THEN 2 ELSE 1 END, fq.id, fqr.id DESC
          LIMIT $limit
        ";
        $st = $this->conn->prepare($sql);
        if ($types) { $st->bind_param($types, ...$params); }
        $st->execute();
        $rs = $st->get_result();

        $questions = [];
        $visits    = [];
        while ($r = $rs->fetch_assoc()) {
            $visitaId = (int)($r['visita_id'] ?? 0);
            if ($visitaId <= 0) continue;
            if (!isset($visits[$visitaId])) {
                $visits[$visitaId] = [
                    'fecha' => $r['fecha'], 'campana' => $r['campana'],
                    'local_codigo' => $r['local_codigo'], 'local_nombre' => $r['local_nombre'],
                    'direccion' => $r['direccion'], 'cadena' => $r['cadena'],
                    'distrito' => $r['distrito'], 'jefe_venta' => $r['jefe_venta'],
                    'usuario' => $r['usuario'], 'answers' => [],
                ];
            }
            $tipoInt  = (int)$r['tipo'];
            $pregText = (string)$r['pregunta'];
            $qKey     = mb_strtolower($pregText, 'UTF-8') . '|' . $tipoInt;
            if (!isset($questions[$qKey])) {
                $questions[$qKey] = ['key' => $qKey, 'text' => $pregText, 'tipo' => $tipoInt];
            }
            $val = '';
            if ($tipoInt === 5 && $r['valor'] !== null && $r['valor'] !== '') {
                $val = (string)$r['valor'];
            } else {
                $val = (string)($r['respuesta'] ?? '');
                if ($tipoInt === 7 && $val !== '') { $val = $this->makeAbsUrl($val); }
            }
            if ($val === '') continue;
            if (!isset($visits[$visitaId]['answers'][$qKey])) {
                $visits[$visitaId]['answers'][$qKey] = [];
            }
            $visits[$visitaId]['answers'][$qKey][] = $val;
        }
        $st->close();

        // Header
        $headers = ['Fecha', 'Campaña', 'Cód. Local', 'Local', 'Dirección', 'Cadena', 'Distrito', 'Jefe de venta', 'Usuario'];
        if (!empty($questions)) {
            uasort($questions, fn($a, $b) => strcmp(mb_strtolower($a['text'], 'UTF-8'), mb_strtolower($b['text'], 'UTF-8')) ?: ($a['tipo'] <=> $b['tipo']));
            foreach ($questions as $q) {
                $headers[] = trim($q['text']) ?: 'Pregunta';
            }
        }
        fputcsv($fh, array_map([$this, 'csvSafe'], $headers));

        // Rows
        foreach ($visits as $visit) {
            $row = [$visit['fecha'], $visit['campana'], $visit['local_codigo'], $visit['local_nombre'],
                    $visit['direccion'], $visit['cadena'], $visit['distrito'], $visit['jefe_venta'], $visit['usuario']];
            foreach ($questions as $qKey => $q) {
                if (!empty($visit['answers'][$qKey])) {
                    $vals = array_filter(array_unique(array_map('strval', $visit['answers'][$qKey])), fn($s) => $s !== '');
                    $row[] = implode(' | ', $vals);
                } else {
                    $row[] = '';
                }
            }
            fputcsv($fh, array_map([$this, 'csvSafe'], $row));
        }
    }

    private function renderPdfHtml(array $rows, int $maxRows, bool $isTruncated): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        // Re-use the same HTML generation but output directly
        echo $this->buildPdfHtml($rows, $maxRows, $isTruncated);
    }

    private function buildPdfHtml(array $rows, int $maxRows, bool $isTruncated): string
    {
        ob_start();
        ?>
        <!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Reporte de Fotos</title>
        <style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#222}
        table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:4px;vertical-align:top}
        th{background:#f2f4f8;font-weight:bold}.thumb-pdf{max-width:150px;max-height:110px;object-fit:cover;display:block}
        .small{font-size:11px;color:#666}</style></head><body>
        <h2>Reporte de Fotos – Panel de Encuesta</h2>
        <?php if ($isTruncated): ?><p class="small">Aviso: limitado a <?=(int)$maxRows?> registros.</p><?php endif; ?>
        <table><thead><tr><th>Imagen</th><th>Cód.</th><th>Local</th><th>Dirección</th><th>Cadena</th><th>Jefe</th><th>Usuario</th><th>Fecha</th></tr></thead><tbody>
        <?php foreach ($rows as $r): $fotos = $r['fotos'] ?: []; ?>
        <tr>
          <td><?php foreach ($fotos as $u): $src = $this->getPdfImgSrc($u); ?>
            <a href="<?=htmlspecialchars($u)?>"><img src="<?=htmlspecialchars($src)?>" class="thumb-pdf" alt=""></a>
          <?php endforeach; if (!$fotos): ?><span class="small">Sin imagen</span><?php endif; ?></td>
          <td><?=htmlspecialchars($r['local_codigo']??'')?></td>
          <td><?=htmlspecialchars($r['local_nombre']??'')?></td>
          <td><?=htmlspecialchars(trim(($r['direccion']??'').(($r['comuna']??'')!==''?' - '.$r['comuna']:''))) ?></td>
          <td><?=htmlspecialchars($r['cadena']??'')?></td>
          <td><?=htmlspecialchars($r['jefe_venta']??'')?></td>
          <td><?=htmlspecialchars($r['usuario']??'')?></td>
          <td><?=htmlspecialchars($r['fecha_subida']??'')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></body></html>
        <?php
        return (string)ob_get_clean();
    }

    private function buildPdfFotosHtml(array $rows, int $maxRows, bool $isTruncated): string
    {
        ob_start();
        ?>
        <!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Reporte de Fotos</title>
        <style>body{font-family:DejaVu Sans,Arial,sans-serif;font-size:9px;color:#222}
        table{width:100%;border-collapse:collapse;table-layout:fixed}
        th,td{border:1px solid #ccc;padding:4px;vertical-align:top;word-wrap:break-word}
        th{background:#f2f4f8;font-weight:bold}
        .col-img{width:80px;text-align:center;vertical-align:middle}
        .thumb-pdf{width:70px;height:auto;display:block;margin:0 auto}
        .small{font-size:8px;color:#666}</style></head><body>
        <h2>Reporte de Fotos – Panel de Encuesta</h2>
        <?php if ($isTruncated): ?><p class="small">Limitado a <?=(int)$maxRows?> fotos.</p><?php endif; ?>
        <table><thead><tr>
          <th class="col-img">Imagen</th><th>Cód.</th><th>Local</th><th>Dirección</th>
          <th>Comuna</th><th>Cadena</th><th>Jefe</th><th>Vendedor</th><th>Usuario</th><th>Fecha</th>
        </tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="col-img">
            <?php if ($r['thumb']): ?>
              <?php if ($r['link']): ?><a href="<?=htmlspecialchars($r['link'],ENT_QUOTES)?>"><?php endif; ?>
              <img src="<?=htmlspecialchars($r['thumb'],ENT_QUOTES)?>" class="thumb-pdf" alt="Foto">
              <?php if ($r['link']): ?></a><?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?=htmlspecialchars($r['local_codigo'])?></td>
          <td><?=htmlspecialchars($r['local_nombre'])?></td>
          <td><?=htmlspecialchars($r['direccion'])?></td>
          <td><?=htmlspecialchars($r['comuna'])?></td>
          <td><?=htmlspecialchars($r['cadena'])?></td>
          <td><?=htmlspecialchars($r['jefe_venta'])?></td>
          <td><?=htmlspecialchars($r['vendedor'])?></td>
          <td><?=htmlspecialchars($r['usuario'])?></td>
          <td><?=htmlspecialchars($r['fecha_foto'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></body></html>
        <?php
        return (string)ob_get_clean();
    }

    private function streamPdf(string $html, string $filename, string $debugId, int $dpi = 96): void
    {
        $loaded = panel_encuesta_load_dompdf();
        if (!$loaded) {
            http_response_code(500); header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'Dompdf no encontrado.', 'debug_id' => $debugId], JSON_UNESCAPED_UNICODE);
            return;
        }
        try {
            $dompdf = new \Dompdf\Dompdf([
                'isRemoteEnabled' => true, 'isHtml5ParserEnabled' => true,
                'dpi' => $dpi, 'chroot' => rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'),
            ]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            $dompdf->stream($filename, ['Attachment' => true]);
        } catch (\Throwable $e) {
            http_response_code(500); header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'Error generando PDF: ' . $e->getMessage(), 'debug_id' => $debugId], JSON_UNESCAPED_UNICODE);
        }
    }

    private function getPdfImgSrc(string $url): string
    {
        $fs  = panel_encuesta_photo_fs_path($url);
        if (!$fs) return $url;
        $ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));
        if ($ext !== 'webp') return $fs;
        $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'panel_encuesta_pdf';
        if (!is_dir($tmpBase)) @mkdir($tmpBase, 0777, true);
        $jpgPath = $tmpBase . DIRECTORY_SEPARATOR . sha1($fs) . '.jpg';
        if (!is_file($jpgPath)) {
            $ok = false;
            if (function_exists('imagecreatefromwebp') && function_exists('imagejpeg')) {
                $img = @imagecreatefromwebp($fs);
                if ($img) { @imagejpeg($img, $jpgPath, 90); imagedestroy($img); $ok = is_file($jpgPath); }
            }
            if (!$ok && class_exists('Imagick')) {
                try {
                    $im = new \Imagick($fs); $im->setImageFormat('jpeg'); $im->writeImage($jpgPath);
                    $im->clear(); $im->destroy(); $ok = is_file($jpgPath);
                } catch (\Throwable) {}
            }
            if (!$ok) return $fs;
        }
        return $jpgPath;
    }

    private function buildThumbDataUri(?string $fotoUrl, int $maxW = 240, int $maxH = 360): string
    {
        if (!$fotoUrl) return $this->placeholderDataUri();
        $fs = panel_encuesta_photo_fs_path($fotoUrl);
        if ($fs === null) return $this->placeholderDataUri();
        $ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));
        if (class_exists('Imagick')) {
            try {
                $im = new \Imagick($fs);
                $im->setImageFormat('jpeg'); $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality(85); $im->stripImage();
                $w = $im->getImageWidth(); $h = $im->getImageHeight();
                if ($w > $maxW || $h > $maxH) { $im->thumbnailImage($maxW, $maxH, true, true); }
                $blob = $im->getImageBlob(); $im->clear(); $im->destroy();
                return 'data:image/jpeg;base64,' . base64_encode($blob);
            } catch (\Throwable) {}
        }
        $src = match ($ext) {
            'jpg','jpeg' => @imagecreatefromjpeg($fs),
            'png'        => @imagecreatefrompng($fs),
            'gif'        => @imagecreatefromgif($fs),
            'webp'       => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fs) : false,
            default      => (function_exists('imagecreatefromstring') && ($raw = @file_get_contents($fs)) !== false)
                             ? @imagecreatefromstring($raw) : false,
        };
        if (!$src) return $this->placeholderDataUri();
        $w = imagesx($src); $h = imagesy($src);
        $ratio = min($maxW / max(1, $w), $maxH / max(1, $h), 1.0);
        $tw = (int)floor($w * $ratio); $th = (int)floor($h * $ratio);
        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        ob_start(); imagejpeg($dst, null, 85); $blob = ob_get_clean();
        imagedestroy($dst); imagedestroy($src);
        return $blob ? 'data:image/jpeg;base64,' . base64_encode((string)$blob) : $this->placeholderDataUri();
    }

    private function placeholderDataUri(int $w = 70, int $h = 70): string
    {
        $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$w\" height=\"$h\"><rect fill=\"#f0f0f0\" width=\"$w\" height=\"$h\"/><text x=\"50%\" y=\"50%\" font-family=\"Arial\" font-size=\"10\" fill=\"#999\" text-anchor=\"middle\" dy=\".3em\">Sin imagen</text></svg>";
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function makeAbsUrl(string $path): string
    {
        if (!$path) return $path;
        if (preg_match('~^https?://~i', $path)) return $path;
        $p = ($path[0] ?? '') === '/' ? $path : ('/' . $path);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'www.visibility.cl') . $p;
    }

    private function csvSafe(mixed $value): string
    {
        $v = (string)$value;
        return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    }

    private function zipSafeName(string $s): string
    {
        $s = preg_replace('/[^a-zA-Z0-9\-_\. áéíóúÁÉÍÓÚñÑüÜ]/u', '_', $s);
        $s = preg_replace('/_{2,}/', '_', $s);
        return mb_substr(trim($s, '_ '), 0, 80);
    }
}
