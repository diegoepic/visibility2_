<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/app/con_.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

/* =========================================================
   FUNCIONES BASE
========================================================= */

function cleanDate(?string $date): string
{
    $date = trim((string)$date);

    if ($date === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);

    return ($dt && $dt->format('Y-m-d') === $date) ? $date : '';
}

function fetchAllPrepared(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Error al preparar consulta: ' . $conn->error);
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

/* =========================================================
   PERFIL / PERMISOS
========================================================= */

$idUsuarioSesion  = (int)($_SESSION['usuario_id'] ?? 0);
$id_empresa       = (int)($_SESSION['empresa_id'] ?? 0);
$user_division_id = (int)($_SESSION['division_id'] ?? 0);

$idPerfil = 0;

try {
    $stmtPerfil = $conn->prepare("
        SELECT id_perfil
        FROM usuario
        WHERE id = ?
        LIMIT 1
    ");

    $stmtPerfil->bind_param("i", $idUsuarioSesion);
    $stmtPerfil->execute();

    $resPerfil = $stmtPerfil->get_result();

    if ($rowPerfil = $resPerfil->fetch_assoc()) {
        $idPerfil = (int)$rowPerfil['id_perfil'];
    }

    $stmtPerfil->close();

} catch (Throwable $e) {
    error_log('[admin_mapa][perfil] ' . $e->getMessage());
}

$puedeCambiarDivision = ($idPerfil === 1);
$isMC = ($user_division_id === 1);

/* =========================================================
   FILTROS CON PERSISTENCIA
========================================================= */

$filtroSessionKey = 'admin_locales_mapa_filtros';

$filtrosDefault = [
    'id_empresa'      => $id_empresa,
    'id_division'     => $puedeCambiarDivision ? 0 : $user_division_id,
    'id_subdivision'  => 0,
    'tipo_gestion'    => 0,
    'estado'          => 0,
    'id_campana'      => 0,
    'id_ejecutor'     => 0,
    'id_distrito'     => 0,
    'id_region'       => 0,
    'id_comuna'       => 0,
    'desde'           => '',
    'hasta'           => '',
    'buscar'          => 0,
];

if (isset($_GET['buscar'])) {
    $filtrosActuales = [
        'id_empresa'      => (int)($_GET['empresa_id'] ?? $id_empresa),
        'id_division'     => (int)($_GET['id_division'] ?? 0),
        'id_subdivision'  => (int)($_GET['id_subdivision'] ?? 0),
        'tipo_gestion'    => (int)($_GET['tipo_gestion'] ?? 0),
        'estado'          => (int)($_GET['estado'] ?? 0),
        'id_campana'      => (int)($_GET['id_campana'] ?? 0),
        'id_ejecutor'     => (int)($_GET['id_ejecutor'] ?? 0),
        'id_distrito'     => (int)($_GET['id_distrito'] ?? 0),
        'id_region'       => (int)($_GET['id_region'] ?? 0),
        'id_comuna'       => (int)($_GET['id_comuna'] ?? 0),
        'desde'           => cleanDate($_GET['desde'] ?? ''),
        'hasta'           => cleanDate($_GET['hasta'] ?? ''),
        'buscar'          => 1,
    ];

    if (!$puedeCambiarDivision) {
        $filtrosActuales['id_division'] = $user_division_id;
    }

    $_SESSION[$filtroSessionKey] = $filtrosActuales;

} else {
    $filtrosActuales = $_SESSION[$filtroSessionKey] ?? $filtrosDefault;
}

$id_empresa          = (int)$filtrosActuales['id_empresa'];
$filter_division     = (int)$filtrosActuales['id_division'];
$filter_subdivision  = (int)$filtrosActuales['id_subdivision'];
$tipoCampana         = (int)$filtrosActuales['tipo_gestion'];
$filter_estado       = (int)$filtrosActuales['estado'];
$filter_campana      = (int)$filtrosActuales['id_campana'];
$id_ejecutor         = (int)$filtrosActuales['id_ejecutor'];
$filter_distrito     = (int)$filtrosActuales['id_distrito'];
$filter_region       = (int)$filtrosActuales['id_region'];
$filter_comuna       = (int)$filtrosActuales['id_comuna'];
$fecha_desde         = (string)$filtrosActuales['desde'];
$fecha_hasta         = (string)$filtrosActuales['hasta'];
$accionBuscar        = (int)$filtrosActuales['buscar'];

/* =========================================================
   DIVISIONES
========================================================= */

$divisiones = [];

try {
    if ($puedeCambiarDivision) {
        $sqlDiv = "
            SELECT id, nombre
            FROM division_empresa
            WHERE estado = 1
        ";

        $paramsDiv = [];
        $typesDiv  = '';

        if ($id_empresa > 0) {
            $sqlDiv .= " AND id_empresa = ? ";
            $paramsDiv[] = $id_empresa;
            $typesDiv .= 'i';
        }

        $sqlDiv .= " ORDER BY nombre ASC ";

        $divisiones = fetchAllPrepared($conn, $sqlDiv, $typesDiv, $paramsDiv);
    }

} catch (Throwable $e) {
    error_log('[admin_mapa][divisiones] ' . $e->getMessage());
    $divisiones = [];
}

/* =========================================================
   DISTRITOS
========================================================= */

$distritos = [];

try {
    if ($id_empresa > 0) {
        $distritos = fetchAllPrepared(
            $conn,
            "
            SELECT DISTINCT 
                d.id, 
                d.nombre_distrito
            FROM local l
            INNER JOIN distrito d ON l.id_distrito = d.id
            WHERE l.id_empresa = ?
            ORDER BY d.nombre_distrito ASC
            ",
            'i',
            [$id_empresa]
        );
    }

} catch (Throwable $e) {
    error_log('[admin_mapa][distritos] ' . $e->getMessage());
    $distritos = [];
}

/* =========================================================
   REGIONES
========================================================= */

$regiones = [];

try {
    if ($id_empresa > 0) {
        $regiones = fetchAllPrepared(
            $conn,
            "
            SELECT DISTINCT
                r.id,
                r.region AS nombre
            FROM local l
            INNER JOIN comuna c ON c.id = l.id_comuna
            INNER JOIN region r ON r.id = c.id_region
            WHERE l.id_empresa = ?
            ORDER BY r.region ASC
            ",
            'i',
            [$id_empresa]
        );
    }

} catch (Throwable $e) {
    error_log('[admin_mapa][regiones] ' . $e->getMessage());
    $regiones = [];
}
/* =========================================================
   COMUNAS
========================================================= */

$comunas = [];

try {
    if ($id_empresa > 0) {
        $sqlComunas = "
            SELECT DISTINCT
                c.id,
                c.comuna AS nombre,
                c.id_region
            FROM local l
            INNER JOIN comuna c ON c.id = l.id_comuna
            INNER JOIN region r ON r.id = c.id_region
            WHERE l.id_empresa = ?
        ";

        $paramsComunas = [$id_empresa];
        $typesComunas  = 'i';

        if ($filter_region > 0) {
            $sqlComunas .= " AND c.id_region = ? ";
            $paramsComunas[] = $filter_region;
            $typesComunas .= 'i';
        }

        $sqlComunas .= " ORDER BY c.comuna ASC ";

        $comunas = fetchAllPrepared($conn, $sqlComunas, $typesComunas, $paramsComunas);
    }

} catch (Throwable $e) {
    error_log('[admin_mapa][comunas] ' . $e->getMessage());
    $comunas = [];
}

/* =========================================================
   QUERY PRINCIPAL
========================================================= */

$locales = [];
$coordenadas_locales = [];

if ($accionBuscar === 1) {
    try {
        $sql = "
            SELECT
                f.id AS id_formulario,

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

                c.id AS id_comuna,
                c.id_region AS id_region,
                UPPER(c.comuna) AS comuna_local,
                UPPER(r.region) AS region_local,

                fq.id_usuario AS id_usuario,
                UPPER(u.usuario) AS usuario_local,

                l.lat AS lat,
                l.lng AS lng,

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
              AND l.lat IS NOT NULL
              AND l.lng IS NOT NULL
              AND l.lat <> ''
              AND l.lng <> ''
        ";

        $params = [$id_empresa];
        $types  = 'i';

$modoMerchan = ($id_ejecutor > 0);

if ($modoMerchan) {
    /*
      MODO RESCATE / CORRECCIÓN:
      Si selecciono un merchan, quiero ver TODO lo asignado a él
      dentro de la empresa, aunque esos locales estén cargados en otra
      división, subdivisión, canal, campaña o tipo de gestión.

      Esto sirve justamente para corregir cargas cruzadas:
      Ejemplo:
      Merchan Red Bull / Tradicional con locales cargados por error
      en Canal Moderno.
    */
    $sql .= " AND fq.id_usuario = ? ";
    $params[] = $id_ejecutor;
    $types .= 'i';

} else {
    /*
      MODO NORMAL:
      Si NO selecciono merchan, aplico filtros normales para no cargar
      todo el universo de locales.
    */

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

    if ($filter_subdivision === -1) {
        $sql .= " AND (f.id_subdivision IS NULL OR f.id_subdivision = 0) ";
    }
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

        if ($filter_region > 0) {
            $sql .= " AND c.id_region = ? ";
            $params[] = $filter_region;
            $types .= 'i';
        }

        if ($filter_comuna > 0) {
            $sql .= " AND l.id_comuna = ? ";
            $params[] = $filter_comuna;
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

        $sql .= "
            ORDER BY fq.fechaPropuesta DESC, l.nombre ASC
        ";

        $locales = fetchAllPrepared($conn, $sql, $types, $params);

    } catch (Throwable $e) {
        error_log('[admin_mapa][locales] ' . $e->getMessage());
        $locales = [];
    }
}

/* =========================================================
   COORDENADAS PARA EL JS ADMIN
========================================================= */

$infoLocales = [];

foreach ($locales as $fila) {
    $idFormulario = (int)($fila['id_formulario'] ?? 0);
    $idLocal      = (int)($fila['id_local'] ?? 0);

    if ($idFormulario <= 0 || $idLocal <= 0) {
        continue;
    }

    $key = $idFormulario . '_' . $idLocal;

    if (!isset($infoLocales[$key])) {
        $infoLocales[$key] = [
            'id_formulario'   => $idFormulario,
            'id_local'        => $idLocal,
            'codigo'          => $fila['codigo'] ?? '',
            'nombre_campana'  => $fila['nombre_campana'] ?? '',
            'modalidad'       => $fila['modalidad'] ?? '',
            'nombre_local'    => $fila['nombre_local'] ?? '',
            'direccion_local' => $fila['direccion_local'] ?? '',
            'id_comuna'       => (int)($fila['id_comuna'] ?? 0),
            'id_region'       => (int)($fila['id_region'] ?? 0),
            'comuna_local'    => $fila['comuna_local'] ?? '',
            'region_local'    => $fila['region_local'] ?? '',
            'id_usuario'      => (int)($fila['id_usuario'] ?? 0),
            'usuario_local'   => $fila['usuario_local'] ?? '',
            'fechaVisita'     => $fila['fechaVisita'] ?? null,
            'horaVisita'      => $fila['horaVisita'] ?? null,
            'fechaPropuesta'  => $fila['fechaPropuesta'] ?? null,
            'pregunta'        => $fila['pregunta'] ?? '-',
            'observacion'     => $fila['observacion'] ?? '',
            'lat'             => is_null($fila['lat']) ? null : (float)$fila['lat'],
            'lng'             => is_null($fila['lng']) ? null : (float)$fila['lng'],
            'preguntas'       => [],
        ];
    }

    $infoLocales[$key]['preguntas'][] = $fila['pregunta'] ?? '-';
}

foreach ($infoLocales as $loc) {
    if ($loc['lat'] === null || $loc['lng'] === null) {
        continue;
    }

    [$markerColor, $estadoLegible] = resolverEstadoMapa($loc['preguntas']);

    $coordenadas_locales[] = [
        'id_formulario'   => $loc['id_formulario'],
        'id_local'        => $loc['id_local'],

        // Compatibilidad por si algún JS antiguo aún lo usa
        'idLocal'         => $loc['id_local'],

        'codigo'          => $loc['codigo'],
        'nombre_campana'  => $loc['nombre_campana'],
        'modalidad'       => $loc['modalidad'],
        'nombre_local'    => $loc['nombre_local'],
        'direccion_local' => $loc['direccion_local'],

        'id_comuna'       => $loc['id_comuna'],
        'id_region'       => $loc['id_region'],
        'comuna_local'    => $loc['comuna_local'],
        'region_local'    => $loc['region_local'],

        'id_usuario'      => $loc['id_usuario'],
        'usuario_local'   => $loc['usuario_local'],
        'fechaVisita'     => $loc['fechaVisita'],
        'horaVisita'      => $loc['horaVisita'],
        'fechaPropuesta'  => $loc['fechaPropuesta'],
        'pregunta'        => $loc['pregunta'],
        'observacion'     => $loc['observacion'],

        // Nombres que usa admin_mapa_lotes.js
        'lat'             => $loc['lat'],
        'lng'             => $loc['lng'],

        // Compatibilidad visual
        'latitud'         => $loc['lat'],
        'longitud'        => $loc['lng'],

        'markerColor'     => $markerColor,
        'estado'          => $estadoLegible,
    ];
}

// No cerrar $conn aquí, porque el módulo y AJAX pueden seguir usando conexión.
?>