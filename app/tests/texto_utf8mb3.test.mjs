/**
 * Un emoji no puede costar una gestión.
 *
 * EL FALLO (producción, 28-09-2026)
 * ---------------------------------
 * Un ejecutor escribió un emoji al gestionar un local y la gestión no se guardó:
 *
 *     endpoint    procesar_gestion_pruebas.php
 *     error_code  TRANSACTION_FAILED
 *     mensaje     Conversion from collation utf8mb4_0900_ai_ci into
 *                 utf8mb3_unicode_ci impossible for parameter
 *
 * con_.php abre la conexión en utf8mb3; procesar_gestion_pruebas.php la sube a utf8mb4
 * (línea 90) porque `gestion_visita` sí es utf8mb4. Desde ahí todo parámetro de texto va
 * como utf8mb4, y dos columnas del mismo guardado siguen en utf8mb3:
 *
 *     formularioQuestion.observacion
 *     form_question_responses.answer_text
 *
 * utf8mb3 llega a 3 bytes por carácter y un emoji ocupa 4. MySQL aborta la transacción
 * entera, y como TRANSACTION_FAILED sale 5xx, offline-queue.js lo reintenta para siempre.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const APP = join(RAIZ, 'app');
const LIB = readFileSync(join(APP, 'lib', 'dominio', 'texto_utf8mb3.php'), 'utf8');
const OBS = readFileSync(join(APP, 'lib', 'dominio', 'observacion.php'), 'utf8');
const PROC = readFileSync(join(APP, 'procesar_gestion_pruebas.php'), 'utf8');
const MIG = readFileSync(join(RAIZ, 'scripts', '41_utf8mb4_observacion.sql'), 'utf8');

const sinComentarios = (src) => src
  .replace(/\/\*[\s\S]*?\*\//g, ' ')
  .split(/\r?\n/)
  .map((l) => l.replace(/(^|\s)\/\/.*$/, '$1'))
  .map((l) => l.replace(/^\s*--.*$/, ''))
  .join('\n');

test('el saneado existe y aguanta UTF-8 inválido', () => {
  assert.ok(LIB.includes('10FFFF'), 'se perdió el rango de 4 bytes');
  assert.ok(LIB.includes('xF0') && LIB.includes('xF7'),
    'falta el respaldo por bytes para cuando el texto no es UTF-8 valido');
  assert.ok(/=== null/.test(LIB), 'no se comprueba el null que devuelve preg_replace');
});

test('la observación se sanea ANTES de escapar y recortar', () => {
  const limpio = sinComentarios(OBS);
  assert.ok(/utf8mb3_saneado\(\$txt\)/.test(limpio),
    'observacion_normalizar() ya no sanea: un emoji en la observación pierde la gestión');
  // Si se saneara después del recorte, el corte podría partir una secuencia de 4 bytes
  // por la mitad y dejar bytes sueltos, que es peor que el emoji.
  const i = limpio.indexOf('utf8mb3_saneado');
  const j = limpio.indexOf('observacion_recortar(');
  assert.ok(i > j, 'el saneado quedó fuera del argumento de observacion_recortar()');
  assert.ok(/require_once __DIR__ \. '\/texto_utf8mb3\.php'/.test(OBS),
    'observacion.php usa utf8mb3_saneado() sin cargar la librería');
});

test('answer_text se sanea en su embudo único', () => {
  const limpio = sinComentarios(PROC);
  assert.ok(/\$answer_text = utf8mb3_saneado\(\$answer_text\);/.test(limpio),
    'insert_fqr() ya no sanea answer_text. Es la única puerta por la que entran las ' +
    'respuestas de encuesta: si se quita de ahí, hay que ponerlo en cada llamador.');
  assert.ok(/require_once __DIR__ \. '\/lib\/dominio\/texto_utf8mb3\.php'/.test(PROC),
    'falta el require explícito: hoy funciona por rebote desde observacion.php, y eso se rompe solo');
});

test('la migración convierte la COLUMNA, nunca la tabla', () => {
  const sql = sinComentarios(MIG);
  // formularioQuestion.material se compara contra material.nombre en 9 consultas y
  // `material` sigue en utf8mb3: convertir la tabla entera arriesga "Illegal mix of
  // collations" en todas ellas. La columna observacion no se compara con nada.
  assert.ok(!/CONVERT TO CHARACTER SET/i.test(sql),
    'la migración convierte la tabla entera: eso puede romper los 9 JOIN contra `material`');
  assert.ok(/MODIFY `observacion`/.test(sql), 'ya no modifica la columna observacion');
  assert.ok(/utf8mb4_unicode_ci/.test(sql) && !/utf8mb4_0900_ai_ci/.test(sql),
    'debe usar utf8mb4_unicode_ci, no el 0900_ai_ci por defecto de MySQL 8: mantenerse en la ' +
    'misma familia de colación reduce el riesgo de mezcla');
});

test('la migración no escribe el largo a mano', () => {
  const sql = sinComentarios(MIG);
  // El largo de esta columna difiere entre ambientes: la auditoría la vio en 360,
  // scripts/40 la deja en 2000 y producción estaba en 600. Un largo fijo trunca datos.
  assert.ok(/CHARACTER_MAXIMUM_LENGTH/.test(sql),
    'el largo tiene que leerse de information_schema, no escribirse a mano');
  assert.ok(!/VARCHAR\(\s*\d+\s*\)/i.test(sql),
    'apareció un VARCHAR con largo fijo: si no coincide con el ambiente, trunca observaciones');
  assert.ok(/IS_NULLABLE/.test(sql), 'la migración no preserva el NOT NULL');
});
