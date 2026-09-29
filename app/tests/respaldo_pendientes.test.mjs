/**
 * El respaldo de la cola atascada tiene que llegar a soporte, no a la carpeta de descargas.
 *
 * EL FALLO QUE ESTO IMPIDE QUE VUELVA (confirmado en terreno el 14-09-2026)
 * ------------------------------------------------------------------------
 * "Descargar copia" construia un blob: y lo entregaba con <a download>. Dentro de la APK,
 * MainActivity.handleDownloadRequest() solo acepta esquemas http y https, asi que lo
 * rechazaba mostrando "Tipo de descarga no soportada"... y una decima de segundo despues la
 * propia app mostraba "Copia descargada (1 envio(s))", porque desde JavaScript no hay forma
 * de enterarse de que el WebView descarto la descarga.
 *
 * El ejecutor veia los dos avisos seguidos, se quedaba con el segundo, y el archivo no
 * existia. Era justo la via de escape que la pantalla le ofrece cuando algo falla, y estaba
 * muerta. Arreglarlo del lado Android obliga a recompilar y repartir la APK; subirlo al
 * servidor se despliega el mismo dia.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const APP = join(dirname(fileURLToPath(import.meta.url)), '..');
const COLA = readFileSync(join(APP, 'assets', 'js', 'offline-queue.js'), 'utf8');
const REC = readFileSync(join(APP, 'recuperacion.php'), 'utf8');
const WRAP = readFileSync(join(APP, '..', 'android', 'VisibilityWebWrapper', 'app', 'src',
                               'main', 'java', 'com', 'visibility', 'wrapper', 'MainActivity.kt'), 'utf8');

/** El cuerpo de exportarRespaldoLocal, sin comentarios. */
function cuerpoExport() {
  const i = COLA.indexOf('async exportarRespaldoLocal(');
  assert.ok(i > 0, 'ya no existe exportarRespaldoLocal');
  const j = COLA.indexOf('\n    },', i);
  return COLA.slice(i, j)
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .split(/\r?\n/).map((l) => l.replace(/(^|\s)\/\/.*$/, '$1')).join('\n');
}

test('el respaldo se sube al servidor, no solo se descarga', () => {
  const c = cuerpoExport();
  assert.ok(/export_diagnostico\.php/.test(c),
    'exportarRespaldoLocal ya no manda el respaldo al servidor.\n' +
    '  La descarga local NO sirve dentro de la APK (ver la cabecera de esta prueba), y el\n' +
    '  botón existe para que soporte vea qué quedó atascado.');
  assert.ok(/method:\s*'POST'/.test(c), 'la subida dejó de ser POST');
});

test('la subida reintenta una vez si el token caducó', () => {
  // Es el caso más frecuente: el ejecutor lleva horas con la página abierta y justo
  // entonces es cuando necesita el respaldo.
  const c = cuerpoExport();
  assert.ok(/419|403/.test(c) && /refreshCSRF\(\)/.test(c),
    'se perdió el reintento con token nuevo: un CSRF vencido dejaría a soporte sin la copia');
});

test('queda una salida que funciona dentro de la APK', () => {
  const c = cuerpoExport();
  assert.ok(/clipboard/.test(c) && /execCommand\('copy'\)/.test(c),
    'sin portapapeles no queda ninguna vía sin conexión: la descarga de archivo la rechaza ' +
    'el envoltorio Android y la subida necesita red.');
});

test('la pantalla no vuelve a afirmar que descargó algo', () => {
  // El bug no era solo que fallara: era que la app aseguraba lo contrario.
  const limpio = REC.replace(/\/\*[\s\S]*?\*\//g, ' ')
    .split(/\r?\n/).map((l) => l.replace(/(^|\s)\/\/.*$/, '$1')).join('\n');
  assert.ok(!/toast\('Copia descargada/.test(limpio),
    'volvió el aviso "Copia descargada", que se mostraba incluso cuando no se descargaba nada');
  assert.ok(/r\.subido/.test(limpio),
    'el aviso ya no distingue si la copia llegó al servidor o no');
  assert.ok(/recCopiar/.test(REC), 'desapareció el botón de copiar al portapapeles');
});

test('el envoltorio Android sigue rechazando blob: — por eso existe todo lo anterior', () => {
  // Si algún día se arregla del lado Android (blob -> base64 por JavascriptInterface), esta
  // prueba falla y toca revisar si la subida al servidor sigue siendo necesaria. Lo es:
  // soporte prefiere el archivo en el servidor antes que en el teléfono del ejecutor.
  assert.ok(/scheme != "http" && scheme != "https"/.test(WRAP),
    'cambió handleDownloadRequest: revisar si la descarga local ya funciona en la APK');
});
