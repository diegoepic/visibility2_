<?php
/**
 * Cargador de variables de entorno compartido por la app y el portal.
 *
 * Antes cada lado hacía lo suyo: app/con_.php traía su propio parser de .env y
 * portal/con_.php tenía las credenciales escritas dentro del archivo. Este
 * cargador es exactamente el que ya funcionaba en app/con_.php, movido a la
 * raíz para que ambos lean la misma fuente.
 *
 * Reglas, iguales a las de antes:
 *   - Una variable ya presente en el entorno real NO se pisa. Eso permite
 *     definir cosas en el panel del hosting y que ganen sobre el archivo.
 *   - Se ignoran líneas vacías y las que empiezan con '#'.
 *   - Se recortan comillas simples o dobles alrededor del valor.
 *
 * El archivo .env NO está versionado (ver .gitignore). El ejemplo con los
 * nombres de todas las variables está en .env.sample.
 */

declare(strict_types=1);

if (!function_exists('v2_load_env')) {

    /**
     * Carga el .env en el entorno del proceso. Idempotente: llamarlo varias
     * veces en el mismo request no repite trabajo.
     *
     * Busca en este orden y usa el PRIMERO que encuentre:
     *   1. La ruta que se le pase por parámetro.
     *   2. El .env de la raíz del repositorio (junto a este archivo).
     *   3. app/.env  — ruta heredada. Existe sólo para que el despliegue actual
     *      no se caiga mientras el .env de la raíz no esté confirmado en
     *      producción. Se puede eliminar en cuanto lo esté.
     */
    function v2_load_env(?string $path = null): void
    {
        static $cargado = false;
        if ($cargado && $path === null) {
            return;
        }

        $candidatos = $path !== null
            ? [$path]
            : [
                __DIR__ . '/.env',
                __DIR__ . '/app/.env',
            ];

        foreach ($candidatos as $archivo) {
            if (!is_readable($archivo)) {
                continue;
            }

            $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lineas === false) {
                continue;
            }

            foreach ($lineas as $linea) {
                $linea = trim($linea);
                if ($linea === '' || $linea[0] === '#') {
                    continue;
                }
                if (!str_contains($linea, '=')) {
                    continue;
                }

                [$clave, $valor] = explode('=', $linea, 2);
                $clave = trim($clave);
                $valor = trim($valor, " \t\"'");

                // El entorno real manda sobre el archivo.
                if ($clave !== '' && getenv($clave) === false) {
                    putenv("$clave=$valor");
                    $_ENV[$clave] = $valor;
                }
            }

            $cargado = true;
            return;
        }

        // Ningún archivo legible. No es un error acá: quien necesite una
        // variable concreta decide qué hacer si falta (ver con_.php, que
        // responde 503 si no hay credenciales de base de datos).
        $cargado = true;
    }
}

if (!function_exists('v2_env')) {

    /**
     * Lee una variable de entorno, cargando el .env si hiciera falta.
     * Devuelve $porDefecto cuando la variable no existe o está vacía.
     */
    function v2_env(string $clave, ?string $porDefecto = null): ?string
    {
        v2_load_env();
        $valor = getenv($clave);
        return ($valor === false || $valor === '') ? $porDefecto : $valor;
    }
}
