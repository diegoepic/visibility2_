<?php
/**
 * =====================================================================
 * pf_totem_api/diagnostico.php
 * Revisa de una pasada toda la cadena tótem → servidor → base de datos
 * y dice exactamente qué falta. Se abre en el navegador:
 *
 *   https://visibility.cl/visibility2/pf_totem_api/diagnostico.php
 *
 * No muestra el token ni datos personales: sólo si las piezas están o no.
 * Borrar este archivo cuando termine la activación.
 * =====================================================================
 */
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$checks = [];
function chk(string $nombre, bool $ok, string $detalle, string $arreglo = ''): void {
    global $checks;
    $checks[] = ['nombre' => $nombre, 'ok' => $ok, 'detalle' => $detalle, 'arreglo' => $arreglo];
}

// ---------------------------------------------------------------- 1. conexión
$conn = null;
try {
    require_once __DIR__ . '/../app/con_.php';
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $conn->set_charset('utf8mb4');
        chk('Conexión a la base de datos', true, 'OK · servidor ' . $conn->server_info);
    } else {
        chk('Conexión a la base de datos', false, 'No se pudo conectar',
            'Revisa DB_HOST/DB_USER/DB_PASSWORD/DB_NAME en app/.env');
        $conn = null;
    }
} catch (Throwable $e) {
    chk('Conexión a la base de datos', false, $e->getMessage(),
        'Verifica que exista app/con_.php y que app/.env esté completo');
}

// ------------------------------------------------------------------ 2. token
$token = (string)(getenv('PF_TOTEM_TOKEN') ?: '');
chk('PF_TOTEM_TOKEN en app/.env', $token !== '',
    $token !== ''
        ? 'Definido · ' . strlen($token) . ' caracteres'
        : 'NO está definido',
    'Agrega una línea  PF_TOTEM_TOKEN=loquesea  en app/.env y pon EXACTAMENTE '
    . 'el mismo valor en pf_totem/js/config.js → sync.token');

// ------------------------------------------------------------------ 3. tablas
$tablas = ['pf_totem_sesion', 'pf_totem_evento', 'pf_totem_ganador'];
$existen = [];
if ($conn) {
    foreach ($tablas as $t) {
        $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        if ($r && $r->num_rows > 0) $existen[] = $t;
    }
    $faltan = array_diff($tablas, $existen);
    chk('Tablas del juego', count($faltan) === 0,
        count($faltan) === 0 ? 'Las 3 existen' : 'Faltan: ' . implode(', ', $faltan),
        'Corre scripts/22_pf_totem_juego.sql en phpMyAdmin');
}

// ------------------------------------------- 4. columnas que el endpoint usa
if ($conn && in_array('pf_totem_sesion', $existen, true)) {
    $necesarias = ['uuid','device_id','inicio','fin','duracion_seg','momento','variedad',
                   'vino','resultado','precision_pct','nivel_final','linea_objetivo','dificultad'];
    $hay = [];
    $r = $conn->query('SHOW COLUMNS FROM pf_totem_sesion');
    while ($r && ($f = $r->fetch_assoc())) $hay[] = $f['Field'];
    $faltanCol = array_diff($necesarias, $hay);
    chk('Columnas de pf_totem_sesion', count($faltanCol) === 0,
        count($faltanCol) === 0 ? 'Todas presentes' : 'Faltan: ' . implode(', ', $faltanCol),
        'Si falta linea_objetivo:  ALTER TABLE pf_totem_sesion '
        . 'ADD COLUMN linea_objetivo DECIMAL(5,2) NULL AFTER nivel_final;');
}

// --------------------------------------------------------- 5. datos recibidos
$conteos = [];
if ($conn && count($existen) === 3) {
    foreach ($tablas as $t) {
        $r = $conn->query("SELECT COUNT(*) c, MAX(recibido_en) u FROM $t");
        $f = $r ? $r->fetch_assoc() : ['c' => 0, 'u' => null];
        $conteos[$t] = $f;
    }
    $totalFilas = (int)$conteos['pf_totem_sesion']['c'] + (int)$conteos['pf_totem_evento']['c'];
    chk('¿Llegaron datos?', $totalFilas > 0,
        $totalFilas > 0
            ? 'Sí · último ingreso ' . ($conteos['pf_totem_evento']['u'] ?: $conteos['pf_totem_sesion']['u'])
            : 'Todavía no llega nada',
        'Si todo lo de arriba está OK, revisa la URL en pf_totem/js/config.js → sync.url. '
        . 'Debe ser  ' . (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://'
        . htmlspecialchars((string)($_SERVER['HTTP_HOST'] ?? 'TU-SERVIDOR'), ENT_QUOTES)
        . dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')) . '/sync.php');

    // el ping del tótem es un POST: si sólo hay eventos "boot" nunca se jugó
    $r = $conn->query("SELECT tipo, COUNT(*) c FROM pf_totem_evento GROUP BY tipo ORDER BY c DESC LIMIT 12");
    $tipos = [];
    while ($r && ($f = $r->fetch_assoc())) $tipos[$f['tipo']] = $f['c'];
}

// ------------------------------------------------------------- 6. URL correcta
$urlEsperada = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://'
    . (string)($_SERVER['HTTP_HOST'] ?? '') . dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')) . '/sync.php';
chk('Este endpoint responde', true, 'Estás viendo esta página, así que la ruta existe');

$hayHttps = !empty($_SERVER['HTTPS']);
chk('HTTPS activo', $hayHttps,
    $hayHttps ? 'Sí' : 'NO · se está sirviendo por http://',
    'Sin HTTPS el service worker del tótem no se registra y el juego queda '
    . 'dependiendo de la señal. Además el navegador puede bloquear el envío.');

$todoOk = true;
foreach ($checks as $c) if (!$c['ok']) $todoOk = false;
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico · Tótem Puntí Ferrer</title>
<style>
  body{font-family:system-ui,'Segoe UI',sans-serif;background:#14110c;color:#f2e7d3;
       margin:0;padding:28px 18px;line-height:1.5}
  .caja{max-width:840px;margin:0 auto}
  h1{font-size:22px;color:#e8cf94;margin:0 0 4px;letter-spacing:.04em}
  .sub{color:#a1957e;font-size:14px;margin-bottom:22px}
  .estado{padding:14px 18px;border-radius:6px;margin-bottom:22px;font-weight:600}
  .estado.ok{background:#1e2b18;border:1px solid #7fae6d;color:#bfe0ae}
  .estado.mal{background:#2b1a18;border:1px solid #c96a5c;color:#eab5ac}
  .item{border:1px solid rgba(201,164,92,.28);border-left-width:4px;
        padding:12px 16px;margin-bottom:10px;border-radius:4px;background:rgba(42,34,22,.35)}
  .item.ok{border-left-color:#7fae6d}
  .item.mal{border-left-color:#c96a5c}
  .item h3{margin:0 0 4px;font-size:15px;color:#f2e7d3}
  .item .det{font-size:14px;color:#cfc3ab}
  .item .fix{font-size:13px;color:#e8cf94;margin-top:8px;
             border-top:1px dashed rgba(201,164,92,.3);padding-top:8px}
  code{background:#0e0c09;padding:2px 6px;border-radius:3px;font-size:13px;
       color:#e8cf94;word-break:break-all}
  table{width:100%;border-collapse:collapse;margin-top:8px;font-size:14px}
  td,th{padding:6px 8px;border-bottom:1px solid rgba(201,164,92,.15);text-align:left}
  th{color:#a1957e;font-weight:500}
  .url{margin-top:22px;padding:14px 16px;background:rgba(42,34,22,.5);
       border:1px solid rgba(201,164,92,.35);border-radius:4px;font-size:14px}
</style></head><body><div class="caja">

<h1>Diagnóstico del tótem · Puntí Ferrer</h1>
<div class="sub">Revisa la cadena completa: servidor → base de datos → datos recibidos.</div>

<div class="estado <?= $todoOk ? 'ok' : 'mal' ?>">
  <?= $todoOk ? '✓ Todo en orden. El tótem puede sincronizar.'
              : '✗ Hay algo que falta. Revisa los puntos marcados abajo.' ?>
</div>

<?php foreach ($checks as $c): ?>
  <div class="item <?= $c['ok'] ? 'ok' : 'mal' ?>">
    <h3><?= $c['ok'] ? '✓' : '✗' ?> <?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?></h3>
    <div class="det"><?= htmlspecialchars($c['detalle'], ENT_QUOTES) ?></div>
    <?php if (!$c['ok'] && $c['arreglo']): ?>
      <div class="fix"><b>Cómo se arregla:</b> <?= htmlspecialchars($c['arreglo'], ENT_QUOTES) ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($conteos): ?>
  <h3 style="color:#e8cf94;margin-top:26px;font-size:16px">Datos en la base</h3>
  <table>
    <tr><th>Tabla</th><th>Filas</th><th>Último ingreso</th></tr>
    <?php foreach ($conteos as $t => $f): ?>
      <tr><td><?= htmlspecialchars($t, ENT_QUOTES) ?></td>
          <td><?= (int)$f['c'] ?></td>
          <td><?= htmlspecialchars((string)($f['u'] ?? '—'), ENT_QUOTES) ?></td></tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<?php if (!empty($tipos)): ?>
  <h3 style="color:#e8cf94;margin-top:26px;font-size:16px">Eventos por tipo</h3>
  <table>
    <tr><th>Tipo</th><th>Cantidad</th></tr>
    <?php foreach ($tipos as $t => $c): ?>
      <tr><td><?= htmlspecialchars((string)$t, ENT_QUOTES) ?></td><td><?= (int)$c ?></td></tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<div class="url">
  <b>La URL que debe tener el tótem</b> en <code>pf_totem/js/config.js</code> → <code>sync.url</code>:<br><br>
  <code><?= htmlspecialchars($urlEsperada, ENT_QUOTES) ?></code>
</div>

</div></body></html>
