<?php
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// 1) Funciones auxiliares
// -----------------------------------------------------------------------------
function fixUrl(string $url, string $base_url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;

    $url = ltrim($url, '/');
    $url = preg_replace('#^(visibility2/app/|app/)#i', '', $url);

    return rtrim($base_url, '/') . '/' . ltrim($url, '/');
}

function formatearFecha($f): string {
    return $f ? date('d/m/Y H:i:s', strtotime($f)) : '';
}

function cacheRemember(string $namespace, array $payload, int $ttl, callable $resolver) {
    $dir = __DIR__ . '/cache_mod_galeria';

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

function renderGaleriaTable(array $data, string $view): void {
    if ($view === 'implementacion'): ?>
        <table id="example"
               class="table table-sm table-bordered table-hover"
               cellspacing="0"
               width="100%">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
                    <th>#</th>
                    <th>Imagen</th>
                    <th>Campaña</th>
                    <th>Cód. Local</th>
                    <th>Local</th>
                    <th>Dirección</th>
                    <th>Material</th>
                    <th>Cadena</th>
                    <th>Cuenta</th>
                    <th>Usuario</th>
                    <th>Fecha</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($data)): ?>
                <?php foreach ($data as $r):
                    $usuarioSafe = preg_replace('/[^a-zA-Z0-9]/', '_', $r['usuario'] ?? '');
                    $inner       = $r['material'] ?? '';
                    $innerSafe   = preg_replace('/[^a-zA-Z0-9]/', '_', $inner);
                    $codigoSafe  = preg_replace('/[^a-zA-Z0-9]/', '_', $r['local_codigo'] ?? '');
                    $prefix      = trim("{$usuarioSafe}_{$innerSafe}_{$codigoSafe}", '_');
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   class="imgCheckbox"
                                   data-urls="<?= htmlspecialchars(implode('||', $r['photos']), ENT_QUOTES) ?>"
                                   data-prefix="<?= htmlspecialchars($prefix, ENT_QUOTES) ?>">
                        </td>
                        <td></td>
                        <td class="custom-img-cell">
                            <span class="badge-count"><?= (int)$r['photos_count'] ?></span>
                            <img src="<?= htmlspecialchars($r['thumbnail'] ?? '', ENT_QUOTES) ?>"
                                 class="thumbnail img-click"
                                 alt="Vista previa"
                                 title="Clic para ver fotos"
                                 data-local="<?= htmlspecialchars($r['local_nombre'] ?? 'Fotos del local', ENT_QUOTES) ?>"
                                 data-urls="<?= htmlspecialchars(implode('||', $r['photos']), ENT_QUOTES) ?>">
                        </td>
                        <td><?= htmlspecialchars($r['campaña_nombre'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['local_codigo'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['local_nombre'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['local_direccion'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['material'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['cadena_nombre'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['cuenta_nombre'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['usuario'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= formatearFecha($r['fechaVisita'] ?? null) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    <?php else: ?>
        <table id="example"
               class="table table-sm table-bordered table-hover"
               cellspacing="0"
               width="100%">
            <thead class="thead-light">
                <tr>
                    <th><input type="checkbox" id="selectAll"></th>
                    <th>#</th>
                    <th>Imagen</th>
                    <th>Campaña</th>
                    <th>Pregunta</th>
                    <th>Cód. Local</th>
                    <th>Local</th>
                    <th>Dirección</th>
                    <th>Cadena</th>
                    <th>Cuenta</th>
                    <th>Usuario</th>
                    <th>Fecha Subida</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($data)): ?>
                <?php foreach ($data as $r):
                    $usuarioSafe = preg_replace('/[^a-zA-Z0-9]/', '_', $r['usuario'] ?? '');
                    $inner       = $r['pregunta'] ?? '';
                    $innerSafe   = preg_replace('/[^a-zA-Z0-9]/', '_', $inner);
                    $codigoSafe  = preg_replace('/[^a-zA-Z0-9]/', '_', $r['local_codigo'] ?? '');
                    $prefix      = trim("{$usuarioSafe}_{$innerSafe}_{$codigoSafe}", '_');
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox"
                                   class="imgCheckbox"
                                   data-urls="<?= htmlspecialchars(implode('||', $r['photos']), ENT_QUOTES) ?>"
                                   data-prefix="<?= htmlspecialchars($prefix, ENT_QUOTES) ?>">
                        </td>
                        <td></td>
                        <td class="custom-img-cell">
                            <span class="badge-count"><?= (int)$r['photos_count'] ?></span>
                            <img src="<?= htmlspecialchars($r['thumbnail'] ?? '', ENT_QUOTES) ?>"
                                 class="thumbnail img-click"
                                 alt="Vista previa"
                                 title="Clic para ver fotos"
                                 data-local="<?= htmlspecialchars($r['local_nombre'] ?? 'Fotos del local', ENT_QUOTES) ?>"
                                 data-urls="<?= htmlspecialchars(implode('||', $r['photos']), ENT_QUOTES) ?>">
                        </td>
                        <td><?= htmlspecialchars($r['campaña_nombre'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['pregunta'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['local_codigo'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['local_nombre'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['local_direccion'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['cadena_nombre'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['cuenta_nombre'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars($r['usuario'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= formatearFecha($r['fechaSubida'] ?? null) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    <?php endif;
}

// -----------------------------------------------------------------------------
// 2) Includes
// -----------------------------------------------------------------------------
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/session_data.php';

// -----------------------------------------------------------------------------
// 3) Parámetros
// -----------------------------------------------------------------------------
$division_id       = (int)($_SESSION['division_id'] ?? 0);
$divisionLogin     = $division_id;
$division          = (int)($_GET['division'] ?? $division_id);
$subdivision       = (int)($_GET['subdivision'] ?? 0);
$region            = (int)($_GET['region'] ?? 0);
$zona              = (int)($_GET['zona'] ?? 0);
$distrito          = (int)($_GET['distrito'] ?? 0);
$comuna            = (int)($_GET['comuna'] ?? 0);
$usuarioFiltro     = (int)($_GET['usuario'] ?? 0);
$jefeVentaFiltro   = (int)($_GET['jefe_venta'] ?? 0);
$codigoLocalFiltro = trim($_GET['codigo_local'] ?? '');
$view              = in_array(trim($_GET['view'] ?? 'implementacion'), ['implementacion', 'encuesta'], true)
    ? trim($_GET['view'] ?? 'implementacion')
    : 'implementacion';

$preguntaFiltro   = trim($_GET['pregunta'] ?? '');
$start_date       = trim($_GET['start_date'] ?? '');
$end_date         = trim($_GET['end_date'] ?? '');
$filtrosAplicados = isset($_GET['filtrar']) && $_GET['filtrar'] === '1';
$base_url         = "https://visibility.cl/visibility2/app/";

// Fecha por defecto solo para implementación y solo al filtrar
if ($filtrosAplicados && $view === 'implementacion' && $start_date === '' && $end_date === '') {
    $today = date('Y-m-d');
    $start_date = $today;
    $end_date   = $today;
}

// Si no se aplicaron filtros, no carga nada
if (!$filtrosAplicados) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo '<div class="alert alert-info mb-0">Aplica filtros para cargar resultados.</div>';
    exit;
}

// -----------------------------------------------------------------------------
// 4) Construcción de filtros base
// -----------------------------------------------------------------------------
$where  = "1=1";
$params = [];
$types  = "";

// filtros comunes
if ($division > 0) {
    $where .= " AND f.id_division = ?";
    $types .= "i";
    $params[] = $division;
}

if ($subdivision > 0) {
    $where .= " AND f.id_subdivision = ?";
    $types .= "i";
    $params[] = $subdivision;
}

if ($region > 0) {
    $where .= " AND r.id = ?";
    $types .= "i";
    $params[] = $region;
}

if ($zona > 0) {
    $where .= " AND z.id = ?";
    $types .= "i";
    $params[] = $zona;
}

if ($distrito > 0) {
    $where .= " AND d.id = ?";
    $types .= "i";
    $params[] = $distrito;
}

if ($comuna > 0) {
    $where .= " AND co.id = ?";
    $types .= "i";
    $params[] = $comuna;
}

if ($usuarioFiltro > 0) {
    $where .= ($view === 'implementacion')
        ? " AND fv.id_usuario = ?"
        : " AND fqr.id_usuario = ?";
    $types .= "i";
    $params[] = $usuarioFiltro;
}

if ($jefeVentaFiltro > 0) {
    $where .= " AND l.id_jefe_venta = ?";
    $types .= "i";
    $params[] = $jefeVentaFiltro;
}

if ($codigoLocalFiltro !== '') {
    $where .= " AND l.codigo LIKE ?";
    $types .= "s";
    $params[] = '%' . $codigoLocalFiltro . '%';
}

$fieldFecha = ($view === 'implementacion') ? 'fq.fechaVisita' : 'fqr.created_at';

if ($start_date !== '') {
    $where .= " AND {$fieldFecha} >= ?";
    $types .= "s";
    $params[] = $start_date . ' 00:00:00';
}

if ($end_date !== '') {
    $where .= " AND {$fieldFecha} <= ?";
    $types .= "s";
    $params[] = $end_date . ' 23:59:59';
}

// filtro pregunta solo en encuesta
if ($view === 'encuesta' && $preguntaFiltro !== '') {
    $where .= " AND UPPER(TRIM(fq.question_text)) = ?";
    $types .= "s";
    $params[] = mb_strtoupper(trim($preguntaFiltro), 'UTF-8');
}

// -----------------------------------------------------------------------------
// 5) Query principal
// -----------------------------------------------------------------------------
if ($view === 'implementacion') {

    $sql = "
        SELECT
            MIN(fv.id) AS foto_id,
            GROUP_CONCAT(COALESCE(fv.url,'') SEPARATOR '||') AS urls,
            fq.material,
            fq.fechaVisita,
            ANY_VALUE(f.nombre) AS campaña_nombre,
            ANY_VALUE(l.codigo) AS local_codigo_completo,
            TRIM(SUBSTRING_INDEX(ANY_VALUE(l.codigo), '-', -1)) AS local_codigo,
            ANY_VALUE(l.nombre) AS local_nombre,
            ANY_VALUE(l.direccion) AS local_direccion,
            ANY_VALUE(co.comuna) AS comuna_nombre,
            ANY_VALUE(r.region) AS region_nombre,
            ANY_VALUE(d.nombre_distrito) AS distrito_nombre,
            ANY_VALUE(z.nombre_zona) AS zona_nombre,
            ANY_VALUE(c.nombre) AS cadena_nombre,
            ANY_VALUE(ct.nombre) AS cuenta_nombre,
            ANY_VALUE(jv.nombre) AS jefe_venta_nombre,
            ANY_VALUE(u.usuario) AS usuario
        FROM formularioQuestion fq
        INNER JOIN formulario f   ON f.id = fq.id_formulario
        INNER JOIN fotoVisita fv  ON fv.id_formularioQuestion = fq.id
        INNER JOIN local l        ON l.id = fq.id_local
        LEFT JOIN comuna co       ON co.id = l.id_comuna
        LEFT JOIN region r        ON r.id = co.id_region
        LEFT JOIN distrito d      ON d.id = l.id_distrito
        LEFT JOIN zona z          ON z.id = d.id_zona
        LEFT JOIN jefe_venta jv   ON jv.id = l.id_jefe_venta
        INNER JOIN cadena c       ON c.id = l.id_cadena
        INNER JOIN cuenta ct      ON ct.id = l.id_cuenta
        INNER JOIN usuario u      ON u.id = fv.id_usuario
        WHERE {$where}
          AND fq.fechaVisita IS NOT NULL
        GROUP BY u.id, l.id, fq.material, fq.fechaVisita
        ORDER BY fq.fechaVisita DESC
    ";

} else {

    $sql = "
        SELECT
            MIN(fqr.id) AS foto_id,
            GROUP_CONCAT(COALESCE(fqr.answer_text,'') SEPARATOR '||') AS urls,
            ANY_VALUE(fqr.created_at) AS fechaSubida,
            UPPER(TRIM(fq.question_text)) AS pregunta,
            ANY_VALUE(f.nombre) AS campaña_nombre,
            ANY_VALUE(l.codigo) AS local_codigo_completo,
            TRIM(SUBSTRING_INDEX(ANY_VALUE(l.codigo), '-', -1)) AS local_codigo,
            ANY_VALUE(l.nombre) AS local_nombre,
            ANY_VALUE(l.direccion) AS local_direccion,
            ANY_VALUE(co.comuna) AS comuna_nombre,
            ANY_VALUE(r.region) AS region_nombre,
            ANY_VALUE(d.nombre_distrito) AS distrito_nombre,
            ANY_VALUE(z.nombre_zona) AS zona_nombre,
            ANY_VALUE(c.nombre) AS cadena_nombre,
            ANY_VALUE(ct.nombre) AS cuenta_nombre,
            ANY_VALUE(jv.nombre) AS jefe_venta_nombre,
            ANY_VALUE(u.usuario) AS usuario
        FROM form_question_responses fqr
        INNER JOIN form_questions fq ON fq.id = fqr.id_form_question
        INNER JOIN formulario f      ON f.id = fq.id_formulario
        INNER JOIN local l           ON l.id = fqr.id_local
        LEFT JOIN comuna co          ON co.id = l.id_comuna
        LEFT JOIN region r           ON r.id = co.id_region
        LEFT JOIN distrito d         ON d.id = l.id_distrito
        LEFT JOIN zona z             ON z.id = d.id_zona
        LEFT JOIN jefe_venta jv      ON jv.id = l.id_jefe_venta
        INNER JOIN cadena c          ON c.id = l.id_cadena
        INNER JOIN cuenta ct         ON ct.id = l.id_cuenta
        INNER JOIN usuario u         ON u.id = fqr.id_usuario
        WHERE {$where}
          AND fq.id_question_type = 7
          AND COALESCE(TRIM(fqr.answer_text), '') <> ''
        GROUP BY fqr.id_usuario, fqr.id_local, fqr.id_form_question
        ORDER BY ANY_VALUE(fqr.created_at) DESC
    ";
}

// -----------------------------------------------------------------------------
// 6) Ejecutar + cachear resultados
// -----------------------------------------------------------------------------
$cachePayload = [
    'view'              => $view,
    'divisionLogin'     => $divisionLogin,
    'division'          => $division,
    'subdivision'       => $subdivision,
    'region'            => $region,
    'zona'              => $zona,
    'distrito'          => $distrito,
    'comuna'            => $comuna,
    'usuarioFiltro'     => $usuarioFiltro,
    'jefeVentaFiltro'   => $jefeVentaFiltro,
    'codigoLocalFiltro' => $codigoLocalFiltro,
    'preguntaFiltro'    => $preguntaFiltro,
    'start_date'        => $start_date,
    'end_date'          => $end_date
];

$data = [];

try {
    $data = cacheRemember('galeria_data_ajax_v1', $cachePayload, 300, function () use ($conn, $sql, $types, $params, $base_url) {
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
            $rawUrls = array_filter(explode('||', (string)($row['urls'] ?? '')));
            $fixed = [];

            foreach ($rawUrls as $u) {
                $f = fixUrl($u, $base_url);
                if ($f !== '') {
                    $fixed[] = $f;
                }
            }

            $row['photos'] = $fixed;
            $row['photos_count'] = count($fixed);
            $row['thumbnail'] = $fixed[0] ?? null;
            $rows[] = $row;
        }

        $stmt->close();

        return $rows;
    });
} catch (Throwable $e) {
    error_log('[ajax_galeria_table] ' . $e->getMessage());
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo '<div class="alert alert-danger mb-0">Ocurrió un error al cargar la tabla.</div>';
    exit;
}

// -----------------------------------------------------------------------------
// 7) Respuesta
// -----------------------------------------------------------------------------
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

renderGaleriaTable($data, $view);