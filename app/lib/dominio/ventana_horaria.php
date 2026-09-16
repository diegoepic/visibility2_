<?php
/**
 * ventana_horaria.php — A QUE HORA ABRE UN LOCAL
 * -----------------------------------------------------------------------------
 * La regla de negocio: hay cuentas cuyos locales abren tarde, y visitarlos antes de esa
 * hora es un viaje perdido. El planificador de ruta los manda al final del día.
 *
 * Hasta la Tanda 6 eso se decidía así, en tres archivos:
 *
 *     stripos($row['tipo_cuenta'], 'botiller') !== false
 *
 * Una regla de negocio escrita como coincidencia de texto sobre el NOMBRE de la cuenta
 * (hallazgo V-29). Renombrar la cuenta la apaga; no hay forma de declarar que otra cuenta
 * abre tarde salvo meterle "botilleria" al nombre; y la hora —13:00— estaba escrita a mano
 * en el front, en el badge de la tabla y en el aviso del mapa.
 *
 * Ahora el dato vive en `cuenta.hora_apertura` (scripts/37). NULL = sin restricción.
 *
 *
 * LO QUE SE MIDIO ANTES DE CAMBIARLO
 * ----------------------------------
 * Sobre el volcado del 2026-09-03:
 *       253 cuentas
 *         1 con "botiller" en el nombre: (228, 'BOTILLERIA')
 *     5.544 locales colgando de ella, de 188.664  (2,9%)
 *
 * O sea: la regla afecta a poco menos del 3% de los locales, todos los días. No es teórica.
 *
 *
 * COMPATIBILIDAD CON EL CLIENTE
 * -----------------------------
 * El front (route_planner.js, route_selection.js, los badges) consume un booleano llamado
 * `isBotilleria`. Ese nombre se conserva a propósito: cambiarlo obliga a tocar el
 * planificador de rutas, y reordenar la ruta con una función de costo que entienda de horas
 * es el hallazgo V-28, que el plan sitúa en la Tanda 7. Acá sólo se cambia DE DONDE sale el
 * dato, no qué hace el cliente con él.
 *
 * Lo que sí mejora de inmediato: la hora deja de estar escrita a mano. El badge muestra la
 * hora real de la cuenta, y el día que a otra cuenta se le ponga `hora_apertura` funciona
 * sola, sin desplegar código.
 */

if (!function_exists('ventana_horaria_activa')) {
    /**
     * ¿Existe ya `cuenta.hora_apertura`? Guarda de migración: los despliegues van
     * desacoplados de las migraciones, así que si la columna no está todavía, todo cae al
     * comportamiento anterior y nada revienta.
     */
    function ventana_horaria_activa(mysqli $conn): bool
    {
        static $activa = null;
        if ($activa !== null) return $activa;

        $activa = false;
        try {
            $r = @$conn->query("SHOW COLUMNS FROM cuenta LIKE 'hora_apertura'");
            if ($r) {
                $activa = ($r->num_rows > 0);
                $r->close();
            }
        } catch (Throwable $e) {
            $activa = false;
        }
        return $activa;
    }
}

if (!function_exists('ventana_horaria_col')) {
    /**
     * La EXPRESION de columna para la hora de apertura, o `NULL` si la migración no está.
     * Devuelve sólo la expresión —sin `AS`— porque unas consultas van agrupadas y otras no,
     * y el envoltorio lo tiene que poner quien llama:
     *
     *     $col = ventana_horaria_col($conn, 'cu');
     *     // consulta agrupada (index_pruebas.php):
     *     "... MAX({$col}) AS hora_apertura ... GROUP BY ..."
     *     // consulta sin GROUP BY (api/sync_bundle.php):
     *     "... {$col} AS hora_apertura ..."
     *
     * Meterle el MAX() aquí dentro habría roto la segunda: un agregado sin GROUP BY colapsa
     * la consulta entera a una fila.
     *
     * @param string $cuenta alias de la tabla `cuenta` en la consulta
     */
    function ventana_horaria_col(mysqli $conn, string $cuenta = 'cu'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $cuenta)) {
            throw new InvalidArgumentException('ventana_horaria_col(): alias inválido.');
        }
        return ventana_horaria_activa($conn) ? "{$cuenta}.hora_apertura" : 'NULL';
    }
}

if (!function_exists('ventana_horaria_de_fila')) {
    /**
     * Normaliza la hora de apertura de una fila ya consultada.
     *
     * @param array $fila debe traer `hora_apertura` (de ventana_horaria_select) y, para el
     *                    camino de respaldo, `tipo_cuenta` (el nombre de la cuenta).
     * @return string|null 'HH:MM' o null si el local no tiene restricción horaria.
     */
    function ventana_horaria_de_fila(array $fila): ?string
    {
        $h = $fila['hora_apertura'] ?? null;
        if ($h !== null && $h !== '') {
            // MySQL devuelve TIME como 'HH:MM:SS'. Al front le basta 'HH:MM'.
            return substr((string)$h, 0, 5);
        }

        // Camino de respaldo, sólo mientras scripts/37 no esté aplicado: la regla vieja,
        // con su hora escrita a mano. Se conserva para que desplegar código antes que la
        // migración no apague la funcionalidad durante ese rato.
        if (stripos((string)($fila['tipo_cuenta'] ?? ''), 'botiller') !== false) {
            return '13:00';
        }
        return null;
    }
}

if (!function_exists('ventana_horaria_abre_tarde')) {
    /**
     * El booleano que consume el front (`isBotilleria`): ¿este local tiene una hora de
     * apertura que el planificador deba respetar?
     *
     * Se conserva el nombre `isBotilleria` en el JSON por compatibilidad — ver la nota del
     * encabezado. Acá, al menos, la pregunta está dicha por lo que significa.
     */
    function ventana_horaria_abre_tarde(array $fila): bool
    {
        return ventana_horaria_de_fila($fila) !== null;
    }
}

if (!function_exists('ventana_horaria_minutos')) {
    /**
     * 'HH:MM' -> minutos desde medianoche. Para comparar contra la hora actual sin parsear
     * fechas en el cliente. Devuelve null si no hay hora.
     */
    function ventana_horaria_minutos(?string $hhmm): ?int
    {
        if ($hhmm === null || !preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) return null;
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h > 23 || $min > 59) return null;
        return $h * 60 + $min;
    }
}
