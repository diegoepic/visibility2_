<?php

if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit();
}


require_once __DIR__ . '/lib/security_headers.php';
emit_security_headers();

/* Regla de visibilidad de ruta (qué asignaciones siguen pendientes). Vive en
   lib/dominio/ porque la comparten esta página y api/sync_bundle.php, y hasta la
   Tanda 6 estaba copiada a mano siete veces entre las dos — con las copias ya
   separadas. Ver el encabezado de ese archivo. */
require_once __DIR__ . '/lib/dominio/ruta_visibilidad.php';
$rutaPendiente = ruta_visibilidad_sql();

/* Ventana horaria por cuenta (a qué hora abre un local). Antes se decidía con
   stripos($tipo_cuenta,'botiller'), o sea una regla de negocio escrita como coincidencia
   de texto sobre el nombre de la cuenta. Ahora sale de cuenta.hora_apertura
   (scripts/37). Ver el encabezado de ese archivo. */
require_once __DIR__ . '/lib/dominio/ventana_horaria.php';

/* C-2: esta pagina nunca habia necesitado un token CSRF porque no enviaba nada. Ahora
   guarda la ruta planificada, asi que si. csrf_token() usa la misma clave de sesion que
   el token en linea de gestionarPruebas.php, o sea que son el mismo token. */
require_once __DIR__ . '/lib/csrf.php';
$colHoraApertura = ventana_horaria_col($conn, 'cu');

/* Aviso del cambio de acceso a RUT. Lo deja procesar_login.php SÓLO cuando la
   persona entró con su nombre de usuario, o sea cuando de verdad va a quedar
   afuera en la fecha de corte. Se consume acá (una vez por inicio de sesión):
   si sigue entrando con el usuario lo vuelve a ver, y deja de verlo cuando
   empieza a usar el RUT. */
$avisoRut = $_SESSION['aviso_login_rut'] ?? null;
unset($_SESSION['aviso_login_rut']);

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

// Map ID de Google Cloud. Sólo un mapa VECTORIAL (Map type: JavaScript, Rendering type: Vector,
// con Tilt y Rotation habilitados) admite inclinación y rotación de cámara; en un mapa raster
// moveCamera() ignora tilt/heading en silencio. Si está vacío, la navegación cae automáticamente
// a la versión 2D (recentrado suave + flecha de rumbo) sin ningún error.
$googleMapsMapId = getenv('GOOGLE_MAPS_MAP_ID');
$googleMapsMapId = is_string($googleMapsMapId) ? trim($googleMapsMapId) : '';
// Descartar los marcadores de posición del .env.sample. Si se cuela uno, Google recibe un
// Map ID inexistente y desactiva el mapa vectorial EN SILENCIO: se ve un mapa plano y el
// chip del HUD dice 2D sin explicar por qué. Mejor tratarlo como "no configurado".
if ($googleMapsMapId !== '' &&
    (stripos($googleMapsMapId, 'CAMBIAR') === 0 || $googleMapsMapId === 'YOUR_VECTOR_MAP_ID')) {
    error_log('index_pruebas: GOOGLE_MAPS_MAP_ID sigue con el valor de ejemplo; se ignora.');
    $googleMapsMapId = '';
}

// Versión de los assets propios. Va como ?v= en las etiquetas script y link para saltarse el
// cache HTTP del navegador. Vive en lib/asset_version.php porque ya no la usa sólo esta página:
// gestionarPruebas.php, gestionar.php, gestionarIW.php y login.php cargan de assets/vendor/.
// IMPORTANTE: al subirla hay que subir también VERSION en app/sw.js y SW_VERSION en
// assets/js/v2_cache.js al MISMO valor, o el service worker seguirá sirviendo los archivos viejos
// (sw.js usa cacheFirst sin revalidación para /visibility2/app).
require_once __DIR__ . '/lib/asset_version.php';
$assetVer = V2_ASSET_VER;

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
            {$rutaPendiente}
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
                {$rutaPendiente}
              ) THEN 1 END)        AS totalCampanas,
    GROUP_CONCAT(DISTINCT CASE WHEN (
                {$rutaPendiente}
              ) THEN f.id END) AS campanasIds,
    MAX(cu.nombre)              AS tipo_cuenta,
    MAX({$colHoraApertura})     AS hora_apertura,
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
                {$rutaPendiente}
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
        'is_botilleria'  => ventana_horaria_abre_tarde($row),
        'horaApertura'   => ventana_horaria_de_fila($row),
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
    MAX({$colHoraApertura}) AS hora_apertura,
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
        'is_botilleria'  => ventana_horaria_abre_tarde($row),
        'horaApertura'   => ventana_horaria_de_fila($row),
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
        'is_botilleria'  => $local['is_botilleria'],
        'horaApertura'   => $local['horaApertura'] ?? null
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
        'is_botilleria'  => $local['is_botilleria'],
        'horaApertura'   => $local['horaApertura'] ?? null
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
     <link rel="stylesheet" href="assets/css/journal.css?v=<?php echo rawurlencode($assetVer); ?>">
     <link rel="stylesheet" href="css/index.css?v=<?php echo rawurlencode($assetVer); ?>">
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
    <a href="recuperacion.php" id="queueFixLink" class="queue-fix" style="display:none"
       title="Ver los envíos con problemas">Revisar</a>
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
                                            data-horaapertura="<?php echo htmlspecialchars($row['horaApertura'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            class="<?php echo $trClass; ?>">
                                            <td class="center"><?php echo htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="center">
                                                <?php if ($esPrioridad) { ?>
                                                    <i class="fa fa-star priority-icon" title="Local prioritario"></i>
                                                <?php } ?>
                                                <?php echo htmlspecialchars($cadena, ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($esBotilleria): ?>
                                                    <span class="badge-botilleria" title="Esta cuenta abre a las <?php echo htmlspecialchars($row['horaApertura'] ?? '13:00', ENT_QUOTES, 'UTF-8'); ?> hrs"><i class="fa fa-clock-o"></i> <?php echo htmlspecialchars($row['horaApertura'] ?? '13:00', ENT_QUOTES, 'UTF-8'); ?></span>
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
                                            data-horaapertura="<?php echo htmlspecialchars($row['horaApertura'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            class="<?php echo $trClass; ?>">
                                             <td class="center"><?php echo htmlspecialchars($row['codigoLocal'], ENT_QUOTES, 'UTF-8'); ?></td>
                                             <td class="center">
                                                <?php if ($esPrioridad) { ?>
                                                    <i class="fa fa-star priority-icon" title="Local prioritario"></i>
                                                <?php } ?>
                                                <?php echo htmlspecialchars($cadena, ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($esBotilleria): ?>
                                                    <span class="badge-botilleria" title="Esta cuenta abre a las <?php echo htmlspecialchars($row['horaApertura'] ?? '13:00', ENT_QUOTES, 'UTF-8'); ?> hrs"><i class="fa fa-clock-o"></i> <?php echo htmlspecialchars($row['horaApertura'] ?? '13:00', ENT_QUOTES, 'UTF-8'); ?></span>
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
              <!-- La altura de esta cabecera la maneja css/index.css (#journalPanel):
                   el tema le fija 36px a toda .panel-heading y acá el contenido son
                   varias filas. No agregar height ni padding-top inline. -->
              <div class="panel-heading" style="display:flex; align-items:flex-start; flex-wrap:wrap; gap:10px;">
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

<?php if ($avisoRut): ?>
<!-- Aviso del cambio de acceso a RUT. Sale sólo a quien entró con su nombre de
     usuario (ver procesar_login.php); el que ya usa su RUT nunca lo ve.
     Va con backdrop estático: es información que no conviene descartar de un
     toque accidental al abrir la app. -->
<div id="modalAvisoRut" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modalAvisoRutLabel" aria-hidden="true" data-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:#f0ad4e;color:#fff;">
        <h4 class="modal-title" id="modalAvisoRutLabel">
          <i class="fa fa-id-card-o"></i> Cambia la forma de ingresar
        </h4>
      </div>
      <div class="modal-body" style="text-align:center;">
        <p style="font-size:16px;margin-bottom:14px;">
          <?php if ((int)$avisoRut['dias'] === 0): ?>
            Desde <b>hoy</b> el ingreso a Visibility es <b>solo con tu RUT</b>.
          <?php elseif ((int)$avisoRut['dias'] === 1): ?>
            Desde <b>mañana</b> el ingreso a Visibility será <b>solo con tu RUT</b>.
          <?php else: ?>
            A partir del <b><?php echo htmlspecialchars($avisoRut['corte'], ENT_QUOTES, 'UTF-8'); ?></b>
            —en <b><?php echo (int)$avisoRut['dias']; ?> días</b>— el ingreso a Visibility
            será <b>solo con tu RUT</b>.
          <?php endif; ?>
        </p>

        <div style="font-size:13px;letter-spacing:.12em;color:#777;text-transform:uppercase;">Tu RUT es</div>
        <div style="font-size:30px;font-weight:700;letter-spacing:2px;color:#2c3e50;margin:6px 0 4px;">
          <?php echo htmlspecialchars($avisoRut['rut'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div style="font-size:13px;color:#888;">sin puntos ni guion</div>

        <p style="margin-top:16px;color:#555;">
          Tu contraseña <b>no cambia</b>. La próxima vez, escribe tu RUT en vez
          de tu nombre de usuario.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-warning" data-dismiss="modal">Entendido</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

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
              <!-- Banner, chips y "Después:" van APILADOS en flujo normal dentro de .nav-top.
                   Antes cada uno estaba en position:absolute con un top fijo (10 / 64 / 96 px)
                   calculado para una instrucción de UNA línea. Con instrucciones largas
                   ("Dirígete al oeste por Vaitea hacia Río Clarillo. Calle de uso restringido")
                   el banner crecía y los chips se le montaban encima. Es la misma trampa que
                   el .panel-heading de altura fija del tema. -->
              <div class="nav-top">
                <div class="nav-banner">
                  <div class="nav-ic" id="navIcon"><i class="fa fa-location-arrow"></i></div>
                  <div style="flex:1;min-width:0;">
                    <div class="nav-main" id="navPrimary">Preparando navegación…</div>
                    <div class="nav-sub" id="navSecondary">—</div>
                  </div>
                </div>
                <div class="nav-chips" id="navChips">
                  <span class="nav-chip nav-chip--gps" id="navGps"><i class="fa fa-location-arrow"></i> GPS</span>
                  <span class="nav-chip" id="navNet"><i class="fa fa-road"></i> —</span>
                  <!-- Deja ver de un vistazo si el mapa está inclinando (vectorial) o plano (raster) -->
                  <span class="nav-chip" id="navVista" title="Tipo de vista del mapa">2D</span>
                  <span class="nav-chip" id="navNextStop"><i class="fa fa-flag-checkered"></i> —</span>
                </div>
                <div class="nav-nextnext" id="navNextNext" style="display:none;">Después: —</div>
              </div>
              <div class="nav-bottom">
                <div class="nav-progress"><div id="hudProgress" class="nav-progress-bar"></div></div>
                <button id="btnExitNav" class="btn btn-default btn-sm" title="Salir de la navegación"><i class="fa fa-times"></i> Salir</button>
                <div class="nav-stats">
                  <div><small>Llegada</small><span id="hudEta">—</span></div>
                  <div><small>Falta</small><span id="hudRemain">—</span></div>
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
              {$rutaPendiente}
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
  window.__GOOGLE_MAPS_MAP_ID  = "<?php echo htmlspecialchars($googleMapsMapId, ENT_QUOTES, 'UTF-8'); ?>";
  window.__ROUTES_PROXY_URL = "<?php echo htmlspecialchars($routesProxyUrl, ENT_QUOTES, 'UTF-8'); ?>";

  /* Datos que antes iban incrustados en el bloque grande de JavaScript y que por eso lo ataban a
     PHP. Se declaran aca para que ese bloque pueda vivir en assets/js/ruta_mapa.js (T6-6).
     Ojo: coordenadas* es la agenda del usuario. Se queda en la pagina, que ya la lleva
     embebida; el .js extraido no tiene ningun dato personal, y por eso SI se puede
     precachear sin repetir el problema de V-21. */
  /* C-2: guardar la ruta planificada.
     Sobre el token en una pagina PRECACHEADA: la copia del service worker solo se sirve sin
     red (networkFirst prueba la red primero), y sin red no se puede guardar nada, asi que el
     token viejo no llega nunca al servidor. Si llegara —red que vuelve con la pagina cargada
     de cache— la validacion CSRF lo rechaza y el guardado se salta en silencio, que es el
     comportamiento buscado. Ver la nota de V-21 sobre que mas lleva embebido esta pagina.
     __RUTA_PLAN_URL vacia apaga el guardado sin desplegar codigo. */
  window.CSRF_TOKEN      = "<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>";
  window.__RUTA_PLAN_URL = "/visibility2/app/api/ruta_plan_guardar.php";

  window.__TEST_MODE  = <?php echo $TEST_MODE ? 'true' : 'false'; ?>;
  window.__COORD_PROG = <?php echo json_encode($coordenadas_locales_programados); ?>;
  window.__COORD_REAG = <?php echo json_encode($coordenadas_locales_reag); ?>;
</script>
<?php $v = '?v=' . rawurlencode($assetVer); ?>
<script src="assets/js/route_preferences.js<?php echo $v; ?>"></script>
<script src="assets/js/route_engine.js<?php echo $v; ?>"></script>
<script src="assets/js/route_selection.js<?php echo $v; ?>"></script>
<script src="assets/js/route_planner.js<?php echo $v; ?>"></script>
<!-- Antes de nav_engine: el motor usa VoiceController, y el shim de más abajo sólo se instala
     si este archivo no cargó (guarda `if (!window.VoiceController)`). -->
<script src="assets/js/voice_controller.js<?php echo $v; ?>"></script>
<script src="assets/js/nav_engine.js<?php echo $v; ?>"></script>
<script src="assets/js/nav_camera.js<?php echo $v; ?>"></script>

<script src="assets/js/ruta_mapa.js"></script>

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
  // Dueño de los trabajos que se encolen en esta sesión. offline-queue.js lo sella en
  // cada job y se niega a enviar los de otro usuario del mismo teléfono.
  window.__USUARIO_ID                 = <?php echo (int)$usuario_id; ?>;
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
    // La píldora cuenta TODO lo que todavía no llegó al servidor, no sólo lo encolado.
    // Antes mostraba sólo `pending`, así que un ejecutor con gestiones en error leía
    // "Cola: 0", cerraba su jornada tranquilo y se iba con trabajo sin subir.
    const sinResolver = pending + running + error;
    if (countEl) {
      countEl.textContent = error > 0
        ? 'Cola: ' + sinResolver + ' (' + error + ' con error)'
        : 'Cola: ' + sinResolver;
      countEl.classList.toggle('queue-has-error', error > 0);
    }
    // El contador honesto por sí solo no sirve de nada si el ejecutor no puede hacer nada
    // con el número. El enlace aparece sólo cuando hay algo atascado que revisar.
    const fixLink = document.getElementById('queueFixLink');
    if (fixLink) fixLink.style.display = (error > 0 || st.blockedAuth || st.blockedCsrf) ? '' : 'none';
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
        // El texto anterior ("historial de colas finalizadas") no era cierto: cleanup()
        // también borraba las gestiones en error, que son trabajo sin subir.
        const ok = confirm('Se borrara el historial de envios COMPLETADOS.\n\nLas gestiones con error se conservan: esas no se han subido todavia.\n\nContinuar?');
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

<script src="assets/js/modal_reportar_direccion.js"></script>

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

<script src="assets/js/modal_cambiar_vendedor.js"></script>

</body>
</html>
<?php
$conn->close();
?>











