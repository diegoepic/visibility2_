<?php
/**
 * estado_gestion.php — EN QUE ESTADO QUEDO UNA GESTION
 * -----------------------------------------------------------------------------
 * El estado de una gestión vive hoy en `formularioQuestion.pregunta`, una columna
 * `varchar(80)` que se llama "pregunta" y guarda cosas como 'cancelado' o 'en proceso'.
 * Ese es el hallazgo V-30. Esta librería no arregla el nombre —eso implicaría tocar 20 y
 * pico de archivos del portal, casi todos exports de Excel que el cliente recibe— pero sí
 * pone en un solo lugar la regla de cómo se lee.
 *
 *
 * LO QUE HAY DE VERDAD EN LA COLUMNA
 * ----------------------------------
 * Medido sobre el volcado del 2026-09-03, 674.020 filas:
 *
 *     'solo_auditoria'         165.252
 *     'solo_implementado'      153.094
 *     ''                       130.861   <- sin gestionar
 *     'implementado_auditado'  115.662
 *     'en proceso'              69.235
 *     'cancelado'               37.652
 *     'solo_retirado'            1.584
 *     ' '                          489   <- un espacio. Tampoco es un estado.
 *     NULL                         127
 *     'completado'                  59   <- ningún camino de escritura de la app lo produce
 *     'no_implementado'              5   <- idem; la app lo escribe en gestion_visita, no acá
 *
 * O sea 131.477 filas (19,5%) no tienen estado ninguno, y hay tres formas distintas de
 * decir eso: '', ' ' y NULL. El espacio suelto es la que muerde: un `pregunta = ''` no lo
 * atrapa, y quien no lo contemple lo manda al ELSE de su CASE.
 *
 *
 * DOS EJES, NO UNO
 * ----------------
 * `etapa_material` es un ENUM aparte (armado/entregado/implementado/retirado) y está en
 * NULL en 673.322 de 674.020 filas — el 99,9%. Sólo 698 filas usan la modalidad por etapas.
 *
 * Y no son la misma cosa contada dos veces: hay filas con
 *
 *     pregunta='cancelado'   x etapa_material='retirado'       24
 *     pregunta='en proceso'  x etapa_material='implementado'   11
 *     pregunta='cancelado'   x etapa_material='implementado'    7
 *
 * que NO son contradicciones. `pregunta` describe cómo terminó la VISITA al local;
 * `etapa_material` describe en qué punto va ESE material. Un local puede cancelarse
 * después de haber retirado un material.
 *
 * Por eso acá no se fusionan en un solo valor: colapsarlas perdería información. La
 * columna `estado_gestion` que agrega scripts/36 normaliza el eje de la VISITA y deja
 * `etapa_material` como está.
 *
 *
 * LO QUE ESTA LIBRERIA NO HACE (todavía)
 * --------------------------------------
 * No unifica las etiquetas de los informes. Cada export tiene su propio vocabulario:
 *
 *     portal/modulos/mod_panel/ajax_detalle_locales.php        'IMPLEMENTADO', 'IMPLE/AUDI'
 *     portal/modulos/descargar_data_programada_adicionales.php 'implementado', minúsculas
 *
 * Unificarlos cambia lo que el cliente ve en sus planillas, así que es una decisión de
 * producto y no algo que corresponda hacer de paso mientras se centraliza una regla.
 */

if (!defined('V2_ESTADOS_GESTION')) {
    /**
     * El vocabulario canónico. Incluye 'completado' y 'no_implementado' aunque ningún
     * camino de escritura actual los produzca: hay 64 filas con esos valores y la
     * migración no puede perderlas.
     */
    define('V2_ESTADOS_GESTION', [
        'sin_gestionar',          // '', ' ' o NULL
        'pendiente',              // 'en proceso'
        'cancelado',
        'solo_auditoria',
        'solo_implementado',
        'solo_retirado',
        'implementado_auditado',
        'no_implementado',        // legacy
        'completado',             // legacy
    ]);
}

if (!function_exists('estado_gestion_normalizar')) {
    /**
     * Traduce el contenido crudo de `formularioQuestion.pregunta` al vocabulario canónico.
     *
     * Función pura: es la mitad de la regla que decide qué ve un supervisor en un informe,
     * y conviene poder razonarla sin base de datos.
     *
     * @param string|null $pregunta valor crudo de la columna
     * @return string uno de V2_ESTADOS_GESTION
     */
    function estado_gestion_normalizar(?string $pregunta): string
    {
        $v = strtolower(trim((string)$pregunta));   // el trim es el que atrapa el ' '
        if ($v === '') return 'sin_gestionar';

        switch ($v) {
            case 'en proceso':
            case 'pendiente':
                return 'pendiente';
            case 'cancelado':
            case 'solo_auditoria':
            case 'solo_implementado':
            case 'solo_retirado':
            case 'implementado_auditado':
            case 'no_implementado':
            case 'completado':
                return $v;
        }
        // Valor que no estaba cuando se escribió esto. Se registra —una vez por valor— y
        // se trata como sin gestionar, que es lo conservador: no inventa un final.
        static $avisados = [];
        if (!isset($avisados[$v])) {
            $avisados[$v] = true;
            error_log('[estado_gestion] valor desconocido en formularioQuestion.pregunta: ' . $v);
        }
        return 'sin_gestionar';
    }
}

if (!function_exists('estado_gestion_terminado')) {
    /**
     * ¿La visita al local se cerró? Ojo: no dice nada de los materiales — para eso está
     * `etapa_material`, que es el otro eje.
     */
    function estado_gestion_terminado(?string $pregunta): bool
    {
        return in_array(estado_gestion_normalizar($pregunta), [
            'solo_auditoria', 'solo_implementado', 'solo_retirado',
            'implementado_auditado', 'completado', 'cancelado',
        ], true);
    }
}

if (!function_exists('estado_gestion_sql')) {
    /**
     * La misma normalización, en SQL, para las consultas que agrupan o filtran por estado
     * sin traerse las filas a PHP. Mismo criterio que ruta_visibilidad_sql(): devuelve una
     * expresión que se interpola, sin `AS` ni coma.
     *
     *     $estado = estado_gestion_sql('fq');
     *     $sql = "SELECT {$estado} AS estado, COUNT(*) FROM formularioQuestion fq
     *              GROUP BY {$estado}";
     *
     * Cuando scripts/36 esté aplicado en todos los ambientes, esto puede pasar a leer
     * directamente `fq.estado_gestion` y la expresión desaparece. Hasta entonces las dos
     * formas dan el mismo resultado, que es justo lo que permite migrar sin coordinar el
     * despliegue con la migración.
     *
     * @throws InvalidArgumentException si el alias no es un identificador SQL.
     */
    function estado_gestion_sql(string $fq = 'fq'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fq)) {
            throw new InvalidArgumentException('estado_gestion_sql(): alias inválido.');
        }
        $p = "LOWER(TRIM(COALESCE({$fq}.pregunta,'')))";

        return "CASE"
             . " WHEN {$p} = '' THEN 'sin_gestionar'"
             . " WHEN {$p} IN ('en proceso','pendiente') THEN 'pendiente'"
             . " WHEN {$p} IN ('cancelado','solo_auditoria','solo_implementado','solo_retirado',"
             .                "'implementado_auditado','no_implementado','completado') THEN {$p}"
             . " ELSE 'sin_gestionar'"
             . " END";
    }
}
