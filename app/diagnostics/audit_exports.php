<?php
declare(strict_types=1);

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
  'files_corrupted' => 0,  // Archivos JSON inválidos o truncados
  'files_empty'     => 0,  // Archivos vacíos
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
    'blocked_auth' => 0,    // Bloqueados por autenticación
    'blocked_csrf' => 0,    // Bloqueados por CSRF
    'total'   => 0,
  ],
  'time_range'      => [ 'min' => null, 'max' => null ],
  // Nuevas métricas de rendimiento
  'performance' => [
    'avg_running_duration_ms' => 0,
    'max_running_duration_ms' => 0,
    'avg_queue_to_success_ms' => 0,  // Latencia createdAt → success
    'jobs_with_duration_data' => 0,
  ],
  // Métricas de duplicados
  'deduplication' => [
    'total_dedupe_keys' => 0,
    'duplicate_attempts' => 0,
    'orphan_jobs' => 0,  // Jobs sin client_guid
  ],
  // Errores por versión de SW
  'errors_by_sw_version' => [],
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

// Variables para métricas de rendimiento
$runningDurations = [];
$queueToSuccessLatencies = [];
$dedupeKeys = [];

foreach ($files as $file) {
  if (!$file->isFile()) continue;
  if (!preg_match('/diagnostico_.*\.json$/', $file->getFilename())) continue;

  $content = @file_get_contents($file->getPathname());
  if ($content === false) continue;

  // Verificar archivo vacío
  if (trim($content) === '') {
    $report['files_empty']++;
    continue;
  }

  $data = json_decode($content, true);

  // Verificar JSON válido
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    $report['files_corrupted']++;
    // Registrar el error de parsing para debugging
    if (!isset($report['parse_errors'])) {
      $report['parse_errors'] = [];
    }
    $report['parse_errors'][] = [
      'file' => $file->getFilename(),
      'error' => json_last_error_msg(),
      'path' => $file->getPathname()
    ];
    continue;
  }

  $report['files_total']++;

  // MEJORA: Leer user_id del JSON primero, fallback al path
  $userId = null;

  // 1. Intentar desde el JSON
  if (!empty($data['user_id'])) {
    $userId = (string)$data['user_id'];
  } elseif (!empty($data['usuario_id'])) {
    $userId = (string)$data['usuario_id'];
  } elseif (!empty($data['session']['usuario_id'])) {
    $userId = (string)$data['session']['usuario_id'];
  }

  // 2. Fallback: inferir desde el path
  if (!$userId) {
    $parts = explode(DIRECTORY_SEPARATOR, $file->getPath());
    for ($i = count($parts) - 1; $i >= 0; $i--) {
      if ($parts[$i] === 'exports' && isset($parts[$i+1])) {
        $userId = $parts[$i+1];
        break;
      }
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
      'blocked_auth' => 0,
      'blocked_csrf' => 0,
      'total'   => 0,
      'success' => 0,
      'endpoints_with_errors' => [],
      'error_codes' => [],
      'avg_latency_ms' => 0,
      'orphan_jobs' => 0,
    ];
  }
  $report['users'][$userId]['files']++;

  // Stats
  $stats = $data['stats']['overall'] ?? [];
  foreach (['pending','running','success','error','blocked','blocked_auth','blocked_csrf','total'] as $k) {
    if (isset($stats[$k])) {
      $v = (int)$stats[$k];
      $report['queue_totals'][$k] += $v;
      if (isset($report['users'][$userId][$k])) {
        $report['users'][$userId][$k] += $v;
      }
    }
  }

  // Calcular blocked_auth y blocked_csrf desde jobs individuales si no están en stats
  $jobs = $data['jobs'] ?? ($data['queue'] ?? []);
  foreach ($jobs as $job) {
    $jobStatus = $job['status'] ?? '';
    if ($jobStatus === 'blocked_auth') {
      $report['queue_totals']['blocked_auth']++;
      $report['users'][$userId]['blocked_auth']++;
    } elseif ($jobStatus === 'blocked_csrf') {
      $report['queue_totals']['blocked_csrf']++;
      $report['users'][$userId]['blocked_csrf']++;
    }

    // Métricas de duración para jobs running
    if ($jobStatus === 'running' && !empty($job['startedAt'])) {
      $startedAt = normalizeTs($job['startedAt']);
      $exportedAt = normalizeTs($data['exported_at'] ?? null);
      if ($startedAt && $exportedAt && $exportedAt > $startedAt) {
        $runningDurations[] = $exportedAt - $startedAt;
      }
    }

    // Métricas de latencia para jobs exitosos
    if ($jobStatus === 'success' && !empty($job['createdAt']) && !empty($job['finishedAt'])) {
      $createdAt = normalizeTs($job['createdAt']);
      $finishedAt = normalizeTs($job['finishedAt']);
      if ($createdAt && $finishedAt && $finishedAt > $createdAt) {
        $queueToSuccessLatencies[] = $finishedAt - $createdAt;
      }
    }

    // Contar dedupe keys y jobs huérfanos
    if (!empty($job['dedupeKey'])) {
      $dedupeKeys[$job['dedupeKey']] = ($dedupeKeys[$job['dedupeKey']] ?? 0) + 1;
    }
    if (empty($job['client_guid']) && in_array($job['type'] ?? '', ['procesar_gestion', 'create_visita', 'upload_material_foto'])) {
      $report['deduplication']['orphan_jobs']++;
      $report['users'][$userId]['orphan_jobs']++;
    }
  }

  // SW version
  $currentSwVersion = null;
  if (!empty($data['sw_version'])) {
    $currentSwVersion = (string)$data['sw_version'];
    incr($report['sw_versions'], $currentSwVersion);
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
    $errCode = $evt['error'] ?? ($evt['lastError']['code'] ?? null);
    $route   = $evt['url'] ?? ($evt['endpoint'] ?? null);
    $isError = ($evt['status'] ?? '') === 'error'
      || str_starts_with((string)($evt['type'] ?? ''), 'attempt_error')
      || $errCode;

    if (!$isError) continue;
    $code = $errCode ?: ($evt['type'] ?? 'UNKNOWN');

    incr($report['errors_by_code'], (string)$code);
    incr($report['users'][$userId]['error_codes'], (string)$code);

    // Cruzar errores con versión de SW
    if ($currentSwVersion) {
      if (!isset($report['errors_by_sw_version'][$currentSwVersion])) {
        $report['errors_by_sw_version'][$currentSwVersion] = [
          'total' => 0,
          'by_code' => []
        ];
      }
      $report['errors_by_sw_version'][$currentSwVersion]['total']++;
      incr($report['errors_by_sw_version'][$currentSwVersion]['by_code'], (string)$code);
    }

    if ($route) {
      $routeKey = (string)$route;
      if (!isset($report['errors_by_route'][$routeKey])) {
        $report['errors_by_route'][$routeKey] = [ 'total' => 0, 'by_code' => [], 'by_sw_version' => [] ];
      }
      $report['errors_by_route'][$routeKey]['total']++;
      incr($report['errors_by_route'][$routeKey]['by_code'], (string)$code);
      if ($currentSwVersion) {
        incr($report['errors_by_route'][$routeKey]['by_sw_version'], $currentSwVersion);
      }
      incr($report['users'][$userId]['endpoints_with_errors'], $routeKey);
    }
  }
}

// Calcular métricas de rendimiento agregadas
if (!empty($runningDurations)) {
  $report['performance']['avg_running_duration_ms'] = (int)round(array_sum($runningDurations) / count($runningDurations));
  $report['performance']['max_running_duration_ms'] = max($runningDurations);
  $report['performance']['jobs_with_duration_data'] = count($runningDurations);
}

if (!empty($queueToSuccessLatencies)) {
  $report['performance']['avg_queue_to_success_ms'] = (int)round(array_sum($queueToSuccessLatencies) / count($queueToSuccessLatencies));
}

// Calcular métricas de deduplicación
$report['deduplication']['total_dedupe_keys'] = count($dedupeKeys);
$report['deduplication']['duplicate_attempts'] = array_sum(array_map(fn($c) => max(0, $c - 1), $dedupeKeys));

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
$lines[] = '## Resumen de archivos';
$lines[] = '- Archivos procesados: ' . $report['files_total'];
$lines[] = '- Archivos corruptos: ' . $report['files_corrupted'];
$lines[] = '- Archivos vacíos: ' . $report['files_empty'];
$lines[] = '- Usuarios detectados: ' . $report['users_total'];
$lines[] = '- Rango temporal: ' . humanDate($report['time_range']['min']) . ' → ' . humanDate($report['time_range']['max']);
$lines[] = '- Versiones de SW: ' . (empty($report['sw_versions']) ? 'N/D' : json_encode($report['sw_versions'], JSON_UNESCAPED_UNICODE));
$lines[] = '';
$lines[] = '## Resumen de colas';
$lines[] = sprintf('- Pendientes: %d | En ejecución: %d | Éxito: %d | Error: %d',
  $report['queue_totals']['pending'],
  $report['queue_totals']['running'],
  $report['queue_totals']['success'],
  $report['queue_totals']['error']
);
$lines[] = sprintf('- Bloqueados total: %d (Auth: %d, CSRF: %d)',
  $report['queue_totals']['blocked'],
  $report['queue_totals']['blocked_auth'],
  $report['queue_totals']['blocked_csrf']
);
$lines[] = '';
$lines[] = '## Métricas de rendimiento';
$lines[] = sprintf('- Duración promedio en running: %d ms', $report['performance']['avg_running_duration_ms']);
$lines[] = sprintf('- Duración máxima en running: %d ms', $report['performance']['max_running_duration_ms']);
$lines[] = sprintf('- Latencia promedio (creado → éxito): %d ms', $report['performance']['avg_queue_to_success_ms']);
$lines[] = sprintf('- Jobs con datos de duración: %d', $report['performance']['jobs_with_duration_data']);
$lines[] = '';
$lines[] = '## Métricas de deduplicación';
$lines[] = sprintf('- Claves dedupe únicas: %d', $report['deduplication']['total_dedupe_keys']);
$lines[] = sprintf('- Intentos duplicados evitados: %d', $report['deduplication']['duplicate_attempts']);
$lines[] = sprintf('- Jobs huérfanos (sin client_guid): %d', $report['deduplication']['orphan_jobs']);
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

$lines[] = '## Errores por versión de Service Worker';
if (empty($report['errors_by_sw_version'])) {
  $lines[] = '- No hay datos de errores por versión de SW';
} else {
  arsort($report['errors_by_sw_version']);
  foreach ($report['errors_by_sw_version'] as $swVer => $data) {
    $topCodes = top($data['by_code'] ?? [], 3);
    $codesStr = json_encode($topCodes, JSON_UNESCAPED_UNICODE);
    $lines[] = sprintf('- SW %s: %d errores (top: %s)', $swVer, $data['total'], $codesStr);
  }
}
$lines[] = '';

$lines[] = '## Usuarios con pendientes/errores';
foreach ($report['users'] as $userId => $u) {
  $hasIssues = ($u['pending'] + $u['running'] + $u['error'] + $u['blocked'] + $u['blocked_auth'] + $u['blocked_csrf'] + $u['orphan_jobs']) > 0;
  if (!$hasIssues) continue;
  $lines[] = sprintf('- Usuario %s: pending=%d, running=%d, error=%d, blocked=%d (auth=%d, csrf=%d), huérfanos=%d',
    $userId, $u['pending'], $u['running'], $u['error'], $u['blocked'],
    $u['blocked_auth'], $u['blocked_csrf'], $u['orphan_jobs']
  );
}
$lines[] = '';

// Advertencias críticas
$lines[] = '## Alertas';
$alerts = [];
if ($report['files_corrupted'] > 0) {
  $alerts[] = sprintf('⚠️  %d archivos JSON corruptos detectados', $report['files_corrupted']);
}
if ($report['queue_totals']['blocked_auth'] > 10) {
  $alerts[] = sprintf('🔴 Alto número de bloqueos por autenticación: %d', $report['queue_totals']['blocked_auth']);
}
if ($report['queue_totals']['blocked_csrf'] > 10) {
  $alerts[] = sprintf('🔴 Alto número de bloqueos por CSRF: %d', $report['queue_totals']['blocked_csrf']);
}
if ($report['performance']['max_running_duration_ms'] > 300000) {
  $alerts[] = sprintf('⚠️  Jobs con duración excesiva en running: %d ms', $report['performance']['max_running_duration_ms']);
}
if ($report['deduplication']['orphan_jobs'] > 0) {
  $alerts[] = sprintf('⚠️  %d jobs huérfanos sin client_guid (problemas de trazabilidad)', $report['deduplication']['orphan_jobs']);
}
if (empty($alerts)) {
  $lines[] = '✅ No hay alertas críticas';
} else {
  foreach ($alerts as $alert) {
    $lines[] = $alert;
  }
}

$out = implode("\n", $lines) . "\n";
if ($output) {
  file_put_contents($output, $out);
} else {
  echo $out;
}