<?php
declare(strict_types=1);

/**
 * Distinción entre "el servidor rechaza esto" y "al servidor le pasó algo".
 *
 * Por qué existe este archivo
 * ---------------------------
 * Los endpoints de gestión son idempotentes: la cola offline reintenta con la misma
 * clave y el servidor devuelve la respuesta que guardó la primera vez. Eso está bien
 * para una respuesta definitiva, y es una pérdida de datos para una transitoria.
 *
 * Hasta v7.4.0 CUALQUIER excepción se guardaba como respuesta definitiva con un 500.
 * Un deadlock de MySQL, un disco lleno o un lock timeout —cosas que se arreglan solas
 * al reintentar— quedaban grabados como el resultado final de esa gestión: todos los
 * reintentos posteriores reproducían el 500 sin ejecutar nada, y el trabajo del
 * ejecutor se perdía para siempre. Con la cola reintentando 8 veces, el ejecutor veía
 * 8 fallos idénticos y ninguna forma de recuperarlo.
 *
 * La regla
 * --------
 *   GestionRechazadaException  -> la MISMA entrada siempre da el MISMO rechazo.
 *                                 Reintentar no sirve. SÍ se persiste (422).
 *   GestionConflictoException  -> el mundo cambió (el local se reasignó, la campaña se
 *                                 cerró). Tampoco sirve reintentar, pero necesita que
 *                                 una persona decida. Se persiste (409).
 *   Cualquier otra excepción   -> fallo de infraestructura. La clave se LIBERA con
 *                                 idempo_mark_failed() y el reintento vuelve a
 *                                 ejecutarse de verdad (500).
 *
 * En la práctica: si el mensaje de error viene de $conn->error o $stmt->error, es
 * infraestructura y se deja como Exception a secas. Si el mensaje se lo vas a mostrar
 * a un ejecutor, es rechazo y va con esta clase.
 */

if (!class_exists('GestionRechazadaException')) {
    class GestionRechazadaException extends Exception
    {
        /** Código estable para el cliente; el centro de recuperación lo traduce a una frase. */
        public string $codigo;
        /** Código HTTP con el que se responde. */
        public int $http;

        public function __construct(string $mensaje, string $codigo = 'VALIDATION', int $http = 422)
        {
            parent::__construct($mensaje);
            $this->codigo = $codigo;
            $this->http   = $http;
        }
    }
}

if (!class_exists('GestionConflictoException')) {
    /**
     * Rechazo por estado del mundo, no por la entrada. Se responde 409 para que el
     * cliente lo mande a la bandeja de conflictos en vez de a la de errores.
     */
    class GestionConflictoException extends GestionRechazadaException
    {
        public function __construct(string $mensaje, string $codigo = 'CONFLICT')
        {
            parent::__construct($mensaje, $codigo, 409);
        }
    }
}
