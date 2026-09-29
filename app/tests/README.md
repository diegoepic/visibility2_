# Pruebas de las funciones puras de la app

No hay bundler ni framework de pruebas: son archivos `.mjs` que corren con el runner
que trae Node (v18+). No hace falta `npm install` ni `package.json`.

```bash
# desde la raíz del repo (visibility2_)
node --test "app/tests/*.test.mjs"
```

En Windows las comillas son obligatorias: sin ellas el intérprete no expande el patrón y
Node intenta cargar la ruta como si fuera un módulo.

## Qué cubren y por qué

Sólo se prueba lo que es **puro y determinista**: geometría de rutas y clasificación de
errores de la cola. Es donde un error no se ve en pantalla — se ve como kilómetros de más
o como trabajo que desaparece.

| Archivo | Cubre |
|---|---|
| `route_planner.test.mjs` | `haversine`, `nearestNeighbor`, `twoOpt` (camino abierto), `chunkPoints`, `buildGoogleMapsExportChunks`, `routeStateHash` y la regla de las botillerías |
| `queue_logic.test.mjs` | `classifyFailure` e `isLogicalSuccess` de `offline-queue.js` |
| `_load.mjs` | Cargador: ejecuta un archivo IIFE de la app dentro de un `window` falso |

## Cómo funciona el cargador

Los módulos de la app son scripts planos que se cuelgan de `window`; no tienen `export`,
así que no se pueden importar. `_load.mjs` los ejecuta en un contexto de `node:vm` con los
globales mínimos y devuelve lo que quedó colgado.

Dos detalles que cuestan tiempo si no se saben:

- **Los arreglos que salen de la vm llevan el prototipo de ESE contexto.** `assert.deepStrictEqual`
  compara prototipos, así que comparar dos listas idénticas falla con *"same structure but are
  not reference-equal"*. Para eso está el ayudante `ids()`.
- **`'clave' in objeto` es verdadero aunque el valor sea `undefined`.** Poner
  `navigator: { serviceWorker: undefined }` hace que `offline-queue.js` entre en la rama del
  service worker y reviente. La clave no debe existir.

## Pruebas marcadas como pendientes

Una prueba en `todo` documenta un defecto conocido que todavía no se corrige; el runner la
reporta aparte y **no** hace fallar la suite.

- `un 409 SIN error_code no debe darse por bueno` → hallazgo **V-12**, se corrige en la
  tarea **T4-2** del plan de implementación. Al arreglarlo, quitar el `todo`.

## Al agregar una prueba

Que falle primero contra el código anterior. Una prueba que pasa con la versión con bug y
con la corregida no está probando nada. Para comprobarlo: revertir el cambio a mano,
correr la suite, confirmar que se pone en rojo, y restaurar.
