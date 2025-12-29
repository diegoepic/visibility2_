<?php
declare(strict_types=1);

/**
 * Auditoría de exports generados por la app offline.
 *
 * Uso:
 *   php app/diagnostics/audit_exports.php [--base=/ruta/a/exports] [--output=archivo.md] [--json]
 */

$baseDir = __DIR__ . '/../exports';
$output  = null;
$asJson  = false;

foreach ($argv as $arg) {
  if (str_starts_with($arg, '--base=')) {
    $baseDir = substr($arg, strlen('--base='));
  } elseif (str_starts_with($arg, '--output=')) {
    $output = substr($arg, strlen('--output='));
  } elseif ($arg === '--json') {
    $asJson = true;
  }
}

$baseDir = rtrim($baseDir, '/');
if (!is_dir($baseDir)) {
  fwrite(STDERR, "Directorio base no encontrado: {$baseDir}\n");
  exit(1);
}

$report = [
  'files_total'     => 0,
  'users_total'     => 0,
  'users'           => [],
  'sw_versions'     => [],
  'errors_by_code'  => [],
  'errors_by_route' => [],
  'queue_totals'    => [
    'pending' => 0,
    'running' => 0,
    'success' => 0,
    'error'   => 0,
    'blocked' => 0,
    'total'   => 0,
  ],
  'time_range'      => [ 'min' => null, 'max' => null ],
];

function incr(array &$arr, string $key, int $by = 1): void {
  if (!isset($arr[$key])) $arr[$key] = 0;
  $arr[$key] += $by;
}

function normalizeTs($val): ?int {
  if (is_int($val) || is_float($val)) {
    return (int)$val;
  }
  if (is_string($val) && $val !== '') {
    if (is_numeric($val)) return (int)$val;
    $ts = strtotime($val);
    if ($ts !== false) return $ts * 1000;
  }
  return null;
}

$files = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($files as $file) {
  if (!$file->isFile()) continue;
  if (!preg_match('/diagnostico_.*\.json$/', $file->getFilename())) continue;

  $content = @file_get_contents($file->getPathname());
  if ($content === false) continue;

  $data = json_decode($content, true);
  if (!is_array($data)) continue;

  $report['files_total']++;

  // Inferir usuario (carpeta inmediata bajo exports)
  $parts = explode(DIRECTORY_SEPARATOR, $file->getPath());
  $userId = null;
  for ($i = count($parts) - 1; $i >= 0; $i--) {
    if ($parts[$i] === 'exports' && isset($parts[$i+1])) {
      $userId = $parts[$i+1];
      break;
    }
  }
  $userId = $userId ?: 'desconocido';

  if (!isset($report['users'][$userId])) {
    $report['users'][$userId] = [
      'files'   => 0,
      'pending' => 0,
      'running' => 0,
      'error'   => 0,
      'blocked' => 0,
      'total'   => 0,
      'success' => 0,
      'endpoints_with_errors' => [],
      'error_codes' => [],
    ];
  }
  $report['users'][$userId]['files']++;

  // Stats
  $stats = $data['stats']['overall'] ?? [];
  foreach (['pending','running','success','error','blocked','total'] as $k) {
    if (isset($stats[$k])) {
      $v = (int)$stats[$k];
      $report['queue_totals'][$k] += $v;
      $report['users'][$userId][$k] += $v;
    }
  }

  // SW version
  if (!empty($data['sw_version'])) {
    incr($report['sw_versions'], (string)$data['sw_version']);
  }

  // Time range (exported_at es unix ms)
  if (!empty($data['exported_at'])) {
    $ts = normalizeTs($data['exported_at']);
    if ($ts) {
      $report['time_range']['min'] = $report['time_range']['min'] ? min($report['time_range']['min'], $ts) : $ts;
      $report['time_range']['max'] = $report['time_range']['max'] ? max($report['time_range']['max'], $ts) : $ts;
    }
  }

  // Recent events
  foreach ($data['recent_events'] ?? [] as $evt) {
    $errCode = $evt['error'] ?? null;
    $route   = $evt['url'] ?? ($evt['endpoint'] ?? null);
    $isError = ($evt['status'] ?? '') === 'error'
      || str_starts_with((string)($evt['type'] ?? ''), 'attempt_error')
      || $errCode;

    if (!$isError) continue;
    $code = $errCode ?: ($evt['type'] ?? 'UNKNOWN');

    incr($report['errors_by_code'], (string)$code);
    incr($report['users'][$userId]['error_codes'], (string)$code);

    if ($route) {
      $routeKey = (string)$route;
      if (!isset($report['errors_by_route'][$routeKey])) {
        $report['errors_by_route'][$routeKey] = [ 'total' => 0, 'by_code' => [] ];
      }
      $report['errors_by_route'][$routeKey]['total']++;
      incr($report['errors_by_route'][$routeKey]['by_code'], (string)$code);
      incr($report['users'][$userId]['endpoints_with_errors'], $routeKey);
    }
  }
}

$report['users_total'] = count($report['users']);

if ($asJson) {
  $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
  if ($output) {
    file_put_contents($output, $json);
  } else {
    echo $json, "\n";
  }
  exit(0);
}

function humanDate($ms): string {
  if (!$ms) return 'N/D';
  $ts = normalizeTs($ms);
  if (!$ts) return 'N/D';
  $sec = (int) floor($ts / 1000);
  return date('Y-m-d H:i:s', $sec);
}

function top(array $arr, int $limit = 5): array {
  arsort($arr);
  return array_slice($arr, 0, $limit, true);
}

$lines   = [];
$lines[] = '# Informe de diagnóstico de exports';
$lines[] = '';
$lines[] = '- Archivos procesados: ' . $report['files_total'];
$lines[] = '- Usuarios detectados: ' . $report['users_total'];
$lines[] = '- Rango temporal: ' . humanDate($report['time_range']['min']) . ' → ' . humanDate($report['time_range']['max']);
$lines[] = '- Versiones de SW: ' . (empty($report['sw_versions']) ? 'N/D' : json_encode($report['sw_versions'], JSON_UNESCAPED_UNICODE));
$lines[] = '';
$lines[] = '## Resumen de colas';
$lines[] = sprintf('- Pendientes: %d | En ejecución: %d | Éxito: %d | Error: %d | Bloqueados: %d',
  $report['queue_totals']['pending'],
  $report['queue_totals']['running'],
  $report['queue_totals']['success'],
  $report['queue_totals']['error'],
  $report['queue_totals']['blocked']
);
$lines[] = '';

$lines[] = '## Errores más frecuentes';
if (empty($report['errors_by_code'])) {
  $lines[] = '- Sin eventos de error en los exports';
} else {
  foreach (top($report['errors_by_code'], 10) as $code => $count) {
    $lines[] = sprintf('- %s: %d', $code, $count);
  }
}
$lines[] = '';

$lines[] = '## Endpoints con más errores';
if (empty($report['errors_by_route'])) {
  $lines[] = '- No se encontraron endpoints con errores';
} else {
  foreach (top(array_map(fn($r) => $r['total'], $report['errors_by_route']), 10) as $route => $total) {
    $codes = $report['errors_by_route'][$route]['by_code'] ?? [];
    $codesStr = json_encode(top($codes, 3), JSON_UNESCAPED_UNICODE);
    $lines[] = sprintf('- %s → %d (códigos: %s)', $route, $total, $codesStr);
  }
}
$lines[] = '';

$lines[] = '## Usuarios con pendientes/errores';
foreach ($report['users'] as $userId => $u) {
  if (($u['pending'] + $u['running'] + $u['error'] + $u['blocked']) === 0) continue;
  $lines[] = sprintf('- Usuario %s: pending=%d, running=%d, error=%d, blocked=%d',
    $userId, $u['pending'], $u['running'], $u['error'], $u['blocked']
  );
}

$out = implode("\n", $lines) . "\n";
if ($output) {
  file_put_contents($output, $out);
} else {
  echo $out;
}
