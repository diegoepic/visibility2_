<?php
declare(strict_types=1);

/**
 * lib_estatus_vehiculo.php
 * -----------------------------------------------------------------------------
 * Cálculo del informe "Estatus del vehículo" (formulario 138).
 *
 * Fuente ÚNICA de verdad del cálculo. La usan:
 *   - informes/descargar_excel_estatus_vehiculo.php   (export a Excel)
 *   - modulos/mod_vehiculos/ajax_estatus_vehiculo.php (vista en pantalla)
 *
 * Por qué existe: antes el cálculo vivía dentro del generador de Excel. Al
 * agregar la vista en línea habría quedado duplicado, y cualquier ajuste futuro
 * a la regla de cumplimiento se aplicaría a uno solo — el Excel y la pantalla
 * mostrarían números distintos, que es justo lo que se quiere evitar.
 *
 * Este archivo NO imprime nada ni abre conexiones: recibe la conexión y
 * devuelve datos.
 */

if (defined('EV_INFORME_LIB_CARGADA')) {
    return;
}
define('EV_INFORME_LIB_CARGADA', true);

define('EV_INFORME_FORMULARIO', 138);

/* ═════════════════════════════════════════════════════════════
 * Helpers de fecha y formato
 * ═════════════════════════════════════════════════════════════ */
/**
 * Modo diario: 2 entradas por día hábil (inicio + término). El cumplimiento se
 * evalúa por hora: inicio = primera subida antes de las 12:00; término = última
 * subida a las 12:00 o después.
 */
function ev_calcExpectedDaysDaily(string $start, string $end, array $holidays): array
{
    $result  = [];
    $current = new DateTime($start);
    $last    = new DateTime($end);

    while ($current <= $last) {
        $dow = (int)$current->format('N');
        if ($dow <= 5) {
            $date = $current->format('Y-m-d');
            if (!in_array($date, $holidays, true)) {
                $result[] = ['date' => $date, 'tipo' => 'inicio'];
                $result[] = ['date' => $date, 'tipo' => 'termino'];
            }
        }
        $current->modify('+1 day');
    }
    return $result;
}

function ev_fmtDate(string $date): string
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d ? $d->format('d/m/Y') : $date;
}

function ev_fmtDateTime(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') return '—';
    $d = new DateTime($dt);
    return $d->format('d/m/Y H:i');
}

function ev_slotFromDateTime(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') return '—';
    try {
        $h = (int)(new DateTime($dt))->format('H');
        return $h < 12 ? 'Entrada' : 'Salida';
    } catch (Throwable $e) {
        return '—';
    }
}

function ev_slotKeyFromDateTime(?string $dt): string
{
    if (!$dt || $dt === '0000-00-00 00:00:00') return 'sin_hora';
    try {
        $h = (int)(new DateTime($dt))->format('H');
        return $h < 12 ? 'inicio' : 'termino';
    } catch (Throwable $e) {
        return 'sin_hora';
    }
}

function ev_normalizarPatente(string $p): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $p));
}

function ev_diasEs(): array
{
    return ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
}

function ev_diasEsCorto(): array
{
    return ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
}

/**
 * Colores de semáforo según % de cumplimiento. Se devuelven como par
 * [fondo, texto] en hexadecimal sin '#', para que sirvan igual en Excel y web.
 */
function ev_complianceColors(float $pct): array
{
    if ($pct >= 80.0) return ['C6EFCE', '276221']; // verde
    if ($pct >= 50.0) return ['FFEB9C', '9C6500']; // amarillo
    return ['FFC7CE', '9C0006'];                     // rojo
}

/**
 * Columnas de la matriz de cumplimiento: dos por día hábil (M = mañana, T = tarde).
 * La usan la Hoja 2 del Excel y la pestaña "Cumplimiento" de la vista web.
 */
function ev_matrixCols(array $expectedDays): array
{
    $short = ev_diasEsCorto();
    $cols  = [];

    foreach ($expectedDays as $e) {
        $d       = new DateTime($e['date']);
        $dateFmt = ($short[(int)$d->format('N')] ?? '?') . ' ' . $d->format('d/m');
        $cols[]  = [
            'date'  => $e['date'],
            'tipo'  => $e['tipo'],
            'tipos' => [$e['tipo']],
            'label' => $dateFmt . "\n" . ($e['tipo'] === 'inicio' ? 'M' : 'T'),
        ];
    }
    return $cols;
}

/**
 * ¿Cumplió el ejecutor esa celda de la matriz?
 * Regla: la subida de la mañana debe ser antes de las 12:00 y la de la tarde
 * desde las 12:00. Centralizado para que la matriz, el % y el Excel usen
 * exactamente el mismo criterio.
 */
function ev_cumpleCelda(array $uploadsUser, array $col): bool
{
    $date = $col['date'];
    if (!isset($uploadsUser[$date])) return false;

    try {
        if (($col['tipo'] ?? '') === 'inicio') {
            return (int)(new DateTime($uploadsUser[$date]['primera']))->format('H') < 12;
        }
        return (int)(new DateTime($uploadsUser[$date]['ultima']))->format('H') >= 12;
    } catch (Throwable $e) {
        return false;
    }
}

/* ═════════════════════════════════════════════════════════════
 * Cálculo completo del informe
 * ═════════════════════════════════════════════════════════════ */

/**
 * Evaluación DIARIA: se esperan 2 subidas por día hábil (mañana y tarde).
 * Antes existía además un modo "clásico" (solo inicio y término de cada bloque
 * de días); se eliminó porque la operación pasó a ser diaria.
 *
 * @param array $feriados  fechas 'Y-m-d' a excluir
 * @return array estructura con todo lo que necesitan el Excel y la vista web
 */
function ev_calcular_informe(mysqli $conn, int $empresa_id, string $start_date,
                             string $end_date, array $feriados): array
{
    $startDt = $start_date . ' 00:00:00';
    $endDt   = $end_date   . ' 23:59:59';

    /* ---- Días/slots esperados ---- */
    $expectedDays = ev_calcExpectedDaysDaily($start_date, $end_date, $feriados);

    $expectedMap = [];
    foreach ($expectedDays as $e) {
        $expectedMap[$e['date']][] = $e['tipo'];
    }
    $expectedDatesUnique = array_keys($expectedMap);
    sort($expectedDatesUnique);

    /* ---- 1) Usuarios elegibles ---- */
    $users = [];
    $stmtU = $conn->prepare("
        SELECT u.id,
               COALESCE(u.rut, '') AS rut,
               u.usuario,
               CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,'')) AS nombre_completo,
               COALESCE(u.email, '') AS email,
               COALESCE(de.nombre, '—') AS division
        FROM usuario u
        LEFT JOIN division_empresa de ON de.id = u.id_division
        WHERE u.activo = 1
          AND u.clasificacion_usuario = 'interno'
          AND u.id_perfil = 3
          AND u.id_empresa = ?
          AND u.id <> 50
        ORDER BY u.usuario ASC
    ");
    $stmtU->bind_param('i', $empresa_id);
    $stmtU->execute();
    $resU = $stmtU->get_result();
    while ($row = $resU->fetch_assoc()) {
        $users[(int)$row['id']] = [
            'rut'             => trim($row['rut']),
            'usuario'         => trim($row['usuario']),
            'nombre_completo' => trim($row['nombre_completo']),
            'email'           => trim($row['email']),
            'division'        => trim($row['division']),
        ];
    }
    $stmtU->close();

    /* ---- 2) Subidas reales (usuario × día) ---- */
    $uploads       = [];
    $allUploadRows = [];

    $stmtS = $conn->prepare("
        SELECT
            r.id_usuario,
            DATE(COALESCE(m.created_at, r.created_at))   AS fecha_subida,
            COUNT(*)                                       AS total_fotos,
            MIN(COALESCE(m.created_at, r.created_at))     AS primera_hora,
            MAX(COALESCE(m.created_at, r.created_at))     AS ultima_hora
        FROM form_question_responses r
        LEFT JOIN form_question_photo_meta m ON m.resp_id = r.id
        JOIN form_questions fq ON fq.id = r.id_form_question
        WHERE fq.id_formulario = " . EV_INFORME_FORMULARIO . "
          AND fq.id_question_type = 7
          AND COALESCE(m.created_at, r.created_at) BETWEEN ? AND ?
        GROUP BY r.id_usuario, fecha_subida
        ORDER BY r.id_usuario, fecha_subida
    ");
    $stmtS->bind_param('ss', $startDt, $endDt);
    $stmtS->execute();
    $resS = $stmtS->get_result();
    while ($row = $resS->fetch_assoc()) {
        $uid  = (int)$row['id_usuario'];
        $date = (string)$row['fecha_subida'];
        $uploads[$uid][$date] = [
            'total'   => (int)$row['total_fotos'],
            'primera' => (string)$row['primera_hora'],
            'ultima'  => (string)$row['ultima_hora'],
        ];
        $allUploadRows[] = [
            'id_usuario'   => $uid,
            'fecha'        => $date,
            'total_fotos'  => (int)$row['total_fotos'],
            'primera_hora' => (string)$row['primera_hora'],
            'ultima_hora'  => (string)$row['ultima_hora'],
        ];
    }
    $stmtS->close();

    /* ---- 3) Fotos duplicadas (mismo sha1 en días distintos) ---- */
    $duplicates = [];
    $dupsByUser = [];

    $stmtD = $conn->prepare("
        SELECT
            r.id_usuario,
            CONCAT(COALESCE(u.nombre,''), ' ', COALESCE(u.apellido,'')) AS nombre_completo,
            COALESCE(u.usuario, CONCAT('user_', r.id_usuario))           AS username,
            JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1'))              AS sha1,
            COUNT(*)                                                       AS total_subidas,
            COUNT(DISTINCT DATE(m.created_at))                            AS dias_distintos,
            GROUP_CONCAT(DISTINCT DATE(m.created_at)
                         ORDER BY DATE(m.created_at) SEPARATOR ', ')      AS fechas,
            MIN(m.created_at)                                              AS primera_subida,
            MAX(m.created_at)                                              AS ultima_subida,
            MIN(m.foto_url)                                                AS primera_url
        FROM form_question_photo_meta m
        JOIN form_question_responses r ON r.id = m.resp_id
        JOIN form_questions fq ON fq.id = r.id_form_question
        JOIN usuario u ON u.id = r.id_usuario AND u.activo = 1
        WHERE fq.id_formulario = " . EV_INFORME_FORMULARIO . "
          AND fq.id_question_type = 7
          AND JSON_EXTRACT(m.meta_json,'$.sha1') IS NOT NULL
          AND DATE(m.created_at) BETWEEN ? AND ?
        GROUP BY r.id_usuario, JSON_UNQUOTE(JSON_EXTRACT(m.meta_json,'$.sha1'))
        HAVING COUNT(DISTINCT DATE(m.created_at)) > 1
        ORDER BY primera_subida DESC
    ");
    $stmtD->bind_param('ss', $start_date, $end_date);
    $stmtD->execute();
    $resD = $stmtD->get_result();
    while ($row = $resD->fetch_assoc()) {
        $duplicates[] = $row;
        $dupsByUser[(int)$row['id_usuario']] = true;
    }
    $stmtD->close();

    /* ---- 4) Respuestas texto/numéricas ---- */
    $nonPhotoQuestions = [];
    $textNumAnswers    = [];

    $stmtNPQ = $conn->prepare("
        SELECT id, question_text, id_question_type
        FROM form_questions
        WHERE id_formulario = " . EV_INFORME_FORMULARIO . "
          AND id_question_type IN (4, 5)
          AND deleted_at IS NULL
        ORDER BY sort_order ASC
    ");
    $stmtNPQ->execute();
    $resNPQ = $stmtNPQ->get_result();
    while ($row = $resNPQ->fetch_assoc()) {
        $nonPhotoQuestions[(int)$row['id']] = [
            'text' => trim($row['question_text']),
            'type' => (int)$row['id_question_type'],
        ];
    }
    $stmtNPQ->close();

    if (!empty($nonPhotoQuestions)) {
        $qidList = implode(',', array_map('intval', array_keys($nonPhotoQuestions)));
        $stmtNPR = $conn->prepare("
            SELECT r.id_usuario, DATE(r.created_at) AS fecha,
                   r.id_form_question, r.answer_text, r.valor
            FROM form_question_responses r
            WHERE r.id_form_question IN ($qidList)
              AND r.created_at BETWEEN ? AND ?
            ORDER BY r.id_usuario, fecha
        ");
        $stmtNPR->bind_param('ss', $startDt, $endDt);
        $stmtNPR->execute();
        $resNPR = $stmtNPR->get_result();
        while ($row = $resNPR->fetch_assoc()) {
            $textNumAnswers[(int)$row['id_usuario']][(string)$row['fecha']][(int)$row['id_form_question']] = [
                'answer_text' => (string)($row['answer_text'] ?? ''),
                'valor'       => $row['valor'],
            ];
        }
        $stmtNPR->close();
    }

    /* Detectar dinámicamente las preguntas de patente y km restantes */
    $qid_patente_encuesta = null;
    $qid_km_restantes     = null;
    foreach ($nonPhotoQuestions as $qid => $qdef) {
        $t = mb_strtolower($qdef['text'], 'UTF-8');
        if ($qid_patente_encuesta === null && str_contains($t, 'patente'))   $qid_patente_encuesta = $qid;
        if ($qid_km_restantes     === null && str_contains($t, 'restantes')) $qid_km_restantes     = $qid;
    }

    /* ---- 5) Vehículos activos por ejecutor ---- */
    $vehiclePatentes = [];
    $vehicleModelos  = [];
    $vehicleInfo     = [];

    $stmtV = $conn->prepare("
        SELECT v.id_merchan, v.patente, v.modelo
        FROM vehiculo v
        WHERE v.id_empresa = ?
          AND v.estado = 1
          AND v.deleted_at IS NULL
          AND v.id_merchan IS NOT NULL
        ORDER BY v.id_merchan ASC, v.updated_at DESC, v.id DESC
    ");
    $stmtV->bind_param('i', $empresa_id);
    $stmtV->execute();
    $resV = $stmtV->get_result();
    while ($row = $resV->fetch_assoc()) {
        $mid = (int)$row['id_merchan'];
        $vehiclePatentes[$mid][trim((string)($row['patente'] ?? '')) ?: '—'] = true;
        $vehicleModelos[$mid][trim((string)($row['modelo'] ?? '')) ?: '—']   = true;
    }
    $stmtV->close();

    foreach ($vehiclePatentes as $mid => $patentes) {
        $vehicleInfo[$mid] = [
            'patente' => implode(' / ', array_keys($patentes)),
            'modelo'  => implode(' / ', array_keys($vehicleModelos[$mid] ?? ['—' => true])),
        ];
    }

    /* ---- 6) Estadísticas por usuario ---- */
    $matrixCols = ev_matrixCols($expectedDays);
    $userStats  = [];

    foreach ($users as $uid => $udata) {
        $complied = 0;
        $expected = count($expectedDays);   // 2 slots por día hábil

        foreach ($expectedDays as $e) {
            if (ev_cumpleCelda($uploads[$uid] ?? [], $e)) $complied++;
        }

        $userStats[$uid] = [
            'expected' => $expected,
            'complied' => $complied,
            'missed'   => $expected - $complied,
            'pct'      => $expected > 0 ? round($complied / $expected * 100, 1) : 0.0,
            'has_dups' => isset($dupsByUser[$uid]),
        ];
    }

    /* Peor cumplimiento primero: es lo que el coordinador necesita ver arriba */
    uasort($userStats, static fn($a, $b) => $a['pct'] <=> $b['pct']);

    return [
        'users'                => $users,
        'uploads'              => $uploads,
        'allUploadRows'        => $allUploadRows,
        'duplicates'           => $duplicates,
        'dupsByUser'           => $dupsByUser,
        'nonPhotoQuestions'    => $nonPhotoQuestions,
        'textNumAnswers'       => $textNumAnswers,
        'vehicleInfo'          => $vehicleInfo,
        'userStats'            => $userStats,
        'expectedDays'         => $expectedDays,
        'expectedMap'          => $expectedMap,
        'expectedDatesUnique'  => $expectedDatesUnique,
        'matrixCols'           => $matrixCols,
        'qid_patente_encuesta' => $qid_patente_encuesta,
        'qid_km_restantes'     => $qid_km_restantes,
    ];
}

/**
 * Último km restante registrado y cuántos días la patente respondida no coincide
 * con la asignada. Se usa en la fila de resumen por ejecutor.
 */
function ev_resumen_extra_usuario(array $data, int $uid, string $patenteAsignadaRaw): array
{
    $kmUltimo = '—';
    $diasDist = 0;
    $patAsig  = ev_normalizarPatente($patenteAsignadaRaw);

    $fechas = array_keys($data['uploads'][$uid] ?? []);
    sort($fechas);

    foreach ($fechas as $fecha) {
        if ($data['qid_km_restantes'] !== null) {
            $ans = $data['textNumAnswers'][$uid][$fecha][$data['qid_km_restantes']] ?? null;
            if ($ans !== null) {
                $txt = trim((string)($ans['answer_text'] ?? ''));
                if ($txt !== '')                  $kmUltimo = $txt;
                elseif ($ans['valor'] !== null)   $kmUltimo = (string)$ans['valor'];
            }
        }
        if ($data['qid_patente_encuesta'] !== null && $patAsig !== '') {
            $ansP = $data['textNumAnswers'][$uid][$fecha][$data['qid_patente_encuesta']] ?? null;
            if ($ansP !== null) {
                $pEnc = ev_normalizarPatente(trim((string)($ansP['answer_text'] ?? '')));
                if ($pEnc !== '' && $pEnc !== $patAsig) $diasDist++;
            }
        }
    }

    return ['km_restantes' => $kmUltimo, 'dias_patente_distinta' => $diasDist];
}
