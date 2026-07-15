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

function distanciaMetrosGal($lat1, $lng1, $lat2, $lng2): ?float {
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) return null;
    $lat1 = (float)$lat1; $lng1 = (float)$lng1;
    $lat2 = (float)$lat2; $lng2 = (float)$lng2;
    if (($lat1 == 0.0 && $lng1 == 0.0) || ($lat2 == 0.0 && $lng2 == 0.0)) return null;
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $R * atan2(sqrt($a), sqrt(1 - $a));
}

// Mismo radio que Seguimiento por Etapas (RADIO_OK_METROS)
function geoBadgeGal($fotoLat, $fotoLng, $localLat, $localLng, int $radioOk = 200): string {
    $d = distanciaMetrosGal($fotoLat, $fotoLng, $localLat, $localLng);
    if ($d === null) {
        return '<span class="badge badge-secondary">Sin datos GPS</span>';
    }
    $txt = $d >= 1000 ? number_format($d / 1000, 1, ',', '.') . ' km' : round($d) . ' m';
    if ($d > $radioOk) {
        return '<span class="badge badge-danger">&#9888; Fuera de rango (' . $txt . ')</span>';
    }
    return '<span class="badge badge-success">En rango (' . $txt . ')</span>';
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

function renderDupTables(array $intentos, array $sospechosas, bool $dedupActivo, string $base_url): void {
    if (!$dedupActivo): ?>
        <div class="alert alert-info">
            La detección de fotos duplicadas aún no está activa en esta base
            (faltan las migraciones 12–15 de <code>scripts/</code>).
        </div>
    <?php endif; ?>

    <p class="text-muted mb-3">
        Intentos de subir fotos duplicadas (bloqueados) y fotos marcadas como sospechosas para revisión.
        Clic en una miniatura para ampliar.
    </p>

    <h5 class="mb-2" style="font-weight:700;">
        <i class="fas fa-ban text-danger mr-1"></i>
        Intentos bloqueados (duplicado exacto) — <?= count($intentos) ?>
    </h5>
    <table class="table table-sm table-bordered table-hover js-dup-table mb-4" width="100%">
        <thead class="thead-light">
            <tr>
                <th>#</th>
                <th>Fecha intento</th>
                <th>Usuario</th>
                <th>Campaña</th>
                <th>Local intentado</th>
                <th>Pregunta / Material (intento)</th>
                <th>Tipo</th>
                <th class="no-sort">Foto original</th>
                <th>Local original</th>
                <th>Pregunta / Material (original)</th>
            </tr>
        </thead>
        <tbody>
        <?php $i = 1; foreach ($intentos as $r):
            $origUrl   = fixUrl($r['orig_url'] ?? '', $base_url);
            $localInt  = trim(($r['local_codigo'] ?? '') . ' ' . ($r['local_nombre'] ?? ''));
            $localOrig = trim(($r['orig_codigo'] ?? '') . ' ' . ($r['orig_nombre'] ?? ''));
            $ctxInt    = trim((string)($r['intento_contexto'] ?? ''));
            $ctxOrig   = trim((string)($r['orig_contexto'] ?? ''));
            ?>
            <tr>
                <td><?= $i ?></td>
                <td><?= formatearFecha($r['created_at'] ?? null) ?></td>
                <td><?= htmlspecialchars($r['usuario'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($r['campana_nombre'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($localInt !== '' ? $localInt : '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($ctxInt !== '' ? $ctxInt : '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($r['tipo'] ?? '', ENT_QUOTES) ?></td>
                <td class="custom-img-cell">
                    <?php if ($origUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($origUrl, ENT_QUOTES) ?>"
                             class="thumbnail img-click"
                             loading="lazy" decoding="async"
                             alt="Foto original"
                             title="Clic para ver la foto original"
                             data-local="<?= htmlspecialchars($localOrig !== '' ? $localOrig : 'Foto original', ENT_QUOTES) ?>"
                             data-urls="<?= htmlspecialchars($origUrl, ENT_QUOTES) ?>">
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= htmlspecialchars($localOrig !== '' ? $localOrig : '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($ctxOrig !== '' ? $ctxOrig : '—', ENT_QUOTES) ?></td>
            </tr>
        <?php $i++; endforeach; ?>
        </tbody>
    </table>

    <h5 class="mb-2" style="font-weight:700;">
        <i class="fas fa-clone text-warning mr-1"></i>
        Fotos sospechosas (posible duplicada) para revisión — <?= count($sospechosas) ?>
    </h5>
    <p class="text-muted mb-2" style="font-size:.85rem;">
        Cada fila muestra la foto marcada y la <b>foto similar</b> ya existente en otro local de la misma
        campaña con la que coincidió. Clic en cualquiera de las dos miniaturas para compararlas.
        La columna <b>Geo</b> cruza las coordenadas de la foto con la ubicación del local (radio 200 m).
    </p>
    <table class="table table-sm table-bordered table-hover js-dup-table" width="100%">
        <thead class="thead-light">
            <tr>
                <th>#</th>
                <th class="no-sort">Foto</th>
                <th class="no-sort">Foto similar</th>
                <th>Tipo</th>
                <th>Campaña</th>
                <th>Pregunta / Material</th>
                <th>Usuario</th>
                <th>Local</th>
                <th>Local foto similar</th>
                <th>Geo</th>
                <th>Fecha</th>
            </tr>
        </thead>
        <tbody>
        <?php $i = 1; foreach ($sospechosas as $r):
            $u      = fixUrl($r['url'] ?? '', $base_url);
            $refUrl = fixUrl($r['ref_url'] ?? '', $base_url);
            $localS = trim(($r['local_codigo'] ?? '') . ' ' . ($r['local_nombre'] ?? ''));
            $localR = trim(($r['ref_codigo'] ?? '') . ' ' . ($r['ref_nombre'] ?? ''));
            $ctxS   = trim((string)($r['contexto'] ?? ''));
            $par    = implode('||', array_filter([$u, $refUrl]));
            ?>
            <tr>
                <td><?= $i ?></td>
                <td class="custom-img-cell">
                    <?php if ($u !== ''): ?>
                        <img src="<?= htmlspecialchars($u, ENT_QUOTES) ?>"
                             class="thumbnail img-click"
                             loading="lazy" decoding="async"
                             alt="Foto sospechosa"
                             title="Clic para comparar con la foto similar"
                             data-local="<?= htmlspecialchars('Sospechosa: ' . ($localS !== '' ? $localS : 's/local') . ($localR !== '' ? ' vs ' . $localR : ''), ENT_QUOTES) ?>"
                             data-urls="<?= htmlspecialchars($par, ENT_QUOTES) ?>">
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="custom-img-cell">
                    <?php if ($refUrl !== ''): ?>
                        <img src="<?= htmlspecialchars($refUrl, ENT_QUOTES) ?>"
                             class="thumbnail img-click"
                             loading="lazy" decoding="async"
                             alt="Foto similar (referencia)"
                             title="Foto ya existente con la que coincidió — clic para comparar"
                             data-local="<?= htmlspecialchars('Similar en: ' . ($localR !== '' ? $localR : 's/local'), ENT_QUOTES) ?>"
                             data-urls="<?= htmlspecialchars(implode('||', array_filter([$refUrl, $u])), ENT_QUOTES) ?>">
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['tipo'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($r['campana_nombre'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($ctxS !== '' ? $ctxS : '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($r['usuario'] ?? '', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($localS !== '' ? $localS : '—', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($localR !== '' ? $localR : '—', ENT_QUOTES) ?></td>
                <td><?= geoBadgeGal($r['foto_lat'] ?? null, $r['foto_lng'] ?? null, $r['local_lat'] ?? null, $r['local_lng'] ?? null) ?></td>
                <td><?= formatearFecha($r['fecha'] ?? null) ?></td>
            </tr>
        <?php $i++; endforeach; ?>
        </tbody>
    </table>
<?php
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
$view              = in_array(trim($_GET['view'] ?? 'implementacion'), ['implementacion', 'encuesta', 'duplicados'], true)
    ? trim($_GET['view'] ?? 'implementacion')
    : 'implementacion';

// Scoping por división: solo MC (división 1) puede consultar otras divisiones;
// el resto queda fijo a la división de su sesión (el hidden del form ya la manda,
// pero no se puede confiar en el GET).
if ($divisionLogin !== 1 && $divisionLogin > 0) {
    $division = $divisionLogin;
}

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
// 4b) Vista "Duplicadas / Sospechosas" (cross-campaña)
//     - Intentos bloqueados (foto_duplicada_log): se muestra la foto ORIGINAL.
//     - Sospechosas (dup_flag=1) de material (fotoVisita) + encuesta
//       (form_question_photo_meta).
//     Guards de existencia para no fatalar si faltan migraciones 12/13/14/15.
//     Sin caché: es una vista de revisión y debe reflejar el estado actual.
// -----------------------------------------------------------------------------
if ($view === 'duplicados') {

    $colExists = function (string $tabla, string $col) use ($conn): bool {
        try {
            $r = $conn->query("SHOW COLUMNS FROM `$tabla` LIKE '" . $conn->real_escape_string($col) . "'");
            $ok = ($r && $r->num_rows > 0);
            if ($r) $r->close();
            return $ok;
        } catch (Throwable $e) { return false; }
    };
    $tblExists = function (string $tabla) use ($conn): bool {
        try {
            $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tabla) . "'");
            $ok = ($r && $r->num_rows > 0);
            if ($r) $r->close();
            return $ok;
        } catch (Throwable $e) { return false; }
    };

    // WHERE compartido: alias fijos f (formulario), l (local), co/r/d/z (geo);
    // columna de usuario y de fecha varían según la query.
    $dupWhere = function (string $userCol, string $dateCol) use (
        $division, $subdivision, $region, $zona, $distrito, $comuna,
        $usuarioFiltro, $jefeVentaFiltro, $codigoLocalFiltro, $start_date, $end_date
    ): array {
        $w = "1=1"; $t = ""; $p = [];
        if ($division > 0)        { $w .= " AND f.id_division = ?";    $t .= "i"; $p[] = $division; }
        if ($subdivision > 0)     { $w .= " AND f.id_subdivision = ?"; $t .= "i"; $p[] = $subdivision; }
        if ($region > 0)          { $w .= " AND r.id = ?";             $t .= "i"; $p[] = $region; }
        if ($zona > 0)            { $w .= " AND z.id = ?";             $t .= "i"; $p[] = $zona; }
        if ($distrito > 0)        { $w .= " AND d.id = ?";             $t .= "i"; $p[] = $distrito; }
        if ($comuna > 0)          { $w .= " AND co.id = ?";            $t .= "i"; $p[] = $comuna; }
        if ($usuarioFiltro > 0)   { $w .= " AND {$userCol} = ?";       $t .= "i"; $p[] = $usuarioFiltro; }
        if ($jefeVentaFiltro > 0) { $w .= " AND l.id_jefe_venta = ?";  $t .= "i"; $p[] = $jefeVentaFiltro; }
        if ($codigoLocalFiltro !== '') { $w .= " AND l.codigo LIKE ?"; $t .= "s"; $p[] = '%' . $codigoLocalFiltro . '%'; }
        if ($start_date !== '')   { $w .= " AND {$dateCol} >= ?";      $t .= "s"; $p[] = $start_date . ' 00:00:00'; }
        if ($end_date !== '')     { $w .= " AND {$dateCol} <= ?";      $t .= "s"; $p[] = $end_date . ' 23:59:59'; }
        return [$w, $t, $p];
    };

    $geoJoins = "
        LEFT JOIN comuna co  ON co.id = l.id_comuna
        LEFT JOIN region r   ON r.id  = co.id_region
        LEFT JOIN distrito d ON d.id  = l.id_distrito
        LEFT JOIN zona z     ON z.id  = d.id_zona
    ";

    $hasLog      = $tblExists('foto_duplicada_log');
    $hasDupMat   = $colExists('fotoVisita', 'dup_flag');
    $hasDupEnc   = $colExists('form_question_photo_meta', 'dup_flag')
                && $colExists('form_question_photo_meta', 'id_formulario');
    $dedupActivo = $hasLog || $hasDupMat || $hasDupEnc;

    $intentos    = [];
    $sospechosas = [];

    try {
        // A) Intentos bloqueados
        if ($hasLog) {
            $hasLogCtx   = $colExists('foto_duplicada_log', 'id_form_question');
            $selIntento  = $hasLogCtx ? "COALESCE(dl.material, fqAtt.question_text)" : "NULL";
            $joinIntento = $hasLogCtx ? "LEFT JOIN form_questions fqAtt ON fqAtt.id = dl.id_form_question" : "";
            [$w, $t, $p] = $dupWhere('dl.id_usuario', 'dl.created_at');
            $sql = "
                SELECT dl.created_at, dl.tipo, u.usuario,
                       f.nombre AS campana_nombre,
                       l.codigo AS local_codigo, l.nombre AS local_nombre,
                       {$selIntento} AS intento_contexto,
                       COALESCE(fv.url, fqpm.foto_url)  AS orig_url,
                       COALESCE(ol1.codigo, ol2.codigo) AS orig_codigo,
                       COALESCE(ol1.nombre, ol2.nombre) AS orig_nombre,
                       COALESCE(fqm.material, fqq.question_text) AS orig_contexto
                FROM foto_duplicada_log dl
                JOIN formulario f ON f.id = dl.id_formulario
                JOIN usuario u    ON u.id = dl.id_usuario
                LEFT JOIN local l ON l.id = dl.id_local
                {$geoJoins}
                {$joinIntento}
                LEFT JOIN fotoVisita fv ON dl.tipo = 'material' AND fv.id = dl.dup_ref_id
                LEFT JOIN local ol1 ON ol1.id = fv.id_local
                LEFT JOIN formularioQuestion fqm ON fqm.id = fv.id_formularioQuestion
                LEFT JOIN form_question_photo_meta fqpm ON dl.tipo = 'encuesta' AND fqpm.id = dl.dup_ref_id
                LEFT JOIN local ol2 ON ol2.id = fqpm.id_local
                LEFT JOIN form_question_responses fqr ON fqr.id = fqpm.resp_id
                LEFT JOIN form_questions fqq ON fqq.id = fqr.id_form_question
                WHERE {$w}
                ORDER BY dl.created_at DESC
                LIMIT 1000
            ";
            if ($st = $conn->prepare($sql)) {
                if ($t !== '') { $st->bind_param($t, ...$p); }
                $st->execute();
                $rs = $st->get_result();
                while ($row = $rs->fetch_assoc()) { $intentos[] = $row; }
                $st->close();
            }
        }

        // B) Sospechosas de MATERIAL (fotoVisita.dup_flag = 1)
        if ($hasDupMat) {
            [$w, $t, $p] = $dupWhere('fv.id_usuario', 'fq.fechaVisita');
            $sql = "
                SELECT 'material' AS tipo, fv.url, u.usuario,
                       f.nombre AS campana_nombre,
                       l.codigo AS local_codigo, l.nombre AS local_nombre,
                       fq.material AS contexto,
                       fq.fechaVisita AS fecha,
                       COALESCE(fv.fotoLat, fv.exif_lat) AS foto_lat,
                       COALESCE(fv.fotoLng, fv.exif_lng) AS foto_lng,
                       l.lat AS local_lat, l.lng AS local_lng,
                       fvr.url  AS ref_url,
                       lr.codigo AS ref_codigo, lr.nombre AS ref_nombre
                FROM fotoVisita fv
                JOIN formularioQuestion fq ON fq.id = fv.id_formularioQuestion
                JOIN formulario f ON f.id = fv.id_formulario
                JOIN usuario u    ON u.id = fv.id_usuario
                LEFT JOIN local l ON l.id = fv.id_local
                {$geoJoins}
                LEFT JOIN fotoVisita fvr ON fvr.id = fv.dup_ref_id
                LEFT JOIN local lr ON lr.id = fvr.id_local
                WHERE {$w} AND fv.dup_flag = 1
                ORDER BY fv.id DESC
                LIMIT 1000
            ";
            if ($st = $conn->prepare($sql)) {
                if ($t !== '') { $st->bind_param($t, ...$p); }
                $st->execute();
                $rs = $st->get_result();
                while ($row = $rs->fetch_assoc()) { $sospechosas[] = $row; }
                $st->close();
            }
        }

        // C) Sospechosas de ENCUESTA (form_question_photo_meta.dup_flag = 1)
        if ($hasDupEnc) {
            [$w, $t, $p] = $dupWhere('m.id_usuario', 'm.created_at');
            $sql = "
                SELECT 'encuesta' AS tipo, m.foto_url AS url, u.usuario,
                       f.nombre AS campana_nombre,
                       l.codigo AS local_codigo, l.nombre AS local_nombre,
                       fqq.question_text AS contexto,
                       m.created_at AS fecha,
                       m.exif_lat AS foto_lat,
                       m.exif_lng AS foto_lng,
                       l.lat AS local_lat, l.lng AS local_lng,
                       mr.foto_url AS ref_url,
                       lr.codigo AS ref_codigo, lr.nombre AS ref_nombre
                FROM form_question_photo_meta m
                JOIN formulario f ON f.id = m.id_formulario
                JOIN usuario u    ON u.id = m.id_usuario
                LEFT JOIN local l ON l.id = m.id_local
                {$geoJoins}
                LEFT JOIN form_question_responses fqr ON fqr.id = m.resp_id
                LEFT JOIN form_questions fqq ON fqq.id = fqr.id_form_question
                LEFT JOIN form_question_photo_meta mr ON mr.id = m.dup_ref_id
                LEFT JOIN local lr ON lr.id = mr.id_local
                WHERE {$w} AND m.dup_flag = 1
                ORDER BY m.id DESC
                LIMIT 1000
            ";
            if ($st = $conn->prepare($sql)) {
                if ($t !== '') { $st->bind_param($t, ...$p); }
                $st->execute();
                $rs = $st->get_result();
                while ($row = $rs->fetch_assoc()) { $sospechosas[] = $row; }
                $st->close();
            }
        }
    } catch (Throwable $e) {
        error_log('[ajax_galeria_table][duplicados] ' . $e->getMessage());
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo '<div class="alert alert-danger mb-0">Ocurrió un error al cargar la tabla.</div>';
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    renderDupTables($intentos, $sospechosas, $dedupActivo, $base_url);
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