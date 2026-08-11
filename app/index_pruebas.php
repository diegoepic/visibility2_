<?php

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/lib/security_headers.php';
emit_security_headers();

$nombre         = htmlspecialchars($_SESSION['usuario_nombre'], ENT_QUOTES, 'UTF-8');
$apellido       = htmlspecialchars($_SESSION['usuario_apellido'], ENT_QUOTES, 'UTF-8');
$empresa_id     = intval($_SESSION['empresa_id']);
$usuario_id     = intval($_SESSION['usuario_id']);
$division_id    = intval($_SESSION['division_id']);
$clasificacion_usuario = '';

$sqlClasificacionUsuario = "
    SELECT clasificacion_usuario
    FROM usuario
    WHERE id = ?
    LIMIT 1
";

$stmtClasificacion = $conn->prepare($sqlClasificacionUsuario);
$stmtClasificacion->bind_param('i', $usuario_id);
$stmtClasificacion->execute();
$resClasificacion = $stmtClasificacion->get_result();

if ($rowClasificacion = $resClasificacion->fetch_assoc()) {
    $clasificacion_usuario = $rowClasificacion['clasificacion_usuario'] ?? '';
}

$stmtClasificacion->close();

if (!in_array($clasificacion_usuario, ['interno', 'externo'], true)) {
    $clasificacion_usuario = '__sin_clasificacion__';
}

$_SESSION['clasificacion_usuario'] = $clasificacion_usuario;

if (!in_array($clasificacion_usuario, ['interno', 'externo'], true)) {
    $clasificacion_usuario = 'interno';
}

$appScope       = '/visibility2/app';
$precacheLimit  = isset($_ENV['GESTIONAR_PRECACHE_LIMIT']) ? (int)$_ENV['GESTIONAR_PRECACHE_LIMIT'] : 10;
$precacheLimit  = $precacheLimit > 0 ? $precacheLimit : 10;
$googleMapsApiKey = getenv('GOOGLE_MAPS_API_KEY');
$googleMapsApiKey = is_string($googleMapsApiKey) ? trim($googleMapsApiKey) : '';

$TEST_MODE = getenv('V2_TEST_MODE') === '1';
// URL del proxy backend de Routes API. Por defecto activo; se puede desactivar con la env
// ROUTES_PROXY_URL='' (rollback a llamada directa a Google). En test mode se desactiva.
$routesProxyEnv = getenv('ROUTES_PROXY_URL');
$routesProxyUrl = $TEST_MODE
    ? ''
    : ($routesProxyEnv !== false ? trim((string)$routesProxyEnv) : '/visibility2/app/api/route_compute.php');
if ($TEST_MODE) {
    $today = date('Y-m-d');
    $campanas = [[
        'id_campana' => 1,
        'nombre_campana' => 'Campaña Test',
        'estado' => '1',
        'fechaInicio' => $today,
        'fechaTermino' => $today
    ]];
    $compCampanas = [];
    $locales = [[
        'fechaPropuesta' => $today,
        'codigoLocal'    => 'T-001',
        'cadena'         => 'Cadena Test',
        'direccionLocal' => 'Dirección Test 123',
        'nombreLocal'    => 'Local Test',
        'vendedor'       => 'Tester',
        'idLocal'        => 1,
        'latitud'        => -33.4489,
        'lng'            => -70.6693,
        'totalCampanas'  => 1,
        'campanasIds'    => ['1'],
        'is_priority'    => 0
    ]];
    $locales_reag = [[
        'fechaPropuesta' => $today,
        'codigoLocal'    => 'T-002',
        'cadena'         => 'Cadena Test',
        'direccionLocal' => 'Dirección Test 456',
        'nombreLocal'    => 'Local Reag',
        'vendedor'       => 'Tester',
        'idLocal'        => 2,
        'latitud'        => -33.4495,
        'lng'            => -70.6702,
        'totalCampanas'  => 1,
        'campanasIds'    => ['1'],
        'is_priority'    => 1
    ]];
    $locales_por_dia = [$today => $locales];
    $locales_reag_por_dia = [$today => $locales_reag];
    $coordenadas_locales_programados = [[
        'idLocal'        => 1,
        'nombre_local'   => 'Cadena Test - Dirección Test 123',
        'latitud'        => -33.4489,
        'lng'            => -70.6693,
        'visitado'       => false,
        'markerColor'    => 'red',
        'fechaPropuesta' => $today
    ]];
    $coordenadas_locales_reag = [[
        'idLocal'        => 2,
        'nombre_local'   => 'Cadena Test - Dirección Test 456',
        'latitud'        => -33.4495,
        'lng'            => -70.6702,
        'visitado'       => false,
        'markerColor'    => 'blue',
        'fechaPropuesta' => $today
    ]];
} else {

$sql_campaigns = "
    SELECT DISTINCT
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.estado,
        f.fechaInicio,
        f.fechaTermino,
        f.modalidad,
        f.id_division,
        COALESCE(de.nombre, 'Sin división') AS division_nombre,
        CASE WHEN f.tipo = 1
              AND f.modalidad IN ('implementacion_auditoria','solo_implementacion')
              AND EXISTS (
                  SELECT 1 FROM formularioQuestion fq2
                  WHERE fq2.id_formulario = f.id
                    AND fq2.material IS NOT NULL
                    AND TRIM(fq2.material) != ''
                    AND fq2.valor_propuesto > 0
              )
             THEN 1 ELSE 0
        END AS tiene_recepcion_materiales
    FROM formularioQuestion fq
    INNER JOIN formulario f ON f.id = fq.id_formulario
    LEFT JOIN division_empresa de ON de.id = f.id_division
    WHERE fq.id_usuario = ?
      AND f.id_empresa = ?
      AND fq.estado = 0
      AND f.tipo in (3,1)
      AND (
            (f.modalidad <> 'implementacion_por_etapas' AND fq.countVisita = 0)
            OR
            (f.modalidad = 'implementacion_por_etapas' AND (fq.etapa_material IS NULL OR fq.etapa_material NOT IN ('implementado','retirado') OR (fq.etapa_material = 'implementado' AND CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED) < CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED))))
          )
      AND f.estado = 1
    ORDER BY f.fechaInicio DESC
";
$stmt_campaigns = $conn->prepare($sql_campaigns);
$stmt_campaigns->bind_param('ii', $usuario_id, $empresa_id);
$stmt_campaigns->execute();
$result_campaigns = $stmt_campaigns->get_result();
$campanas = [];

while ($row = $result_campaigns->fetch_assoc()) {
    $campanas[] = [
        'id_campana'                => (int)$row['id_campana'],
        'nombre_campana'            => htmlspecialchars($row['nombre_campana'], ENT_QUOTES, 'UTF-8'),
        'estado'                    => htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8'),
        'fechaInicio'               => $row['fechaInicio'],
        'fechaTermino'              => $row['fechaTermino'],
        'modalidad'                 => $row['modalidad'] ?? '',
        'tiene_recepcion_materiales'=> (int)($row['tiene_recepcion_materiales'] ?? 0),
        'id_division'               => (int)($row['id_division'] ?? 0),
        'division_nombre'           => htmlspecialchars($row['division_nombre'] ?? 'Sin división', ENT_QUOTES, 'UTF-8'),
    ];
}

/* Divisiones presentes en las campañas del ejecutor: alimentan el filtro del
   panel. Se arman acá (y no en JS) para no depender del texto renderizado. */
$divisionesCampanas = [];
foreach ($campanas as $c) {
    $divisionesCampanas[(int)$c['id_division']] = $c['division_nombre'];
}
asort($divisionesCampanas, SORT_NATURAL | SORT_FLAG_CASE);

/* Normaliza texto para búsqueda: minúsculas y sin tildes, para que escribir
   "navidad" encuentre "NAVIDAD" y "promoción" encuentre "PROMOCION". */
if (!function_exists('v2_norm_busqueda')) {
    function v2_norm_busqueda(string $s): string {
        $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        $s = mb_strtolower(trim($s), 'UTF-8');
        return strtr($s, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        ]);
    }
}

/* Conteo de recepciones previas por campaña (para badge) */
$campanaIdsConRecepcion = array_column($campanas, 'id_campana');
$recepcionesCount = [];
if (!empty($campanaIdsConRecepcion)) {
    $placeholders = implode(',', array_fill(0, count($campanaIdsConRecepcion), '?'));
    $types        = str_repeat('i', count($campanaIdsConRecepcion) + 1);
    $params       = array_merge($campanaIdsConRecepcion, [$usuario_id]);
    $stmtRC = $conn->prepare("
        SELECT id_formulario, COUNT(*) AS total
        FROM material_recepcion
        WHERE id_formulario IN ($placeholders) AND id_usuario = ?
        GROUP BY id_formulario
    ");
    $stmtRC->bind_param($types, ...$params);
    $stmtRC->execute();
    $resRC = $stmtRC->get_result();
    while ($r = $resRC->fetch_assoc()) {
        $recepcionesCount[(int)$r['id_formulario']] = (int)$r['total'];
    }
    $stmtRC->close();
}
$stmt_campaigns->close();


$condicionExclusionEspecial = ($usuario_id === 2) ? "" : "AND f.id <> 2037";

$sql_comp = "
    SELECT DISTINCT
        f.id AS id_campana,
        f.nombre AS nombre_campana,
        f.estado
    FROM formulario f
    WHERE f.tipo = 2
      AND f.estado = 1
      AND f.deleted_at IS NULL
      {$condicionExclusionEspecial}
      AND EXISTS (
            SELECT 1
            FROM formulario_division_habilitada fdh
            WHERE fdh.id_formulario = f.id
              AND fdh.id_division = ?
              AND fdh.clasificacion_usuario = ?
      )
    ORDER BY f.nombre ASC
";

$stmt_comp = $conn->prepare($sql_comp);
$stmt_comp->bind_param('is', $division_id, $clasificacion_usuario);

$stmt_comp->execute();
$result_comp = $stmt_comp->get_result();
$compCampanas = [];
while ($row = $result_comp->fetch_assoc()) {
    $compCampanas[] = [
        'id_campana' => (int)$row['id_campana'],
        'nombre_campana' => htmlspecialchars($row['nombre_campana'], ENT_QUOTES, 'UTF-8'),
        'estado'         => htmlspecialchars($row['estado'], ENT_QUOTES, 'UTF-8')
    ];
}
$stmt_comp->close();


// 3) Locales Programados (no visitados)
$sql = "
    SELECT
    IFNULL(DATE(fq.fechaPropuesta), CURDATE()) AS fechaPropuesta,
    l.codigo    AS codigoLocal,
    c.nombre    AS cadena,
    l.direccion AS direccionLocal,
    l.nombre    AS nombreLocal,
    IFNULL(v.nombre_vendedor, '') AS vendedor,
    l.id_vendedor AS idVendedor,
    IFNULL(v.telefono, '') AS vendedorTelefono,
    IFNULL(co.comuna, '') AS comuna,
    l.id        AS idLocal,
    l.lat       AS latitud,
    l.lng       AS lng,
    COUNT(CASE WHEN (
                (f.modalidad <> 'implementacion_por_etapas' AND fq.countVisita = 0)
                OR (f.modalidad = 'implementacion_por_etapas' AND (fq.etapa_material IS NULL OR fq.etapa_material NOT IN ('implementado','retirado') OR (fq.etapa_material = 'implementado' AND CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED) < CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED))))
              ) THEN 1 END)        AS totalCampanas,
    GROUP_CONCAT(DISTINCT CASE WHEN (
                (f.modalidad <> 'implementacion_por_etapas' AND fq.countVisita = 0)
                OR (f.modalidad = 'implementacion_por_etapas' AND (fq.etapa_material IS NULL OR fq.etapa_material NOT IN ('implementado','retirado') OR (fq.etapa_material = 'implementado' AND CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED) < CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED))))
              ) THEN f.id END) AS campanasIds,
    MAX(cu.nombre)              AS tipo_cuenta,
    MAX(fq.is_priority)         AS is_priority,
    MAX(l.relevancia)           AS relevancia,
    MAX(le.oc)                  AS oc,
    MAX(le.cooler)              AS cooler
FROM formularioQuestion fq
INNER JOIN formulario f ON f.id        = fq.id_formulario
INNER JOIN local      l ON l.id        = fq.id_local
INNER JOIN cadena     c ON c.id        = l.id_cadena
INNER JOIN vendedor   v ON v.id = l.id_vendedor
LEFT JOIN comuna     co ON co.id = l.id_comuna
LEFT JOIN cuenta     cu ON cu.id = l.id_cuenta
LEFT JOIN local_ext  le ON le.id_local = l.id
WHERE fq.id_usuario    = ?
  AND f.id_empresa      = ?
  AND f.tipo           IN (3,1)
  AND f.estado         = 1
GROUP BY
    IFNULL(DATE(fq.fechaPropuesta), CURDATE()),
    l.codigo, c.nombre, l.direccion, l.nombre, co.comuna,
    l.id, l.lat, l.lng, v.nombre_vendedor, l.id_vendedor, v.telefono
HAVING SUM(CASE WHEN (
                (f.modalidad <> 'implementacion_por_etapas' AND fq.countVisita = 0)
                OR (f.modalidad = 'implementacion_por_etapas' AND (fq.etapa_material IS NULL OR fq.etapa_material NOT IN ('implementado','retirado') OR (fq.etapa_material = 'implementado' AND CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED) < CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED))))
              ) THEN 1 ELSE 0 END) > 0
ORDER BY fechaPropuesta ASC, c.nombre, l.direccion
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $usuario_id, $empresa_id);
$stmt->execute();
$result = $stmt->get_result();

$locales = [];
while ($row = $result->fetch_assoc()) {
    $row['campanasIds'] = explode(',', $row['campanasIds']);
    $locales[] = [
        'fechaPropuesta' => $row['fechaPropuesta'],
        'codigoLocal'    => htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'),
        'cadena'         => htmlspecialchars($row['cadena'], ENT_QUOTES, 'UTF-8'),
        'direccionLocal' => htmlspecialchars($row['direccionLocal'], ENT_QUOTES, 'UTF-8'),
        'nombreLocal'    => htmlspecialchars($row['nombreLocal'], ENT_QUOTES, 'UTF-8'),
        'vendedor'       => htmlspecialchars($row['vendedor'], ENT_QUOTES, 'UTF-8'),
        'idVendedor'     => (int)$row['idVendedor'],
        'vendedorTelefono' => htmlspecialchars($row['vendedorTelefono'], ENT_QUOTES, 'UTF-8'),
        'idLocal'        => (int)$row['idLocal'],
        'latitud'        => (float)$row['latitud'],
        'lng'            => (float)$row['lng'],
        'totalCampanas'  => (int)$row['totalCampanas'],
        'campanasIds'    => $row['campanasIds'],
        'is_priority'    => (int)$row['is_priority'],
        'is_botilleria'  => (stripos($row['tipo_cuenta'] ?? '', 'botiller') !== false),
        'comuna'         => htmlspecialchars($row['comuna'], ENT_QUOTES, 'UTF-8'),
        'relevancia'     => $row['relevancia'] !== null ? (int)$row['relevancia'] : null,
        'oc'             => htmlspecialchars($row['oc'] ?? '', ENT_QUOTES, 'UTF-8'),
        'cooler'         => htmlspecialchars($row['cooler'] ?? '', ENT_QUOTES, 'UTF-8'),
    ];
}

$stmt->close();

// Override de coordenadas por solicitudes pendientes del usuario actual
$geoOverrides = [];
$stmtOvr = $conn->prepare("SELECT id_local, lat_nueva, lng_nueva, dir_nueva FROM solicitud_cambio_local WHERE id_usuario=? AND estado='pendiente' AND deleted_at IS NULL");
$stmtOvr->bind_param('i', $usuario_id);
$stmtOvr->execute();
foreach ($stmtOvr->get_result()->fetch_all(MYSQLI_ASSOC) as $ovr) {
    $geoOverrides[(int)$ovr['id_local']] = $ovr;
}
$stmtOvr->close();

if (!empty($geoOverrides)) {
    foreach ($locales as &$loc) {
        $lid = $loc['idLocal'];
        if (isset($geoOverrides[$lid])) {
            $ov = $geoOverrides[$lid];
            $loc['latitud']       = (float)$ov['lat_nueva'];
            $loc['lng']           = (float)$ov['lng_nueva'];
            $loc['direccionLocal'] = htmlspecialchars((string)$ov['dir_nueva'], ENT_QUOTES, 'UTF-8');
            $loc['_geo_pendiente'] = true;
        }
    }
    unset($loc);
}

// Override de vendedor por solicitudes pendientes del usuario actual
$vendedorOverrides = [];
$stmtOvrV = $conn->prepare("SELECT id_local, nombre_vendedor_nuevo, telefono_nuevo FROM solicitud_cambio_vendedor WHERE id_usuario=? AND estado='pendiente' AND deleted_at IS NULL");
$stmtOvrV->bind_param('i', $usuario_id);
$stmtOvrV->execute();
foreach ($stmtOvrV->get_result()->fetch_all(MYSQLI_ASSOC) as $ovr) {
    $vendedorOverrides[(int)$ovr['id_local']] = $ovr;
}
$stmtOvrV->close();

if (!empty($vendedorOverrides)) {
    foreach ($locales as &$loc) {
        $lid = $loc['idLocal'];
        if (isset($vendedorOverrides[$lid])) {
            $ov = $vendedorOverrides[$lid];
            $loc['vendedor']         = htmlspecialchars((string)$ov['nombre_vendedor_nuevo'], ENT_QUOTES, 'UTF-8');
            $loc['vendedorTelefono'] = htmlspecialchars((string)($ov['telefono_nuevo'] ?? ''), ENT_QUOTES, 'UTF-8');
            $loc['_vendedor_pendiente'] = true;
        }
    }
    unset($loc);
}

// Listado completo de vendedores para el selector del modal "Cambiar vendedor"
$vendedoresDisponibles = [];
$stmtVendList = $conn->prepare("
    SELECT MIN(id) AS id, ANY_VALUE(nombre_vendedor) AS nombre_vendedor, IFNULL(MAX(telefono),'') AS telefono
    FROM vendedor
    GROUP BY BINARY nombre_vendedor
    ORDER BY nombre_vendedor ASC
");
$stmtVendList->execute();
foreach ($stmtVendList->get_result()->fetch_all(MYSQLI_ASSOC) as $v) {
    $vendedoresDisponibles[] = [
        'id'       => (int)$v['id'],
        'nombre'   => $v['nombre_vendedor'],
        'telefono' => $v['telefono'],
    ];
}
$stmtVendList->close();

/* Permiso "adelantar ruta": a los ejecutores que NO lo tienen se les ocultan
   los locales con fecha futura. Se filtra la lista base (no solo el <select>)
   para que tabla, selector de fechas, mapa y planificador queden consistentes:
   filtrar solo el selector dejaría las fechas futuras igual de accesibles. */
require_once __DIR__ . '/lib/ruta_permisos.php';
$puedeAdelantarRuta = ruta_puede_adelantar($conn, (int)$usuario_id);
if (!$puedeAdelantarRuta) {
    $locales = ruta_filtrar_fechas_futuras($locales);
}

$locales_por_dia = [];
foreach ($locales as $local) {
    $fecha = $local['fechaPropuesta'];
    if (!isset($locales_por_dia[$fecha])) {
        $locales_por_dia[$fecha] = [];
    }
    $locales_por_dia[$fecha][] = $local;
}

// 4) Locales Reagendados
$sql_reagendados = "
SELECT
    IFNULL(DATE(fq.fechaPropuesta), CURDATE()) AS fechaPropuesta,
    l.codigo  AS codigoLocal,
    c.nombre  AS cadena,
    l.direccion AS direccionLocal,
    l.nombre AS nombreLocal,
    IFNULL(v.nombre_vendedor, '') AS vendedor,
    l.id_vendedor AS idVendedor,
    IFNULL(v.telefono, '') AS vendedorTelefono,
    l.id AS idLocal,
    l.lat AS latitud,
    l.lng AS lng,
    COUNT(DISTINCT f.id) AS totalCampanas,
    GROUP_CONCAT(DISTINCT f.id) AS campanasIds,
    MAX(cu.nombre) AS tipo_cuenta,
    MAX(fq.is_priority) AS is_priority,
    MAX(l.relevancia)   AS relevancia,
    MAX(le.oc)          AS oc,
    MAX(le.cooler)      AS cooler
FROM formularioQuestion fq
INNER JOIN formulario   f ON f.id = fq.id_formulario
INNER JOIN local        l ON l.id = fq.id_local
INNER JOIN cadena       c ON c.id = l.id_cadena
INNER JOIN vendedor     v ON v.id = l.id_vendedor
LEFT JOIN cuenta       cu ON cu.id = l.id_cuenta
LEFT JOIN local_ext    le ON le.id_local = l.id
WHERE fq.id_usuario = ?
  AND f.id_empresa  = ?
  AND f.tipo        IN (3,1)
  AND f.estado      = 1
  AND fq.pregunta   = 'en proceso'
GROUP BY
    IFNULL(DATE(fq.fechaPropuesta), CURDATE()),
    l.codigo, c.nombre, l.direccion, l.nombre,
    l.id, l.lat, l.lng, v.nombre_vendedor, l.id_vendedor, v.telefono
ORDER BY
    fechaPropuesta ASC,
    c.nombre,
    l.direccion
";

$stmt_reag = $conn->prepare($sql_reagendados);
$stmt_reag->bind_param('ii', $usuario_id, $empresa_id);
$stmt_reag->execute();
$result_reag = $stmt_reag->get_result();

$locales_reag = [];
while ($row = $result_reag->fetch_assoc()) {
    $row['campanasIds'] = explode(',', $row['campanasIds']);
    $locales_reag[] = [
        'fechaPropuesta' => $row['fechaPropuesta'],
        'codigoLocal'    => htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'),
        'cadena'         => htmlspecialchars($row['cadena'], ENT_QUOTES, 'UTF-8'),
        'direccionLocal' => htmlspecialchars($row['direccionLocal'], ENT_QUOTES, 'UTF-8'),
        'nombreLocal'    => htmlspecialchars($row['nombreLocal'], ENT_QUOTES, 'UTF-8'),
        'vendedor'       => htmlspecialchars($row['vendedor'], ENT_QUOTES, 'UTF-8'),
        'idVendedor'     => (int)$row['idVendedor'],
        'vendedorTelefono' => htmlspecialchars($row['vendedorTelefono'], ENT_QUOTES, 'UTF-8'),
        'idLocal'        => (int)$row['idLocal'],
        'latitud'        => (float)$row['latitud'],
        'lng'            => (float)$row['lng'],
        'totalCampanas'  => (int)$row['totalCampanas'],
        'campanasIds'    => $row['campanasIds'],
        'is_priority'    => (int)$row['is_priority'],
        'is_botilleria'  => (stripos($row['tipo_cuenta'] ?? '', 'botiller') !== false),
        'relevancia'     => $row['relevancia'] !== null ? (int)$row['relevancia'] : null,
        'oc'             => htmlspecialchars($row['oc'] ?? '', ENT_QUOTES, 'UTF-8'),
        'cooler'         => htmlspecialchars($row['cooler'] ?? '', ENT_QUOTES, 'UTF-8'),
    ];
}
$stmt_reag->close();

if (!empty($geoOverrides)) {
    foreach ($locales_reag as &$loc) {
        $lid = $loc['idLocal'];
        if (isset($geoOverrides[$lid])) {
            $ov = $geoOverrides[$lid];
            $loc['latitud']       = (float)$ov['lat_nueva'];
            $loc['lng']           = (float)$ov['lng_nueva'];
            $loc['direccionLocal'] = htmlspecialchars((string)$ov['dir_nueva'], ENT_QUOTES, 'UTF-8');
            $loc['_geo_pendiente'] = true;
        }
    }
    unset($loc);
}

if (!empty($vendedorOverrides)) {
    foreach ($locales_reag as &$loc) {
        $lid = $loc['idLocal'];
        if (isset($vendedorOverrides[$lid])) {
            $ov = $vendedorOverrides[$lid];
            $loc['vendedor']         = htmlspecialchars((string)$ov['nombre_vendedor_nuevo'], ENT_QUOTES, 'UTF-8');
            $loc['vendedorTelefono'] = htmlspecialchars((string)($ov['telefono_nuevo'] ?? ''), ENT_QUOTES, 'UTF-8');
            $loc['_vendedor_pendiente'] = true;
        }
    }
    unset($loc);
}

// Misma regla para reagendados: un reagendado a fecha futura también sería
// adelantar ruta, así que se filtra igual.
if (!$puedeAdelantarRuta) {
    $locales_reag = ruta_filtrar_fechas_futuras($locales_reag);
}

$locales_reag_por_dia = [];
foreach ($locales_reag as $local) {
    $fecha = $local['fechaPropuesta'];
    if (!isset($locales_reag_por_dia[$fecha])) {
        $locales_reag_por_dia[$fecha] = [];
    }
    $locales_reag_por_dia[$fecha][] = $local;
}

// Preparar datos para el mapa
$coordenadas_locales_programados = [];
foreach ($locales as $local) {
    $markerColor = $local['is_botilleria'] ? 'orange' : (($local['is_priority'] === 1) ? 'blue' : 'red');
    $coordenadas_locales_programados[] = [
        'idLocal'        => $local['idLocal'],
        'nombre_local'   => $local['cadena'] . ' - ' . $local['direccionLocal'],
        'direccion'      => $local['direccionLocal'],
        'latitud'        => $local['latitud'],
        'lng'            => $local['lng'],
        'visitado'       => false,
        'markerColor'    => $markerColor,
        'fechaPropuesta' => $local['fechaPropuesta'],
        'is_botilleria'  => $local['is_botilleria']
    ];
}
$coordenadas_locales_reag = [];
foreach ($locales_reag as $local) {
    $markerColor = $local['is_botilleria'] ? 'orange' : (($local['is_priority'] === 1) ? 'blue' : 'red');
    $coordenadas_locales_reag[] = [
        'idLocal'        => $local['idLocal'],
        'nombre_local'   => $local['cadena'] . ' - ' . $local['direccionLocal'],
        'direccion'      => $local['direccionLocal'],
        'latitud'        => $local['latitud'],
        'lng'            => $local['lng'],
        'visitado'       => false,
        'markerColor'    => $markerColor,
        'fechaPropuesta' => $local['fechaPropuesta'],
        'is_botilleria'  => $local['is_botilleria']
    ];
}
}
?>
<!DOCTYPE html>
<html lang="es" class="no-js">
<head>
    <title>Visibility 2</title>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- CSS -->
    <link rel="stylesheet" href="assets/plugins/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/plugins/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/main-responsive.css">
    <link rel="stylesheet" href="assets/css/offline.css">
    <link rel="stylesheet" href="assets/css/nav_ar.css">
     <link rel="stylesheet" href="assets/css/journal.css">
     <link rel="stylesheet" href="css/index.css">
    <style>
      .badge-botilleria {
          display: inline-block;
          background: #e67e22;
          color: #fff;
          border-radius: 10px;
          padding: 1px 7px;
          font-size: 11px;
          margin-left: 4px;
          white-space: nowrap;
      }

      /* ── Filtros del panel de campañas programadas ── */
      .camp-filtros {
          padding: 0 0 8px;
          margin-bottom: 6px;
          border-bottom: 1px solid #e5e5e5;
      }
      .camp-filtros-row { margin-left: 0; margin-right: 0; }
      .camp-col-izq { padding-left: 0; padding-right: 3px; }
      .camp-col-der { padding-left: 3px; padding-right: 0; }
      .camp-acciones {
          margin-top: 6px;
          display: flex;
          align-items: center;
          flex-wrap: wrap;
          gap: 6px;
      }
      .camp-acciones #campContador { margin-left: auto; font-size: 11px; }
      /* Campaña oculta por el filtro del panel (distinto de "tachada") */
      li.camp-prog.camp-filtrada { display: none !important; }
      .camp-sin-resultados {
          padding: 10px;
          color: #888;
          font-size: 13px;
          text-align: center;
      }
    </style>
</head>
<body>
<?php
if (isset($_SESSION['success'])) {
    echo '<div id="success-alert" class="alert alert-success" role="alert">' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
?>
<div id="queuePanel" class="queue-panel collapsed" aria-live="polite">
  <div class="queue-panel__row">
    <span id="netBadge" class="net-badge">-</span>
    <span id="queueCount" class="queue-muted">Cola: -</span>
    <button type="button" id="queueToggle" class="queue-panel__toggle" title="Expandir / contraer">&#9650;</button>
  </div>
  <div id="queueDetail" class="queue-panel__detail">Estado de sincronizacion: -</div>
  <div class="queue-panel__actions">
    <button type="button" id="queueRetryBtn" class="btn btn-xs btn-info">Reintentar</button>
    <button type="button" id="queueClearBtn" class="btn btn-xs btn-default">Limpiar</button>
  </div>
</div>

<!-- Contenedor de toasts no bloqueantes -->
<div id="v2Toasts" class="v2-toasts" aria-live="polite"></div>

<!-- Navbar -->
<div class="navbar navbar-inverse navbar-fixed-top">
   <div class="container">
      <div class="navbar-header" style="background-color: white;">
         <button data-target=".navbar-collapse" data-toggle="collapse" class="navbar-toggle" type="button" style="display:none;">
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
            <span class="icon-bar"></span>
         </button>
           <h1 class="navbar-brand2">
              <img src="assets/imagenes/logo/logo-Visibility.png" alt="Logo Visibility" class="logo-visibility">
              <span>VISIBILITY</span>
           </h1>
        </div>
      <div class="navbar-tools" style="background-color: white;">
         <div class="nickname"><?php echo $nombre . ' ' . $apellido; ?></div>
         <ul class="nav navbar-right">
            <li class="dropdown current-user">
               <a data-toggle="dropdown" data-hover="dropdown" class="dropdown-toggle" data-close-others="true" href="#">
                  <i class="fa fa-chevron-down"></i>
               </a>
               <ul class="dropdown-menu">
                  <li><a href="perfil.php"><i class="fa fa-user"></i> &nbsp;Perfil</a></li>
                  <li><a href="logout.php"><i class="fa fa-sign-out"></i> &nbsp;Cerrar sesión</a></li>
               </ul>
            </li>
         </ul>
      </div>
   </div>
</div>

<!-- Contenido principal -->
<div class="main-container">
   <div class="main-content">
      <div class="container">
         <div class="row">
            <div class="col-sm-12">
               <div class="page-header">
                  <h2>Gestor de Actividades <!--small>Campañas en curso &amp; campañas IW</small--></h1>
               </div>
            </div>
         </div>

         <div class="row" style="margin-bottom: 15px;">
            <button type="button" class="btn btn-info" id="btnActualizar" style="margin-left: 3.5%;" onclick="window.location.reload();">
                <i class="fa fa-refresh"></i> Actualizar
            </button>
            <!-- Sidebar: Campañas Programadas -->
            <div class="col-sm-5">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="fa fa-check-square-o"></i> Campañas Programadas
                        <div class="panel-tools">
                            <a class="btn btn-xs btn-link panel-collapse" data-toggle="collapse" href="#campanasCollapse">
                                <i class="fa fa-chevron-down"></i>
                            </a>
                        </div>
                    </div>
                    <div id="campanasCollapse" class="panel-body panel-scroll collapse in">

                        <?php if (count($campanas) > 0): ?>
                        <!-- Filtros del panel: son SOLO visuales (ayudan a encontrar la campaña).
                             Lo que oculta locales de la tabla y del mapa sigue siendo el tachado. -->
                        <div class="camp-filtros">
                            <div class="row camp-filtros-row">
                                <div class="col-xs-7 camp-col-izq">
                                    <input type="text" id="filtroCampanaTexto" class="form-control input-sm"
                                           placeholder="Buscar campaña..." autocomplete="off">
                                </div>
                                <div class="col-xs-5 camp-col-der">
                                    <select id="filtroCampanaDivision" class="form-control input-sm">
                                        <option value="">Todas las divisiones</option>
                                        <?php foreach ($divisionesCampanas as $idDiv => $nomDiv): ?>
                                            <option value="<?= (int)$idDiv ?>"><?= $nomDiv ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="camp-acciones">
                                <button type="button" id="btnOcultarCamps" class="btn btn-xs btn-default">
                                    <i class="fa fa-eye-slash"></i> <span id="txtOcultarCamps">Ocultar todas</span>
                                </button>
                                <button type="button" id="btnMostrarCamps" class="btn btn-xs btn-default">
                                    <i class="fa fa-eye"></i> Mostrar todas
                                </button>
                                <small id="campContador" class="text-muted"></small>
                            </div>
                        </div>
                        <?php endif; ?>

                        <ul class="todo list-group">
                            <?php
                            if (count($campanas) > 0) {
                                foreach ($campanas as $campana) {
                                    $id_campana    = $campana['id_campana'];
                                    $nombre_camp   = $campana['nombre_campana'];
                                    $fechaInicio   = date('d-m-Y', strtotime($campana['fechaInicio']));
                                    $fechaTermino  = date('d-m-Y', strtotime($campana['fechaTermino']));
                                    $tieneRec      = (int)($campana['tiene_recepcion_materiales'] ?? 0);
                                    $nRec          = $recepcionesCount[$id_campana] ?? 0;
                                    // data-division / data-busqueda alimentan el filtro del panel
                                    $divCamp       = (int)($campana['id_division'] ?? 0);
                                    $busqCamp      = htmlspecialchars(
                                        v2_norm_busqueda($nombre_camp . ' ' . ($campana['division_nombre'] ?? '')),
                                        ENT_QUOTES, 'UTF-8'
                                    );
                                    echo '<li class="list-group-item camp-prog" data-idcampana="' . $id_campana . '"'
                                       . ' data-division="' . $divCamp . '"'
                                       . ' data-busqueda="' . $busqCamp . '">';
                                    echo ' <a class="todo-actions" href="javascript:void(0)">';
                                    echo '   <i class="fa fa-square-o"></i> ';
                                    echo '   <span class="desc">' . $nombre_camp . ' (' . $fechaInicio . ' - ' . $fechaTermino . ')</span>';
                                    echo ' </a>';
                                    if ($tieneRec) {
                                        $badge = $nRec > 0
                                            ? '<span style="background:#217346;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;">' . $nRec . '</span>'
                                            : '<span style="background:#c0392b;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;">Pendiente</span>';
                                        echo ' <div style="margin-top:5px;padding-left:22px;">';
                                        echo '   <a href="recepcion_materiales.php?id_formulario=' . $id_campana . '" '
                                            . 'style="font-size:12px;color:#217346;font-weight:600;">'
                                            . '<i class="fa fa-cubes"></i> Recepción de materiales ' . $badge . '</a>';
                                        echo ' </div>';
                                    }
                                    echo '</li>';
                                }
                            } else {
                                echo '<li class="list-group-item">No hay campañas programadas.</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Botón para ver el mapa y selector de fecha para programados -->
            <div class="col-sm-2">
               <button class="btn btn-primary_v btn-block" style="margin-bottom: 10px;" data-toggle="modal" data-target="#modalMapa">
                  <i class="fa fa-map-marker"></i> Ver Mapa
               </button>
               <button class="btn btn-info btn-block" style="margin-bottom: 10px;" data-toggle="modal" data-target="#modalAyudaFuncionamiento">
                  <i class="fa fa-question-circle"></i> ¿Cómo funciona?
               </button>
               <div class="form-group">
                 <label for="filtroFechaProg">Seleccionar fecha:</label>
                 <select id="filtroFechaProg" class="form-control">
                    <?php
                      foreach ($locales_por_dia as $fecha => $localesDia) {
                          $selected = ($fecha == date('Y-m-d')) ? 'selected' : '';
                          echo '<option value="'.$fecha.'" '.$selected.'>'.date('d-m-Y', strtotime($fecha)).'</option>';
                      }
                    ?>
                 </select>
               </div>
               <div id="contadorLocales" class="text-center" style="margin-top:5px;">
                 <small>Tabla: <span id="countTabla">0</span> | Mapa: <span id="countMapa">0</span> | Excluidos: <span id="countEx">0</span></small>
               </div>
            </div>

            <!-- Botón para cambiar al panel de locales reagendados -->
            <div class="col-sm-5 text-right">
                <button id="btnVerReagendados" class="btn btn-warning">
                    Ver Locales Reagendados
                </button>
            </div>
         </div><!-- /.row -->
         

         <!-- Panel de Locales Programados -->
         <div id="panelProgramados">
         <div class="row">
            <div class="col-sm-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="fa fa-users"></i> Locales Programados
                        <div class="panel-tools">
                            <a class="btn btn-xs btn-link panel-collapse" data-toggle="collapse" href="#localesProgCollapse">
                                <i class="fa fa-chevron-down"></i>
                            </a>
                        </div>
                    </div>
                    <div id="localesProgCollapse" class="panel-body collapse in">
                        <!-- Filtro de búsqueda -->
                        <div class="form-group" style="max-width: 300px;">
                          <input type="text" id="filtroLocalesProg" class="form-control" placeholder="Filtrar por código, cadena, comuna o dirección...">
                        </div>
                        <?php if (!empty($locales_por_dia)): ?>
                            <?php foreach ($locales_por_dia as $fecha => $localesDia): ?>
                                <h4 data-fechaencabezado="<?php echo $fecha; ?>">
                                    <?php echo date("d-m-Y", strtotime($fecha)); ?>
                                </h4>
                                <table class="table table-striped table-hover" data-fechaTabla="<?php echo $fecha; ?>">
                                    <thead>
                                        <tr>
                                            <th class="center">Código</th>
                                            <th class="center">Cadena</th>
                                            <th>Comuna</th>
                                            <th>Dirección</th>
                                            <th class="center">Ruta</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($localesDia as $row):
                                            $cadena         = $row['cadena'];
                                            $direccionLocal = $row['direccionLocal'];
                                            $totalCamp      = $row['totalCampanas'];
                                            $idLocal        = $row['idLocal'];
                                            $campanasIds    = $row['campanasIds'];
                                            $esPrioridad    = ($row['is_priority'] === 1);
                                            $esBotilleria   = ($row['is_botilleria'] ?? false);
                                            $trClass        = $esPrioridad ? 'priority-row' : '';
                                        ?>
                                          <?php
                                          $busquedaProg = trim(strtolower(
                                            $row['codigoLocal'] . ' ' .
                                            $cadena . ' ' .
                                            ($row['comuna'] ?? '') . ' ' .
                                            $direccionLocal . ' ' .
                                            ($row['nombreLocal'] ?? '')
                                          ));
                                        ?>
                                        <tr data-idlocal="<?php echo $idLocal; ?>"
                                            data-campanas="<?php echo implode(',', $campanasIds); ?>"
                                            data-lat="<?php echo $row['latitud']; ?>"
                                            data-lng="<?php echo $row['lng']; ?>"
                                            data-busqueda="<?php echo htmlspecialchars($busquedaProg, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-isbotilleria="<?php echo $esBotilleria ? 'true' : 'false'; ?>"
                                            class="<?php echo $trClass; ?>">
                                            <td class="center"><?php echo htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="center">
                                                <?php if ($esPrioridad) { ?>
                                                    <i class="fa fa-star priority-icon" title="Local prioritario"></i>
                                                <?php } ?>
                                                <?php echo htmlspecialchars($cadena, ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($esBotilleria): ?>
                                                    <span class="badge-botilleria" title="Botillería: abre aprox las 12:30/13:00 hrs"><i class="fa fa-clock-o"></i> 13:00</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $row['comuna']; ?></td>
                                            <td>
                                                <?php echo $direccionLocal; ?>
                                                <?php if ($row['_geo_pendiente'] ?? false): ?>
                                                    <span style="display:inline-block;background:#f59e0b;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap" title="Tienes una solicitud de cambio pendiente de revisión">📍 En revisión</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="center">
                                              <input type="checkbox" class="in-route" checked title="Incluir este local en la ruta">
                                            </td>
                                            <td class="center">
                                                <div style="display: flex; align-items: center; justify-content: center;">
                                                    <span class="circulo"><?php echo $totalCamp; ?></span>
                                                    <div style="margin-left: 10px;">
                                                        <div class="btn-group">
                                                            <a class="btn btn-primary dropdown-toggle btn-sm" data-toggle="dropdown" href="#">
                                                                <i class="fa fa-cog"></i> <span class="caret"></span>
                                                            </a>
                                                            <ul role="menu" class="dropdown-menu pull-right">
                                                                <li role="presentation">
                                                                    <a role="menuitem" tabindex="-1" href="#responsiveProg<?php echo $idLocal; ?>" data-toggle="modal">
                                                                        <i class="fa fa-edit"></i> Campañas
                                                                    </a>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="center">No se encontraron campañas programadas.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
         </div><!-- /.row -->
         </div><!-- /#panelProgramados -->

        

         <!-- Panel de Locales Reagendados -->
         <div id="panelReagendados" style="display:none;">
<p class="text-muted">Última sincronización: <span id="lastSyncBadge">-</span></p>
         <div class="row">
            <div class="col-sm-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="fa fa-calendar"></i> Locales Reagendados
                        <div class="panel-tools">
                            <a class="btn btn-xs btn-link panel-collapse" data-toggle="collapse" href="#localesReagCollapse">
                                <i class="fa fa-chevron-down"></i>
                            </a>
                        </div>
                        <div class="pull-right">
                          <button id="btnVerProgramados" class="btn btn-warning btn-xs">Ver Programados</button>
                        </div>
                    </div>
                    <div id="localesReagCollapse" class="panel-body collapse in">
                        <div class="form-group" style="max-width: 300px;">
                          <input type="text" id="filtroLocalesReag" class="form-control" placeholder="Filtrar por código, nombre o dirección...">
                        </div>
                        <div class="form-group">
                          <label for="filtroFechaReag">Seleccionar fecha:</label>
                          <select id="filtroFechaReag" class="form-control">
                            <?php 
                              foreach ($locales_reag_por_dia as $fecha => $localesDia) {
                                  $selected = ($fecha == date('Y-m-d')) ? 'selected' : '';
                                  echo '<option value="'.$fecha.'" '.$selected.'>'.date('d-m-Y', strtotime($fecha)).'</option>';
                              }
                            ?>
                          </select>
                        </div>
                        <?php if (!empty($locales_reag_por_dia)): ?>
                            <?php foreach ($locales_reag_por_dia as $fecha => $localesDia): ?>
                                <h4 data-fechaencabezado="<?php echo $fecha; ?>">
                                    <?php echo date("d-m-Y", strtotime($fecha)); ?>
                                </h4>
                                <table class="table table-striped table-hover" data-fechaTabla="<?php echo $fecha; ?>">
                                    <thead>
                                        <tr>
                                            <th class="center">Código</th>
                                            <th class="center">Cadena</th>
                                            <th>Dirección</th>
                                            <th class="center">Ruta</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($localesDia as $row):
                                            $cadena         = $row['cadena'];
                                            $direccionLocal = $row['direccionLocal'];
                                            $totalCamp      = $row['totalCampanas'];
                                            $idLocal        = $row['idLocal'];
                                            $campanasIds    = $row['campanasIds'];
                                            $esPrioridad    = ($row['is_priority'] === 1);
                                            $esBotilleria   = ($row['is_botilleria'] ?? false);
                                            $trClass        = $esPrioridad ? 'priority-row' : '';
                                        ?>
                                        <?php
                                          $busquedaReag = trim(strtolower(
                                            $row['codigoLocal'] . ' ' .
                                            $cadena . ' ' .
                                            $direccionLocal . ' ' .
                                            ($row['nombreLocal'] ?? '')
                                          ));
                                        ?>
                                        <tr data-idlocal="<?php echo $idLocal; ?>"
                                            data-campanas="<?php echo implode(',', $campanasIds); ?>"
                                            data-lat="<?php echo $row['latitud']; ?>"
                                            data-lng="<?php echo $row['lng']; ?>"
                                            data-busqueda="<?php echo htmlspecialchars($busquedaReag, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-isbotilleria="<?php echo $esBotilleria ? 'true' : 'false'; ?>"
                                            class="<?php echo $trClass; ?>">
                                             <td class="center"><?php echo htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'); ?></td>
                                             <td class="center">
                                                <?php if ($esPrioridad) { ?>
                                                    <i class="fa fa-star priority-icon" title="Local prioritario"></i>
                                                <?php } ?>
                                                <?php echo htmlspecialchars($cadena, ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($esBotilleria): ?>
                                                    <span class="badge-botilleria" title="Botillería: abre a las 13:00 hrs"><i class="fa fa-clock-o"></i> 13:00</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $direccionLocal; ?>
                                                <?php if ($row['_geo_pendiente'] ?? false): ?>
                                                    <span style="display:inline-block;background:#f59e0b;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap" title="Tienes una solicitud de cambio pendiente de revisión">📍 En revisión</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="center">
                                              <input type="checkbox" class="in-route" checked title="Incluir este local en la ruta">
                                            </td>
                                            <td class="center">
                                                <div style="display: flex; align-items: center; justify-content: center;">
                                                    <span class="circulo"><?php echo $totalCamp; ?></span>
                                                    <div style="margin-left: 10px;">
                                                        <div class="btn-group">
                                                            <a class="btn btn-primary dropdown-toggle btn-sm" data-toggle="dropdown" href="#">
                                                                <i class="fa fa-cog"></i> <span class="caret"></span>
                                                            </a>
                                                            <ul role="menu" class="dropdown-menu pull-right">
                                                                <li role="presentation">
                                                                    <a role="menuitem" tabindex="-1" href="#responsiveReag<?php echo $idLocal; ?>" data-toggle="modal">
                                                                        <i class="fa fa-edit"></i> Campañas
                                                                    </a>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="center">No se encontraron locales reagendados.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
         </div>
         </div><!-- /#panelReagendados -->

         <!-- Actividades complementarias -->
         <div class="row" style="margin-top: 20px;">
             <div class="col-sm-12">
                <div class="panel panel-default">
                    <div class="panel-heading">
                        <i class="fa fa-question-circle"></i> Actividades complementarias
                        <div class="panel-tools">
                            <a class="btn btn-xs btn-link panel-collapse" data-toggle="collapse" href="#compCampanasCollapse">
                                <i class="fa fa-chevron-down"></i>
                            </a>
                        </div>
                    </div>
                    <div id="compCampanasCollapse" class="panel-body panel-scroll collapse in">
                      <ul class="todo list-group">
                        <?php
                        if (count($compCampanas) > 0) {
                            $gestionarBase = ($usuario_id === 2) ? 'gestionarIW.php' : 'gestionarIW.php';
                            foreach ($compCampanas as $campana) {
                                $idCamp = (int)$campana['id_campana'];
                                $nomCamp = $campana['nombre_campana'];
                                echo '<li class="list-group-item" data-idcampana="'.$idCamp.'">';
                                echo '  <a class="todo-actions" href="javascript:void(0)">';
                                echo '      <i class="fa fa-circle"></i> ';
                                echo '      <span class="desc">'.$nomCamp.'</span>';
                                echo '  </a>';
                                echo '  <a href="'.$gestionarBase.'?idCampana='.urlencode($idCamp).'" class="btn btn-primary btn-sm" title="Gestionar Campaña">';
                                echo '      <i class="fa fa-cog"></i> Gestionar';
                                echo '  </a>';
                                echo '</li>';
                            }
                        } else {
                            echo '<li class="list-group-item">No hay actividades complementarias.</li>';
                        }
                        ?>
                      </ul>
                    </div>
                </div>
             </div>
         </div>
         
        
<i class="fa fa-list-alt"></i> Gestiones
        <div class="row" id="journalRow">
          <div class="col-sm-12">
            <div class="panel panel-default" id="journalPanel">
              <div class="panel-heading" style="display:flex; align-items:left; flex-wrap:wrap; gap:10px;">
                <span class="label label-default" id="jr-badge-pending">Pendientes: 0</span>
                <span class="label label-warning" id="jr-badge-running">Enviando: 0</span>
                <span class="label label-success" id="jr-badge-success">Subidas: 0</span>
                <span class="label label-danger"  id="jr-badge-error">Errores: 0</span>
                <span class="label label-warning" id="jr-badge-blocked">Bloqueadas: 0</span>
                <div style="margin-left:auto; display:flex; gap:6px; flex-wrap:wrap;">
                  <button class="btn btn-xs btn-default" id="jr-btn-flush"><i class="fa fa-upload"></i> Reintentar ahora</button>
                  <button class="btn btn-xs btn-default" id="jr-btn-clear-today"><i class="fa fa-eraser"></i> Limpiar subidas (hoy)</button>
                  <button class="btn btn-xs btn-default" id="jr-btn-export"><i class="fa fa-download"></i> Exportar diagnóstico</button>
                </div>
              </div>
              <div class="panel-body">
                <!-- Progreso global -->
                <div class="progress" style="margin:6px 0 10px;">
                  <div id="jr-global-progress" class="progress-bar" role="progressbar" style="width:0%;">0%</div>
                </div>

                <!-- Cabecera del mes con flechas de navegación -->
                <div style="display:flex; align-items:center; justify-content:space-between; margin:0 0 6px;">
                  <button class="btn btn-xs btn-default" id="jr-cal-prev"><i class="fa fa-chevron-left"></i></button>
                  <strong id="jr-cal-title" style="font-size:15px; text-transform:capitalize;"></strong>
                  <button class="btn btn-xs btn-default" id="jr-cal-next"><i class="fa fa-chevron-right"></i></button>
                </div>

                <!-- Grilla del calendario -->
                <div id="jr-cal-grid" style="display:grid; grid-template-columns:repeat(7,1fr); gap:3px; margin-bottom:6px;"></div>

                <!-- Leyenda -->
                <div style="font-size:11px; color:#888; margin-bottom:8px;">
                  <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#3c763d;vertical-align:middle;"></span> Subidas al servidor &nbsp;
                  <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#f0ad4e;vertical-align:middle;"></span> Pendientes &nbsp;
                  <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#a94442;vertical-align:middle;"></span> Error
                </div>

                <!-- Detalle del día seleccionado -->
                <div id="jr-cal-detail" class="jr-list" style="display:none;"></div>

                <!-- Contenedores legacy (ocultos, requeridos por journal_ui.js) -->
                <div id="jr-list-today" class="jr-list" style="display:none;"></div>
                <div id="jr-list-week"  class="jr-list" style="display:none;"></div>
              </div>
            </div>
          </div>
        </div>
         
         
      </div><!-- /.container -->
   </div><!-- /.main-content -->
</div><!-- /.main-container -->

<!-- Modal de ayuda "¿Cómo funciona?" -->
<div id="modalAyudaFuncionamiento" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modalAyudaFuncionamientoLabel" aria-hidden="true">
   <div class="modal-dialog modal-lg">
      <div class="modal-content">
         <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
            <h4 class="modal-title" id="modalAyudaFuncionamientoLabel">¿Cómo funciona?</h4>
         </div>
         <div class="modal-body">
            <p>Este panel resume todo lo que puedes hacer en la aplicación con palabras simples pensadas para ejecutores en terreno.</p>

            <h4>1. Tablas de locales programados y reagendados</h4>
            <ul>
               <li><strong>Programados:</strong> es la tabla principal. Se agrupa por fecha y muestra código, cadena, comuna, dirección y opciones de ruta.</li>
               <li><strong>Reagendados:</strong> se abre con el botón <em>Ver Locales Reagendados</em>. Tiene el mismo formato, pero solo con los locales tipificados como pendiente</li>
               <li><strong>Filtro de texto:</strong> sobre cada tabla hay un cuadro para buscar por código, cadena, comuna o dirección. Escribe cualquier palabra y la tabla filtra al instante.</li>
               <li><strong>Campañas tachadas:</strong> en el panel de campañas (izquierda) puedes tocar el nombre para tacharlo. Las campañas tachadas se ocultan de la tabla y del mapa para evitar visitas equivocadas. Tu selección queda guardada en el dispositivo, así que se mantiene aunque actualices la página.</li>
               <li><strong>Buscar entre muchas campañas:</strong> si tienes varias, usa el buscador y el filtro por división del panel para encontrarlas más rápido. Esos filtros solo ordenan la lista del panel: <em>no</em> ocultan locales. Para trabajar con pocas campañas, toca <strong>Ocultar todas</strong> y luego destacha solo las que quieras ver. El contador del panel te indica cuántas tienes sin mostrar.</li>
        
            </ul>

            <h4>2. Gestionar un local y registrar campañas</h4>
            <ul>
               <li><strong>Botón Gestionar Local:</strong> cada local tiene el botón azul con un engranaje. Al presionarlo abre el modal del local y desde ahí eliges <em>Gestionar</em> para entrar a la seccion de gestionar Local.</li>
   
               <li><strong>Actividades complementarias:</strong>abajo de la tabla de locales aparecen las gestiones complementarias, como pueden ser gestiones adicionales, estatus del vehículo, etc.</li>
            </ul>

            <h4>3. Guardar locales para trabajar sin conexión</h4>
            <ul>
               <li><strong>Guardar locales:</strong> Para guardar locales y asi poder gestionarlos sin conexión, simplemente tienes que ingresar al menos una vez a la seccion de gestionar local de manera online, ahi ya queda guardado para gestionarlo offline, ya sea en el momento o mas tarde</li>
            </ul>

            <h4>4. Ruta y navegación</h4>
            <ul>
               <li><strong>Ver Mapa:</strong> abre el mapa con todos los locales visibles según la fecha y campañas activas.</li>
               <li><strong>Armar ruta:</strong> usa los checks de cada fila (columna Ruta) para incluir o excluir locales. Los tachados o excluidos desaparecen del mapa.</li>
               <li><strong>Recalcular:</strong> el botón <em>Recalcular</em> ordena la ruta. Puedes activar <em>Optimizar</em> para que Google proponga el mejor orden.</li>
               <li><strong>Exportar a Google Maps:</strong> en el mapa usa <em>Abrir en Google Maps</em> para abrir la ruta en la app de google maps del teléfono.</li>
               <li><strong>Indicaciones paso a paso:</strong> el botón <em>Indicaciones</em> abre el panel lateral con cada giro, distancia y tiempo estimado.</li>
               <li><strong>Modo navegación:</strong> <em>Iniciar navegación</em> activa la vista 3D con flecha en vivo; <em>Centrar</em> devuelve el mapa a tu posición.(funcionalidad en progreso)</li>
            </ul>

            <h4>5. Extras útiles</h4>
            <ul>
               <li><strong>Contadores rápidos:</strong> bajo el selector de fecha verás cuántos locales están en la tabla, en el mapa y cuántos excluiste.</li>
               <li><strong>Estado de red:</strong> el mensaje <em>Online/Offline</em> te avisa si puedes sincronizar. Cuando vuelve la señal, las gestiones pendientes se envían solas.</li>
               <li><strong>Bitácora:</strong> la sección <em>Gestiones</em> muestra lo enviado hoy y en la semana con progreso total y errores a reintentar.</li>
            </ul>

            <p class="text-muted">Si algo no funciona como esperas, refresca la página o revisa que tengas señal. Todo lo cacheado permanece guardado para que no pierdas tu trabajo.</p>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
         </div>
      </div>
   </div>
</div>

<!-- Modal Mapa -->
<div id="modalMapa" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modalMapaLabel" aria-hidden="true">
   <div class="modal-dialog modal-lg">
      <div class="modal-content">
         <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
            <h4 class="modal-title" id="modalMapaLabel">Mapa de Ruta en Tiempo Real</h4>
         </div>
         <div class="modal-body" style="position: relative;">
            <div id="map" style="height: 500px; width: 100%;"></div>

<!-- Fix #18: aviso cuando se superan 24 locales en la ruta -->
<div id="routeWarning" class="alert alert-warning" style="display:none;margin:6px 0 0;padding:6px 10px;font-size:13px;"></div>

<div id="panelInfoRuta" class="panel-ruta-mobile">

  <div class="sheet-handle" id="sheetHandle" title="Arrastra para expandir/contraer"></div>

  <div class="ruta-stats-mobile">
    <div class="stat-pill">
      <span class="stat-label">Distancia</span>
      <strong id="distanciaTotal">0 km</strong>
    </div>
    <div class="stat-pill">
      <span class="stat-label">Duración</span>
      <strong id="duracionEstimada">0 min</strong>
    </div>
  </div>

  <div class="ruta-grid-mobile">
    <button id="btnCentrar" class="btn-ruta-mobile btn-light">
      <i class="fa fa-crosshairs"></i>
      <span>Centrar</span>
    </button>

    <button id="btnRecalcular" class="btn-ruta-mobile btn-blue">
      <i class="fa fa-refresh"></i>
      <span>Recalcular</span>
    </button>

    <button id="btnIndicaciones" class="btn-ruta-mobile btn-lime">
      <i class="fa fa-list-ol"></i>
      <span>Indicaciones</span>
    </button>

    <button id="btnTraffic" class="btn-ruta-mobile btn-light">
      <i class="fa fa-car"></i>
      <span>Tráfico</span>
    </button>

    <button id="btnVoz" class="btn-ruta-mobile btn-light">
      <i class="fa fa-volume-up"></i>
      <span>Voz</span>
    </button>

    <label class="btn-ruta-mobile btn-light btn-check-mobile">
      <input type="checkbox" id="optimizeOrder" checked>
      <span>Optimizar</span>
    </label>

    <label class="btn-ruta-mobile btn-light btn-check-mobile">
      <input type="checkbox" id="autoRecalc" checked>
      <span>Auto</span>
    </label>
  </div>

  <div class="ruta-actions-mobile">
    <button id="btnExportar" class="btn-ruta-mobile btn-green btn-full">
      <i class="fa fa-external-link"></i>
      <span>Abrir en Google Maps</span>
    </button>

    <button id="btnStartNav" class="btn-ruta-mobile btn-darkgreen btn-full">
      <i class="fa fa-location-arrow"></i>
      <span>Iniciar navegación</span>
    </button>
  </div>

  <div class="ruta-api-mobile">
    <span id="apiCounters">API: Routes 0 | Fallback 0</span>
  </div>

</div>

            <!-- HUD de Navegación -->
            <div id="navHud" class="nav-hud" style="display:none;">
              <div class="nav-banner">
                <div class="nav-ic" id="navIcon"><i class="fa fa-location-arrow"></i></div>
                <div style="flex:1;">
                  <div class="nav-main" id="navPrimary">Preparando navegación…</div>
                  <div class="nav-sub" id="navSecondary">—</div>
                </div>
              </div>
              <div class="nav-nextnext" id="navNextNext" style="display:none;">Después: —</div>
              <div class="nav-chips" id="navChips">
                <span class="nav-chip nav-chip--gps" id="navGps"><i class="fa fa-location-arrow"></i> GPS</span>
                <span class="nav-chip" id="navNet"><i class="fa fa-road"></i> —</span>
                <span class="nav-chip" id="navNextStop"><i class="fa fa-flag-checkered"></i> —</span>
              </div>
              <div class="nav-bottom">
                <button id="btnExitNav" class="btn btn-default btn-sm" title="Salir de la navegación"><i class="fa fa-times"></i> Salir</button>
                <div class="nav-stats">
                  <div><small>Llegada</small><span id="hudEta">—</span></div>
                  <div><small>Restante</small><span id="hudRemain">—</span></div>
                  <div><small>Tiempo</small><span id="hudTime">—</span></div>
                </div>
                <button id="btnVozNav" class="btn btn-default btn-sm" title="Silenciar / activar voz"><i class="fa fa-volume-up"></i></button>
                <button id="btnSkipStop" class="btn btn-default btn-sm" title="Saltar la parada actual"><i class="fa fa-step-forward"></i> Saltar</button>
                <button id="btnRecenter" class="btn btn-default btn-sm" title="Volver a seguir mi ubicación"><i class="fa fa-crosshairs"></i> Recentrar</button>
              </div>
            </div>

            
            <!-- Drawer Indicaciones -->
            <div id="drawerIndicaciones" class="route-drawer">
              <div class="drawer-header">
                <h5 class="drawer-title">Indicaciones paso a paso</h5>
                <button type="button" class="btn btn-xs btn-default" id="btnCloseDrawer">
                  <i class="fa fa-times"></i>
                </button>
              </div>
              <div class="drawer-body">
                <ol id="listaIndicaciones" class="steps-list"></ol>
              </div>
            </div>

            <div id="loadingIndicator">
               <i class="fa fa-spinner fa-spin"></i> Obteniendo ubicación...
            </div>
         </div>
         <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
         </div>
      </div>
   </div>
</div>

<?php
// Modales de gestión diferenciados para programados y reagendados
$precacheTargets   = [];
$precacheTargetsId = [];

// Modales para locales programados (pendientes: countVisita = 0)
$idsGeneradosProg = [];
foreach ($locales as $row) {
    $idLocal = (int)$row['idLocal'];
    if (in_array($idLocal, $idsGeneradosProg)) continue;
    $idsGeneradosProg[] = $idLocal;
    $codigoLocal    = $row['codigoLocal'];
    $nombreLocal    = $row['nombreLocal'];
    $direccionLocal = $row['direccionLocal'];
    $vendedor       = $row['vendedor'];
    $idVendedor     = $row['idVendedor'] ?? 0;
    $vendedorTelefono = $row['vendedorTelefono'] ?? '';
    $vendedorPendienteBadge = ($row['_vendedor_pendiente'] ?? false)
        ? " <span style='display:inline-block;background:#f59e0b;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap'>🧑 En revisión</span>"
        : '';
    $cvPayloadProg = htmlspecialchars(json_encode([
        'idLocal'          => $idLocal,
        'nombreLocal'      => $nombreLocal,
        'idVendedor'       => (int)$idVendedor,
        'vendedorNombre'   => $vendedor,
        'vendedorTelefono' => $vendedorTelefono,
        'tipo'             => 'Prog',
    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $oc             = $row['oc'] ?? '';
    $cooler         = $row['cooler'] ?? '';
    $relevancia     = $row['relevancia'] ?? null;

    $sql_campanas = "
      SELECT DISTINCT
          f.id AS idCampana,
          f.nombre AS nombreCampana,
          f.fechaInicio,
          f.fechaTermino,
          f.estado
      FROM formularioQuestion fq
      INNER JOIN formulario AS f ON f.id = fq.id_formulario
      WHERE fq.id_usuario  = ?
        AND fq.id_local    = ?
        AND f.id_empresa   = ?
        AND f.tipo        IN (3,1)
        AND f.estado       = 1
        AND (
              (f.modalidad <> 'implementacion_por_etapas' AND fq.countVisita = 0)
              OR (f.modalidad = 'implementacion_por_etapas' AND (fq.etapa_material IS NULL OR fq.etapa_material NOT IN ('implementado','retirado') OR (fq.etapa_material = 'implementado' AND CAST(COALESCE(NULLIF(fq.valor,''),'0') AS UNSIGNED) < CAST(COALESCE(NULLIF(fq.valor_propuesto,''),'0') AS UNSIGNED))))
            )
      ORDER BY f.fechaInicio DESC
    ";

    $stmt_campanas = $conn->prepare($sql_campanas);
    $stmt_campanas->bind_param('iii', $usuario_id, $idLocal, $empresa_id);
    $stmt_campanas->execute();
    $result_campanas = $stmt_campanas->get_result();

    $extraInfoProg = '';
    if ($relevancia !== null) $extraInfoProg .= "<br><small><strong>Relevancia:</strong> {$relevancia}</small>";
    if ($oc !== '')           $extraInfoProg .= "<br><small><strong>OC:</strong> {$oc}</small>";
    if ($cooler !== '')       $extraInfoProg .= "<br><small><strong>Cooler:</strong> {$cooler}</small>";

    echo "
    <div id='responsiveProg{$idLocal}' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='myModalLabelProg{$idLocal}' aria-hidden='true'>
      <div class='modal-dialog'>
        <div class='modal-content'>
          <div class='modal-header'>
            <button type='button' class='close' data-dismiss='modal' aria-hidden='true'>&times;</button>
            <h4 class='modal-title' id='myModalLabelProg{$idLocal}'>
              Local: {$codigoLocal} - {$nombreLocal}<br>
              Dirección: {$direccionLocal}<br>
              Vendedor: <span class='cv-vendedor-texto'>{$vendedor}</span>{$vendedorPendienteBadge}
              <button type='button' class='btn btn-warning btn-xs' style='margin-left:4px;vertical-align:middle' onclick='abrirModalCambiarVendedor({$cvPayloadProg})' title='Cambiar vendedor'>
                <i class='fa fa-user'></i>
              </button>
              {$extraInfoProg}
            </h4>
          </div>
          <div class='modal-body'>
            <table class='table table-bordered'>
                <thead>
                    <tr>
                        <th>Nombre de la Campaña</th>
                        <th>Gestionar</th>
                    </tr>
                </thead>
                <tbody>
    ";

    if ($result_campanas->num_rows > 0) {
        while ($campana = $result_campanas->fetch_assoc()) {
            $idCampana     = (int)$campana['idCampana'];
            $nombreCampana = htmlspecialchars($campana['nombreCampana'], ENT_QUOTES, 'UTF-8');
            $gestionarUrl  = $appScope . '/gestionarPruebas.php'
                . '?idCampana=' . urlencode($idCampana)
                . '&nombreCampana=' . urlencode($nombreCampana)
                . '&idLocal=' . urlencode($idLocal)
                . '&idUsuario=' . urlencode($usuario_id);
            $gestionarUrlAttr = htmlspecialchars($gestionarUrl, ENT_QUOTES, 'UTF-8');

            $precacheKey = $idLocal . '|' . $idCampana;
            if (!isset($precacheTargetsId[$precacheKey])) {
                $precacheTargetsId[$precacheKey] = true;
                $precacheTargets[] = [
                    'idLocal'        => $idLocal,
                    'nombreLocal'    => $nombreLocal,
                    'direccionLocal' => $direccionLocal,
                    'idUsuario'      => $usuario_id,
                    'idCampana'      => $idCampana,
                    'nombreCampana'  => $nombreCampana
                ];
            }
            echo "
                <tr data-idcampana='{$idCampana}'>
                    <td>{$nombreCampana}</td>
                    <td class='center'>
                      <div class='btn-group btn-group-sm'>
                        <a href='{$gestionarUrlAttr}' class='btn btn-info'>
                          <i class='fa fa-pencil'></i> Gestionar
                        </a>
                      </div>
                    </td>
                </tr>
            ";
        }
    } else {
        echo "
            <tr>
                <td colspan='2' class='center'>No hay campañas asociadas a este local.</td>
            </tr>
        ";
    }

    echo "
                </tbody>
            </table>
          </div>
          <div class='modal-footer'>
            <button type='button' class='btn btn-default' data-dismiss='modal'>Cerrar</button>
          </div>
        </div>
      </div>
    </div>
    ";
    $stmt_campanas->close();
}

// Modales para locales reagendados (pendientes: pregunta = 'en proceso')
$idsGeneradosReag = [];
foreach ($locales_reag as $row) {
    $idLocal = (int)$row['idLocal'];
    if (in_array($idLocal, $idsGeneradosReag)) continue;
    $idsGeneradosReag[] = $idLocal;
    $codigoLocal    = $row['codigoLocal'];
    $nombreLocal    = $row['nombreLocal'];
    $direccionLocal = $row['direccionLocal'];
    $vendedor       = $row['vendedor'];
    $idVendedor     = $row['idVendedor'] ?? 0;
    $vendedorTelefono = $row['vendedorTelefono'] ?? '';
    $vendedorPendienteBadge = ($row['_vendedor_pendiente'] ?? false)
        ? " <span style='display:inline-block;background:#f59e0b;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap'>🧑 En revisión</span>"
        : '';
    $cvPayloadReag = htmlspecialchars(json_encode([
        'idLocal'          => $idLocal,
        'nombreLocal'      => $nombreLocal,
        'idVendedor'       => (int)$idVendedor,
        'vendedorNombre'   => $vendedor,
        'vendedorTelefono' => $vendedorTelefono,
        'tipo'             => 'Reag',
    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $oc             = $row['oc'] ?? '';
    $cooler         = $row['cooler'] ?? '';
    $relevancia     = $row['relevancia'] ?? null;

    $sql_campanas = "
      SELECT DISTINCT
          f.id AS idCampana,
          f.nombre AS nombreCampana,
          f.fechaInicio,
          f.fechaTermino,
          f.estado
      FROM formularioQuestion fq
      INNER JOIN formulario AS f ON f.id = fq.id_formulario
      WHERE fq.id_usuario = ?
        AND fq.id_local   = ?
        AND f.id_empresa  = ?
        AND f.tipo       IN (3,1)
        AND f.estado      = 1
        AND fq.pregunta   = 'en proceso'
      ORDER BY f.fechaInicio DESC
    ";

    $stmt_campanas = $conn->prepare($sql_campanas);
    $stmt_campanas->bind_param('iii', $usuario_id, $idLocal, $empresa_id);
    $stmt_campanas->execute();
    $result_campanas = $stmt_campanas->get_result();

    $extraInfoReag = '';
    if ($relevancia !== null) $extraInfoReag .= "<br><small><strong>Relevancia:</strong> {$relevancia}</small>";
    if ($oc !== '')           $extraInfoReag .= "<br><small><strong>OC:</strong> {$oc}</small>";
    if ($cooler !== '')       $extraInfoReag .= "<br><small><strong>Cooler:</strong> {$cooler}</small>";

    echo "
    <div id='responsiveReag{$idLocal}' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='myModalLabelReag{$idLocal}' aria-hidden='true'>
      <div class='modal-dialog'>
        <div class='modal-content'>
          <div class='modal-header'>
            <button type='button' class='close' data-dismiss='modal' aria-hidden='true'>&times;</button>
            <h4 class='modal-title' id='myModalLabelReag{$idLocal}'>
              Local: {$codigoLocal} - {$nombreLocal}<br>
              Dirección: {$direccionLocal}<br>
              Vendedor: <span class='cv-vendedor-texto'>{$vendedor}</span>{$vendedorPendienteBadge}
              <button type='button' class='btn btn-warning btn-xs' style='margin-left:4px;vertical-align:middle' onclick='abrirModalCambiarVendedor({$cvPayloadReag})' title='Cambiar vendedor'>
                <i class='fa fa-user'></i>
              </button>
              {$extraInfoReag}
            </h4>
          </div>
          <div class='modal-body'>
            <table class='table table-bordered'>
                <thead>
                    <tr>
                        <th>Nombre de la Campaña</th>
                        <th>Gestionar</th>
                    </tr>
                </thead>
                <tbody>
    ";

    if ($result_campanas->num_rows > 0) {
        while ($campana = $result_campanas->fetch_assoc()) {
            $idCampana     = (int)$campana['idCampana'];
            $nombreCampana = htmlspecialchars($campana['nombreCampana'], ENT_QUOTES, 'UTF-8');
            $gestionarUrl  = $appScope . '/gestionarPruebas.php'
                . '?idCampana=' . urlencode($idCampana)
                . '&nombreCampana=' . urlencode($nombreCampana)
                . '&idLocal=' . urlencode($idLocal)
                . '&idUsuario=' . urlencode($usuario_id);
            $gestionarUrlAttr = htmlspecialchars($gestionarUrl, ENT_QUOTES, 'UTF-8');

            $precacheKey = $idLocal . '|' . $idCampana;
            if (!isset($precacheTargetsId[$precacheKey])) {
                $precacheTargetsId[$precacheKey] = true;
                $precacheTargets[] = [
                    'idLocal'        => $idLocal,
                    'nombreLocal'    => $nombreLocal,
                    'direccionLocal' => $direccionLocal,
                    'idUsuario'      => $usuario_id,
                    'idCampana'      => $idCampana,
                    'nombreCampana'  => $nombreCampana
                ];
            }
            echo "
                <tr data-idcampana='{$idCampana}'>
                    <td>{$nombreCampana}</td>
                    <td class='center'>
                      <div class='btn-group btn-group-sm'>
                        <a href='{$gestionarUrlAttr}' class='btn btn-info'>
                          <i class='fa fa-pencil'></i> Gestionar
                        </a>
                      </div>
                    </td>
                </tr>
            ";
        }
    } else {
        echo "
            <tr>
                <td colspan='2' class='center'>No hay campañas asociadas a este local.</td>
            </tr>
        ";
    }

    echo "
                </tbody>
            </table>
          </div>
          <div class='modal-footer'>
            <button type='button' class='btn btn-default' data-dismiss='modal'>Cerrar</button>
          </div>
        </div>
      </div>
    </div>
    ";
    $stmt_campanas->close();
}
?>

<div class="footer clearfix">
   <div class="footer-inner">
      2025 &copy; Visibility 2 por Mentecreativa.
   </div>
   <div class="footer-items">
      <span class="go-top"><i class='fa fa-chevron-up'></i></span>
   </div>
</div>


<!-- Scripts -->
<script src="assets/plugins/jquery/jquery-3.6.0.min.js"></script>
<script src="assets/plugins/bootstrap/js/bootstrap.min.js" defer></script>
<script>
  window.__GOOGLE_MAPS_API_KEY = "<?php echo htmlspecialchars($googleMapsApiKey, ENT_QUOTES, 'UTF-8'); ?>";
  window.__ROUTES_PROXY_URL = "<?php echo htmlspecialchars($routesProxyUrl, ENT_QUOTES, 'UTF-8'); ?>";
</script>
<script src="assets/js/route_preferences.js"></script>
<script src="assets/js/route_engine.js"></script>
<script src="assets/js/route_selection.js"></script>
<script src="assets/js/route_planner.js"></script>
<script src="assets/js/nav_engine.js"></script>
<script src="assets/js/ar_view_lite.js"></script>

<script>
// ============ Preferencias/estado ============
function debounce(fn, d){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn.apply(null,a),d); }; }
window.optimizeOrder = true;   // toggle "Optimizar"
window.autoRecalc    = true;   // toggle "Auto"
window.voiceEnabled  = false;  // toggle "Voz"
window.trafficEnabled = false; // toggle "Tráfico" (capa visual + modo de cálculo 'traffic')

// Toast no bloqueante reutilizable (reemplaza alerts). `actions` = [{label, cls, fn}].
// Nota: varios modales (reportar dirección / cambiar vendedor) ya invocan mostrarToast con fallback
// a alert; aquí queda definido de verdad.
window.mostrarToast = function(msg, type, duration, actions){
  const cont = document.getElementById('v2Toasts');
  if(!cont){ if(type==='error'||type==='warn') console.warn(msg); return null; }
  const el = document.createElement('div');
  el.className = 'v2-toast v2-toast--' + (type || 'info');
  const span = document.createElement('span');
  span.className = 'v2-toast__msg';
  span.textContent = msg;
  el.appendChild(span);
  (actions || []).forEach(a => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'btn btn-xs ' + (a.cls || 'btn-default');
    b.textContent = a.label;
    b.addEventListener('click', () => { try { a.fn && a.fn(); } catch(_){} dismiss(); });
    el.appendChild(b);
  });
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'v2-toast__close';
  close.innerHTML = '&times;';
  close.addEventListener('click', dismiss);
  el.appendChild(close);
  cont.appendChild(el);
  let to = setTimeout(dismiss, duration || 4000);
  function dismiss(){
    if(!el.parentNode) return;
    clearTimeout(to);
    el.classList.add('v2-toast--out');
    setTimeout(() => { if(el.parentNode) el.parentNode.removeChild(el); }, 250);
  }
  return el;
};
var mostrarToast = window.mostrarToast; // alias local
function savePref(k,v){ try{ localStorage.setItem(k, JSON.stringify(v)); }catch(e){} }
function loadPref(k,f){ try{ const v=localStorage.getItem(k); return v?JSON.parse(v):f; }catch(e){ return f; } }
function rememberMode(m){ savePref('v2_mode', m); }
function loadMode(){ return loadPref('v2_mode','prog'); }
function hasReagendadosData(){
  if (window.markersReag && Object.keys(window.markersReag).length) return true;
  return $('#panelReagendados table tbody tr').length > 0;
}
function rememberDate(mode, date){ savePref('v2_date_'+mode, date); }  function loadDate(mode){ return loadPref('v2_date_'+mode, ''); }
// Excluidos
function exclKey(modo, fecha, id){ return `${modo}|${fecha}|${id}`; }
function loadExcluded(){ window.excluded = new Set(loadPref('v2_excluded', [])); }
function saveExcluded(){ savePref('v2_excluded', Array.from(window.excluded||[])); }
loadExcluded();

// ============ Utilidades de ruta ============
const GOOGLE_MAPS_API_KEY = window.__GOOGLE_MAPS_API_KEY || '';
const MAP_ID = "YOUR_VECTOR_MAP_ID";
const MAPS_LIBRARIES = 'geometry';
const IS_TEST_MODE = <?php echo $TEST_MODE ? 'true' : 'false'; ?>;
let mapsScriptPromise = null;
let mapsRetryTimer = null;
const apiCounters = { timezone_calls: 0 };
let isMapVisible = false; // "Maps visibility guard"
window.startGeoWatch=window.startGeoWatch||function(){};
window.stopGeoWatch=window.stopGeoWatch||function(){};

// Contadores de API: solo en modo debug (?debug=1 o localStorage.v2_debug=1).
const V2_DEBUG = /[?&]debug=1/.test(location.search) || localStorage.getItem('v2_debug') === '1';
function updateApiCounters(){
  const el=document.getElementById('apiCounters');
  if(!el) return;
  el.style.display = V2_DEBUG ? '' : 'none';
  if(!V2_DEBUG) return;
  const stats = window.RouteEngine ? window.RouteEngine.getStats() : {};
  const routes = stats.routes_api_requests || 0;
  const fallback = stats.directions_fallback_requests || 0;
  const mem = stats.cache_hits_memory || 0;
  const idb = stats.cache_hits_idb || 0;
  const total = stats.route_requests_total || 0;
  const reroutes = stats.reroutes_triggered || 0;
  el.textContent=`API: Routes ${routes} | Fallback ${fallback} | Cache M ${mem} | Cache DB ${idb} | Total ${total} | Reroutes ${reroutes}`;
}
updateApiCounters();
window.addEventListener('route-engine-stats', updateApiCounters);

function scheduleMapsRetry(){
  if (mapsRetryTimer) return;
  const retry = ()=>{
    mapsRetryTimer = null;
    loadGoogleMapsSdk().then(()=>{ if(!window.mapa) initMap(); }).catch(()=>{});
  };
  if (navigator.onLine){
    mapsRetryTimer = setTimeout(retry, 5000);
  } else {
    const onBackOnline = ()=>{ window.removeEventListener('online', onBackOnline); retry(); };
    window.addEventListener('online', onBackOnline);
  }
}

function loadGoogleMapsSdk(){
  if (window.google && window.google.maps) return Promise.resolve(window.google.maps);
  if (mapsScriptPromise) return mapsScriptPromise;
  if (IS_TEST_MODE){
    window.google = {
      maps: {
        Map: function(){ this.setCenter=()=>{}; this.setZoom=()=>{}; this.fitBounds=()=>{}; this.addListener=()=>{}; },
        Marker: function(opts){ this._map = opts.map; this.setMap=(m)=>{ this._map=m; }; this.getMap=()=>this._map; this.setPosition=()=>{}; this.getPosition=()=>({ toJSON:()=>({lat:0,lng:0}) }); },
        InfoWindow: function(){ this.open=()=>{}; },
        LatLng: function(lat,lng){ this.lat=()=>lat; this.lng=()=>lng; this.toJSON=()=>({lat,lng}); },
        LatLngBounds: function(){ this.extend=()=>{}; },
        TrafficLayer: function(){ this._map=null; this.getMap=()=>this._map; this.setMap=(m)=>{ this._map=m; }; },
        geometry: { spherical: { computeDistanceBetween: ()=>0 }, encoding: { encodePath: ()=>' ', decodePath: ()=>[] } },
        DirectionsService: function(){ this.route=(req, cb)=>cb({ routes:[{ legs: [], overview_path: [] }]}, 'OK'); },
        DirectionsStatus: { OK: 'OK' },
        SymbolPath: { CIRCLE: 'CIRCLE' },
        event: { trigger: ()=>{} },
        TravelMode: { DRIVING: 'DRIVING' }
      }
    };
    return Promise.resolve(window.google.maps);
  }
  if (!GOOGLE_MAPS_API_KEY){
    return Promise.reject(new Error('Falta la Google Maps API key.'));
  }

  mapsScriptPromise = new Promise((resolve, reject)=>{
    const prev = document.querySelector('script[data-google-maps-loader="true"]');
    if (prev) prev.remove();
    const s = document.createElement('script');
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(GOOGLE_MAPS_API_KEY)}&libraries=${MAPS_LIBRARIES}`;
    s.async = true; s.defer = true; s.dataset.googleMapsLoader = 'true';
    const onError = ()=>{ cleanup(); reject(new Error('Error al cargar Google Maps.')); };
    const onLoad = ()=>{ cleanup(true); resolve(window.google.maps); };
    const cleanup = (ok)=>{ s.onload=null; s.onerror=null; if (!ok && s.parentNode) s.parentNode.removeChild(s); };
    s.onload = onLoad;
    s.onerror = onError;
    document.head.appendChild(s);
  }).catch(err=>{ mapsScriptPromise=null; scheduleMapsRetry(); throw err; });

  return mapsScriptPromise;
}

async function ensureMapReady(){
  await loadGoogleMapsSdk();
  if (!window.mapa) initMap();
}
function secondsFromDuration(d){ if (typeof d==='string' && d.endsWith('s')) return Math.round(parseFloat(d)); return 0; }
function decode(encoded){ return google.maps.geometry.encoding.decodePath(encoded).map(ll=>({lat:ll.lat(), lng:ll.lng()})); }
function fmtKm(m){ return (m>=1000) ? (m/1000).toFixed(1)+' km' : Math.round(m)+' m'; }

// Pinta polilíneas por tráfico (Routes v2)
function buildTrafficPolylines(map, route){
  return window.RouteEngine.buildTrafficPolylines(map, route);
}

// Drawer de pasos
function renderIndicacionesFromRoute(route){
  const $ol=$('#listaIndicaciones'); $ol.empty();
  if(!route || !(route.legs||[]).length){
    $ol.append('<li>Instrucciones detalladas disponibles en modo navegación.</li>');
    return;
  }
  (route.legs||[]).forEach(leg=>{
    (leg.steps||[]).forEach(st=>{
      const ins=(st.navigationInstruction && st.navigationInstruction.instructions) || '';
      const dist=(st.distanceMeters!=null)? fmtKm(st.distanceMeters):'';
      const dur =(st.staticDuration)? Math.round(secondsFromDuration(st.staticDuration)/60)+' min':'';
      const meta=[dist,dur].filter(Boolean).join(' • ');
      $ol.append(`<li>${ins || 'Sigue la vía'}<br><small>${meta}</small></li>`);
    });
  });
}

// Motor unificado (Routes v2) con fallback a DirectionsService + caché en memoria
// Fix #15: propaga el flag force para saltarse el rate limit cuando el usuario recalcula manualmente
async function computeRouteUnified({origin,destination,waypoints=[], optimize=true, mode, force=false, bypassCache=false}){
  const chosenMode = mode || (window.trafficEnabled ? 'traffic' : 'preview');
  // force salta throttle pero NO la caché; bypassCache se usa explícitamente para datos frescos
  return window.RouteEngine.computeRouteUnified({ origin, destination, waypoints, optimize, mode: chosenMode, bypassThrottle: force, bypassCache });
}

// Delegado a RouteSelection.collect() — mantiene compatibilidad con llamadas existentes
function collectCurrentPoints(){
  const modo     = window.modoLocal;
  const cont     = (modo === 'prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const selId    = (modo === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const searchId = (modo === 'prog') ? '#filtroLocalesProg' : '#filtroLocalesReag';
  const result   = window.RouteSelection.collect({
    modo,
    fechaSel:   $(selId).val(),
    searchTerm: $(searchId).val(),
    excluded:   window.excluded,
    cont
  });
  return result.pts;
}

// ── Numeración de marcadores en el mapa ──────────────────────────────────────

function makeNumberedIcon(num, color){
  const fs  = num > 9 ? '10' : '13';
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">` +
    `<path d="M15 0C6.72 0 0 6.72 0 15c0 11.25 15 27 15 27S30 26.25 30 15C30 6.72 23.28 0 15 0z" ` +
    `fill="${color}" stroke="rgba(0,0,0,0.3)" stroke-width="1"/>` +
    `<text x="15" y="16" text-anchor="middle" dominant-baseline="middle" ` +
    `fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="${fs}" font-weight="bold">${num}</text>` +
    `</svg>`;
  return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

// Aplica números de orden a los marcadores del modo activo
function applyRouteNumbers(orderedPts){
  const markers = (window.modoLocal === 'prog') ? window.markersProg : window.markersReag;
  // Primero restaurar todos los marcadores a su ícono original
  Object.values(markers).forEach(function(m){
    if (m.originalIcon) { m.marker.setIcon(m.originalIcon); }
  });
  // Luego numerar los que están en la ruta
  orderedPts.forEach(function(pt, idx){
    const m = markers[pt.idLocal];
    if (!m) return;
    const color = m.markerColor === 'orange' ? '#e67e22' : m.markerColor === 'blue' ? '#2980b9' : '#c0392b';
    m.marker.setIcon({ url: makeNumberedIcon(idx + 1, color), scaledSize: new google.maps.Size(30, 42) });
  });
}

// Limpia todos los números y restaura íconos originales
function clearRouteNumbers(){
  [window.markersProg, window.markersReag].forEach(function(markers){
    if (!markers) return;
    Object.values(markers).forEach(function(m){
      if (m.originalIcon) { m.marker.setIcon(m.originalIcon); }
    });
  });
}

// Texto a voz (toggleable)
function speak(text){
  if(!window.voiceEnabled) return;
  try{
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'es-CL';
    const v=speechSynthesis.getVoices().find(v=>/es\-(CL|ES|MX)/i.test(v.lang));
    if(v) u.voice=v;
    speechSynthesis.speak(u);
  }catch(_){}
}

function logRouteEvent(payload){
  const now=new Date();
  console.debug('[route]', { ts: now.toISOString(), ...payload });
}

// Reordena las filas del tbody según el orden optimizado devuelto por Routes API
function renderTableOrder(orderedPts){
  const cont  = (window.modoLocal === 'prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const selId = (window.modoLocal === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const fechaSel = $(selId).val();
  const $tbody = $(`${cont} table[data-fechaTabla="${fechaSel}"] tbody`);
  if (!$tbody.length) return;
  const rowMap = {};
  $tbody.find('tr').each(function(){ rowMap[parseInt($(this).data('idlocal'),10)] = $(this).detach(); });
  orderedPts.forEach(function(pt, idx){
    const $tr = rowMap[pt.idLocal];
    if (!$tr) return;
    let $badge = $tr.find('.v2-route-ord');
    if (!$badge.length) {
      $tr.find('.in-route').closest('td').prepend(
        '<span class="v2-route-ord" style="display:inline-block;background:#4285F4;color:#fff;border-radius:50%;width:18px;height:18px;text-align:center;line-height:18px;font-size:11px;font-weight:bold;margin-right:3px;vertical-align:middle;"></span>'
      );
      $badge = $tr.find('.v2-route-ord');
    }
    $badge.text(idx + 1);
    $tbody.append($tr);
  });
  // Filas no incluidas en la ruta (sin checkbox o excluidas) van al final
  Object.values(rowMap).forEach(function($tr){ if ($tr && $tr.parent().length === 0) $tbody.append($tr); });
}

window.planRouteFromSelection = async function (origen, opts={}){
  const { force=false, trigger='unknown' } = opts;
  if(!isMapVisible || !window.mapa) { logRouteEvent({trigger, skipped:'map_hidden'}); return; }

  // Recolectar mediante RouteSelection para obtener metadata cross-date
  const modo     = window.modoLocal;
  const cont     = (modo === 'prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const selId    = (modo === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const searchId = (modo === 'prog') ? '#filtroLocalesProg' : '#filtroLocalesReag';
  const selection = window.RouteSelection.collect({
    modo, fechaSel: $(selId).val(), searchTerm: $(searchId).val(), excluded: window.excluded, cont
  });
  const puntos = selection.pts;

  if (!puntos.length) {
    (window.mapa.__trafficSegs||[]).forEach(s=>s.setMap(null)); window.mapa.__trafficSegs=[];
    window.plannedRoute=null; window.lastComputedRoute=null; window._lastRouteStateHash=null; window._lastComputedOrigin=null;
    clearRouteNumbers();
    $('#distanciaTotal').text('0 km'); $('#duracionEstimada').text('0 min'); $('#listaIndicaciones').empty(); return;
  }

  // Aviso cross-date: si la búsqueda activa mezcla locales de varias fechas, pedir confirmación
  if (selection.isMultiDate && trigger !== 'cross_date_confirmed') {
    const ok = await window.RouteSelection.confirmCrossDate(selection.fechas);
    if (!ok) return;
    // Re-invocar con flag que indica que el usuario ya confirmó
    return window.planRouteFromSelection(origen, { ...opts, trigger: 'cross_date_confirmed' });
  }

  // routeStateHash: cambia si cambian las paradas, el ORIGEN (bucket ~50 m), el modo, la
  // optimización, el tráfico, la fecha o el modo local. Reemplaza al antiguo selectionHash (que
  // solo miraba ids y nunca el origen GPS, por lo que ignoraba que el ejecutor se hubiera movido).
  const _planMode = window.trafficEnabled ? 'traffic' : 'preview';
  const routeStateHash = window.RoutePlanner.routeStateHash({
    ids:       puntos.map(p => p.idLocal),
    origin:    origen,
    mode:      _planMode,
    optimize:  window.optimizeOrder,
    traffic:   window.trafficEnabled,
    fecha:     $(selId).val(),
    modoLocal: modo
  });

  // Triggers que SIEMPRE recalculan (no se saltan aunque el hash no cambie): recálculo manual,
  // centrar, abrir modal y reroute de navegación.
  const ALWAYS_RECALC = new Set(['manual_recalc', 'center', 'modal_open', 'navigation-reroute']);
  let shouldRecalc;
  if (force || ALWAYS_RECALC.has(trigger)) {
    shouldRecalc = true;
  } else if (trigger === 'gps_move') {
    // Movimiento GPS: solo si Auto está activo y el ejecutor se movió de forma significativa
    // (>75 m respecto al último origen calculado) o cambió el bucket de origen (hash distinto).
    if (!window.autoRecalc) {
      shouldRecalc = false;
    } else {
      const last  = window._lastComputedOrigin;
      const moved = !last || window.RoutePlanner.haversine(last, origen) > 75;
      shouldRecalc = moved || (routeStateHash !== window._lastRouteStateHash);
    }
  } else {
    shouldRecalc = (routeStateHash !== window._lastRouteStateHash);
  }

  if (!shouldRecalc) {
    if (window.lastComputedRoute) buildTrafficPolylines(window.mapa, window.lastComputedRoute.route);
    return;
  }
  window._lastRouteStateHash = routeStateHash;

  const horaMinActual = new Date().getHours() * 60 + new Date().getMinutes();
  const hayBotillerias = puntos.some(p => p.isBotilleria);
  if (hayBotillerias && horaMinActual < 13 * 60) {
    const nBot = puntos.filter(p => p.isBotilleria).length;
    const $w = $('#routeWarning');
    if ($w.length) {
      $w.html(`<i class="fa fa-clock-o"></i> Hay ${nBot} botillería(s) en la ruta. Se agendaron al final (abren a las 13:00 hrs).`).stop(true).show();
      setTimeout(() => $w.fadeOut(), 9000);
    }
  }
  const mode = window.trafficEnabled ? 'traffic' : 'preview';
  try{
    const plan = await window.RoutePlanner.planFull({
      origin:        origen,
      points:        puntos,
      optimize:      window.optimizeOrder,
      mode,
      bypassThrottle: force,
      bypassCache:    force   // recálculo manual ("Recalcular") fuerza ruta fresca, sin caché
    });
    window.plannedRoute      = plan.route;
    window.lastComputedRoute = { orderedPts: plan.orderedPts, route: plan.route, chunks: plan.chunks, totals: plan.totals };
    window._lastComputedOrigin = origen; // base para el umbral de movimiento del próximo gps_move

    // Reordenar tabla y numerar marcadores del mapa con el orden final
    renderTableOrder(plan.orderedPts);
    applyRouteNumbers(plan.orderedPts);

    // UI de chunks: banner informativo cuando la ruta fue dividida
    if (plan.chunks && plan.chunks.length > 1) {
      window._routeChunks = plan.chunks;
      const $w = $('#routeWarning');
      if ($w.length) {
        $w.html(
          `<i class="fa fa-map-signs"></i> Ruta larga dividida en <strong>${plan.chunks.length} bloques</strong> ` +
          `de cálculo (${plan.orderedPts.length} locales). Usa <strong>“Abrir en Google Maps”</strong> ` +
          `para exportarla en enlaces encadenados.`
        ).stop(true).show();
      }
    } else {
      window._routeChunks = null;
    }

    buildTrafficPolylines(window.mapa, plan.route);
    // Distancia/duración = TOTALES agregados de todos los bloques (no solo el primero).
    const totDist = (plan.totals && plan.totals.distanceMeters)  || plan.route.distanceMeters || 0;
    const totSecs = (plan.totals && plan.totals.durationSeconds) || secondsFromDuration(plan.route.duration);
    const km  = (totDist/1000).toFixed(2);
    const min = Math.round(totSecs/60);
    $('#distanciaTotal').text(`${km} km`);
    $('#duracionEstimada').text(`${min} min`);
    renderIndicacionesFromRoute(plan.route);
    if (!navigator.onLine && (trigger === 'manual_recalc' || trigger === 'modal_open')) {
      mostrarToast('Usando ruta guardada sin conexión.', 'info');
    }
    logRouteEvent({trigger, apiUsed: plan.apiUsed});
    speak(`Ruta actualizada. ${km} kilómetros, ${min} minutos.`);
  }catch(err){
    logRouteEvent({trigger, error:String(err)});
    if (err.message?.includes('Sin conexión')) {
      mostrarToast('Sin conexión y sin ruta guardada para mostrar.', 'warn');
    } else {
      const $w = $('#routeWarning');
      if ($w.length) $w.text('Error al calcular ruta: ' + (err.message || 'Error desconocido')).stop(true).show();
    }
  }
};
window.debouncedPlanRoute = debounce((pos)=>window.planRouteFromSelection(pos,{trigger:'gps_move'}), 1000);

// Estado marcadores y contadores
window.markersProg={}; window.markersReag={}; window.plannedRoute=null;
function hideAllMarkers(obj){ Object.values(obj).forEach(m => m.marker.setMap(null)); }
function ensureDateSelectedFor(mode){
  const selId = (mode === 'prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const $sel  = $(selId); const saved = loadDate(mode);
  if (saved && $sel.find(`option[value="${saved}"]`).length) $sel.val(saved);
  if (!$sel.val()) { const first = $sel.find('option:first').val(); if (first) $sel.val(first); }
}
function setMode(mode){
  const desired = mode || 'prog';
  const finalMode = (desired === 'reag' && !hasReagendadosData()) ? 'prog' : desired;
  window.modoLocal = finalMode;
  rememberMode(finalMode);
  if (finalMode === 'prog') { $('#panelReagendados').hide(); $('#panelProgramados').show(); }
  else { $('#panelProgramados').hide(); $('#panelReagendados').show(); }
  clearRouteNumbers(); // limpiar números del modo anterior antes de ocultar marcadores
  hideAllMarkers(window.markersProg); hideAllMarkers(window.markersReag);
  window.lastComputedRoute = null; window._lastRouteStateHash = null; window._lastComputedOrigin = null;
  ensureDateSelectedFor(finalMode); applyFilters();
  const pos = window.ejecutorMarker?.getPosition();
  if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) { window.debouncedPlanRoute(pos.toJSON()); }
}
function updateCounts(){
  const mode   = window.modoLocal || 'prog';
  const panel  = (mode==='prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  $('#countTabla').text($(panel+' table[data-fechaTabla] tbody tr:visible:not([data-done="1"])').length);
  const markers=(mode==='prog')?window.markersProg:window.markersReag;
  const count=Object.values(markers).filter(m=>m.marker.getMap()!==null).length;
  $('#countMapa').text(count);
  const selId = (mode==='prog')?'#filtroFechaProg':'#filtroFechaReag'; const fechaSel = $(selId).val();
  let excl=0; Object.keys(markers).forEach(id=>{ if (window.excluded.has(`${mode}|${fechaSel}|${id}`)) excl++; }); $('#countEx').text(excl);
}

// Fix #3: shim de VoiceController que delega en speak() local
// nav_engine.js usa VoiceController; este puente lo conecta con la función speak() de esta página
if (!window.VoiceController) {
  window.VoiceController = {
    speak: function(text) { speak(text); },
    speakNavigation: function(text) { speak(text); },
    speakWaypointArrival: function(name, remaining) {
      speak(remaining > 0 ? 'Parada alcanzada. Quedan ' + remaining + '.' : 'Última parada alcanzada.');
    },
    speakArrival: function() { speak('Has llegado a tu destino.'); },
    speakReroute: function() { speak('Recalculando ruta.'); },
    pause: function() { try { window.speechSynthesis.pause(); } catch(_) {} },
    resume: function() { try { window.speechSynthesis.resume(); } catch(_) {} }
  };
}

// Fix #13: estado para evitar reconstruir el <select> de fechas si no cambiaron
const _fechasOkKeyByMode = { prog: null, reag: null };

// applyFilters: respeta campañas tachadas + fecha + excluidos + checkboxes
window.applyFilters = function(){
  const modo      = window.modoLocal || 'prog';
  const selId     = (modo==='prog') ? '#filtroFechaProg' : '#filtroFechaReag';
  const searchId  = (modo==='prog') ? '#filtroLocalesProg' : '#filtroLocalesReag';
  const container = (modo==='prog') ? '#localesProgCollapse' : '#localesReagCollapse';
  const markers   = (modo==='prog') ? window.markersProg : window.markersReag;
  const other     = (modo==='prog') ? window.markersReag : window.markersProg;
  hideAllMarkers(other);
  const searchTerm = String($(searchId).val() || '').trim().toLowerCase();
  const tachadas = $('ul.todo .completed').map((i,li)=>String($(li).data('idcampana'))).get();
  const fechasOk = {};
  $(`${container} table[data-fechaTabla]`).each(function(){
    const fecha = $(this).attr('data-fechaTabla'); let tiene = false;
    $(this).find('tbody tr').each(function(){
      const camps = String($(this).data('campanas')||'').split(',');
      const ok = camps.some(c => !tachadas.includes(c));
      $(this).data('okCamp', ok);
      if (ok) tiene = true;
    });
    fechasOk[fecha] = tiene;
  });
  
  // Fix #13: reconstruir el <select> solo si el conjunto de fechas cambió
  const $sel = $(selId);
  const prev = $sel.val();
  const newFechasKey = Object.keys(fechasOk).filter(f=>fechasOk[f]).sort().join(',');
  if (newFechasKey !== _fechasOkKeyByMode[modo]) {
    _fechasOkKeyByMode[modo] = newFechasKey;
    $sel.empty();
    Object.keys(fechasOk).filter(f=>fechasOk[f]).sort().forEach(f=>{
      const [y,m,d]=f.split('-'); $sel.append(`<option value="${f}">${d}-${m}-${y}</option>`);
    });
  }
  if (prev && fechasOk[prev]) $sel.val(prev);
  if (!$sel.val()) $sel.val($sel.find('option:first').val() || '');
  const fechaSel = $sel.val(); rememberDate(modo, fechaSel);
  $(`${container} h4[data-fechaencabezado], ${container} table[data-fechaTabla]`).hide();
  if (searchTerm) {
    // Con texto: buscar en todas las fechas
    $(`${container} table[data-fechaTabla]`).each(function(){
      const fecha = $(this).attr('data-fechaTabla');
      let anyVisible = false;
      $(this).find('tbody tr').each(function(){
        const okCamp = !!$(this).data('okCamp');
        const txt = String($(this).data('busqueda') || $(this).text() || '').toLowerCase();
        const matches = txt.includes(searchTerm);
        $(this).toggle(okCamp && matches);
        if (okCamp && matches) anyVisible = true;
        const id = parseInt($(this).data('idlocal'),10);
        const $chk = $(this).find('input.in-route');
        if ($chk.length){
          const excluded = window.excluded.has(`${modo}|${fecha}|${id}`);
          $chk.prop('checked', !excluded);
        }
      });
      if (anyVisible){
        $(`${container} h4[data-fechaencabezado="${fecha}"]`).show();
        $(this).show();
      }
    });
  } else if (fechaSel){
    // Sin texto: mostrar solo la fecha seleccionada
    $(`${container} h4[data-fechaencabezado="${fechaSel}"], ${container} table[data-fechaTabla="${fechaSel}"]`).show();
    $(`${container} table[data-fechaTabla="${fechaSel}"] tbody tr`).each(function(){
      const okCamp = !!$(this).data('okCamp');
      $(this).toggle(okCamp);
      const id = parseInt($(this).data('idlocal'),10);
      const $chk = $(this).find('input.in-route');
      if ($chk.length){
        const excluded = window.excluded.has(`${modo}|${fechaSel}|${id}`);
        $chk.prop('checked', !excluded);
      }
    });
  }
  Object.entries(markers).forEach(([id,m])=>{
    // Un local puede estar programado en varias fechas; mostrar su (único) marcador si tiene
    // CUALQUIER fila visible en el contenedor, no solo en la fecha guardada en el marcador.
    const visibleRow = $(`${container} table[data-fechaTabla] tbody tr[data-idlocal="${id}"]:visible:not([data-done="1"])`).length > 0;
    m.marker.setMap(visibleRow ? window.mapa : null);
  });
  const visibles = Object.values(markers).filter(m=>m.marker.getMap()!==null);
  if (visibles.length && window.google && window.google.maps){
    const b = new google.maps.LatLngBounds(); visibles.forEach(m=>b.extend(m.marker.getPosition())); window.mapa.fitBounds(b);
  }
  updateCounts();
  const pos = window.ejecutorMarker?.getPosition();
  if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(pos.toJSON());
};

// ====== INIT MAP ======
window.initMap=function(){
  if (window.mapa) {
    google.maps.event.trigger(window.mapa, 'resize');
    return;
  }
  const coordenadasProg=<?php echo json_encode($coordenadas_locales_programados); ?>;
  const coordenadasReag=<?php echo json_encode($coordenadas_locales_reag); ?>;

  const mapOptions = { zoom:12, center:{lat:-33.4489, lng:-70.6693} };
  if (MAP_ID && MAP_ID !== 'YOUR_VECTOR_MAP_ID') mapOptions.mapId = MAP_ID;

  window.mapa=new google.maps.Map(document.getElementById('map'), mapOptions);

  const orangeMarkerSvg = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">' +
    '<path d="M15 0C6.72 0 0 6.72 0 15c0 11.25 15 27 15 27S30 26.25 30 15C30 6.72 23.28 0 15 0z" fill="#e67e22" stroke="#fff" stroke-width="1.5"/>' +
    '<circle cx="15" cy="15" r="6" fill="#fff"/>' +
    '</svg>'
  );

  // Marcadores Programados
  coordenadasProg.forEach(local=>{
    // Dedup por idLocal: un local puede venir repetido (varias fechas). Crear más de un marcador
    // por local deja marcadores huérfanos en el mapa (sin número y sin ocultar). Uno por local.
    if (window.markersProg[local.idLocal]) return;
    let iconUrl = 'assets/images/marker_red1.png';
    if (local.markerColor === 'orange') iconUrl = orangeMarkerSvg;
    else if (local.markerColor === 'blue') iconUrl = 'assets/images/marker_blue1.png';
    const iconObj = { url:iconUrl, scaledSize:new google.maps.Size(30,30) };
    const marker=new google.maps.Marker({
      position:{lat:local.latitud, lng:local.lng}, map:window.mapa, title:local.nombre_local,
      icon: iconObj
    });
    const botNote = local.is_botilleria
      ? `<span style="color:#e67e22;font-size:12px;font-weight:600;"><i class="fa fa-clock-o"></i> Botillería: abre a las 13:00 hrs</span><br><br>`
      : '';
    const _snProg = local.nombre_local.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const _sdProg = (local.direccion||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const iw=new google.maps.InfoWindow({content:
      `<div style="min-width:200px;"><strong>${local.nombre_local}</strong><br><br>${botNote}
       <button type="button" class="btn btn-primary btn-sm" style="width:100%;margin-bottom:5px" data-toggle="modal" data-target="#responsiveProg${local.idLocal}">
       <i class="fa fa-cog"></i> Gestionar Local</button>
       <button type="button" class="btn btn-warning btn-sm" style="width:100%" onclick="abrirModalReportarDir(${local.idLocal},'${_snProg}','${_sdProg}',${local.latitud},${local.lng})">
       <i class="fa fa-map-marker"></i> Reportar dirección</button></div>`});
    marker.addListener('click',()=>iw.open(window.mapa,marker));
    // originalIcon permite restaurar el ícono tras limpiar numeración
    window.markersProg[local.idLocal]={ marker, fechaPropuesta: local.fechaPropuesta, markerColor: local.markerColor, originalIcon: iconObj };
  });

  // Marcadores Reagendados
  coordenadasReag.forEach(local=>{
    if (window.markersReag[local.idLocal]) return; // dedup por idLocal (ver nota en Programados)
    let iconUrl = (local.markerColor === 'orange') ? orangeMarkerSvg : 'assets/images/marker_red1.png';
    if (local.markerColor === 'blue') iconUrl = 'assets/images/marker_blue1.png';
    const iconObj = { url:iconUrl, scaledSize:new google.maps.Size(30,30) };
    const marker=new google.maps.Marker({
      position:{lat:local.latitud, lng:local.lng}, map:window.mapa, title:local.nombre_local,
      icon: iconObj
    });
    const botNote = local.is_botilleria
      ? `<span style="color:#e67e22;font-size:12px;font-weight:600;"><i class="fa fa-clock-o"></i> Botillería: abre a las 13:00 hrs</span><br><br>`
      : '';
    const _snReag = local.nombre_local.replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const _sdReag = (local.direccion||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const iw=new google.maps.InfoWindow({content:
      `<div style="min-width:200px;"><strong>${local.nombre_local}</strong><br><br>${botNote}
       <button type="button" class="btn btn-primary btn-sm" style="width:100%;margin-bottom:5px" data-toggle="modal" data-target="#responsiveReag${local.idLocal}">
       <i class="fa fa-cog"></i> Gestionar Local</button>
       <button type="button" class="btn btn-warning btn-sm" style="width:100%" onclick="abrirModalReportarDir(${local.idLocal},'${_snReag}','${_sdReag}',${local.latitud},${local.lng})">
       <i class="fa fa-map-marker"></i> Reportar dirección</button></div>`});
    marker.addListener('click',()=>iw.open(window.mapa,marker));
    window.markersReag[local.idLocal]={ marker, fechaPropuesta: local.fechaPropuesta, markerColor: local.markerColor, originalIcon: iconObj };
  });

  // Ubicación ejecutor + capa tráfico
  window.ejecutorMarker=new google.maps.Marker({
    position:{lat:-33.4489, lng:-70.6693}, map:window.mapa, title:'Tu Ubicación',
    icon:{ path:google.maps.SymbolPath.CIRCLE, scale:8, fillColor:'#4285F4', fillOpacity:1, strokeColor:'#fff', strokeWeight:2 }
  });
  window.trafficLayer = new google.maps.TrafficLayer();

  // Geo + recálculo con umbral (solo cuando el modal está visible)
  const MIN_MOVE_METERS=60; let lastPos=null; let geoWatchId=null;
  function stopGeoWatch(){ if(geoWatchId!=null){ navigator.geolocation.clearWatch(geoWatchId); geoWatchId=null; } }
  function startGeoWatch(){
    if(geoWatchId!=null || !navigator.geolocation) return;
    geoWatchId = navigator.geolocation.watchPosition(pos=>{
      const cur=new google.maps.LatLng(pos.coords.latitude, pos.coords.longitude);
      window.ejecutorMarker.setPosition(cur);
      if(!lastPos || google.maps.geometry.spherical.computeDistanceBetween(lastPos, cur) > MIN_MOVE_METERS){
        lastPos=cur; const json=cur.toJSON();
        if (window.navigator3D && window.navigator3D.active) return;
        if (window.autoRecalc && isMapVisible) window.debouncedPlanRoute(json);
      }
    },err=>{ console.error(err); if(isMapVisible) mostrarToast('No se pudo obtener tu ubicación.', 'warn'); },
    { enableHighAccuracy:true, maximumAge:2000, timeout:10000 });
  }
  window.startGeoWatch=startGeoWatch; window.stopGeoWatch=stopGeoWatch;

  // Modal show
  $('#modalMapa').on('shown.bs.modal', function(){
    isMapVisible=true; startGeoWatch();
    google.maps.event.trigger(window.mapa, 'resize');
    ensureDateSelectedFor(window.modoLocal||'prog'); applyFilters();
    const pos=window.ejecutorMarker?.getPosition();
    if(pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON(),{force:true, trigger:'modal_open'});
  });
  $('#modalMapa').on('hidden.bs.modal', function(){
    isMapVisible=false; stopGeoWatch();
  });

  // Botones
  $('#btnActualizar').on('click', function(){ rememberMode('prog'); });
  $('#btnCentrar').on('click', ()=>{
    if(!navigator.geolocation) return;
    $('#loadingIndicator').show();
    navigator.geolocation.getCurrentPosition(p=>{
      const cur=new google.maps.LatLng(p.coords.latitude, p.coords.longitude);
      window.ejecutorMarker.setPosition(cur); window.mapa.setCenter(cur); window.mapa.setZoom(15);
      if(isMapVisible && !(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(cur.toJSON());
      $('#loadingIndicator').hide();
    }, ()=>{ $('#loadingIndicator').hide(); mostrarToast('No se pudo centrar tu ubicación.', 'warn'); }, { enableHighAccuracy:true, maximumAge:0, timeout:10000 });
  });
  $('#btnRecalcular').on('click', ()=>{
    const pos=window.ejecutorMarker?.getPosition();
    if(pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON(),{force:true, trigger:'manual_recalc'});
  });
  $('#btnIndicaciones').on('click', ()=> $('#drawerIndicaciones').toggleClass('open'));
  $('#btnCloseDrawer').on('click', ()=> $('#drawerIndicaciones').removeClass('open'));
  $('#btnTraffic').on('click', function(){
    const turningOn = !window.trafficEnabled;
    window.trafficEnabled = turningOn;
    // 1) Capa visual de tráfico de Google
    if (window.trafficLayer) window.trafficLayer.setMap(turningOn ? window.mapa : null);
    $(this).toggleClass('btn-info', turningOn);
    // 2) Recalcular en modo 'traffic' (TRAFFIC_AWARE + departureTime) — salvo navegando
    const pos = window.ejecutorMarker?.getPosition();
    if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) {
      window.planRouteFromSelection(pos.toJSON(), { force:true, trigger:'traffic_toggle' });
    }
    mostrarToast(turningOn ? 'Tráfico en vivo activado.' : 'Tráfico desactivado.', 'info');
  });
  $('#optimizeOrder').on('change', function(){ window.optimizeOrder=$(this).is(':checked'); const pos=window.ejecutorMarker?.getPosition(); if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) window.planRouteFromSelection(pos.toJSON(),{trigger:'optimize_toggle'}); });
  $('#autoRecalc').on('change', function(){ window.autoRecalc=$(this).is(':checked'); });
  $('#btnVoz').on('click', function(){ window.voiceEnabled=!window.voiceEnabled; $(this).toggleClass('btn-info', window.voiceEnabled); if (window.voiceEnabled) speak('Voz activada.'); else { try{ speechSynthesis.cancel(); }catch(_){}} });
  // ---- Export a Google Maps por bloques (respeta el límite de waypoints por URL: 3 móvil / 9 desktop) ----
  function detectExportEnv(){
    return /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '') ? 'mobile' : 'desktop';
  }
  function buildExportLinks(){
    const pos = window.ejecutorMarker?.getPosition();
    if (!pos) return { error: 'No se pudo obtener tu ubicación.' };
    // Orden optimizado si existe; si no, recolectar desde la tabla. El GPS encabeza la secuencia.
    const pts = (window.lastComputedRoute?.orderedPts?.length ? window.lastComputedRoute.orderedPts : collectCurrentPoints());
    if (!pts.length) return { error: 'Selecciona al menos un local en la columna Ruta.' };
    const seq   = [pos.toJSON(), ...pts];
    const links = window.RoutePlanner.buildGoogleMapsExportChunks(seq, detectExportEnv());
    return { links };
  }
  $('#btnExportar').on('click', function(){
    const { links, error } = buildExportLinks();
    if (error){ mostrarToast(error, 'warn'); return; }
    if (!links || !links.length){ mostrarToast('No hay paradas para exportar.', 'warn'); return; }
    if (links.length === 1){ window.open(links[0].url, '_blank'); return; }
    // Varios bloques: mostrar los enlaces encadenados (no abrir muchas pestañas a la vez → el
    // navegador bloquearía los popups). Cada bloque continúa donde terminó el anterior.
    window._mapsExportLinks = links;
    const env   = detectExportEnv();
    const maxWp = env === 'mobile' ? 3 : 9;
    const $w = $('#routeWarning');
    if ($w.length){
      $w.html(
        `<i class="fa fa-external-link"></i> La ruta supera el límite de Google Maps (${maxWp} paradas por enlace). ` +
        `Ábrela en <strong>${links.length} bloques</strong> encadenados:<br>` +
        links.map((l, i) =>
          `<button type="button" class="btn btn-xs ${i===0?'btn-success':'btn-default'} v2-maps-block" style="margin:2px" data-idx="${i}">` +
          `Bloque ${i+1}/${links.length} (${l.waypoints.length+1} paradas)</button>`
        ).join(' ')
      ).stop(true).show();
    } else {
      window.open(links[0].url, '_blank');
    }
  });
  $(document).on('click', '.v2-maps-block', function(){
    const idx = parseInt($(this).data('idx'), 10);
    const l = (window._mapsExportLinks || [])[idx];
    if (l && l.url) window.open(l.url, '_blank');
    $(this).removeClass('btn-success btn-default').addClass('btn-primary'); // marcar bloque abierto
  });

  // Modo inicial — fix #2: listeners de btnVer* movidos a document.ready (evita doble binding)
  $(document).on('change', 'table input.in-route', function(){
    const $tr=$(this).closest('tr'); const id=parseInt($tr.data('idlocal'),10); const modo=window.modoLocal;
    // Usar la fecha real de la fila (no el filtro activo) para soportar búsqueda cross-date
    const fecha = $tr.closest('table').data('fechatabla') || ((modo==='prog')?$('#filtroFechaProg').val():$('#filtroFechaReag').val());
    const key=`${modo}|${fecha}|${id}`;
    if ($(this).is(':checked')) window.excluded.delete(key); else window.excluded.add(key); saveExcluded(); updateCounts();
    const pos = window.ejecutorMarker?.getPosition(); if (isMapVisible && pos && !(window.navigator3D && window.navigator3D.active)) window.debouncedPlanRoute(pos.toJSON()); logRouteEvent({trigger:'checkbox'});
  });

 setMode(window.modoLocal || loadMode() || 'prog'); // arranca con modo recordado
  setTimeout(()=>$('#filtroFechaProg').trigger('change'), 200);
};

// ======= Navegación 3D compacta + HUD =======
(function(){
    let navigator3D=null; let navSteps=[]; let navLastPos=null;

    function escapeHtmlLite(s){ return String(s==null?'':s).replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    // Etiqueta legible del local (código · cadena) a partir de su fila en la tabla.
    function localLabel(idLocal){
      const $tr = $(`tr[data-idlocal="${idLocal}"]`).first();
      if(!$tr.length) return null;
      const $tds = $tr.find('td');
      const codigo = $tds.eq(0).text().trim();
      const cadena = $tds.eq(1).clone().children().remove().end().text().trim(); // solo texto, sin iconos/badges
      return [codigo, cadena].filter(Boolean).join(' · ') || null;
    }

    function setChip($el, html, cls){
      if(!$el.length) return;
      $el.removeClass('nav-chip--ok nav-chip--warn nav-chip--bad');
      if(cls) $el.addClass(cls);
      $el.html(html);
    }

    // Actualiza solo los nodos de texto del HUD (sin reconstruir innerHTML por tick).
    function renderNavHud(){
      const nav = window.navigator3D;
      if(!nav || !nav.active) return;
      const U = NavEngine.utils;
      const step = nav.getCurrentStep();
      const next = nav.getNextStep();

      $('#navPrimary').text(step ? (step.text || 'Sigue la vía') : 'Calculando…');
      $('#navIcon').html(`<i class="fa ${step ? U.getManeuverIcon(step.maneuver) : 'fa-location-arrow'}"></i>`);
      $('#navSecondary').text((step && navLastPos) ? U.formatDistance(U.haversine(navLastPos, step.end)) : '—');

      if(next){ $('#navNextNext').show().text('Después: ' + (next.text || '')); }
      else { $('#navNextNext').hide(); }

      $('#hudEta').text(nav.getETA().toLocaleTimeString('es-CL', { hour:'2-digit', minute:'2-digit' }));
      $('#hudRemain').text(U.formatDistance(nav.getRemainingDistance()));
      $('#hudTime').text(U.formatDuration(nav.getRemainingDuration()));

      const wp = nav.waypoints && nav.waypoints[nav.waypointIdx];
      let stopTxt = 'Destino final';
      if(wp){ stopTxt = (wp.idLocal && localLabel(wp.idLocal)) || ('Parada ' + (nav.waypointIdx + 1) + '/' + nav.waypoints.length); }
      setChip($('#navNextStop'), `<i class="fa fa-flag-checkered"></i> ${escapeHtmlLite(stopTxt)}`, '');
    }
    window.renderNavHud = renderNavHud;

    // Muestra/oculta el HUD y colapsa el bottom sheet durante la navegación.
    function showHud(show){
      $('#navHud').css('display', show ? 'block' : 'none');
      $('#panelInfoRuta').toggleClass('sheet-collapsed', !!show);
    }

    function ensureNav(){
      if(!navigator3D){
        navigator3D = new NavEngine.Navigator3D(window.mapa, {
          onRoute:(route, steps, isReroute)=>{
            window.plannedRoute = route; navSteps = steps||[];
            buildTrafficPolylines(window.mapa, route);
            renderIndicacionesFromRoute(route);
            renderNavHud();
            if(isReroute) speak('Ruta recalculada');
          },
          onPosition:(cur)=>{ navLastPos = cur; if(window.ARViewLite) ARViewLite.updatePosition(cur); renderNavHud(); },
          onStep:(idx, step)=>{ renderNavHud(); if(step && window.voiceEnabled){ window.speechSynthesis.cancel(); speak(step.text||''); } },
          onGpsStatus:(status)=>{
            const $g = $('#navGps');
            if(status==='weak') setChip($g, '<i class="fa fa-exclamation-triangle"></i> GPS débil', 'nav-chip--warn');
            else setChip($g, '<i class="fa fa-location-arrow"></i> GPS OK', 'nav-chip--ok');
          },
          // cameraTracking de nav_engine es la única fuente: alterna el botón Recentrar.
          onCameraTrackingChanged:(tracking)=>{ $('#btnRecenter').toggleClass('show', !tracking); },
          onStop:()=>{
            showHud(false);
            $('#btnRecenter').removeClass('show');
            if(window.ARViewLite) ARViewLite.stop();
            window.navigator3D = navigator3D; // active=false tras stop
          },
          onCamera:(cur, speed, heading)=>{
            if(!window.mapa) return;
            const zoom = speed>45 ? 16 : 17;
            window.mapa.moveCamera({ center: cur, zoom, tilt:55, heading: heading || 0 }); // rota con el avance
          }
        });
        window.navigator3D = navigator3D; // exponer para guards externos
      }
      return navigator3D;
    }

    $('#btnStartNav').on('click', async ()=>{
      if(!window.mapa){ mostrarToast('El mapa aún no está listo.', 'warn'); return; }
      const nav=ensureNav();
      const pts = (window.lastComputedRoute?.orderedPts?.length ? window.lastComputedRoute.orderedPts : collectCurrentPoints());
      const pos=window.ejecutorMarker?.getPosition();
      if(!pos || !pts.length){ mostrarToast('Necesitas al menos 1 parada y tu ubicación.', 'warn'); return; }
      const origin=pos.toJSON(); const destination=pts[pts.length-1]; const waypoints=pts.slice(0,-1);
      try{
        await nav.startFromSelection({ origin, destination, waypoints, optimize:window.optimizeOrder });
        navLastPos = origin;
        showHud(true);
        $('#btnRecenter').removeClass('show');
        renderNavHud();
      }
      catch(_){ mostrarToast('No se pudo iniciar la navegación.', 'error'); }
    });
    $('#btnExitNav').on('click', ()=>{ const nav=ensureNav(); nav.stop(); });
    $('#btnRecenter').on('click', ()=>{ const nav=window.navigator3D; if(nav) nav.recenter(); $('#btnRecenter').removeClass('show'); });
    $('#btnVozNav').on('click', function(){
      window.voiceEnabled = !window.voiceEnabled;
      $(this).find('i').attr('class', window.voiceEnabled ? 'fa fa-volume-up' : 'fa fa-volume-off');
      $('#btnVoz').toggleClass('btn-info', window.voiceEnabled);
      if(window.voiceEnabled) speak('Voz activada.'); else { try{ speechSynthesis.cancel(); }catch(_){} }
    });
    $('#btnSkipStop').on('click', ()=>{ const nav=window.navigator3D; if(nav && nav.active){ nav.skipCurrentStop(); } });

    // ---- Eventos de navegación → HUD + mensajes no bloqueantes ----
    window.addEventListener('nav:rerouting', ()=>{ setChip($('#navNet'), '<i class="fa fa-refresh fa-spin"></i> Recalculando…', 'nav-chip--warn'); mostrarToast('Recalculando ruta…', 'info'); });
    window.addEventListener('nav:rerouted', ()=>{ setChip($('#navNet'), '<i class="fa fa-road"></i> Ruta lista', ''); renderNavHud(); });
    window.addEventListener('nav:off_route', ()=>{ mostrarToast('Te saliste de la ruta. Recalculando si continúas…', 'warn'); });
    window.addEventListener('nav:gps_weak', ()=>{ mostrarToast('GPS débil: la posición puede ser imprecisa.', 'warn'); });
    window.addEventListener('nav:gps_denied', (e)=>{ mostrarToast((e.detail && e.detail.message) || 'Activa la ubicación para navegar.', 'error', 6000); });
    window.addEventListener('nav:arrived_destination', ()=>{ mostrarToast('Has llegado a tu destino final.', 'success', 6000); });
    window.addEventListener('nav:arrived_waypoint', (e)=>{
      const wp = e.detail && e.detail.waypoint;
      const id = wp && wp.idLocal;
      const lbl = id ? (localLabel(id) || ('local ' + id)) : 'la parada';
      const actions = [];
      if(id){
        const modo = window.modoLocal || 'prog';
        const modalId = (modo==='prog' ? '#responsiveProg' : '#responsiveReag') + id;
        actions.push({ label:'Gestionar', cls:'btn-info', fn:()=>{ $(modalId).modal('show'); } });
      }
      actions.push({ label:'Saltar', cls:'btn-default', fn:()=>{ const nav=window.navigator3D; if(nav) nav.skipCurrentStop(); } });
      mostrarToast('Llegaste a ' + lbl + '.', 'success', 9000, actions);
    });
  })();


// ======= Bottom sheet del panel de ruta =======
(function(){
  const sheet  = document.getElementById('panelInfoRuta');
  const handle = document.getElementById('sheetHandle');
  if(!sheet || !handle) return;
  const SNAPS = ['sheet-peek','sheet-half','sheet-full'];
  function curSnap(){ const i = SNAPS.findIndex(c=>sheet.classList.contains(c)); return i<0 ? 1 : i; }
  function setSnap(i){ i=Math.max(0,Math.min(SNAPS.length-1,i)); SNAPS.forEach(c=>sheet.classList.remove(c)); sheet.classList.add(SNAPS[i]); }
  setSnap(1); // "media" por defecto
  let startY=null, moved=0;
  handle.addEventListener('pointerdown', e=>{ startY=e.clientY; moved=0; try{ handle.setPointerCapture(e.pointerId); }catch(_){} });
  handle.addEventListener('pointermove', e=>{ if(startY!=null) moved=e.clientY-startY; });
  function end(){
    if(startY==null) return;
    const d=moved; startY=null; const i=curSnap();
    if(d < -25)      setSnap(i+1);                 // arrastrar arriba → expandir
    else if(d > 25)  setSnap(i-1);                 // arrastrar abajo → contraer
    else             setSnap((i+1)%SNAPS.length);  // tap → ciclar peek/half/full
  }
  handle.addEventListener('pointerup', end);
  handle.addEventListener('pointercancel', ()=>{ startY=null; });
})();

/* =====================================================================
   PANEL DE CAMPAÑAS PROGRAMADAS — filtros y acciones masivas
   Los filtros (texto / división) son SOLO visuales: ocultan <li> del panel
   para ayudar a ENCONTRAR una campaña entre muchas. Lo que oculta locales
   de la tabla y del mapa sigue siendo el tachado (clase .completed), que es
   lo que lee applyFilters(). Mantener esa separación es lo que hace el
   comportamiento predecible: un solo mecanismo decide qué se visita.
   Solo aplica a programadas; el panel de complementarias queda igual.
   ===================================================================== */
const CAMPS_PREF_KEY = 'v2_camps_ocultas';

function normCampTxt(s){
  // Minusculas y sin tildes, para que "promocion" encuentre "PROMOCION".
  // NFD separa la letra de su tilde; luego se descartan las marcas
  // diacriticas por rango (U+0300..U+036F). Se filtra por codigo en vez de
  // usar un regex con esos caracteres para que el archivo quede 100% ASCII
  // y no dependa de la codificacion con que se suba por FTP.
  return String(s || '').toLowerCase().normalize('NFD')
    .split('')
    .filter(function (ch) {
      var c = ch.charCodeAt(0);
      return c < 0x0300 || c > 0x036F;
    })
    .join('');
}
function $campsProg(){ return $('li.camp-prog'); }
function $campsProgVisibles(){ return $('li.camp-prog:not(.camp-filtrada)'); }

function guardarCampsOcultas(){
  savePref(CAMPS_PREF_KEY,
    $campsProg().filter('.completed').map((i, li) => String($(li).data('idcampana'))).get());
}

function restaurarCampsOcultas(){
  const ids = loadPref(CAMPS_PREF_KEY, []);
  if (!Array.isArray(ids) || !ids.length) return;

  // Se descartan IDs de campañas que ya no están en la ruta, para que la
  // preferencia no acumule basura de campañas terminadas.
  const vivos   = new Set($campsProg().map((i, li) => String($(li).data('idcampana'))).get());
  const limpios = ids.map(String).filter(id => vivos.has(id));

  $campsProg().each(function(){
    if (!limpios.includes(String($(this).data('idcampana')))) return;
    $(this).addClass('completed')
           .find('i').first().removeClass('fa-square-o').addClass('fa-check-square-o');
  });
  if (limpios.length !== ids.length) savePref(CAMPS_PREF_KEY, limpios);
}

function actualizarContadorCamps(){
  const total    = $campsProg().length;
  const visibles = $campsProgVisibles().length;
  const ocultas  = $campsProg().filter('.completed').length;

  $('#txtOcultarCamps').text(visibles === total ? 'Ocultar todas' : ('Ocultar ' + visibles));

  let txt = (visibles === total) ? (total + ' campañas') : (visibles + ' de ' + total);
  if (ocultas > 0) txt += ' · ' + ocultas + ' sin mostrar';
  $('#campContador').text(txt);
}

function filtrarPanelCampanas(){
  const q   = normCampTxt($('#filtroCampanaTexto').val()).trim();
  const div = String($('#filtroCampanaDivision').val() || '');

  $campsProg().each(function(){
    const $li   = $(this);
    const okTxt = !q   || normCampTxt($li.data('busqueda')).includes(q);
    const okDiv = !div || String($li.data('division')) === div;
    $li.toggleClass('camp-filtrada', !(okTxt && okDiv));
  });

  $('#campSinResultados').remove();
  // Solo si hay campañas pero ninguna calza: si el ejecutor no tiene campañas,
  // ya se muestra "No hay campañas programadas" y sobraría este mensaje.
  if ($campsProg().length > 0 && $campsProgVisibles().length === 0) {
    $('#campanasCollapse ul.todo').append(
      '<li id="campSinResultados" class="camp-sin-resultados">Ninguna campaña coincide con el filtro.</li>');
  }
  actualizarContadorCamps();
}

/** Aplica o quita el tachado a un conjunto de campañas. */
function setTachadoCamps($lis, tachar){
  $lis.each(function(){
    const $i = $(this).find('i').first();
    $(this).toggleClass('completed', tachar);
    if (tachar) $i.removeClass('fa-square-o').addClass('fa-check-square-o');
    else        $i.removeClass('fa-check-square-o').addClass('fa-square-o');
  });
  guardarCampsOcultas();
  actualizarContadorCamps();
  applyFilters();   // una sola vez al final: dentro del bucle serían N pasadas
}

// ======= Wire-up básico =======
$(document).ready(function(){
  setTimeout(()=>$('#success-alert').fadeOut('slow'),3000);
  $('#filtroFechaProg, #filtroFechaReag').off('change').on('change', function(){ applyFilters(); });
  $(document).on('click', '.todo-actions', function(){
    const $li = $(this).closest('li');
    // .find('i').first() sobre el propio <a>: antes se tomaban TODOS los <i> del
    // <li>, así que en campañas con "Recepción de materiales" el ícono de cubos
    // también recibía las clases de checkbox y se veía mal.
    const $i = $(this).find('i').first();
    $li.toggleClass('completed');
    $i.toggleClass('fa-square-o fa-check-square-o');
    if ($li.hasClass('camp-prog')) { guardarCampsOcultas(); actualizarContadorCamps(); }
    applyFilters();
  });

  // Panel de campañas programadas: restaurar preferencia + filtros + masivos
  restaurarCampsOcultas();
  $('#filtroCampanaTexto').on('input', debounce(filtrarPanelCampanas, 150));
  $('#filtroCampanaDivision').on('change', filtrarPanelCampanas);
  $('#btnOcultarCamps').on('click', function(){ setTachadoCamps($campsProgVisibles(), true); });
  $('#btnMostrarCamps').on('click', function(){ setTachadoCamps($campsProg(), false); });
  filtrarPanelCampanas();
  // Fix #2: listeners de modo solo aquí — eliminados los duplicados que estaban dentro de initMap()
  $('#btnVerReagendados').on('click', function(){ $('#filtroLocalesReag').val(''); setMode('reag'); });
  $('#btnVerProgramados').on('click', function(){ $('#filtroLocalesProg').val(''); setMode('prog'); });
  $('#modalMapa').on('show.bs.modal', function(){
    ensureMapReady().catch(()=>{
      mostrarToast('No se pudo cargar Google Maps. Reintentaremos cuando haya conexión.', 'error', 6000);
    });
  });
  // filtros de texto -> aplica filtros completos
  $('#filtroLocalesProg, #filtroLocalesReag').on('input', debounce(()=>applyFilters(),200));
  // inicia
  window.modoLocal='prog'; setTimeout(applyFilters, 500);
});


    
    
</script>

<script src="assets/js/db.js"></script>
<script src="assets/js/offline-queue.js"></script>
<script src="assets/js/v2_cache.js"></script>
<script src="assets/js/bootstrap_index_cache.js"></script>
<script src="assets/js/journal_db.js"></script>
<script src="assets/js/journal_ui.js"></script>
<script>
  window.__GESTIONAR_PRECACHE_TARGETS = <?php echo json_encode($precacheTargets, JSON_UNESCAPED_UNICODE); ?>;
  window.__GESTIONAR_PRECACHE_LIMIT   = <?php echo (int)$precacheLimit; ?>;
  window.__GESTIONAR_PRECACHE_USER    = <?php echo (int)$usuario_id; ?>;
  window.vendedoresDisponibles = <?php echo json_encode($vendedoresDisponibles, JSON_UNESCAPED_UNICODE); ?>;
</script>

<script>
function localYmd(d){
  const dt = d instanceof Date ? d : new Date();
  const y = dt.getFullYear();
  const m = String(dt.getMonth()+1).padStart(2,'0');
  const da = String(dt.getDate()).padStart(2,'0');
  return y + '-' + m + '-' + da;
}

(function(){
  function buildGestionarUrl(t){
    if (!t || !t.idCampana || !t.idLocal || !t.idUsuario) return null;
    const params = new URLSearchParams({
      idCampana: String(t.idCampana),
      nombreCampana: String(t.nombreCampana || ''),
      idLocal: String(t.idLocal),
      idUsuario: String(t.idUsuario)
    });
    return `/visibility2/app/gestionarPruebas.php?${params.toString()}`;
  }

  function precacheTargets(){
    const list = Array.isArray(window.__GESTIONAR_PRECACHE_TARGETS) ? window.__GESTIONAR_PRECACHE_TARGETS : [];
    const limit = Number(window.__GESTIONAR_PRECACHE_LIMIT || 0) || 0;
    if (!list.length || !limit) return;
    const urls = list.slice(0, limit).map(buildGestionarUrl).filter(Boolean);
    if (!urls.length) return;
    if (navigator.serviceWorker && navigator.serviceWorker.controller) {
      navigator.serviceWorker.controller.postMessage({ type: 'PRECACHE_ASSETS', assets: urls });
    } else if (navigator.serviceWorker && navigator.serviceWorker.ready) {
      navigator.serviceWorker.ready.then((reg) => {
        const sw = reg.active || reg.waiting || reg.installing;
        if (sw) sw.postMessage({ type: 'PRECACHE_ASSETS', assets: urls });
      }).catch(()=>{});
    }
  }

  if ('serviceWorker' in navigator) {
    if (document.readyState === 'complete') precacheTargets();
    else window.addEventListener('load', precacheTargets);
  }
})();


(function(){
    
  async function applyGestionOutcomeLocally(ymd, localId, estadoFinal, fechaReagendada) {
    if (!window.V2Cache || !V2Cache.route) return;

    var estado = String(estadoFinal || '').toLowerCase();
    var id     = Number(localId);
    if (!id) return;

    var esCerrado =
      /implementado/.test(estado) ||
      /auditado/.test(estado) ||
      /cancelado/.test(estado);

    var esPendiente = /pendiente/.test(estado);

    try {
      if (esCerrado) {
        // Desaparece de la agenda local
        await (V2Cache.route.hideLocalForDate?.(ymd, id) || Promise.resolve());
      } else if (esPendiente) {
        // Va a reagendados (usa fecha nueva si viene del servidor)
        var nuevaFecha = fechaReagendada || ymd;
        await (V2Cache.route.markReagendadoForDate?.(ymd, nuevaFecha, id) || Promise.resolve());
      }
    } catch(_){}
  }

  window.addEventListener('queue:dispatch:success', async function(e){
    var job  = (e && e.detail && (e.detail.job || e.detail)) || null;
    var resp = (e && e.detail && e.detail.response) || {};
    if (!job) return;

    var f      = job.fields || {};
    var lid    = job.meta?.local_id || f.id_local || f.idLocal || null;
    var estado = (job.meta && job.meta.estado_final) ||
                 resp.estado_final ||
                 resp.estado_gestion || '';

    if (!lid) return;

    var ymd = localYmd();

    // Aplica el mismo comportamiento histórico que tenía online
    await applyGestionOutcomeLocally(
      ymd,
      lid,
      estado,
      resp.fecha_propuesta || resp.fecha_reagendada || resp.fecha_visita || null
    );
    try {
      if (window.BootstrapIndex?.refreshToday) {
        BootstrapIndex.refreshToday();
      } else if (typeof window.renderProgramadosHoy === 'function') {
        renderProgramadosHoy();
      }
    } catch(_){}
  });
})();





(function(){
  // --- 0) Guardas de entorno ---
  if (!window.V2DB) { console.warn('V2DB no disponible aún (db.js). El modo offline del index se activará cuando esté.'); }

  // --- 1) Badges de red ---
  function setNetBadge(){
    var b = document.getElementById('netBadge');
    if(!b) return;
    if(navigator.onLine){ b.textContent='Online'; b.classList.add('is-online'); b.classList.remove('is-offline'); }
    else { b.textContent='Offline'; b.classList.add('is-offline'); b.classList.remove('is-online'); }
  }
  window.addEventListener('online', setNetBadge);
  window.addEventListener('offline', setNetBadge);
  setNetBadge();

  // --- Toggle colapso del queue panel ---
  (function(){
    var panel  = document.getElementById('queuePanel');
    var toggle = document.getElementById('queueToggle');
    if (!panel || !toggle) return;
    function applyState(collapsed){
      panel.classList.toggle('collapsed', collapsed);
      toggle.innerHTML = collapsed ? '&#9650;' : '&#9660;';
      toggle.title = collapsed ? 'Expandir' : 'Contraer';
    }
    // Arrancar colapsado por defecto
    applyState(true);
    toggle.addEventListener('click', function(e){
      e.stopPropagation();
      var isNowCollapsed = !panel.classList.contains('collapsed');
      applyState(isNowCollapsed);
    });
    // Click en el panel (fuera del toggle) también expande
    panel.addEventListener('click', function(e){
      if (e.target === toggle || toggle.contains(e.target)) return;
      if (panel.classList.contains('collapsed')) applyState(false);
    });
  })();

  async function updateQueuePanel(){
  const panel = document.getElementById('queuePanel');
  if (!panel) return;
  const countEl = document.getElementById('queueCount');
  const detailEl = document.getElementById('queueDetail');

  if (!window.Queue || !window.AppDB || typeof Queue.getStatus !== 'function') {
    if (countEl) countEl.textContent = 'Cola: -';
    if (detailEl) detailEl.textContent = 'Cola no disponible.';
    return;
  }

  try {
    const st = await Queue.getStatus();
    const pending = Number(st.pending || 0);
    const running = Number(st.running || 0);
    const error = Number(st.error || 0);
    if (countEl) countEl.textContent = 'Cola: ' + pending;
    const parts = [];
    parts.push('Pendientes: ' + pending);
    parts.push('Enviando: ' + running);
    parts.push('Errores: ' + error);
    if (st.blocked === 'auth') parts.push('Bloqueada por sesi n');
    if (st.blocked === 'csrf') parts.push('Bloqueada por CSRF');

    let typeLine = '';
    try {
      const jobs = [];
      if (typeof Queue.listPending === 'function') {
        const p = await Queue.listPending();
        if (Array.isArray(p)) jobs.push(...p);
      }
      if (typeof Queue.listRunning === 'function') {
        const r = await Queue.listRunning();
        if (Array.isArray(r)) jobs.push(...r);
      }

      if (jobs.length) {
        const counts = { encuesta: 0, material: 0, gestion: 0, visita: 0, otros: 0 };
        jobs.forEach(j => {
          const kind = (j && j.meta && j.meta.kind) ? String(j.meta.kind) : String(j.type || j.url || '');
          const k = kind.toLowerCase();
          const photoCount = Array.isArray(j && j.files) ? j.files.length : 0;
          if (k.includes('pregunta_foto')) counts.encuesta += (photoCount || 1);
          else if (k.includes('upload_material')) counts.material += (photoCount || 1);
          else if (k.includes('procesar_gestion')) counts.gestion += 1;
          else if (k.includes('create_visita')) counts.visita += 1;
          else counts.otros += 1;
        });
        const detailParts = [];
        if (counts.encuesta) detailParts.push('Encuesta: ' + counts.encuesta);
        if (counts.material) detailParts.push('Material: ' + counts.material);
        if (counts.gestion) detailParts.push('Gestiones: ' + counts.gestion);
        if (counts.visita) detailParts.push('Visitas: ' + counts.visita);
        if (counts.otros) detailParts.push('Otros: ' + counts.otros);
        if (detailParts.length) typeLine = detailParts.join(', ');
      }
    } catch (_) {
      typeLine = '';
    }

    if (detailEl) detailEl.textContent = parts.join('   ') + (typeLine ? ' | ' + typeLine : '');
  } catch (e) {
    if (detailEl) detailEl.textContent = 'No se pudo leer estado de cola.';
  }
}

  function wireQueuePanel(){
    const retryBtn = document.getElementById('queueRetryBtn');
    const clearBtn = document.getElementById('queueClearBtn');
    if (retryBtn) {
      retryBtn.addEventListener('click', async () => {
        retryBtn.disabled = true;
        try { await Queue.flushNow(); } catch(_){ }
        setTimeout(updateQueuePanel, 500);
        retryBtn.disabled = false;
      });
    }
    if (clearBtn) {
      clearBtn.addEventListener('click', async () => {
        const ok = confirm('Esto limpiara el historial de colas finalizadas.  Continuar?');
        if (!ok) return;
        try { await AppDB.cleanup(0); } catch(_){ }
        setTimeout(updateQueuePanel, 200);
      });
    }
  }

  window.addEventListener('queue:update', updateQueuePanel);
  window.addEventListener('queue:blocked', updateQueuePanel);
  window.addEventListener('queue:unblocked', updateQueuePanel);
  window.addEventListener('queue:enqueue', updateQueuePanel);
  window.addEventListener('queue:enqueued', updateQueuePanel);
  window.addEventListener('queue:dispatch:success', updateQueuePanel);
  window.addEventListener('queue:dispatch:error', updateQueuePanel);
  window.addEventListener('online', updateQueuePanel);
  window.addEventListener('offline', updateQueuePanel);

  document.addEventListener('DOMContentLoaded', () => {
    wireQueuePanel();
    updateQueuePanel();
  });

  // --- 2) Pintado de listas desde IndexedDB ---
  function paintList(sel, rows){
    const cont = document.querySelector(sel);
    if(!cont) return;
    if(!rows || !rows.length){
      cont.innerHTML = '<div class="empty-state"><i class="fa fa-cloud-download"></i> No hay datos locales.</div>';
      return;
    }
    cont.innerHTML = '<div class="route-list"></div>';
    const list = cont.querySelector('.route-list');

    rows.forEach(r=>{
      // r esperado: { id_local, nombre_local, direccion, comuna, campanasIds:[], estado, hoy }
      const idCampana = (r.campanasIds && r.campanasIds[0]) ? r.campanasIds[0] : null;
      const href = idCampana
        ? '/visibility2/app/gestionarPruebas.php'
          + '?idCampana=' + encodeURIComponent(idCampana)
          + '&nombreCampana=' + encodeURIComponent(r.nombre_campana || '')
          + '&idLocal=' + encodeURIComponent(r.id_local)
          + '&idUsuario=' + encodeURIComponent(<?php echo (int)$usuario_id; ?>)
        : 'javascript:void(0)';

      const a = document.createElement('a');
      a.className='route-card';
      a.href = href;
      if (!idCampana) { a.classList.add('disabled'); a.style.pointerEvents='none'; }

      a.innerHTML = `
        <h4 class="route-card__title">${r.nombre_local || ('Local #'+r.id_local)}</h4>
        <p class="route-card__subtitle">${[r.direccion, r.comuna].filter(Boolean).join(' · ')}</p>
        <div class="route-card__meta">
          <span class="chip chip--${r.estado === 'reagendado' ? 'reagendado':'programado'}">
            <i class="fa fa-calendar"></i>${r.estado || 'programado'}
          </span>
          ${r.hoy ? '<span class="chip chip--hoy"><i class="fa fa-bolt"></i>Hoy</span>':''}
          <span class="chip chip--teal"><span class="badge-dot badge-dot--cached"></span>cache</span>
        </div>`;
      list.appendChild(a);
    });
  }

  async function renderRouteFromIDB(){
    try{
      if (!window.V2DB || !V2DB.route){ return; }
      await V2DB.ready();
      const programados = await V2DB.route.getAll('programados');
      const reagendados = await V2DB.route.getAll('reagendados');
      paintList('#routeProgramados', programados);
      paintList('#routeReagendados', reagendados);
      const meta = await V2DB.meta.get('lastSync');
      const el = document.getElementById('lastSyncBadge');
      if (el) el.textContent = meta?.value || '-';
    }catch(e){ console.warn('renderRouteFromIDB error:', e); }
  }

  // --- 3) Sembrado inicial (tus datos PHP del render actual → IDB) ---
  async function seedFromPHP(){
    try{
      if (!window.V2DB || !V2DB.route){ return; }
      await V2DB.ready();

      // PHP → JS
      const phpProgramados = <?php echo json_encode($locales, JSON_UNESCAPED_UNICODE); ?>;
      const phpReagendados = <?php echo json_encode($locales_reag, JSON_UNESCAPED_UNICODE); ?>;

      // Normalización mínima al formato esperado por IDB
      const norm = (rows, estado) => (rows||[]).map(r=>({
        id_local: r.idLocal,
        nombre_local: r.nombreLocal || (r.cadena ? (r.cadena+' - '+(r.direccionLocal||'')) : ''),
        direccion: r.direccionLocal || '',
        comuna: r.comuna || '',
        lat: r.latitud, lng: r.lng,
        campanasIds: r.campanasIds || [],
        estado: estado,
        hoy: r.fechaPropuesta === localYmd()
      }));

      const prog = norm(phpProgramados, 'programado');
      const reag = norm(phpReagendados, 'reagendado');

      // Insert/merge en IDB (no borra si ya existe; deja que sync los reemplace)
      if (prog.length) await V2DB.route.putMany('programados', prog);
      if (reag.length) await V2DB.route.putMany('reagendados', reag);
      await V2DB.meta.set('lastSync', new Date().toLocaleString('es-CL'));

    }catch(e){ console.warn('seedFromPHP error:', e); }
  }

  // --- 4) Sincronización con servidor (cuando hay red) ---
  async function syncBundle(){
    if(!navigator.onLine) return;
    try{
      const res = await fetch('/visibility2/app/api/sync_bundle.php', {
        method:'POST',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({
          empresa_id: <?php echo (int)$empresa_id; ?>,
          usuario_id: <?php echo (int)$usuario_id; ?>
        })
      });
      if(!res.ok) throw new Error('HTTP '+res.status);
      const bundle = await res.json();
      if (!window.V2DB || !V2DB.route){ return; }
      await V2DB.ready();

      await V2DB.tx(async t=>{
        await V2DB.route.clear('programados');
        await V2DB.route.clear('reagendados');
        await V2DB.route.putMany('programados', bundle.route?.programados || []);
        await V2DB.route.putMany('reagendados', bundle.route?.reagendados || []);
        // opcional: guardar otros catálogos si tu db.js los expone (locales, campanas, materiales, preguntas)
        await V2DB.meta.set('lastSync', new Date().toLocaleString('es-CL'));
      });

      await renderRouteFromIDB();
    }catch(e){ console.warn('sync_bundle falló:', e); }
  }

  // --- 5) Arranque ---
  (async function init(){
    // 1) Pintamos lo último guardado (si hubiese)
    await renderRouteFromIDB();
    // 2) Sembramos con lo que ya vino del server en este render
    await seedFromPHP();
    await renderRouteFromIDB();
    // 3) Si hay red, sincronizamos contra API (y repintamos)
    await syncBundle();
  })();

  // Re-sincroniza cuando vuelve la conectividad
  window.addEventListener('online', ()=>{ setNetBadge(); syncBundle(); });

})();

</script>

<!-- OFFLINE-FIRST: registro de Service Worker -->
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/visibility2/app/sw.js', { scope: '/visibility2/app/' })
    .then(function(reg){
      // Forzar activación inmediata de la nueva versión
      if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      reg.addEventListener('updatefound', function(){
        var nw = reg.installing;
        if (!nw) return;
        nw.addEventListener('statechange', function(){
          if (nw.state === 'installed' && navigator.serviceWorker.controller) {
            reg.waiting && reg.waiting.postMessage({ type: 'SKIP_WAITING' });
          }
        });
      });
    })
    .catch(function(err){ console.error('SW register error:', err); });
}
</script>



<!-- ═══════════════════════════════════════════════════════════
     MODAL REPORTAR DIRECCIÓN INCORRECTA
════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalReportarDir" tabindex="-1" role="dialog" aria-labelledby="modalReportarDirLabel">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#1f2d3d;color:#fff">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="modalReportarDirLabel">
                    <i class="fa fa-map-marker"></i> Reportar dirección incorrecta
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:13px">
                    <strong id="rdNombreLocal"></strong><br>
                    Dirección actual: <span id="rdDirActual" style="font-style:italic"></span>
                </p>

                <div class="form-group">
                    <label style="font-weight:700">Nueva dirección</label>
                    <div class="input-group">
                        <input type="text" id="rdDirNueva" class="form-control" placeholder="Ej: Av. Providencia 1234, Santiago">
                        <span class="input-group-btn">
                            <button class="btn btn-default" type="button" onclick="rdGeocodeDir()">
                                <i class="fa fa-search"></i> Buscar
                            </button>
                        </span>
                    </div>
                </div>

                <div id="rdMiniMap" style="height:260px;width:100%;border-radius:10px;border:1px solid #ddd;margin-bottom:12px"></div>

                <div class="row">
                    <div class="col-xs-6">
                        <label style="font-weight:700;font-size:13px">Latitud</label>
                        <input type="number" id="rdLat" class="form-control input-sm" step="0.0000001" readonly>
                    </div>
                    <div class="col-xs-6">
                        <label style="font-weight:700;font-size:13px">Longitud</label>
                        <input type="number" id="rdLng" class="form-control input-sm" step="0.0000001" readonly>
                    </div>
                </div>

                <div id="rdDistancia" class="text-muted" style="font-size:12px;margin-top:8px"></div>
                <div id="rdError" class="text-danger" style="font-size:13px;margin-top:6px;display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="rdBtnEnviar" onclick="rdEnviarSolicitud()">
                    <i class="fa fa-paper-plane"></i> Enviar solicitud
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var rdIdLocal     = 0;
    var rdLatActual   = 0;
    var rdLngActual   = 0;
    var rdMap         = null;
    var rdMarker      = null;
    var rdGeocoder    = null;

    function rdHaversine(lat1, lng1, lat2, lng2) {
        var R = 6371;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat/2)*Math.sin(dLat/2) +
                Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*
                Math.sin(dLng/2)*Math.sin(dLng/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    function rdActualizarDistancia() {
        var lat = parseFloat(document.getElementById('rdLat').value);
        var lng = parseFloat(document.getElementById('rdLng').value);
        if (!lat || !lng) return;
        var km = rdHaversine(rdLatActual, rdLngActual, lat, lng);
        document.getElementById('rdDistancia').textContent =
            'Distancia desde dirección actual: ' + km.toFixed(2) + ' km' +
            (km > 5 ? '  ⚠️ (será marcada como sospechosa)' : '');
    }

    function rdInitMap() {
        loadGoogleMapsSdk().then(function (maps) {
            var center = { lat: rdLatActual, lng: rdLngActual };
            rdMap = new maps.Map(document.getElementById('rdMiniMap'), {
                center: center,
                zoom: 16,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            });
            rdMarker = new maps.Marker({ position: center, map: rdMap, draggable: true, title: 'Arrastrar para ajustar' });
            rdGeocoder = new maps.Geocoder();

            rdMarker.addListener('dragend', function (ev) {
                document.getElementById('rdLat').value = ev.latLng.lat().toFixed(7);
                document.getElementById('rdLng').value = ev.latLng.lng().toFixed(7);
                rdActualizarDistancia();
            });
        }).catch(function () {
            document.getElementById('rdMiniMap').innerHTML = '<p class="text-danger text-center" style="padding:20px">No se pudo cargar el mapa.</p>';
        });
    }

    window.rdGeocodeDir = function () {
        var dir = document.getElementById('rdDirNueva').value.trim();
        if (!dir || !rdGeocoder) return;
        rdGeocoder.geocode({ address: dir + ', Chile' }, function (results, status) {
            if (status === 'OK' && results[0]) {
                var loc = results[0].geometry.location;
                var lat = loc.lat(), lng = loc.lng();
                document.getElementById('rdLat').value = lat.toFixed(7);
                document.getElementById('rdLng').value = lng.toFixed(7);
                rdMap.setCenter({ lat: lat, lng: lng });
                rdMarker.setPosition({ lat: lat, lng: lng });
                rdActualizarDistancia();
            } else {
                document.getElementById('rdError').textContent = 'No se encontró la dirección. Intenta ser más específico.';
                document.getElementById('rdError').style.display = 'block';
                setTimeout(function(){ document.getElementById('rdError').style.display='none'; }, 3000);
            }
        });
    };

    window.abrirModalReportarDir = function (idLocal, nombre, dirActual, lat, lng) {
        rdIdLocal   = idLocal;
        rdLatActual = lat;
        rdLngActual = lng;

        document.getElementById('rdNombreLocal').textContent = nombre;
        document.getElementById('rdDirActual').textContent   = dirActual;
        document.getElementById('rdDirNueva').value          = '';
        document.getElementById('rdLat').value               = lat;
        document.getElementById('rdLng').value               = lng;
        document.getElementById('rdDistancia').textContent   = '';
        document.getElementById('rdError').style.display     = 'none';

        $('#modalReportarDir').modal('show');

        // Inicializar el mapa cuando el modal esté visible
        $('#modalReportarDir').one('shown.bs.modal', function () {
            if (!rdMap) {
                rdInitMap();
            } else {
                rdMap.setCenter({ lat: lat, lng: lng });
                rdMarker.setPosition({ lat: lat, lng: lng });
                google.maps.event.trigger(rdMap, 'resize');
            }
        });
    };

    window.rdEnviarSolicitud = function () {
        var dirNueva = document.getElementById('rdDirNueva').value.trim();
        var lat      = parseFloat(document.getElementById('rdLat').value);
        var lng      = parseFloat(document.getElementById('rdLng').value);

        if (!dirNueva) {
            document.getElementById('rdError').textContent = 'Ingresa la nueva dirección.';
            document.getElementById('rdError').style.display = 'block';
            return;
        }
        if (!lat || !lng) {
            document.getElementById('rdError').textContent = 'Ajusta la ubicación en el mapa.';
            document.getElementById('rdError').style.display = 'block';
            return;
        }

        document.getElementById('rdBtnEnviar').disabled = true;
        document.getElementById('rdBtnEnviar').innerHTML = '<i class="fa fa-spinner fa-spin"></i> Enviando...';
        document.getElementById('rdError').style.display = 'none';

        var fd = new FormData();
        fd.append('action',   'crear');
        fd.append('id_local', rdIdLocal);
        fd.append('dir_nueva', dirNueva);
        fd.append('lat_nueva', lat);
        fd.append('lng_nueva', lng);

        fetch('/visibility2/app/api/solicitud_cambio_local.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                var newLat = parseFloat(document.getElementById('rdLat').value);
                var newLng = parseFloat(document.getElementById('rdLng').value);
                var newDir = document.getElementById('rdDirNueva').value.trim();

                $('#modalReportarDir').modal('hide');

                // Mover el pin en el mapa inmediatamente
                var markerEntry = (window.markersProg && window.markersProg[rdIdLocal])
                               || (window.markersReag && window.markersReag[rdIdLocal]);
                if (markerEntry && window.google && window.google.maps) {
                    markerEntry.marker.setPosition({ lat: newLat, lng: newLng });
                    // Actualizar la posición de referencia para numeración y recálculo
                    if (markerEntry.originalIcon) {
                        markerEntry.marker.setIcon(markerEntry.originalIcon);
                    }
                }

                // Actualizar data-lat/data-lng en las filas de tabla y mostrar badge
                $('tr[data-idlocal="' + rdIdLocal + '"]').each(function() {
                    $(this).attr('data-lat', newLat).attr('data-lng', newLng);
                    // Añadir badge "En revisión" en celda de dirección si no existe
                    var $dirCell = $(this).find('td').filter(function() {
                        return $(this).text().trim().length > 0 && !$(this).find('input,button,a,.circulo').length;
                    }).first();
                    if ($dirCell.length && !$dirCell.find('.badge-geo-pendiente').length) {
                        $dirCell.append('<span class="badge-geo-pendiente" style="display:inline-block;background:#f59e0b;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap" title="Solicitud de cambio pendiente de revisión">📍 En revisión</span>');
                    }
                });

                // Recalcular ruta con la nueva posición
                var pos = window.ejecutorMarker && window.ejecutorMarker.getPosition();
                if (pos && typeof window.planRouteFromSelection === 'function' && window.isMapVisible !== false) {
                    window.planRouteFromSelection(pos.toJSON(), { trigger: 'geo_report', force: true });
                }

                var msg = 'Solicitud enviada. Tu ruta usará esta dirección hasta que sea revisada.';
                if (resp.sospechoso) {
                    msg += ' (marcada para revisión prioritaria)';
                }
                if (typeof mostrarToast === 'function') {
                    mostrarToast(msg, 'success');
                } else {
                    alert(msg);
                }
            })
            .catch(function() {
                document.getElementById('rdError').textContent = 'Error al enviar. Intenta nuevamente.';
                document.getElementById('rdError').style.display = 'block';
            })
            .finally(function() {
                document.getElementById('rdBtnEnviar').disabled = false;
                document.getElementById('rdBtnEnviar').innerHTML = '<i class="fa fa-paper-plane"></i> Enviar solicitud';
            });
    };

    // Tecla Enter en el campo de dirección dispara la búsqueda
    document.getElementById('rdDirNueva').addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); rdGeocodeDir(); }
    });
})();
</script>

<!-- ═══════════════════════════════════════════════════════════
     MODAL CAMBIAR VENDEDOR
════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalCambiarVendedor" tabindex="-1" role="dialog" aria-labelledby="modalCambiarVendedorLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#1f2d3d;color:#fff">
                <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.8">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="modalCambiarVendedorLabel">
                    <i class="fa fa-user"></i> Cambiar vendedor
                </h4>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:13px">
                    <strong id="cvNombreLocal"></strong><br>
                    Vendedor actual: <span id="cvVendedorActual" style="font-style:italic"></span>
                </p>

                <div class="form-group">
                    <label style="font-weight:700">Vendedor</label>
                    <select id="cvSelectVendedor" class="form-control"></select>
                </div>

                <div style="margin-bottom:10px">
                    <button type="button" class="btn btn-xs btn-default" onclick="cvSeleccionarNuevo()">
                        <i class="fa fa-plus-circle"></i> El vendedor no está en la lista — solicitar alta nueva
                    </button>
                </div>

                <div class="form-group" id="cvNuevoVendedorBox" style="display:none">
                    <div class="alert alert-info" style="padding:6px 10px;font-size:12px;margin-bottom:8px">
                        <i class="fa fa-info-circle"></i>
                        Esta solicitud será revisada por un editor, quien creará el vendedor si corresponde.
                    </div>
                    <label style="font-weight:700">Nombre del nuevo vendedor</label>
                    <input type="text" id="cvNuevoNombre" class="form-control" placeholder="Ej: Juan Pérez">
                </div>

                <div class="form-group">
                    <label style="font-weight:700">Teléfono <span class="text-muted">(opcional)</span></label>
                    <input type="text" id="cvTelefono" class="form-control" placeholder="+56 9 1234 5678">
                </div>

                <div id="cvError" class="text-danger" style="font-size:13px;margin-top:6px;display:none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="cvBtnEnviar" onclick="cvEnviarSolicitud()">
                    <i class="fa fa-paper-plane"></i> Enviar solicitud
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var cvIdLocal = 0;
    var cvTipo    = 'Prog';
    var cvOpcionesCargadas = false;

    function cvCargarOpciones() {
        var $select = $('#cvSelectVendedor');
        $select.empty();
        $select.append($('<option>').val('0').text('➕ Crear nuevo vendedor'));
        (window.vendedoresDisponibles || []).forEach(function (v) {
            var label = v.nombre + (v.telefono ? ' (' + v.telefono + ')' : '');
            $select.append($('<option>').val(v.id).attr('data-tel', v.telefono || '').attr('data-nombre', v.nombre).text(label));
        });
        cvOpcionesCargadas = true;
    }

    function cvAplicarSeleccion() {
        var val = $('#cvSelectVendedor').val();
        if (val === '0') {
            $('#cvNuevoVendedorBox').show();
            $('#cvNuevoNombre').val('');
        } else {
            $('#cvNuevoVendedorBox').hide();
            var tel = $('#cvSelectVendedor option:selected').attr('data-tel') || '';
            $('#cvTelefono').val(tel);
        }
    }

    window.cvSeleccionarNuevo = function () {
        $('#cvSelectVendedor').val('0');
        cvAplicarSeleccion();
        $('#cvNuevoNombre').focus();
    };

    $(document).on('change', '#cvSelectVendedor', cvAplicarSeleccion);

    window.abrirModalCambiarVendedor = function (payload) {
        if (!cvOpcionesCargadas) cvCargarOpciones();

        cvIdLocal = payload.idLocal;
        cvTipo    = payload.tipo;

        document.getElementById('cvNombreLocal').textContent   = payload.nombreLocal;
        document.getElementById('cvVendedorActual').textContent = payload.vendedorNombre || 'no aplica';
        document.getElementById('cvError').style.display = 'none';

        var $select = $('#cvSelectVendedor');
        if (payload.idVendedor && $select.find('option[value="' + payload.idVendedor + '"]').length) {
            $select.val(String(payload.idVendedor));
        } else {
            $select.val('0');
        }
        cvAplicarSeleccion();
        if ($select.val() !== '0') {
            $('#cvTelefono').val(payload.vendedorTelefono || '');
        }

        $('#modalCambiarVendedor').modal('show');
    };

    window.cvEnviarSolicitud = function () {
        var idVendedorNuevo = $('#cvSelectVendedor').val();
        var esNuevo = idVendedorNuevo === '0';
        var nombreNuevo = esNuevo
            ? document.getElementById('cvNuevoNombre').value.trim()
            : ($('#cvSelectVendedor option:selected').attr('data-nombre') || '');
        var telefonoNuevo = document.getElementById('cvTelefono').value.trim();

        if (esNuevo && !nombreNuevo) {
            document.getElementById('cvError').textContent = 'Ingresa el nombre del nuevo vendedor.';
            document.getElementById('cvError').style.display = 'block';
            return;
        }

        if (esNuevo) {
            var nombreNormalizado = nombreNuevo.trim().toLowerCase();
            var existente = (window.vendedoresDisponibles || []).find(function (v) {
                return String(v.nombre || '').trim().toLowerCase() === nombreNormalizado;
            });
            if (existente) {
                document.getElementById('cvError').textContent = 'Ya existe un vendedor con ese nombre: "' + existente.nombre + '". Selecciónalo de la lista en vez de crear uno nuevo.';
                document.getElementById('cvError').style.display = 'block';
                return;
            }
        }

        document.getElementById('cvBtnEnviar').disabled = true;
        document.getElementById('cvBtnEnviar').innerHTML = '<i class="fa fa-spinner fa-spin"></i> Enviando...';
        document.getElementById('cvError').style.display = 'none';

        var fd = new FormData();
        fd.append('action', 'crear');
        fd.append('id_local', cvIdLocal);
        fd.append('id_vendedor_nuevo', esNuevo ? 0 : idVendedorNuevo);
        fd.append('nombre_vendedor_nuevo', nombreNuevo);
        fd.append('telefono_nuevo', telefonoNuevo);

        fetch('/visibility2/app/api/solicitud_cambio_vendedor.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.ok) {
                    document.getElementById('cvError').textContent = resp.msg || 'Error al enviar.';
                    document.getElementById('cvError').style.display = 'block';
                    return;
                }

                $('#modalCambiarVendedor').modal('hide');

                var $h4 = $('#myModalLabel' + cvTipo + cvIdLocal);
                $h4.find('.cv-vendedor-texto').text(nombreNuevo);
                if (!$h4.find('.badge-vendedor-pendiente').length) {
                    $h4.find('.cv-vendedor-texto').after(
                        '<span class="badge-vendedor-pendiente" style="display:inline-block;background:#f59e0b;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap">🧑 En revisión</span>'
                    );
                }

                var msg = 'Solicitud enviada. El vendedor será actualizado cuando se revise.';
                if (resp.sospechoso) {
                    msg += ' (marcada para revisión prioritaria)';
                }
                if (typeof mostrarToast === 'function') {
                    mostrarToast(msg, 'success');
                } else {
                    alert(msg);
                }
            })
            .catch(function () {
                document.getElementById('cvError').textContent = 'Error al enviar. Intenta nuevamente.';
                document.getElementById('cvError').style.display = 'block';
            })
            .finally(function () {
                document.getElementById('cvBtnEnviar').disabled = false;
                document.getElementById('cvBtnEnviar').innerHTML = '<i class="fa fa-paper-plane"></i> Enviar solicitud';
            });
    };
})();
</script>

</body>
</html>
<?php
$conn->close();
?>











