/**
 * Pruebas de las reglas centralizadas en app/lib/dominio/ (T6-2).
 *
 * Igual que ruta_visibilidad.test.mjs: no se ejecuta PHP —no hay intérprete en este
 * entorno— sino que se leen los archivos. Lo que vigilan es lo que se rompe con el
 * tiempo: que alguien vuelva a resolver la regla a mano en su archivo.
 *
 * Estas pruebas se ganaron el sueldo el día que se escribieron: encontraron seis copias
 * de la comprobación de permisos que no había aparecido en ningún grep hecho a mano.
 *
 * Ejecutar:  node --test "app/tests/*.test.mjs"
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = dirname(fileURLToPath(import.meta.url));
const APP = join(AQUI, '..');
const DOMINIO = join(APP, 'lib', 'dominio');

function phpsBajo(dir) {
  const out = [];
  for (const nombre of readdirSync(dir)) {
    if (nombre === 'vendor' || nombre === 'node_modules' || nombre === 'uploads') continue;
    const p = join(dir, nombre);
    if (statSync(p).isDirectory()) out.push(...phpsBajo(p));
    else if (nombre.endsWith('.php')) out.push(p);
  }
  return out;
}

const ARCHIVOS = phpsBajo(APP);
const rel = (p) => relative(APP, p).split(sep).join('/');
const fuente = (p) => readFileSync(p, 'utf8');
/** El SQL de este repo va partido en varias líneas y a veces con "\n" dentro de la cadena. */
const aplanar = (p) => fuente(p).split('\\n').join(' ').replace(/\s+/g, ' ');

/* ------------------------------------------------------------------ preguntas por cadena */

test('preguntas_cadena.php expone las cuatro funciones', () => {
  const src = fuente(join(DOMINIO, 'preguntas_cadena.php'));
  for (const f of ['preguntas_cadena_activa', 'preguntas_cadena_de_local',
                   'preguntas_cadena_filtro', 'preguntas_cadena_visible']) {
    assert.match(src, new RegExp('function ' + f + '\\('), 'falta ' + f);
  }
});

test('nadie resuelve la regla de cadena a mano', () => {
  // Estas dos firmas son la resolución: el SHOW COLUMNS de la guarda de migración y el
  // filtro por id_cadena. Fuera de lib/dominio/ no deberían aparecer.
  const FIRMAS = [
    "SHOW COLUMNS FROM form_questions LIKE 'id_cadena'",
    'id_cadena IS NULL',
  ];
  const culpables = [];
  for (const p of ARCHIVOS) {
    if (p.startsWith(DOMINIO)) continue;
    const src = fuente(p);
    for (const f of FIRMAS) if (src.includes(f)) culpables.push(rel(p) + '  ->  ' + f);
  }
  assert.deepStrictEqual(
    culpables, [],
    'La regla "cadena propia, si no general" volvió a resolverse a mano. Usá\n' +
    '  preguntas_cadena_filtro()  para pintar\n' +
    '  preguntas_cadena_visible() para guardar\n' +
    'de lib/dominio/preguntas_cadena.php.\nArchivos:\n  ' + culpables.join('\n  ')
  );
});

test('los cuatro archivos de encuesta aplican la regla de cadena', () => {
  // gestionarIW.php queda fuera a propósito: en IW (tipo 2) las preguntas se pintan
  // antes de elegir el local, así que no hay cadena contra la cual resolver.
  const ESPERADOS = [
    'gestionarPruebas.php',                     // pintado actual
    'gestionar.php',                            // pintado antiguo — no la aplicaba hasta T6-2
    'procesar_gestion_pruebas.php',             // guardado
    'api/mobile/gestion_bootstrap_mobile.php',  // API móvil — tampoco la aplicaba
  ];
  for (const archivo of ESPERADOS) {
    const src = fuente(join(APP, archivo));
    assert.ok(src.includes('dominio/preguntas_cadena.php'),
      archivo + ' no requiere lib/dominio/preguntas_cadena.php');
    assert.ok(/preguntas_cadena_(filtro|visible)\(/.test(src),
      archivo + ' requiere la librería pero no llama a ninguna de sus funciones');
  }
});

test('toda consulta que lista preguntas de una campaña pasa por el filtro', () => {
  // Firma de un listado: FROM form_questions ... WHERE id_formulario = ?
  const rx = /FROM form_questions\s+WHERE id_formulario = \?(\{\$filtroCadena\['cond'\]\}|\{\$condCadena\})?/g;
  const sinFiltro = [];
  for (const p of ARCHIVOS) {
    if (p.startsWith(DOMINIO)) continue;
    if (/gestionarIW/.test(rel(p))) continue;   // tipo 2, fuera de alcance
    for (const m of fuente(p).matchAll(rx)) {
      if (!m[1]) sinFiltro.push(rel(p) + '  ->  ' + m[0].replace(/\s+/g, ' '));
    }
  }
  assert.deepStrictEqual(sinFiltro, [],
    'Listado de preguntas sin filtro de cadena:\n  ' + sinFiltro.join('\n  '));
});

/* ----------------------------------------------------------------------- autorización */

test('autorizacion.php expone las tres funciones', () => {
  const src = fuente(join(DOMINIO, 'autorizacion.php'));
  for (const f of ['puede_usuario_tocar', 'auth_visita', 'visita_es_del_usuario']) {
    assert.match(src, new RegExp('function ' + f + '\\('), 'falta ' + f);
  }
});

/**
 * Las dos pruebas que siguen persiguen la comprobación SUELTA —la que las funciones de
 * dominio reemplazan— y no cualquier consulta que filtre por dueño. Filtrar por
 * id_usuario dentro de una consulta que además trae datos es la forma correcta y la más
 * barata: api/gestion_snapshot.php y api/gestion_status.php lo hacen así y está bien.
 *
 * La firma de la comprobación suelta es que NO selecciona datos: sólo `1`, `COUNT(*)`,
 * `id` o `id_local`.
 */

test('nadie comprueba la propiedad de una visita a mano', () => {
  const rx = /SELECT (?:id|id_local|id, id_local) FROM visita WHERE id ?= ?\? AND id_usuario ?= ?\?/;
  const culpables = ARCHIVOS
    .filter((p) => !p.startsWith(DOMINIO))
    .filter((p) => rx.test(aplanar(p)))
    .map(rel)
    // Los *_pruebas del flujo IW son copias muertas: gestionarIW.php y gestionarIW_pruebas.php
    // apuntan AMBOS a los endpoints sin sufijo. Se borran en T6-7; hasta entonces no se tocan.
    .filter((f) => !/IW_pruebas\.php$/.test(f));

  assert.deepStrictEqual(culpables, [],
    'Comprobación de propiedad de visita hecha a mano. Usá auth_visita() de\n' +
    'lib/dominio/autorizacion.php.\nArchivos:\n  ' + culpables.join('\n  '));
});

test('nadie comprueba la asignación de un local a mano', () => {
  const rx = new RegExp(
    'SELECT (?:1|COUNT\\(\\*\\)(?: AS \\w+)?|\\w+\\.id) ' +
    'FROM formularioQuestion(?: fq)?' +
    '(?: (?:INNER )?JOIN \\w+ +\\w+ ON [^;]{0,120}?)?' +
    'WHERE (?:fq\\.)?id_formulario ?= ?\\? ' +
    'AND (?:fq\\.)?id_local ?= ?\\? ' +
    'AND (?:fq\\.)?id_usuario ?= ?\\?'
  );
  const culpables = ARCHIVOS
    .filter((p) => !p.startsWith(DOMINIO))
    .filter((p) => rx.test(aplanar(p)))
    .map(rel);

  assert.deepStrictEqual(culpables, [],
    'Comprobación de asignación hecha a mano. Usá puede_usuario_tocar() de\n' +
    'lib/dominio/autorizacion.php.\nArchivos:\n  ' + culpables.join('\n  '));
});

test('quien llama a las funciones de dominio carga su librería', () => {
  const MAPA = [
    [/\bpuede_usuario_tocar\(|\bauth_visita\(/, 'dominio/autorizacion.php', 'lib/api_helpers.php'],
    [/\bpreguntas_cadena_\w+\(/, 'dominio/preguntas_cadena.php', null],
  ];
  for (const p of ARCHIVOS) {
    if (p.startsWith(DOMINIO)) continue;
    const src = fuente(p);
    for (const [uso, lib, viaIndirecta] of MAPA) {
      if (!uso.test(src)) continue;
      const directo = src.includes(lib);
      const indirecto = viaIndirecta !== null && src.includes(viaIndirecta);
      assert.ok(directo || indirecto,
        rel(p) + ' usa una función de ' + lib + ' sin requerirla');
    }
  }
});
