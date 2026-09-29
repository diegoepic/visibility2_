/**
 * T6-6: sacar el JavaScript inline de las páginas grandes de la app.
 *
 * QUÉ SE MIDIÓ ANTES DE EMPEZAR
 * -----------------------------
 *   index_pruebas.php     195.013 B totales,  99.096 B de <script> inline  (50,8 %)
 *   gestionarPruebas.php  196.587 B totales, 124.891 B de <script> inline  (63,5 %)
 *
 * Las navegaciones van por networkFirst (sw.js), así que ese JavaScript —que no cambia
 * entre cargas— se vuelve a bajar en CADA apertura de página. Con la mediana de 13 locales
 * por jornada son ~1,6 MB diarios por ejecutor, sin comprimir, en datos móviles.
 *
 * POR QUÉ SE HACE DE A UN BLOQUE POR DESPLIEGUE
 * --------------------------------------------
 * Son ~4.950 líneas repartidas en 14 bloques, muchas con PHP interpolado. Un error de
 * extracción no da error de compilación: da un botón que deja de responder. Se empieza por
 * los bloques SIN PHP, que se mueven byte por byte.
 *
 * LA TRAMPA QUE HAY QUE RESPETAR
 * ------------------------------
 * cacheFirst() de sw.js hace cache.match(request, { ignoreVary: true }) — SIN ignoreSearch.
 * Un archivo pedido con ?v=... no encuentra la entrada precacheada, que se guarda sin query
 * string. Si un bloque extraído se pide con ?v=, sin red no carga y sus botones quedan
 * muertos: exactamente la clase de regresión que se le cambió a un ejecutor por un arreglo
 * de privacidad en V-21. De ahí las dos pruebas del medio de este archivo.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const APP = join(dirname(fileURLToPath(import.meta.url)), '..');
const leer = (...p) => readFileSync(join(APP, ...p), 'utf8');

/**
 * Bloques ya extraídos. Esta lista CRECE a medida que avanza T6-6; cada entrada dice de qué
 * página salió, para que se pueda volver atrás sin arqueología.
 */
const EXTRAIDOS = [
  { archivo: 'gestion_geo.js',              origen: 'gestionarPruebas.php', firma: 'ensureFreshGeo' },
  { archivo: 'gestion_form.js',             origen: 'gestionarPruebas.php', firma: 'subirFotoPregunta' },
  { archivo: 'gestion_offline.js',          origen: 'gestionarPruebas.php', firma: 'VISIT_SESSION_KEY' },
  { archivo: 'ruta_mapa.js',                origen: 'index_pruebas.php', firma: 'MAPS_LIBRARIES' },
  { archivo: 'modal_reportar_direccion.js', origen: 'index_pruebas.php', firma: 'rdIdLocal' },
  { archivo: 'modal_cambiar_vendedor.js',   origen: 'index_pruebas.php', firma: 'cvIdLocal' },
];

/**
 * Presupuesto de JS inline, en bytes.
 *
 * Está para que no vuelva a colarse LÓGICA dentro del PHP. Si esta prueba falla al alza,
 * la pregunta es qué se agregó: si es lógica, va a un .js de assets/js/ y se suma a
 * EXTRAIDOS y a STATIC_ASSETS de sw.js.
 *
 * La única razón legítima para subir un tope es la CONFIGURACIÓN: los valores que PHP
 * tiene que emitir (ids, nombres, tokens, banderas) no pueden vivir en un archivo estático,
 * que es precisamente por qué existen los bloques window.__*. Cuando el tope sube por eso,
 * se anota aquí:
 *
 *   18.101 B  T6-6, tras extraer los seis bloques (venía de 99.096 B)
 *   18.855 B  C-2, bloque de configuración del guardado de ruta: CSRF_TOKEN,
 *             __RUTA_PLAN_URL y el comentario que explica por qué el token puede ir
 *             en una página precacheada
 *
 * gestionarPruebas.php: 2.905 B, sin cambios desde T6-6.
 */
const PRESUPUESTO = {
  'index_pruebas.php': 19000,
  'gestionarPruebas.php': 3200,
};

const bytesInline = (src) => {
  // Normaliza los saltos: gestionarPruebas.php esta en CRLF e index_pruebas.php en LF, y
  // sin esto el presupuesto dependeria del checkout (un byte por linea, ~2,9 KB de mas).
  src = src.split('\r\n').join('\n');
  let n = 0;
  for (const m of src.matchAll(/<script(?![^>]*\ssrc=)[^>]*>([\s\S]*?)<\/script>/g)) {
    n += Buffer.byteLength(m[1], 'utf8');
  }
  return n;
};

/* --------------------------------------------------------- los archivos existen y compilan */

test('cada bloque extraído existe y es JavaScript válido', () => {
  for (const { archivo } of EXTRAIDOS) {
    const ruta = join(APP, 'assets', 'js', archivo);
    assert.ok(existsSync(ruta), 'falta assets/js/' + archivo);
    const src = readFileSync(ruta, 'utf8');
    // Compila sin ejecutar: detecta el error de sintaxis que un corte mal hecho produce.
    assert.doesNotThrow(() => new vm.Script(src, { filename: archivo }),
      archivo + ' no compila: el corte del <script> quedó mal');
    assert.ok(!/<\?(php|=)/.test(src),
      archivo + ' tiene PHP dentro: un .js no lo interpreta, sale como texto y rompe el parseo');
  }
});

test('el bloque ya no quedó también dentro del PHP', () => {
  // Duplicarlo es peor que no haberlo movido: se ejecuta dos veces y la segunda pisa a la
  // primera con listeners repetidos.
  for (const { archivo, origen, firma } of EXTRAIDOS) {
    const php = leer(origen);
    const inline = [...php.matchAll(/<script(?![^>]*\ssrc=)[^>]*>([\s\S]*?)<\/script>/g)]
      .map((m) => m[1]).join('\n');
    assert.ok(!inline.includes(firma),
      origen + ' sigue teniendo inline el bloque de ' + archivo + ' (busqué "' + firma + '")');
    assert.ok(php.includes('assets/js/' + archivo),
      origen + ' no carga assets/js/' + archivo + ': el bloque desapareció sin reemplazo');
  }
});

/* ------------------------------------------------- la trampa del precache y el query string */

/** Los assets/ que sw.js precachea, tal como se guardan: sin query string. */
function precacheados() {
  const sw = leer('sw.js');
  const ini = sw.indexOf('const STATIC_ASSETS');
  const lista = sw.slice(ini, sw.indexOf('];', ini));
  return new Set([...lista.matchAll(/\$\{APP_SCOPE\}\/(assets\/[^`]+)/g)].map((m) => m[1]));
}

test('todo bloque extraído está precacheado en sw.js', () => {
  const pre = precacheados();
  for (const { archivo } of EXTRAIDOS) {
    assert.ok(pre.has('assets/js/' + archivo),
      'assets/js/' + archivo + ' no está en STATIC_ASSETS de sw.js.\n' +
      '  Antes de extraerlo, ese código estaba inline y por tanto SIEMPRE disponible sin red.\n' +
      '  Fuera del precache, sin conexión no carga y sus botones dejan de responder.');
  }
});

test('ningún archivo precacheado se pide con ?v=', () => {
  // cacheFirst() no usa ignoreSearch: la entrada se guarda sin query string, así que pedirla
  // con ?v=... falla el match, se va a la red y sin red devuelve 503.
  const pre = precacheados();
  for (const pagina of ['index_pruebas.php', 'gestionarPruebas.php']) {
    const php = leer(pagina);
    for (const m of php.matchAll(/<script[^>]*\ssrc="([^"]+)"/g)) {
      const src = m[1];
      const ruta = src.replace(/<\?php[\s\S]*?\?>/g, '').split('?')[0]
        .replace(/^\//, '').replace('visibility2/app/', '');
      if (!pre.has(ruta)) continue;
      assert.ok(!src.includes('?v=') && !src.includes('<?php'),
        pagina + ' pide ' + ruta + ' con versión en la URL, pero está precacheado.\n' +
        '  El cache-busting de los precacheados lo da el nombre del cache (static-${VERSION}),\n' +
        '  no el query string. Con ?v= no hay acierto de cache y sin red el archivo no existe.');
    }
  }
});

/* ------------------------------------------------------------------ el presupuesto que baja */

test('el JavaScript inline no vuelve a crecer', () => {
  for (const [pagina, tope] of Object.entries(PRESUPUESTO)) {
    const n = bytesInline(leer(pagina));
    assert.ok(n <= tope,
      pagina + ' tiene ' + n + ' B de <script> inline y el tope es ' + tope + ' B.\n' +
      '  Si lo que creció es LÓGICA, va a un .js de assets/js/ y se agrega a EXTRAIDOS y a\n' +
      '  STATIC_ASSETS de sw.js. Si es configuración que PHP tiene que emitir, subí el tope\n' +
      '  y anotá el motivo en el comentario de PRESUPUESTO.');
  }
});
