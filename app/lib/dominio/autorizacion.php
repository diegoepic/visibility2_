<?php
/**
 * autorizacion.php — QUIEN PUEDE TOCAR QUE
 * -----------------------------------------------------------------------------
 * Dos preguntas, y las dos se hacían copiadas a mano en una decena de endpoints:
 *
 *   1) ¿Este ejecutor tiene ESTE local asignado en ESTA campaña?
 *      -> puede_usuario_tocar()
 *      La asignación vive en `formularioQuestion`: si no hay ninguna fila con
 *      (id_formulario, id_local, id_usuario), el local no es suyo.
 *
 *   2) ¿Esta visita es suya?
 *      -> auth_visita()
 *      El cliente manda `visita_id` en el POST. Aceptarlo a ciegas fue el hallazgo
 *      V-07: un ejecutor podía colgarle fotos y respuestas a la visita de otro, y
 *      hasta cerrársela.
 *
 *
 * POR QUE NO SE RESPONDE NI SE CORTA DESDE ACA
 * --------------------------------------------
 * Cada llamador tiene su propia forma de rechazar —JSON con `error_code`, `die()` con
 * texto, redirección con mensaje— y sobre todo su propio criterio: en el flujo offline,
 * un `visita_id` que no calza casi siempre es un mapeo local desactualizado, no un
 * ataque, y lo correcto es caer al `client_guid` en vez de rechazar. Por eso estas
 * funciones devuelven un dato y quien llama decide.
 *
 *
 * QUE SE MOVIO AQUI Y QUE NO
 * --------------------------
 * `visita_es_del_usuario()` vivía en lib/api_helpers.php (T2-2). Se movió acá y se dejó
 * como envoltorio sobre auth_visita(), así que sus tres llamadores siguen funcionando
 * sin tocarse. api_helpers.php ahora requiere este archivo.
 *
 * Sobre `puede_usuario_tocar()` y la empresa: hay una asimetría real entre los dos
 * sitios que hacían esta comprobación.
 *
 *     procesar_gestion_pruebas.php   miraba SOLO formularioQuestion
 *     upload_estado_foto_pruebas.php miraba además f.id_empresa = <empresa de la sesión>
 *
 * O sea, hoy un ejecutor asignado por error a una campaña de otra empresa PUEDE guardar
 * la gestión pero NO puede subirle la foto de evidencia. Es una inconsistencia, pero
 * resolverla en un sentido u otro es una decisión de negocio con datos detrás, no algo
 * que corresponda cambiar de paso mientras se centraliza. Por eso `$empresa` es
 * opcional: cada llamador conserva exactamente la severidad que tenía.
 *
 *
 * FALLAN CERRADO
 * --------------
 * Si el prepare() falla, puede_usuario_tocar() devuelve false y auth_visita() devuelve
 * null: o sea "no autorizado", no "error del servidor". Es un cambio respecto de dos
 * llamadores que antes respondían 500 en ese caso (create_visita_pruebas.php y los
 * api/gestion_*), y es deliberado: una comprobación de permisos que no se pudo hacer no
 * es una comprobación aprobada. En la práctica un prepare fallido significa que la base
 * está caída, y entonces la petición se va a caer igual dos líneas más abajo.
 */

if (!function_exists('puede_usuario_tocar')) {
    /**
     * ¿El usuario tiene este local asignado en esta campaña?
     *
     * @param int|null $id_empresa          Si se pasa, exige además que la campaña sea de
     *                                       esa empresa. Ver la nota sobre la asimetría.
     * @param bool     $exigeLocalVigente    Si es true, exige además que el local siga
     *                                       existiendo en `local`. Sólo lo usa
     *                                       agregarMaterial_pruebas.php, que traía un
     *                                       INNER JOIN a `local` en su comprobación. Puede
     *                                       haber sido un join de copia y pega, pero hay
     *                                       56.306 formularioQuestion apuntando a locales
     *                                       borrados, así que la diferencia es real y no
     *                                       se cambia de paso: se conserva tal cual.
     */
    function puede_usuario_tocar(
        mysqli $conn,
        int $usuario_id,
        int $id_formulario,
        int $id_local,
        ?int $id_empresa = null,
        bool $exigeLocalVigente = false
    ): bool {
        if ($usuario_id <= 0 || $id_formulario <= 0 || $id_local <= 0) return false;

        $joins  = '';
        $tipos  = '';
        $params = [];
        if ($id_empresa !== null) {
            $joins .= ' JOIN formulario f ON f.id = fq.id_formulario AND f.id_empresa = ?';
            $tipos .= 'i';
            $params[] = $id_empresa;
        }
        if ($exigeLocalVigente) {
            $joins .= ' JOIN local l ON l.id = fq.id_local';
        }
        $tipos .= 'iii';
        array_push($params, $id_formulario, $id_local, $usuario_id);

        $sql = "SELECT 1 FROM formularioQuestion fq{$joins}
                 WHERE fq.id_formulario = ? AND fq.id_local = ? AND fq.id_usuario = ?
                 LIMIT 1";

        $st = $conn->prepare($sql);
        if (!$st) return false;
        $st->bind_param($tipos, ...$params);
        $st->execute();
        $st->store_result();
        $ok = $st->num_rows > 0;
        $st->close();
        return $ok;
    }
}

if (!function_exists('auth_visita')) {
    /**
     * Comprueba que la visita sea del usuario y de la campaña, y de paso devuelve su
     * local — que es lo que varios llamadores necesitaban y por lo que
     * procesar_gestionIW.php hacía la MISMA consulta dos veces seguidas.
     *
     * @param int|null $id_local     Si se pasa, exige además que sea de ese local.
     * @param bool     $soloAbierta  Si es true, exige `fecha_fin IS NULL`.
     * @param bool     $registrar    Si es false, un fallo NO se anota en el error_log.
     *                               Se usa donde no encontrarla es lo normal: crear_visitaIW.php
     *                               comprueba una visita guardada en $_SESSION que puede haber
     *                               quedado vieja, y ahi el fallo es rutina, no una senal.
     * @return array{id:int,id_local:int}|null  null = no existe o no le corresponde.
     */
    function auth_visita(
        mysqli $conn,
        int $visita_id,
        int $usuario_id,
        int $id_formulario,
        ?int $id_local = null,
        bool $soloAbierta = false,
        bool $registrar = true
    ): ?array {
        if ($visita_id <= 0 || $usuario_id <= 0) return null;

        $sql = "SELECT id, id_local FROM visita
                 WHERE id = ? AND id_usuario = ? AND id_formulario = ?";
        $tipos  = 'iii';
        $params = [$visita_id, $usuario_id, $id_formulario];

        if ($id_local !== null) {
            $sql .= " AND id_local = ?";
            $tipos .= 'i';
            $params[] = $id_local;
        }
        if ($soloAbierta) {
            $sql .= " AND fecha_fin IS NULL";
        }
        $sql .= " LIMIT 1";

        $st = $conn->prepare($sql);
        if (!$st) return null;
        $st->bind_param($tipos, ...$params);
        $st->execute();
        $st->bind_result($okId, $okLocal);
        $hay = $st->fetch();
        $st->close();

        if (!$hay) {
            // Si aparece seguido para el mismo usuario, ya no es un mapeo viejo del
            // cliente offline sino alguien probando ids ajenos.
            if ($registrar) error_log(sprintf(
                '[seguridad] visita ajena o inexistente rechazada: uid=%d visita=%d form=%d local=%s',
                $usuario_id, $visita_id, $id_formulario,
                $id_local === null ? '-' : (string)$id_local
            ));
            return null;
        }
        return ['id' => (int)$okId, 'id_local' => (int)$okLocal];
    }
}

if (!function_exists('visita_es_del_usuario')) {
    /**
     * Forma corta de auth_visita() para quien sólo quiere el id validado.
     * Vivía en lib/api_helpers.php; se conserva el nombre y la firma porque la usan
     * procesar_gestion_pruebas.php, procesar_pregunta_foto_pruebas.php y
     * upload_material_foto_pruebas.php.
     *
     * @return int el id validado, o 0.
     */
    function visita_es_del_usuario(
        mysqli $conn,
        int $visita_id,
        int $usuario_id,
        int $id_formulario,
        int $id_local
    ): int {
        $v = auth_visita($conn, $visita_id, $usuario_id, $id_formulario, $id_local);
        return $v === null ? 0 : $v['id'];
    }
}
