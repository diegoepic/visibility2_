<?php
/**
 * preguntas_cadena.php — QUE PREGUNTAS DE ENCUESTA VE UN LOCAL
 * -----------------------------------------------------------------------------
 * Una campaña puede tener dos clases de preguntas:
 *
 *   generales           `form_questions.id_cadena IS NULL`  (comportamiento histórico)
 *   propias de cadena   `form_questions.id_cadena = <cadena>`
 *
 * La regla, y no admite matices:
 *
 *     Si la cadena del local tiene preguntas propias en esta campaña, el local ve
 *     SOLO esas. Si no tiene, ve las generales. NUNCA se mezclan.
 *
 * "Nunca se mezclan" es lo importante. Mezclar sería lo que sale sin querer si uno
 * simplemente consulta `WHERE id_formulario = ?`: al ejecutor le aparecen preguntas
 * de cadenas que no son la suya.
 *
 * Alcance: campañas tipo 1 y 3. La modalidad IW (tipo 2) queda fuera a propósito —
 * ahí las preguntas se pintan ANTES de elegir el local, así que no hay cadena contra
 * la cual resolver.
 *
 *
 * POR QUE EXISTE ESTE ARCHIVO (y qué se encontró al escribirlo)
 * ------------------------------------------------------------
 * El CLAUDE.md afirmaba que la regla estaba aplicada en cinco archivos. Estaba en dos:
 *
 *     app/gestionarPruebas.php               SI  (pintado, formulario actual)
 *     app/procesar_gestion_pruebas.php       SI  (guardado, descarta lo que no calza)
 *     app/gestionar.php                      NO  <- traía TODAS las preguntas
 *     app/api/mobile/gestion_bootstrap_mobile.php  NO  <- idem
 *     app/api/sync_bundle.php                n/a (no resuelve: manda id_cadena al cliente)
 *
 * Los dos que no la aplicaban formaban una trampa con el que sí: el formulario viejo
 * pintaba preguntas de otras cadenas, el ejecutor las respondía, y el guardado las
 * DESCARTABA en silencio (el `continue` de procesar_gestion_pruebas.php). Trabajo hecho
 * en terreno que no llega a ninguna parte y nadie se entera.
 *
 * Se midió antes de arreglar: sobre el volcado del 2026-09-03, `form_questions.id_cadena`
 * es NULL en las **33.259** filas. O sea la funcionalidad está construida y nadie la usa
 * todavía, y por eso la trampa nunca se disparó. Aplicar la regla en los dos archivos que
 * faltaban es hoy un cambio de CERO efecto en producción — que es exactamente el mejor
 * momento para hacerlo, antes de que la primera campaña por cadena lo convierta en un
 * incidente.
 *
 *
 * GUARDA DE MIGRACION
 * -------------------
 * La columna la agrega scripts/17 y los despliegues van desacoplados de las migraciones,
 * así que todo pasa por preguntas_cadena_activa(): si la columna no existe, todas las
 * funciones se comportan como antes de la migración (todo es general) y nada revienta.
 */

if (!function_exists('preguntas_cadena_activa')) {
    /**
     * ¿Existe form_questions.id_cadena? Se consulta una sola vez por petición: antes
     * cada archivo hacía su propio SHOW COLUMNS, y procesar_gestion_pruebas.php lo hacía
     * dentro del guardado.
     */
    function preguntas_cadena_activa(mysqli $conn): bool
    {
        static $activa = null;
        if ($activa !== null) return $activa;

        $activa = false;
        try {
            $r = @$conn->query("SHOW COLUMNS FROM form_questions LIKE 'id_cadena'");
            if ($r) {
                $activa = ($r->num_rows > 0);
                $r->close();
            }
        } catch (Throwable $e) {
            $activa = false;   // ante la duda, comportamiento previo a la migración
        }
        return $activa;
    }
}

if (!function_exists('preguntas_cadena_de_local')) {
    /**
     * Cadena a la que pertenece el local. 0 si no se sabe.
     * Devuelve 0 sin consultar nada cuando la migración no está aplicada.
     */
    function preguntas_cadena_de_local(mysqli $conn, int $idLocal): int
    {
        if ($idLocal <= 0 || !preguntas_cadena_activa($conn)) return 0;

        static $cache = [];
        if (isset($cache[$idLocal])) return $cache[$idLocal];

        $cad = 0;
        $st = $conn->prepare("SELECT id_cadena FROM local WHERE id = ? LIMIT 1");
        if ($st) {
            $st->bind_param('i', $idLocal);
            $st->execute();
            $st->bind_result($tmp);
            if ($st->fetch()) $cad = (int)$tmp;
            $st->close();
        }
        return $cache[$idLocal] = $cad;
    }
}

if (!function_exists('preguntas_cadena_filtro')) {
    /**
     * Resuelve la regla para el PINTADO: devuelve el trozo de WHERE que hay que pegarle
     * a la consulta de preguntas, junto con los tipos y parámetros del bind.
     *
     *     $f = preguntas_cadena_filtro($conn, $idCampana, $idCadenaLocal);
     *     $sql = "SELECT ... FROM form_questions WHERE id_formulario = ?{$f['cond']}
     *             ORDER BY sort_order ASC";
     *     $stmt->bind_param($f['tipos'], ...$f['params']);
     *
     * El primer parámetro SIEMPRE es $idCampana, así que la consulta debe empezar por
     * `WHERE id_formulario = ?`.
     *
     * @return array{cond:string, tipos:string, params:array<int,int>}
     */
    function preguntas_cadena_filtro(mysqli $conn, int $idCampana, int $idCadenaLocal): array
    {
        $general = ['cond' => '', 'tipos' => 'i', 'params' => [$idCampana]];

        if (!preguntas_cadena_activa($conn)) return $general;

        // ¿Tiene esta cadena preguntas propias en esta campaña?
        $propias = 0;
        if ($idCadenaLocal > 0) {
            $st = $conn->prepare(
                "SELECT COUNT(*) FROM form_questions WHERE id_formulario = ? AND id_cadena = ?"
            );
            if ($st) {
                $st->bind_param('ii', $idCampana, $idCadenaLocal);
                $st->execute();
                $st->bind_result($tmp);
                if ($st->fetch()) $propias = (int)$tmp;
                $st->close();
            }
        }

        if ($propias > 0) {
            return ['cond' => ' AND id_cadena = ?', 'tipos' => 'ii', 'params' => [$idCampana, $idCadenaLocal]];
        }
        // Sin preguntas propias -> las generales. NO se mezcla con las de otras cadenas.
        return ['cond' => ' AND id_cadena IS NULL', 'tipos' => 'i', 'params' => [$idCampana]];
    }
}

if (!function_exists('preguntas_cadena_visible')) {
    /**
     * La misma regla vista desde el GUARDADO, pregunta por pregunta: ¿esta respuesta
     * corresponde a este local?
     *
     * Función pura a propósito: es la mitad de la regla que decide si el trabajo de un
     * ejecutor se guarda o se tira, y conviene poder razonarla sin base de datos.
     *
     * @param int|null $idCadenaPregunta  form_questions.id_cadena (null = general)
     * @param int      $idCadenaLocal     cadena del local que se está gestionando
     */
    function preguntas_cadena_visible(?int $idCadenaPregunta, int $idCadenaLocal): bool
    {
        if ($idCadenaPregunta === null) return true;          // general: siempre válida
        return $idCadenaPregunta === $idCadenaLocal;
    }
}
