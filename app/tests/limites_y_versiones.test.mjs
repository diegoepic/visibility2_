/**
 * Pruebas del limitador de peticiones (V-23) y de la tríada de versiones de assets.
 *
 * NOTA HISTÓRICA — por si alguien busca las pruebas del service worker:
 * la T6-4 incluía también un arreglo para V-21 (el SW precacheaba `index_pruebas.php`, que
 * lleva la agenda personal embebida). Se revirtió por decisión del dueño del producto: el
 * arreglo dejaba al ejecutor sin su agenda sin red, que es funcionalidad de todos los días,
 * a cambio de tapar una fuga que sólo se da en teléfonos compartidos y sólo si el usuario
 * anterior no cerró sesión. V-21 sigue ABIERTO.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const APP = join(dirname(fileURLToPath(import.meta.url)), '..');

/**
 * Quita comentarios de // y de bloque. Sin esto, una prueba que busca un literal en el
 * código lo encuentra en el comentario que EXPLICA por qué ese literal ya no debe estar,
 * y falla sola. Pasó tres veces al escribir estas pruebas.
 */
function sinComentarios(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .split(/\r?\n/)
    .map((l) => l.replace(/(^|\s)\/\/.*$/, '$1'))
    .join('\n');
}

/* ------------------------------------------------- la tríada de versiones de assets */

test('las tres versiones de assets coinciden', () => {
  // Si no coinciden, el service worker sigue sirviendo los archivos viejos y el despliegue
  // no existe para los ejecutores. Es el error de despliegue más caro de este proyecto
  // porque no da ningún síntoma: la app simplemente no cambia.
  const ver = (archivo, rx) => {
    const m = readFileSync(join(APP, archivo), 'utf8').match(rx);
    assert.ok(m, 'no se encontró la versión en ' + archivo);
    return m[1];
  };
  const a = ver('lib/asset_version.php', /define\('V2_ASSET_VER',\s*'([^']+)'\)/);
  const b = ver('sw.js', /const VERSION\s*=\s*'([^']+)'/);
  const c = ver('assets/js/v2_cache.js', /window\.SW_VERSION\s*=\s*window\.SW_VERSION\s*\|\|\s*'([^']+)'/);

  assert.deepStrictEqual([b, c], [a, a],
    'Las tres versiones tienen que ser la MISMA.\n' +
    `  lib/asset_version.php : ${a}\n  sw.js                 : ${b}\n  assets/js/v2_cache.js : ${c}`);
});

/* ---------------------------------------------------- V-23: el limitador de peticiones */

const RL = readFileSync(join(APP, 'lib', 'rate_limiter.php'), 'utf8');

test('el limitador acepta trabajar sin conexión a la base', () => {
  // Es lo que permite ponerle tope a api/route_compute.php, que no abre $conn: con una
  // conexión por llamada, el tope costaría más que la llamada a Google que evita.
  assert.match(RL, /function rate_limit_check\(\s*\?mysqli \$conn/,
    'rate_limit_check() volvió a exigir un mysqli');
  assert.ok(RL.includes('rate_limit_apcu_disponible'), 'se perdió el backend de APCu');
});

test('quien llama decide qué pasa si el limitador no puede decidir', () => {
  assert.match(RL, /bool\s+\$fallaCerrado = false/,
    'se perdió el parámetro $fallaCerrado');
  // Y el caso "ningún backend" tiene que quedar en el log, se bloquee o no: un tope que
  // no se está aplicando debe ser visible.
  assert.ok(RL.includes("'[rate_limit] sin backend disponible"),
    'el caso sin backend ya no se registra en el log');
});

test('route_compute tiene tope y no abre conexión para conseguirlo', () => {
  const rc = readFileSync(join(APP, 'api', 'route_compute.php'), 'utf8');
  assert.match(rc, /rate_limit_check\(null, 'route_compute'/,
    'api/route_compute.php perdió su tope, o lo hace abriendo $conn');
  assert.ok(!sinComentarios(rc).includes('con_.php'),
    'route_compute.php abrió conexión a la base: cada cálculo de ruta pagaría una conexión');
});

test('la limpieza del rate limiter ya no corre dentro de las peticiones', () => {
  assert.ok(!/random_int\(1,\s*10\)/.test(RL),
    'volvió el DELETE probabilístico dentro de rate_limit_check(); va en scripts/cron_gc.php');
  const cron = readFileSync(join(APP, '..', 'scripts', 'cron_gc.php'), 'utf8');
  assert.ok(cron.includes('rate_limit_log'),
    'scripts/cron_gc.php no limpia rate_limit_log');
});
