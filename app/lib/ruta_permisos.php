<?php
/**
 * ruta_permisos.php
 * -----------------------------------------------------------------------------
 * Permiso de "adelantar ruta": si un ejecutor puede o no ver locales con fecha
 * futura. Los que NO pueden solo ven HOY y días anteriores.
 *
 * Vive acá porque lo consumen dos entradas distintas de la app, y ambas deben
 * aplicar la MISMA regla o el permiso se puede saltar:
 *   - index_pruebas.php   (la página con el selector de fechas)
 *   - api/sync_bundle.php (la caché offline; si no se filtra, el ejecutor
 *                          igual tendría las fechas futuras en el dispositivo)
 */

if (!function_exists('ruta_col_puede_adelantar_existe')) {
    /**
     * Guarda de migración: la columna la agrega scripts/21 y los despliegues van
     * desacoplados de las migraciones. Si aún no existe, se asume que todos
     * pueden adelantar (comportamiento previo) y nada se rompe.
     */
    function ruta_col_puede_adelantar_existe(mysqli $conn): bool {
        static $existe = null;
        if ($existe !== null) return $existe;
        $r = @mysqli_query($conn, "SHOW COLUMNS FROM usuario LIKE 'puede_adelantar_ruta'");
        $existe = ($r && mysqli_num_rows($r) > 0);
        return $existe;
    }
}

if (!function_exists('ruta_puede_adelantar')) {
    /**
     * @return bool true = ve todas las fechas; false = solo hoy y anteriores.
     */
    function ruta_puede_adelantar(mysqli $conn, int $usuarioId): bool {
        static $cache = [];
        if ($usuarioId <= 0) return true;
        if (isset($cache[$usuarioId])) return $cache[$usuarioId];

        if (!ruta_col_puede_adelantar_existe($conn)) {
            return $cache[$usuarioId] = true;
        }

        $puede = true;
        $st = $conn->prepare("SELECT puede_adelantar_ruta FROM usuario WHERE id = ? LIMIT 1");
        if ($st) {
            $st->bind_param('i', $usuarioId);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            // NULL o columna ausente → se permite (no se restringe por accidente)
            if ($row !== null && $row['puede_adelantar_ruta'] !== null) {
                $puede = ((int)$row['puede_adelantar_ruta'] === 1);
            }
        }
        return $cache[$usuarioId] = $puede;
    }
}

if (!function_exists('ruta_filtrar_fechas_futuras')) {
    /**
     * Quita del arreglo los locales con fechaPropuesta posterior a hoy.
     * Se aplica sobre la lista YA construida para que tabla, selector de fechas,
     * mapa y planificador de ruta queden todos consistentes.
     *
     * @param array $locales filas con clave 'fechaPropuesta' (Y-m-d)
     */
    function ruta_filtrar_fechas_futuras(array $locales): array {
        $hoy = date('Y-m-d');
        $out = [];
        foreach ($locales as $l) {
            $f = (string)($l['fechaPropuesta'] ?? '');
            if ($f === '' || $f <= $hoy) $out[] = $l;   // formato Y-m-d: comparar como string es seguro
        }
        return $out;
    }
}
