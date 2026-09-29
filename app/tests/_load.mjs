/**
 * Carga un archivo IIFE de la app dentro de un `window` falso, para poder probar sus
 * funciones puras desde Node sin navegador y sin tocar el archivo original.
 *
 * Los módulos de la app (route_planner.js, route_engine.js, ...) son scripts planos que
 * se cuelgan de `window`. No hay bundler ni `export`, así que no se pueden importar:
 * se ejecutan en un contexto de vm y se lee lo que dejaron colgado.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const AQUI = path.dirname(fileURLToPath(import.meta.url));

/**
 * @param {string} rutaRelativa  ruta del archivo respecto de app/tests/
 * @param {object} extraGlobals  globales adicionales para el contexto (stubs, Date fija, ...)
 * @param {object} winSeed       propiedades iniciales del `window` falso (addEventListener, ...)
 * @returns {object} el objeto `window` resultante
 */
export function loadGlobal(rutaRelativa, extraGlobals = {}, winSeed = {}) {
  const src = readFileSync(path.resolve(AQUI, rutaRelativa), 'utf8');
  const win = Object.assign({}, winSeed);

  const base = {
    window: win,
    self: win,
    console,
    Math, Date, JSON, Number, String, Array, Object, Boolean, RegExp,
    Promise, Set, Map, Error, isNaN, parseInt, parseFloat,
    encodeURIComponent, decodeURIComponent,
    setTimeout, clearTimeout, setInterval, clearInterval,
  };

  const ctx = vm.createContext(Object.assign(base, extraGlobals));
  // El contexto ES el objeto global: lo que el IIFE asigne a `window.X` queda en win.X,
  // pero lo que asigne sin prefijo queda en ctx. Se devuelven ambos fusionados.
  vm.runInContext(src, ctx, { filename: rutaRelativa });
  return Object.assign({}, ctx, win);
}

/**
 * Date congelada a una hora del día. Necesario para probar la regla de las botillerías,
 * que depende de si son antes o después de las 13:00: sin esto, la prueba pasaría por la
 * mañana y fallaría por la tarde.
 */
export function DateAHora(hora, minuto = 0) {
  const REAL = Date;
  return class extends REAL {
    constructor(...args) {
      if (args.length) { super(...args); return; }
      super(2026, 0, 15, hora, minuto, 0);
    }
    static now() { return REAL.now(); }
  };
}

/** Punto de ruta mínimo, con los campos que consume el planificador. */
export function pt(idLocal, lat, lng, extra = {}) {
  return Object.assign({ idLocal, lat, lng, isBotilleria: false }, extra);
}

/**
 * Extrae los idLocal como arreglo del realm de Node.
 *
 * Los arreglos que devuelve el código cargado en la vm llevan el Array.prototype de ESE
 * contexto, y assert.deepStrictEqual compara prototipos: sin este paso, comparar dos
 * listas idénticas falla con "same structure but are not reference-equal".
 */
export function ids(puntos) {
  return Array.from(puntos, p => p.idLocal);
}

/** Distancia total de una secuencia, en metros, usando el haversine del propio planner. */
export function largoRuta(RoutePlanner, origen, puntos) {
  let total = 0;
  let actual = origen;
  for (const p of puntos) {
    total += RoutePlanner.haversine(actual, p);
    actual = p;
  }
  return total;
}
