/**
 * Presupuesto de consultas dentro de bucles en el guardado de la gestión (T6-3).
 *
 * POR QUE ESTA PRUEBA
 * -------------------
 * `procesar_gestion_pruebas.php` hace todo su trabajo dentro de una transacción. Cada
 * `$conn->prepare()` dentro de un bucle es un viaje al servidor por elemento, con los
 * bloqueos tomados. Se midió sobre el volcado del 2026-09-03 cuánto vale cada bucle:
 *
 *     materiales por gestión   mediana 1    p90 2    p99 9     máximo 50
 *     respuestas por gestión   mediana 44   p90 90   p99 143   máximo 2104
 *
 * O sea el bucle caro era el de la ENCUESTA, no el de materiales — al revés de lo que
 * decía el hallazgo V-18 de la auditoría. Ahí se hacían TRES prepares por respuesta
 * (tipo de pregunta, texto de la opción, y el INSERT), unos 132 en la gestión mediana.
 * Ahora son cero: los metadatos de las preguntas se precargan en una consulta
 * (`mapaPreguntasDeEncuesta`) y las dos sentencias se preparan una vez y se reejecutan.
 *
 * Esta prueba no mide rendimiento: fija un presupuesto para que nadie vuelva a meter un
 * prepare en el bucle sin darse cuenta. Si el número sube, la prueba dice por qué importa.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const APP = join(dirname(fileURLToPath(import.meta.url)), '..');
const ARCHIVO = 'procesar_gestion_pruebas.php';

/**
 * Profundidad de bucle por línea. Heurística deliberadamente simple —cuenta llaves y
 * arranca en foreach/for/while— porque el archivo tiene un estilo consistente y una
 * heurística que se entiende de un vistazo vale más acá que un parser de PHP.
 */
function prepararesEnBucle(src) {
  const lineas = src.split('\n');
  const pila = [];
  const hallazgos = [];

  for (let i = 0; i < lineas.length; i++) {
    const l = lineas[i];
    const s = l.trim();
    const delta = (l.split('{').length - 1) - (l.split('}').length - 1);

    if (/^(foreach|for|while)\s*\(/.test(s)) {
      pila.push(delta);
    } else if (pila.length) {
      pila[pila.length - 1] += delta;
      while (pila.length && pila[pila.length - 1] <= 0) pila.pop();
    }

    if (pila.length > 0 && l.includes('$conn->prepare(')) {
      hallazgos.push({ linea: i + 1, profundidad: pila.length, texto: s.slice(0, 90) });
    }
  }
  return hallazgos;
}

const SRC = readFileSync(join(APP, ARCHIVO), 'utf8');

/**
 * Presupuesto actual. Los 10 que quedan están todos en bucles cuya mediana es 1
 * (materiales), así que bajarlos no cambia nada medible. Si vas a subir este número,
 * medí primero cuántos elementos recorre tu bucle.
 */
const PRESUPUESTO = 10;

test('no crecen las consultas preparadas dentro de bucles', () => {
  const h = prepararesEnBucle(SRC);
  const detalle = h.map((x) => `  L${x.linea} (prof ${x.profundidad})  ${x.texto}`).join('\n');
  assert.ok(
    h.length <= PRESUPUESTO,
    `${ARCHIVO} tiene ${h.length} prepare() dentro de bucles (presupuesto: ${PRESUPUESTO}).\n` +
    'Cada uno es un viaje al servidor por elemento, con la transacción abierta.\n' +
    'Antes de subir el presupuesto, medí cuántos elementos recorre el bucle:\n' +
    'la encuesta tiene mediana 44 y los materiales mediana 1.\n' + detalle
  );
});

test('el bucle de respuestas de encuesta no prepara nada', () => {
  // El bucle empieza en el foreach de $_POST['respuesta'] y termina donde se cierra
  // $stmtO. Dentro no puede haber ni un prepare: es el bucle largo.
  const ini = SRC.indexOf("foreach ($_POST['respuesta'] as $id_form_question => $respValue)");
  assert.notStrictEqual(ini, -1, 'no se encontró el bucle de respuestas');
  const fin = SRC.indexOf('$stmtO->close();', ini);
  assert.notStrictEqual(fin, -1, 'no se encontró el cierre de $stmtO tras el bucle');

  const cuerpo = SRC.slice(ini, fin);
  const prepares = (cuerpo.match(/\$conn->prepare\(/g) || []).length;
  assert.strictEqual(prepares, 0,
    `El bucle de respuestas volvió a tener ${prepares} prepare(). La mediana de una ` +
    'gestión son 44 respuestas: preparar ahí dentro son 44 viajes al servidor con la ' +
    'transacción abierta. Preparalo antes del bucle y reejecutalo, o precargá el dato ' +
    'como hace mapaPreguntasDeEncuesta().');
});

test('las precargas siguen existiendo y se usan', () => {
  for (const [fn, uso] of [
    ['mapaMaterialesDeGestion', '$mapaMateriales = mapaMaterialesDeGestion('],
    ['mapaPreguntasDeEncuesta', '$mapaPreguntas = mapaPreguntasDeEncuesta('],
  ]) {
    assert.ok(SRC.includes('function ' + fn + '('), 'falta la función ' + fn);
    assert.ok(SRC.includes(uso), fn + ' está definida pero ya nadie la llama');
  }
});

test('el id de material sale del mapa, no de una consulta por material', () => {
  // Exige que sea un prepare() de verdad: el docblock de mapaMaterialesDeGestion cita
  // el SQL viejo a propósito, para explicar qué reemplazó.
  assert.ok(
    !/\$conn->prepare\("SELECT m\.id FROM material m JOIN/.test(SRC),
    'volvió la consulta de material por fila dentro del bucle; usá $mapaMateriales[$idFQ] ?? 0'
  );
  const lecturas = (SRC.match(/\$mapaMateriales\[\$idFQ\] \?\? 0/g) || []).length;
  assert.strictEqual(lecturas, 4, 'se esperaban 4 lecturas del mapa, hay ' + lecturas);
});

test('las sentencias reutilizadas no se cierran dentro del bucle', () => {
  // El error clásico al izar un prepare: cerrarlo en la primera vuelta y reventar en la
  // segunda con "mysqli_stmt::execute(): Couldn't fetch mysqli_stmt".
  const ini = SRC.indexOf("foreach ($_POST['respuesta'] as $id_form_question => $respValue)");
  const fin = SRC.indexOf('$stmtO->close();', ini);
  const cuerpo = SRC.slice(ini, fin);

  // Dentro del bucle sólo puede cerrarse justo antes de un throw, que aborta todo.
  for (const linea of cuerpo.split('\n')) {
    if (!linea.includes('$stmtO->close()')) continue;
    assert.ok(linea.includes('throw '),
      'Se cierra $stmtO dentro del bucle sin abortar:\n  ' + linea.trim());
  }
});
