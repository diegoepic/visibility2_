<?php
ob_start();
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/con_.php';

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!isset($_SESSION['usuario_id'])) {
    responder([
        'ok' => false,
        'msg' => 'Sesión expirada.'
    ]);
}

$conn = $conn ?? $conexion ?? $mysqli ?? null;

if (!$conn) {
    responder([
        'ok' => false,
        'msg' => 'No existe conexión a base de datos.'
    ]);
}

$conn->set_charset('utf8mb4');

$idCampana = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$idEmpresa = (int)($_SESSION['empresa_id'] ?? 0);

if ($idCampana <= 0) {
    responder([
        'ok' => false,
        'msg' => 'ID de campaña inválido.'
    ]);
}

function normalizarFechaIso($date)
{
    if (
        empty($date) ||
        $date === '0000-00-00' ||
        $date === '0000-00-00 00:00:00'
    ) {
        return null;
    }

    $ts = strtotime($date);
    if (!$ts) {
        return null;
    }

    return date('Y-m-d', $ts);
}

function formatearFechaCL($iso)
{
    if (empty($iso)) {
        return null;
    }

    $ts = strtotime($iso);
    if (!$ts) {
        return null;
    }

    return date('d-m-Y', $ts);
}

function rangoDiasHabiles($startIso, $endIso)
{
    $out = [];

    if (empty($startIso) || empty($endIso)) {
        return $out;
    }

    $inicio = new DateTime($startIso);
    $fin    = new DateTime($endIso);

    if ($inicio > $fin) {
        [$inicio, $fin] = [$fin, $inicio];
    }

    while ($inicio <= $fin) {
        $n = (int)$inicio->format('N');
        if ($n >= 1 && $n <= 5) {
            $out[] = $inicio->format('Y-m-d');
        }
        $inicio->modify('+1 day');
    }

    return $out;
}

function agruparFechasConsecutivas(array $fechasIso)
{
    if (empty($fechasIso)) {
        return [];
    }

    sort($fechasIso);

    $rangos = [];
    $inicio = $fechasIso[0];
    $prev   = $fechasIso[0];

    for ($i = 1; $i < count($fechasIso); $i++) {
        $actual = $fechasIso[$i];

        $prevDate   = new DateTime($prev);
        $actualDate = new DateTime($actual);

        $diff = (int)$prevDate->diff($actualDate)->days;

        if ($diff === 1 || ($diff <= 3 && count(rangoDiasHabiles($prev, $actual)) === 2)) {
            $prev = $actual;
            continue;
        }

        $diasHabiles = count(rangoDiasHabiles($inicio, $prev));

        $rangos[] = [
            'desde_iso' => $inicio,
            'hasta_iso' => $prev,
            'desde'     => formatearFechaCL($inicio),
            'hasta'     => formatearFechaCL($prev),
            'dias'      => $diasHabiles
        ];

        $inicio = $actual;
        $prev   = $actual;
    }

    $diasHabiles = count(rangoDiasHabiles($inicio, $prev));

    $rangos[] = [
        'desde_iso' => $inicio,
        'hasta_iso' => $prev,
        'desde'     => formatearFechaCL($inicio),
        'hasta'     => formatearFechaCL($prev),
        'dias'      => $diasHabiles
    ];

    return $rangos;
}

function percentTimeline($baseIso, $finIso, $targetIso)
{
    if (!$baseIso || !$finIso || !$targetIso) {
        return 0;
    }

    $total = diffDiasHabilesOrNull($baseIso, $finIso);
    $avance = diffDiasHabilesOrNull($baseIso, $targetIso);

    $total = abs((int)$total);
    $avance = abs((int)$avance);

    if ($total <= 0) {
        return 0;
    }

    return round(($avance / $total) * 100, 2);
}

function responder(array $data): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function formatDateOrNull($date)
{
    if (
        empty($date) ||
        $date === '0000-00-00' ||
        $date === '0000-00-00 00:00:00'
    ) {
        return null;
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return null;
    }

    return date('d-m-Y', $timestamp);
}

function diffDiasHabilesOrNull($start, $end)
{
    if (empty($start) || empty($end)) {
        return null;
    }

    $tsStart = strtotime($start);
    $tsEnd = strtotime($end);

    if (!$tsStart || !$tsEnd) {
        return null;
    }

    $fechaInicio = new DateTime(date('Y-m-d', $tsStart));
    $fechaFin    = new DateTime(date('Y-m-d', $tsEnd));

    if ($fechaInicio == $fechaFin) {
        return 0;
    }

    $direccion = $fechaFin > $fechaInicio ? 1 : -1;
    $contador = 0;

    $cursor = clone $fechaInicio;

    while ($cursor != $fechaFin) {
        $cursor->modify($direccion > 0 ? '+1 day' : '-1 day');

        $diaSemana = (int)$cursor->format('N'); // 1 lunes / 7 domingo

        if ($diaSemana >= 1 && $diaSemana <= 5) {
            $contador++;
        }
    }

    return $contador * $direccion;
}

function timelinePercent($days, $max)
{
    if ($days === null || $max <= 0) {
        return null;
    }

    return min(100, max(0, round(($days / $max) * 100, 1)));
}

function porcentajeSeguro($numerador, $denominador, $decimales = 1)
{
    $numerador = (float)$numerador;
    $denominador = (float)$denominador;

    if ($denominador <= 0) {
        return 0;
    }

    return round(($numerador / $denominador) * 100, $decimales);
}

function promedioSeguro(array $valores, $decimales = 1)
{
    $filtrados = array_values(array_filter($valores, function ($v) {
        return $v !== null && $v !== '';
    }));

    if (count($filtrados) === 0) {
        return 0;
    }

    return round(array_sum($filtrados) / count($filtrados), $decimales);
}

function clasificarRiesgoRegion($avance, $diasPrimeraVisita)
{
    // Ajusta esta lógica si quieres otro criterio
    if ($avance < 40 || ($diasPrimeraVisita !== null && $diasPrimeraVisita > 7)) {
        return 'En riesgo';
    }

    if ($avance >= 100) {
        return 'Completado';
    }

    if ($avance >= 70) {
        return 'En curso';
    }

    return 'Pendiente';
}

function claseRiesgoRegion($estado)
{
    switch ($estado) {
        case 'Completado':
            return 'status-success';
        case 'En curso':
            return 'status-primary';
        case 'Pendiente':
            return 'status-warning';
        case 'En riesgo':
            return 'status-danger';
        default:
            return 'status-neutral';
    }
}

try {

    /*
    |--------------------------------------------------------------------------
    | DATOS PRINCIPALES DE CAMPAÑA
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            f.id,
            UPPER(f.nombre) AS nombre_campana,
            f.fechaInicio,
            f.fechaTermino,

            COUNT(DISTINCT fq.id_local) AS locales_asignados,

            COUNT(DISTINCT CASE 
                WHEN fq.countVisita > 0
                THEN fq.id_local
            END) AS locales_visitados,

            COUNT(DISTINCT CASE 
                WHEN fq.countVisita > 0
                 AND fq.pregunta IN (
                    'solo_auditoria',
                    'solo_implementado',
                    'implementado_auditado',
                    'completado'
                 )
                THEN fq.id_local
            END) AS locales_gestionados

        FROM formulario f

        LEFT JOIN formularioQuestion fq
            ON fq.id_formulario = f.id

        WHERE f.id = ?
          AND f.id_empresa = ?

        GROUP BY 
            f.id,
            f.nombre,
            f.fechaInicio,
            f.fechaTermino

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $idCampana, $idEmpresa);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        responder([
            'ok' => false,
            'msg' => 'No se encontró la campaña.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PRIMERA Y ÚLTIMA VISITA
    |--------------------------------------------------------------------------
    | Primero usamos form_question_responses.created_at porque representa
    | el registro real de respuestas.
    |--------------------------------------------------------------------------
    */

    $sqlVisitas = "
        SELECT
            MIN(r.created_at) AS primera_visita,
            MAX(r.created_at) AS ultima_visita
        FROM formularioQuestion fq

        INNER JOIN form_question_responses r
            ON r.id_form_question = fq.id

        WHERE fq.id_formulario = ?
          AND r.created_at IS NOT NULL
          AND CAST(r.created_at AS CHAR) NOT IN (
              '',
              '0000-00-00',
              '0000-00-00 00:00:00'
          )
    ";

    $stmt = $conn->prepare($sqlVisitas);
    $stmt->bind_param("i", $idCampana);
    $stmt->execute();

    $visitas = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $primeraVisita = $visitas['primera_visita'] ?? null;
    $ultimaVisita  = $visitas['ultima_visita'] ?? null;
    
    
    $sqlVisitasAgrupadas = "
        SELECT 
            t.fecha_visita,
            COUNT(DISTINCT t.id_local) AS cantidad
        FROM (
            SELECT 
                fq.id_local,
                DATE(
                    CASE
                        WHEN r.created_at IS NOT NULL
                         AND CAST(r.created_at AS CHAR) NOT IN ('', '0000-00-00', '0000-00-00 00:00:00')
                        THEN r.created_at
    
                        WHEN fq.fechaVisita IS NOT NULL
                         AND CAST(fq.fechaVisita AS CHAR) NOT IN ('', '0000-00-00', '0000-00-00 00:00:00')
                        THEN fq.fechaVisita
    
                        ELSE NULL
                    END
                ) AS fecha_visita
            FROM formularioQuestion fq
            LEFT JOIN form_question_responses r
                ON r.id_form_question = fq.id
            WHERE fq.id_formulario = ?
              AND fq.countVisita > 0
        ) t
        WHERE t.fecha_visita IS NOT NULL
        GROUP BY t.fecha_visita
        ORDER BY t.fecha_visita ASC
    ";
    
    $stmt = $conn->prepare($sqlVisitasAgrupadas);
    $stmt->bind_param("i", $idCampana);
    $stmt->execute();
    $resVisitasAgrupadas = $stmt->get_result();
    
    $visitasPorFecha = [];
    $visitDatesIso = [];
    
    while ($r = $resVisitasAgrupadas->fetch_assoc()) {
        $fechaIso = normalizarFechaIso($r['fecha_visita']);
        if (!$fechaIso) {
            continue;
        }
    
        $visitasPorFecha[$fechaIso] = [
            'fecha_iso' => $fechaIso,
            'fecha'     => formatearFechaCL($fechaIso),
            'cantidad'  => (int)$r['cantidad']
        ];
    
        $visitDatesIso[] = $fechaIso;
    }
    
    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | FALLBACK
    |--------------------------------------------------------------------------
    | Si no hay respuestas, usamos formularioQuestion.fechaVisita.
    | Ojo: aquí NO comparamos directo contra 0000-00-00 como fecha,
    | lo convertimos a CHAR para evitar error de MySQL strict.
    |--------------------------------------------------------------------------
    */

    if (empty($primeraVisita) && empty($ultimaVisita)) {
        $sqlVisitasFallback = "
            SELECT
                MIN(
                    CASE
                        WHEN fq.countVisita > 0
                         AND fq.fechaVisita IS NOT NULL
                         AND CAST(fq.fechaVisita AS CHAR) NOT IN (
                            '',
                            '0000-00-00',
                            '0000-00-00 00:00:00'
                         )
                        THEN fq.fechaVisita
                    END
                ) AS primera_visita,

                MAX(
                    CASE
                        WHEN fq.countVisita > 0
                         AND fq.fechaVisita IS NOT NULL
                         AND CAST(fq.fechaVisita AS CHAR) NOT IN (
                            '',
                            '0000-00-00',
                            '0000-00-00 00:00:00'
                         )
                        THEN fq.fechaVisita
                    END
                ) AS ultima_visita

            FROM formularioQuestion fq

            WHERE fq.id_formulario = ?
        ";

        $stmt = $conn->prepare($sqlVisitasFallback);
        $stmt->bind_param("i", $idCampana);
        $stmt->execute();

        $visitasFallback = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $primeraVisita = $visitasFallback['primera_visita'] ?? null;
        $ultimaVisita  = $visitasFallback['ultima_visita'] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | CÁLCULOS
    |--------------------------------------------------------------------------
    */

    $asignados   = (int)$row['locales_asignados'];
    $visitados   = (int)$row['locales_visitados'];
    $gestionados = (int)$row['locales_gestionados'];

    $ratioVisita = $asignados > 0
        ? round(($visitados / $asignados) * 100, 1)
        : 0;

    $ratioEjecucion = $asignados > 0
        ? round(($gestionados / $asignados) * 100, 1)
        : 0;

    if ($ratioEjecucion >= 80) {
        $estadoTexto = 'Alto';
        $estadoClass = 'success';
    } elseif ($ratioEjecucion >= 50) {
        $estadoTexto = 'Medio';
        $estadoClass = 'warning';
    } else {
        $estadoTexto = 'Bajo';
        $estadoClass = 'danger';
    }

    $fechaInicio  = $row['fechaInicio'] ?? null;
    $fechaTermino = $row['fechaTermino'] ?? null;

    $diasHastaPrimera = diffDiasHabilesOrNull($fechaInicio, $primeraVisita);
    $diasPlanificados = diffDiasHabilesOrNull($fechaInicio, $fechaTermino);
    $diasEntreVisitas = diffDiasHabilesOrNull($primeraVisita, $ultimaVisita);
    $diasHastaUltima  = diffDiasHabilesOrNull($fechaInicio, $ultimaVisita);
    
$fechaInicioIso  = normalizarFechaIso($fechaInicio);
$fechaTerminoIso = normalizarFechaIso($fechaTermino);
$primeraVisitaIso = normalizarFechaIso($primeraVisita);
$ultimaVisitaIso  = normalizarFechaIso($ultimaVisita);

$baseTimelineIso = $fechaInicioIso;
if ($primeraVisitaIso && (!$baseTimelineIso || $primeraVisitaIso < $baseTimelineIso)) {
    $baseTimelineIso = $primeraVisitaIso;
}

$finTimelineIso = $fechaTerminoIso;
if ($ultimaVisitaIso && (!$finTimelineIso || $ultimaVisitaIso > $finTimelineIso)) {
    $finTimelineIso = $ultimaVisitaIso;
}

if (!$baseTimelineIso) {
    $baseTimelineIso = $fechaInicioIso ?: $primeraVisitaIso ?: $ultimaVisitaIso ?: $fechaTerminoIso;
}

if (!$finTimelineIso) {
    $finTimelineIso = $ultimaVisitaIso ?: $fechaTerminoIso ?: $fechaInicioIso ?: $primeraVisitaIso;
}

$eventosPorFecha = [];

// Inicio
if ($fechaInicioIso) {
    $eventosPorFecha[$fechaInicioIso][] = [
        'tipo'  => 'inicio',
        'label' => 'Inicio campaña'
    ];
}

// Término
if ($fechaTerminoIso) {
    $eventosPorFecha[$fechaTerminoIso][] = [
        'tipo'  => 'termino',
        'label' => 'Término planificado'
    ];
}

// Visitas efectivas agrupadas
foreach ($visitasPorFecha as $fechaIso => $vf) {
    $label = $vf['cantidad'] . ' visita' . ($vf['cantidad'] === 1 ? '' : 's') . ' efectiva' . ($vf['cantidad'] === 1 ? '' : 's');

    $eventosPorFecha[$fechaIso][] = [
        'tipo'      => 'visita',
        'label'     => $label,
        'cantidad'  => $vf['cantidad']
    ];

    if ($primeraVisitaIso && $fechaIso === $primeraVisitaIso) {
        $eventosPorFecha[$fechaIso][] = [
            'tipo'  => 'meta',
            'label' => 'Primera visita'
        ];
    }

    if ($ultimaVisitaIso && $fechaIso === $ultimaVisitaIso) {
        $eventosPorFecha[$fechaIso][] = [
            'tipo'  => 'meta',
            'label' => 'Última visita'
        ];
    }
}

// timeline eventos ordenados
ksort($eventosPorFecha);

$eventosTimeline = [];
foreach ($eventosPorFecha as $fechaIso => $items) {
    $eventosTimeline[] = [
        'fecha_iso' => $fechaIso,
        'fecha'     => formatearFechaCL($fechaIso),
        'pos'       => percentTimeline($baseTimelineIso, $finTimelineIso, $fechaIso),
        'items'     => $items
    ];
}

// días hábiles sin visitas
$businessAll = rangoDiasHabiles($baseTimelineIso, $finTimelineIso);
$visitSet = array_flip($visitDatesIso);

$sinVisitas = [];
foreach ($businessAll as $diaIso) {
    if (!isset($visitSet[$diaIso])) {
        $sinVisitas[] = $diaIso;
    }
}

$rangosSinVisitas = agruparFechasConsecutivas($sinVisitas);

$rangosSinVisitasTimeline = [];
foreach ($rangosSinVisitas as $rango) {
    $rangosSinVisitasTimeline[] = [
        'desde_iso' => $rango['desde_iso'],
        'hasta_iso' => $rango['hasta_iso'],
        'desde'     => $rango['desde'],
        'hasta'     => $rango['hasta'],
        'dias'      => $rango['dias'],
        'left'      => percentTimeline($baseTimelineIso, $finTimelineIso, $rango['desde_iso']),
        'right'     => percentTimeline($baseTimelineIso, $finTimelineIso, $rango['hasta_iso'])
    ];
}    

    /*
    |--------------------------------------------------------------------------
    | LÍNEA DE TIEMPO
    |--------------------------------------------------------------------------
    | Usamos el mayor valor entre duración planificada y duración real,
    | así si la última visita pasa la fecha término, la línea igual se acomoda.
    |--------------------------------------------------------------------------
    */

    $timelineMax = max(
        1,
        (int)($diasPlanificados ?? 0),
        (int)($diasHastaUltima ?? 0),
        (int)($diasHastaPrimera ?? 0)
    );

    $posPrimera = timelinePercent($diasHastaPrimera, $timelineMax);
    $posUltima  = timelinePercent($diasHastaUltima, $timelineMax);
    $posTermino = timelinePercent($diasPlanificados, $timelineMax);

    
    /*
    |--------------------------------------------------------------------------
    | ANALÍTICA POR REGIÓN Y USUARIO
    |--------------------------------------------------------------------------
    */

    $sqlRegion = "
        SELECT
            r.id AS id_region,
            UPPER(r.region) AS region_nombre,

            COUNT(DISTINCT fq.id_local) AS locales_asignados,

            COUNT(DISTINCT CASE
                WHEN fq.countVisita > 0 THEN fq.id_local
            END) AS locales_visitados,

            MIN(CASE
                WHEN fq.countVisita > 0
                AND fq.fechaVisita IS NOT NULL
                AND CAST(fq.fechaVisita AS CHAR) NOT IN (
                    '',
                    '0000-00-00',
                    '0000-00-00 00:00:00'
                )
                THEN DATE(fq.fechaVisita)
            END) AS primera_visita_region,

            MAX(CASE
                WHEN fq.countVisita > 0
                AND fq.fechaVisita IS NOT NULL
                AND CAST(fq.fechaVisita AS CHAR) NOT IN (
                    '',
                    '0000-00-00',
                    '0000-00-00 00:00:00'
                )
                THEN DATE(fq.fechaVisita)
            END) AS ultima_visita_region

        FROM formularioQuestion fq
        INNER JOIN local l
            ON l.id = fq.id_local
        LEFT JOIN comuna c
            ON c.id = l.id_comuna
        LEFT JOIN region r
            ON r.id = c.id_region

        WHERE fq.id_formulario = ?

        GROUP BY r.id, r.region
        ORDER BY r.region ASC
    ";

    $stmtRegion = $conn->prepare($sqlRegion);
    $stmtRegion->bind_param("i", $idCampana);
    $stmtRegion->execute();
    $resRegion = $stmtRegion->get_result();

    $regionesDetalle = [];
    $idsRegion = [];
    $slaRegionValores = [];

    while ($rr = $resRegion->fetch_assoc()) {
        $asignadosRegion = (int)$rr['locales_asignados'];
        $visitadosRegion = (int)$rr['locales_visitados'];
        $avanceRegion    = porcentajeSeguro($visitadosRegion, $asignadosRegion);

        $primeraRegionIso = normalizarFechaIso($rr['primera_visita_region']);
        $ultimaRegionIso  = normalizarFechaIso($rr['ultima_visita_region']);

        $diasPrimeraRegion = diffDiasHabilesOrNull($fechaInicio, $primeraRegionIso);
        $diasUltimaRegion  = diffDiasHabilesOrNull($fechaInicio, $ultimaRegionIso);

        $estadoRegion = clasificarRiesgoRegion($avanceRegion, $diasPrimeraRegion);
        $estadoClass  = claseRiesgoRegion($estadoRegion);

        $filaRegion = [
            'id_region'            => (int)$rr['id_region'],
            'region_nombre'        => $rr['region_nombre'] ?: 'SIN REGIÓN',
            'asignados'            => $asignadosRegion,
            'visitados'            => $visitadosRegion,
            'avance'               => $avanceRegion,
            'primera_visita'       => formatearFechaCL($primeraRegionIso),
            'ultima_visita'        => formatearFechaCL($ultimaRegionIso),
            'dias_hasta_primera'   => $diasPrimeraRegion,
            'dias_hasta_ultima'    => $diasUltimaRegion,
            'estado'               => $estadoRegion,
            'estado_class'         => $estadoClass
        ];

        $regionesDetalle[] = $filaRegion;
        $idsRegion[] = (int)$rr['id_region'];

        if ($diasPrimeraRegion !== null) {
            $slaRegionValores[] = $diasPrimeraRegion;
        }
    }

    $stmtRegion->close();

    /*
    |--------------------------------------------------------------------------
    | USUARIOS POR REGIÓN
    |--------------------------------------------------------------------------
    */

    $sqlUsuariosRegion = "
        SELECT
            r.id AS id_region,
            UPPER(r.region) AS region_nombre,
            u.id AS id_usuario,
            UPPER(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellido, ''))) AS usuario_nombre,

            COUNT(DISTINCT fq.id_local) AS locales_asignados,
            COUNT(DISTINCT CASE
                WHEN fq.countVisita > 0 THEN fq.id_local
            END) AS locales_visitados

        FROM formularioQuestion fq
        INNER JOIN local l
            ON l.id = fq.id_local
        LEFT JOIN comuna c
            ON c.id = l.id_comuna
        LEFT JOIN region r
            ON r.id = c.id_region
        LEFT JOIN usuario u
            ON u.id = fq.id_usuario

        WHERE fq.id_formulario = ?

        GROUP BY r.id, r.region, u.id, u.nombre, u.apellido
        ORDER BY r.region ASC, usuario_nombre ASC
    ";

    $stmtUsuariosRegion = $conn->prepare($sqlUsuariosRegion);
    $stmtUsuariosRegion->bind_param("i", $idCampana);
    $stmtUsuariosRegion->execute();
    $resUsuariosRegion = $stmtUsuariosRegion->get_result();

    $usuariosPorRegion = [];

    while ($ur = $resUsuariosRegion->fetch_assoc()) {
        $idRegion = (int)$ur['id_region'];

        if (!isset($usuariosPorRegion[$idRegion])) {
            $usuariosPorRegion[$idRegion] = [];
        }

        $usuariosPorRegion[$idRegion][] = [
            'id_usuario'      => (int)$ur['id_usuario'],
            'usuario_nombre'  => trim($ur['usuario_nombre']) !== '' ? $ur['usuario_nombre'] : 'SIN USUARIO',
            'asignados'       => (int)$ur['locales_asignados'],
            'visitados'       => (int)$ur['locales_visitados'],
            'avance'          => porcentajeSeguro((int)$ur['locales_visitados'], (int)$ur['locales_asignados'])
        ];
    }

    $stmtUsuariosRegion->close();

/*
|--------------------------------------------------------------------------
| RESUMEN EJECUTIVO DE REGIONES
|--------------------------------------------------------------------------
*/

$regionesActivas = count($regionesDetalle);
$regionesEnRiesgo = 0;

foreach ($regionesDetalle as $reg) {
    if ($reg['estado'] === 'En riesgo') {
        $regionesEnRiesgo++;
    }
}

/*
|--------------------------------------------------------------------------
| REGIÓN MÁS LENTA Y MAYOR AVANCE
|--------------------------------------------------------------------------
| Evita mostrar la misma región como lenta y rápida cuando existen
| más regiones con avances distintos.
|--------------------------------------------------------------------------
*/

$regionesValidas = array_values(array_filter($regionesDetalle, function ($reg) {
    return isset($reg['asignados']) && (int)$reg['asignados'] > 0;
}));

$regionesOrdenadasAsc = $regionesValidas;
$regionesOrdenadasDesc = $regionesValidas;

usort($regionesOrdenadasAsc, function ($a, $b) {
    $cmp = ((float)$a['avance'] <=> (float)$b['avance']);

    if ($cmp === 0) {
        return strcmp($a['region_nombre'], $b['region_nombre']);
    }

    return $cmp;
});

usort($regionesOrdenadasDesc, function ($a, $b) {
    $cmp = ((float)$b['avance'] <=> (float)$a['avance']);

    if ($cmp === 0) {
        return strcmp($a['region_nombre'], $b['region_nombre']);
    }

    return $cmp;
});

$regionMasLenta = $regionesOrdenadasAsc[0] ?? null;
$regionMasRapida = $regionesOrdenadasDesc[0] ?? null;

$hayEmpateGeneral = false;

if (count($regionesValidas) > 1 && $regionMasLenta && $regionMasRapida) {
    $avanceMin = (float)$regionMasLenta['avance'];
    $avanceMax = (float)$regionMasRapida['avance'];

    /*
    | Si el mínimo y máximo son iguales, todas las regiones tienen
    | exactamente el mismo avance.
    */
    if ($avanceMin === $avanceMax) {
        $hayEmpateGeneral = true;
    }

    /*
    | Si por alguna razón quedó la misma región como lenta y rápida,
    | pero sí existen diferencias de avance, buscamos otra región
    | para el mayor avance.
    */
    if (
        !$hayEmpateGeneral &&
        (int)$regionMasLenta['id_region'] === (int)$regionMasRapida['id_region']
    ) {
        foreach ($regionesOrdenadasDesc as $reg) {
            if ((int)$reg['id_region'] !== (int)$regionMasLenta['id_region']) {
                $regionMasRapida = $reg;
                break;
            }
        }
    }
}

$slaPromedio = promedioSeguro($slaRegionValores, 1);
    
    $conn->close();

    responder([
        'ok' => true,
        'data' => [
            'id' => (int)$row['id'],
            'nombre_campana' => $row['nombre_campana'],

            'fecha_inicio' => formatDateOrNull($fechaInicio),
            'fecha_termino' => formatDateOrNull($fechaTermino),
            'primera_visita' => formatDateOrNull($primeraVisita),
            'ultima_visita' => formatDateOrNull($ultimaVisita),

            'locales_asignados' => $asignados,
            'locales_visitados' => $visitados,
            'locales_gestionados' => $gestionados,

            'ratio_visita' => $ratioVisita,
            'ratio_ejecucion' => $ratioEjecucion,
            
            'pct_visita_total' => $ratioVisita,
            'porcentaje_visita' => $ratioVisita,
            'visita_completa' => $ratioVisita >= 100,

            'estado_texto' => $estadoTexto,
            'estado_class' => $estadoClass,

            'dias_hasta_primera' => $diasHastaPrimera,
            'dias_hasta_ultima' => $diasHastaUltima,
            'dias_entre_visitas' => $diasEntreVisitas,
            'dias_planificados' => $diasPlanificados,

            'timeline' => [
                'primera' => $posPrimera,
                'ultima' => $posUltima,
                'termino' => $posTermino
            ],
            'timeline_v2' => [
                'base' => formatearFechaCL($baseTimelineIso),
                'base_iso' => $baseTimelineIso,
                'fin' => formatearFechaCL($finTimelineIso),
                'fin_iso' => $finTimelineIso,
                'eventos' => $eventosTimeline,
                'sin_visitas' => $rangosSinVisitasTimeline
            ],
            'region_analytics' => [
                'resumen' => [
                    'regiones_activas' => $regionesActivas,
                
                    'region_mas_lenta' => $regionMasLenta ? [
                        'id_region' => $regionMasLenta['id_region'],
                        'region_nombre' => $regionMasLenta['region_nombre'],
                        'avance' => $regionMasLenta['avance']
                    ] : null,
                
                    'region_mayor_avance' => $regionMasRapida ? [
                        'id_region' => $regionMasRapida['id_region'],
                        'region_nombre' => $regionMasRapida['region_nombre'],
                        'avance' => $regionMasRapida['avance']
                    ] : null,
                
                    'empate_general_avance' => $hayEmpateGeneral,
                
                    'sla_promedio' => $slaPromedio,
                    'regiones_en_riesgo' => $regionesEnRiesgo
                ],
                'regiones' => $regionesDetalle,
                'usuarios_por_region' => $usuariosPorRegion
            ]
        ]
    ]);

} catch (Throwable $e) {
    responder([
        'ok' => false,
        'msg' => 'Error AJAX detalle campaña: ' . $e->getMessage()
    ]);
}