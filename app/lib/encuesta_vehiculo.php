<?php
/**
 * encuesta_vehiculo.php
 * -----------------------------------------------------------------------------
 * Configuración y helpers de la encuesta complementaria "Estatus del vehículo"
 * (formulario 138).
 *
 * Dos funcionalidades viven acá:
 *   1) La pregunta de PATENTE se renderiza como selector buscable contra la
 *      tabla `vehiculo` (en vez de texto libre) para evitar errores de tipeo.
 *   2) La FOTO DEL ODÓMETRO dispara una lectura automática del kilometraje
 *      con IA (ver odometro_ia.php y api/odometro_read.php).
 *
 * Se resuelve por ID de pregunta en vez de crear un tipo de pregunta nuevo:
 * un tipo nuevo obligaría a tocar el constructor de encuestas del portal, los
 * exports y la app móvil para un caso puntual de una sola campaña.
 */

if (defined('ENCUESTA_VEHICULO_CARGADA')) {
    return;
}
define('ENCUESTA_VEHICULO_CARGADA', true);

/* =========================================================================
   IDs de la campaña 138 (verificados contra la BBDD)
   ========================================================================= */
define('EV_FORMULARIO_ID',   138);
define('EV_QID_PATENTE',   52753);  // 'INGRESE PATENTE DEL VEHÍCULO (FORMATO ABCD-12)' (tipo 4)
define('EV_QID_KM',          593);  // 'INGRESE KILOMETRAJE DE LA CAMIONETA:'           (tipo 5)
define('EV_QID_FOTO_ODO',    594);  // 'FOTO ODOMETRO'                                  (tipo 7)

/* =========================================================================
   Lectura de odómetro con IA
   ========================================================================= */

/**
 * Lee OPENAI_API_KEY dando PRIORIDAD al archivo app/.env por sobre getenv().
 *
 * Por qué no se usa getenv() primero: app/con_.php solo hace putenv() cuando la
 * variable NO existe ya en el entorno. Si el servidor (Apache/PHP-FPM) tiene un
 * OPENAI_API_KEY antiguo definido, el .env queda ignorado y la app usa una key
 * obsoleta — con el síntoma confuso de "puse la misma key y da 401".
 * El .env es la fuente de verdad; el entorno queda solo como respaldo.
 */
function ev_leer_api_key(): string {
    $k = '';

    $envFile = dirname(__DIR__) . '/.env';   // app/.env
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$name, $val] = explode('=', $line, 2);
            if (trim($name) === 'OPENAI_API_KEY') { $k = $val; }   // gana la última
        }
    }

    if ($k === '') {
        $env = getenv('OPENAI_API_KEY');
        if ($env !== false) $k = (string)$env;
    }
    if ($k === '') return '';

    // Limpia lo que suele colarse al pegarla (comillas, ; final, saltos de línea):
    // un carácter de más también produce un 401 difícil de diagnosticar.
    $k = preg_replace('/\s+/', '', $k) ?? '';
    return trim($k, " \t\r\n\"';,");
}

define('EV_OPENAI_API_KEY', ev_leer_api_key());

define('EV_MODELO_IA',      'gpt-4o');   // validado en el piloto: 100% en odómetros digitales legibles
define('EV_UMBRAL_CONF',    0.80);       // bajo esto NO se autocompleta; se pide ingreso manual
define('EV_IMG_MAX_PX',     1024);
define('EV_IMG_JPEG_Q',     85);
define('EV_HTTP_TIMEOUT',   40);

// Topes de consumo (defensa en capas)
define('EV_MAX_INTENTOS_FOTO',   2);   // por foto: 1ª lectura + 1 reintento
define('EV_MAX_LECTURAS_DIA',    6);   // por usuario y día
define('EV_MAX_RAFAGA_MIN',     10);   // por usuario y minuto (anti-bucle de JS)
define('EV_MAX_LECTURAS_MES', 6000);   // corte global: sobre esto no se llama a la API

// Precio referencial USD por 1.000.000 de tokens (solo para estimar costo)
define('EV_PRECIO_IN',   2.50);
define('EV_PRECIO_OUT', 10.00);

// Validación contra la lectura anterior del mismo vehículo
define('EV_SALTO_KM_MAX', 1500);  // salto plausible entre dos lecturas consecutivas

/* =========================================================================
   Helpers
   ========================================================================= */

function ev_ia_habilitada(): bool {
    return EV_OPENAI_API_KEY !== '';
}

function ev_tabla_lecturas_existe(mysqli $conn): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $r = @mysqli_query($conn, "SHOW TABLES LIKE 'odometro_lectura'");
    $ok = ($r && mysqli_num_rows($r) > 0);
    return $ok;
}

/**
 * Normaliza una patente para comparar: mayúsculas y sin guiones/espacios.
 * 'vxyc-44' y 'VXYC 44' colapsan a 'VXYC44'.
 */
function ev_normalizar_patente(string $p): string {
    $p = mb_strtoupper(trim($p), 'UTF-8');
    return preg_replace('/[^A-Z0-9]/', '', $p) ?? '';
}

/**
 * Vehículos activos de una empresa, para el selector de patente.
 * El vehículo asignado al usuario queda marcado (es_mio) para destacarlo arriba.
 *
 * @return array<int,array{id:int,patente:string,modelo:string,es_mio:int}>
 */
function ev_vehiculos_empresa(mysqli $conn, int $idEmpresa, int $idUsuario): array {
    $out = [];
    if ($idEmpresa <= 0) return $out;

    $sql = "SELECT id, patente, COALESCE(modelo,'') AS modelo,
                   CASE WHEN id_merchan = ? THEN 1 ELSE 0 END AS es_mio
              FROM vehiculo
             WHERE id_empresa = ?
               AND estado = 1
               AND deleted_at IS NULL
               AND patente <> ''
             ORDER BY es_mio DESC, patente ASC";

    $st = $conn->prepare($sql);
    if (!$st) return $out;
    $st->bind_param('ii', $idUsuario, $idEmpresa);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'id'      => (int)$r['id'],
            'patente' => (string)$r['patente'],
            'modelo'  => (string)$r['modelo'],
            'es_mio'  => (int)$r['es_mio'],
        ];
    }
    $st->close();
    return $out;
}

/**
 * Busca un vehículo de la empresa por patente (comparación normalizada).
 * Devuelve la fila o null. Es la validación server-side anti-tipeo.
 */
function ev_buscar_vehiculo_por_patente(mysqli $conn, string $patente, int $idEmpresa): ?array {
    $norm = ev_normalizar_patente($patente);
    if ($norm === '' || $idEmpresa <= 0) return null;

    // La normalización se hace en SQL para tolerar guiones/espacios guardados distinto.
    $sql = "SELECT id, patente
              FROM vehiculo
             WHERE id_empresa = ?
               AND deleted_at IS NULL
               AND UPPER(REPLACE(REPLACE(REPLACE(patente,'-',''),' ',''),'.','')) = ?
             ORDER BY estado DESC, id DESC
             LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return null;
    $st->bind_param('is', $idEmpresa, $norm);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/**
 * Último kilometraje registrado para una patente en la campaña (excluyendo
 * una visita dada). Sirve para advertir saltos implausibles.
 * Devuelve null si no hay historial.
 */
function ev_ultimo_km_de_patente(mysqli $conn, string $patente, int $visitaExcluir = 0): ?array {
    $norm = ev_normalizar_patente($patente);
    if ($norm === '') return null;

    $qPat = EV_QID_PATENTE;
    $qKm  = EV_QID_KM;

    // Visitas donde se respondió esta patente, y el km de esa misma visita.
    $sql = "SELECT rk.answer_text AS km, rk.created_at
              FROM form_question_responses rp
              JOIN form_question_responses rk
                ON rk.visita_id = rp.visita_id
               AND rk.id_form_question = ?
             WHERE rp.id_form_question = ?
               AND UPPER(REPLACE(REPLACE(REPLACE(rp.answer_text,'-',''),' ',''),'.','')) = ?
               AND rp.visita_id <> ?
               AND rk.answer_text REGEXP '^[0-9]+$'
             ORDER BY rk.created_at DESC
             LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return null;
    $st->bind_param('iisi', $qKm, $qPat, $norm, $visitaExcluir);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row || $row['km'] === null || $row['km'] === '') return null;
    return ['km' => (int)$row['km'], 'fecha' => (string)$row['created_at']];
}
