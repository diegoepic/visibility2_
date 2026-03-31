<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

// -----------------------------------------------------------------------------
// 1) Validar sesión
// -----------------------------------------------------------------------------
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

// -----------------------------------------------------------------------------
// 2) Funciones auxiliares
// -----------------------------------------------------------------------------
function cleanDate(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return ($dt && $dt->format('Y-m-d') === $date) ? $date : '';
}

function cacheRemember(string $namespace, array $payload, int $ttl, callable $resolver)
{
    $dir = __DIR__ . '/cache_mod_panel';

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $hash = md5(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $file = $dir . '/' . $namespace . '_' . $hash . '.cache';

    if (is_file($file) && (time() - filemtime($file) < $ttl)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = @unserialize($raw);
            if ($data !== false || $raw === serialize(false)) {
                return $data;
            }
        }
    }

    $data = $resolver();
    @file_put_contents($file, serialize($data), LOCK_EX);

    return $data;
}

function fetchAllPrepared(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Error al preparar la consulta: ' . $conn->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function resolverEstadoMapa(array $preguntas): array
{
    $complValues = ['AUDITORIA', 'IMPLEMENTACION', 'IMPL/AUD'];

    if (count(array_intersect($preguntas, $complValues)) > 0) {
        $markerColor = 'green';
    } elseif (count(array_unique($preguntas)) === 1 && ($preguntas[0] ?? '-') === '-') {
        $markerColor = 'red';
    } else {
        $markerColor = 'orange';
    }

    $estadoRaw = $preguntas[0] ?? '-';

    if (in_array($estadoRaw, $complValues, true)) {
        $estadoLegible = 'GESTIONADO';
    } elseif ($estadoRaw === '-' || trim((string)$estadoRaw) === '') {
        $estadoLegible = '-';
    } else {
        $estadoLegible = strtoupper(str_replace('_', ' ', (string)$estadoRaw));
    }

    return [$markerColor, $estadoLegible];
}

// -----------------------------------------------------------------------------
// 3) Parámetros de sesión / filtros
// -----------------------------------------------------------------------------
$id_empresa       = (int)($_SESSION['empresa_id'] ?? 0);
$user_division_id = (int)($_SESSION['division_id'] ?? 0);
$isMC             = ($user_division_id === 1);

$filter_division    = $isMC ? (int)($_GET['id_division'] ?? 0) : $user_division_id;
$filter_subdivision = (int)($_GET['id_subdivision'] ?? 0);
$filter_estado      = (int)($_GET['estado'] ?? 0);
$filter_campana     = (int)($_GET['id_campana'] ?? 0);
$id_ejecutor        = (int)($_GET['id_ejecutor'] ?? 0);
$filter_distrito    = (int)($_GET['id_distrito'] ?? 0);
$fecha_desde        = cleanDate($_GET['desde'] ?? '');
$fecha_hasta        = cleanDate($_GET['hasta'] ?? '');
$accionBuscar       = (int)($_GET['buscar'] ?? 0);
$tipoCampana        = (int)($_GET['tipo_gestion'] ?? 0); // 1=Campaña, 3=Ruta, 0=Todas

// -----------------------------------------------------------------------------
// 4) Catálogos cacheados
// -----------------------------------------------------------------------------
$divisiones = [];
if ($isMC) {
    try {
        $divisiones = cacheRemember(
            'mapa_divisiones_v1',
            ['id_empresa' => $id_empresa],
            900,
            function () use ($conn, $id_empresa) {
                $sql = "
                    SELECT id, nombre
                    FROM division_empresa
                    WHERE id_empresa = ?
                      AND estado = 1
                    ORDER BY nombre
                ";

                return fetchAllPrepared($conn, $sql, 'i', [$id_empresa]);
            }
        );
    } catch (Throwable $e) {
        error_log('[mapa_data][divisiones] ' . $e->getMessage());
        $divisiones = [];
    }
}

try {
    $distritos = cacheRemember(
        'mapa_distritos_v1',
        ['id_empresa' => $id_empresa],
        900,
        function () use ($conn, $id_empresa) {
            $sql = "
                SELECT DISTINCT d.id, d.nombre_distrito
                FROM local l
                INNER JOIN distrito d ON l.id_distrito = d.id
                WHERE l.id_empresa = ?
                ORDER BY d.nombre_distrito
            ";

            return fetchAllPrepared($conn, $sql, 'i', [$id_empresa]);
        }
    );
} catch (Throwable $e) {
    error_log('[mapa_data][distritos] ' . $e->getMessage());
    $distritos = [];
}

// -----------------------------------------------------------------------------
// 5) Query principal cacheada
// -----------------------------------------------------------------------------
$locales = [];

if ($accionBuscar === 1) {
    $sql = "
        SELECT
            CASE
                WHEN f.modalidad = 'solo_auditoria' THEN 'AUDITORIA'
                WHEN f.modalidad = 'solo_implementacion' THEN 'IMPLEMENTACION'
                WHEN f.modalidad = 'implementacion_auditoria' THEN 'IMPL/AUD'
                ELSE UPPER(f.modalidad)
            END AS modalidad,
            l.id AS id_local,
            l.codigo AS codigo,
            UPPER(l.nombre) AS nombre_local,
            UPPER(l.direccion) AS direccion_local,
            UPPER(c.comuna) AS comuna_local,
            UPPER(r.region) AS region_local,
            UPPER(u.usuario) AS usuario_local,
            l.lat AS latitud,
            l.lng AS longitud,
            DATE(fq.fechaVisita) AS fechaVisita,
            TIME(fq.fechaVisita) AS horaVisita,
            fq.fechaPropuesta AS fechaPropuesta,
            CASE
                WHEN fq.pregunta = 'solo_auditoria' THEN 'AUDITORIA'
                WHEN fq.pregunta IN ('solo_implementacion', 'solo_implementado') THEN 'IMPLEMENTACION'
                WHEN fq.pregunta = 'implementado_auditado' THEN 'IMPL/AUD'
                WHEN fq.pregunta = 'local_no_existe' THEN 'LOCAL NO EXISTE'
                WHEN fq.pregunta IN ('en proceso', 'cancelado')
                    THEN TRIM(SUBSTRING_INDEX(REPLACE(COALESCE(fq.observacion, ''), '|', '-'), '-', 1))
                ELSE UPPER(fq.pregunta)
            END AS pregunta,
            f.nombre AS nombre_campana,
            fq.observacion AS observacion
        FROM formularioQuestion fq
        INNER JOIN usuario u    ON u.id = fq.id_usuario
        INNER JOIN local l      ON l.id = fq.id_local
        INNER JOIN comuna c     ON c.id = l.id_comuna
        INNER JOIN region r     ON r.id = c.id_region
        INNER JOIN formulario f ON f.id = fq.id_formulario
        WHERE f.id_empresa = ?
    ";

    $params = [$id_empresa];
    $types  = 'i';

    if (in_array($tipoCampana, [1, 3], true)) {
        $sql .= " AND f.tipo = ? ";
        $params[] = $tipoCampana;
        $types .= 'i';
    }

    if ($filter_campana > 0) {
        $sql .= " AND f.id = ? ";
        $params[] = $filter_campana;
        $types .= 'i';
    }

    if ($id_ejecutor > 0) {
        $sql .= " AND fq.id_usuario = ? ";
        $params[] = $id_ejecutor;
        $types .= 'i';
    }

    if ($filter_division > 0) {
        $sql .= " AND f.id_division = ? ";
        $params[] = $filter_division;
        $types .= 'i';
    }

    if ($filter_subdivision > 0) {
        $sql .= " AND f.id_subdivision = ? ";
        $params[] = $filter_subdivision;
        $types .= 'i';
    }

    if (in_array($filter_estado, [1, 3], true)) {
        $sql .= " AND f.estado = ? ";
        $params[] = $filter_estado;
        $types .= 'i';
    }

    if ($filter_distrito > 0) {
        $sql .= " AND l.id_distrito = ? ";
        $params[] = $filter_distrito;
        $types .= 'i';
    }

    if ($fecha_desde !== '') {
        $sql .= " AND fq.fechaPropuesta >= ? ";
        $params[] = $fecha_desde . ' 00:00:00';
        $types .= 's';
    }

    if ($fecha_hasta !== '') {
        $sql .= " AND fq.fechaPropuesta <= ? ";
        $params[] = $fecha_hasta . ' 23:59:59';
        $types .= 's';
    }

    $sql .= " ORDER BY fq.fechaPropuesta DESC, l.nombre ASC";

    $cachePayload = [
        'id_empresa'         => $id_empresa,
        'user_division_id'   => $user_division_id,
        'isMC'               => $isMC ? 1 : 0,
        'filter_division'    => $filter_division,
        'filter_subdivision' => $filter_subdivision,
        'filter_estado'      => $filter_estado,
        'filter_campana'     => $filter_campana,
        'id_ejecutor'        => $id_ejecutor,
        'filter_distrito'    => $filter_distrito,
        'fecha_desde'        => $fecha_desde,
        'fecha_hasta'        => $fecha_hasta,
        'tipoCampana'        => $tipoCampana,
        'accionBuscar'       => $accionBuscar
    ];

    try {
        $locales = cacheRemember(
            'mapa_locales_v2',
            $cachePayload,
            300,
            function () use ($conn, $sql, $types, $params) {
                return fetchAllPrepared($conn, $sql, $types, $params);
            }
        );
    } catch (Throwable $e) {
        error_log('[mapa_data][locales] ' . $e->getMessage());
        $locales = [];
    }
}

// -----------------------------------------------------------------------------
// 6) Generar datos del mapa desde locales ya cacheados
// -----------------------------------------------------------------------------
$infoLocales = [];

foreach ($locales as $fila) {
    $idLocal = (int)($fila['id_local'] ?? 0);
    if ($idLocal <= 0) {
        continue;
    }

    if (!isset($infoLocales[$idLocal])) {
        $infoLocales[$idLocal] = [
            'id_local'         => $idLocal,
            'nombre_campana'   => $fila['nombre_campana'] ?? '',
            'modalidad'        => $fila['modalidad'] ?? '',
            'nombre_local'     => $fila['nombre_local'] ?? '',
            'direccion_local'  => $fila['direccion_local'] ?? '',
            'comuna_local'     => $fila['comuna_local'] ?? '',
            'region_local'     => $fila['region_local'] ?? '',
            'usuario_local'    => $fila['usuario_local'] ?? '',
            'fechaVisita'      => $fila['fechaVisita'] ?? null,
            'horaVisita'       => $fila['horaVisita'] ?? null,
            'pregunta'         => $fila['pregunta'] ?? '-',
            'latitud'          => is_null($fila['latitud']) ? null : (float)$fila['latitud'],
            'longitud'         => is_null($fila['longitud']) ? null : (float)$fila['longitud'],
            'preguntas'        => []
        ];
    }

    $infoLocales[$idLocal]['preguntas'][] = $fila['pregunta'] ?? '-';
}

$coordenadas_locales = [];

if ($accionBuscar === 1) {
    foreach ($infoLocales as $loc) {
        if ($loc['latitud'] === null || $loc['longitud'] === null) {
            continue;
        }

        [$markerColor, $estadoLegible] = resolverEstadoMapa($loc['preguntas']);

        $coordenadas_locales[] = [
            'idLocal'         => $loc['id_local'],
            'nombre_campana'  => $loc['nombre_campana'],
            'modalidad'       => $loc['modalidad'],
            'nombre_local'    => $loc['nombre_local'],
            'direccion_local' => $loc['direccion_local'],
            'comuna_local'    => $loc['comuna_local'],
            'region_local'    => $loc['region_local'],
            'usuario_local'   => $loc['usuario_local'],
            'fechaVisita'     => $loc['fechaVisita'],
            'horaVisita'      => $loc['horaVisita'],
            'pregunta'        => $loc['pregunta'],
            'latitud'         => $loc['latitud'],
            'longitud'        => $loc['longitud'],
            'markerColor'     => $markerColor,
            'estado'          => $estadoLegible
        ];
    }
}

$conn->close();
?>