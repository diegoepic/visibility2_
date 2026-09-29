<?php
/**
 * ruta_visibilidad.php — QUE LOCALES SIGUEN PENDIENTES EN LA RUTA
 * -----------------------------------------------------------------------------
 * Esta es LA regla que decide si una asignación (formularioQuestion) todavía
 * tiene trabajo por hacer y por lo tanto debe aparecerle al ejecutor.
 *
 * Tiene dos mitades, porque hay dos formas de trabajar:
 *
 *   Campañas normales
 *       Pendiente = nunca se visitó (countVisita en 0).
 *       Una visita cierra el local. No hay medias tintas.
 *
 *   Campañas "implementación por etapas"
 *       Un material recorre armado -> entregado -> implementado -> retirado a lo
 *       largo de VARIAS visitas, así que countVisita no sirve: sube en cada
 *       visita aunque el material siga sin terminar. Lo que cierra un material es
 *       la etapa:
 *           retirado                        -> terminado
 *           implementado con valor >= valor_propuesto -> terminado
 *           implementado con valor <  valor_propuesto -> PARCIAL, sigue pendiente
 *           armado / entregado / sin etapa  -> pendiente
 *
 *
 * POR QUE EXISTE ESTE ARCHIVO
 * ---------------------------
 * Hasta la Tanda 6 este mismo predicado estaba copiado a mano SIETE veces: cinco
 * en app/index_pruebas.php (la página) y dos en app/api/sync_bundle.php (la caché
 * offline). Las copias YA se habían separado:
 *
 *     index_pruebas.php  ->  fq.countVisita = 0
 *     sync_bundle.php    ->  fq.countVisita IS NULL OR fq.countVisita = 0
 *
 * `countVisita` admite NULL, así que las dos copias no dicen lo mismo. Sobre el
 * volcado del 2026-09-03 la diferencia era de UNA fila entre 674.020: un local
 * que estaba en la caché offline del ejecutor pero no en la página web.
 *
 * Una fila no es una urgencia. El punto es otro: siete copias de una regla se
 * separan solas, y la próxima diferencia no tiene por qué ser de una fila.
 *
 * Se unificó hacia la versión de sync_bundle (`IS NULL OR = 0`) a propósito:
 * NULL en countVisita significa "nunca visitado", igual que 0. Y unificar en esa
 * dirección AÑADE el local a la página en vez de quitarlo de la caché — ante la
 * duda, que el ejecutor vea trabajo de más y no de menos.
 *
 *
 * COMO SE USA
 * -----------
 * Devuelve una expresión booleana entre paréntesis, SIN `AND` ni `WHERE`, para
 * que sirva en las cuatro posiciones donde hace falta:
 *
 *     WHERE ... AND {$pendiente}
 *     COUNT(CASE WHEN {$pendiente} THEN 1 END)
 *     GROUP_CONCAT(DISTINCT CASE WHEN {$pendiente} THEN f.id END)
 *     HAVING SUM(CASE WHEN {$pendiente} THEN 1 ELSE 0 END) > 0
 *
 * Guárdala en una variable antes del heredoc/comilla doble: PHP interpola
 * variables, no llamadas a función.
 *
 *     require_once __DIR__ . '/lib/dominio/ruta_visibilidad.php';
 *     $pendiente = ruta_visibilidad_sql();
 *     $sql = "SELECT ... WHERE fq.id_usuario = ? AND {$pendiente}";
 *
 * Si algún día cambia la regla —una etapa nueva, otro criterio de parcial— se
 * cambia acá y las siete consultas cambian juntas. Hay una prueba
 * (app/tests/ruta_visibilidad.test.mjs) que falla si alguien vuelve a pegar el
 * predicado a mano en otro archivo.
 */

if (!function_exists('ruta_visibilidad_alias_valido')) {
    /**
     * Los alias entran en la consulta sin escapar, así que no pueden venir de
     * ningún lado que no sea código. Un identificador SQL y nada más.
     */
    function ruta_visibilidad_alias_valido(string $alias): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias);
    }
}

if (!function_exists('ruta_visibilidad_sql')) {
    /**
     * @param string $f  alias de la tabla `formulario`         (por defecto 'f')
     * @param string $fq alias de la tabla `formularioQuestion` (por defecto 'fq')
     * @return string    expresión booleana entre paréntesis
     *
     * @throws InvalidArgumentException si un alias no es un identificador SQL.
     */
    function ruta_visibilidad_sql(string $f = 'f', string $fq = 'fq'): string
    {
        if (!ruta_visibilidad_alias_valido($f) || !ruta_visibilidad_alias_valido($fq)) {
            throw new InvalidArgumentException(
                'ruta_visibilidad_sql(): los alias deben ser identificadores SQL.'
            );
        }

        // Los valores vienen en columnas VARCHAR: '' y NULL se normalizan a '0'
        // antes de comparar, o la comparación numérica da resultados raros.
        $valor     = "CAST(COALESCE(NULLIF({$fq}.valor,''),'0') AS UNSIGNED)";
        $propuesto = "CAST(COALESCE(NULLIF({$fq}.valor_propuesto,''),'0') AS UNSIGNED)";

        return "("
             .   "({$f}.modalidad <> 'implementacion_por_etapas'"
             .    " AND ({$fq}.countVisita IS NULL OR {$fq}.countVisita = 0))"
             .   " OR "
             .   "({$f}.modalidad = 'implementacion_por_etapas' AND ("
             .        "{$fq}.etapa_material IS NULL"
             .        " OR {$fq}.etapa_material NOT IN ('implementado','retirado')"
             .        " OR ({$fq}.etapa_material = 'implementado' AND {$valor} < {$propuesto})"
             .   "))"
             . ")";
    }
}
