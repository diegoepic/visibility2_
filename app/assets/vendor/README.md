# Librerías de terceros servidas desde el propio servidor

Hasta v7.3.0 estas cuatro librerías se cargaban desde `cdn.jsdelivr.net`. Eso tenía dos
problemas concretos:

1. **La app offline se quedaba sin ellas.** El service worker no cachea orígenes externos
   (`sw.js` corta con `if (url.origin !== self.location.origin) return`), así que en modo
   avión `imageCompression`, `heic2any` y `exifr` simplemente no existían: las fotos se
   encolaban sin comprimir y sin metadatos EXIF.
2. **`browser-image-compression` estaba pedido con `@latest`.** Nadie sabía qué versión
   corría en los teléfonos, y un cambio del paquete entraba a producción sin despliegue.

Ahora se sirven desde aquí, con versión fija, y las tres primeras van precacheadas en
`sw.js` (`STATIC_ASSETS`).

## Qué hay, y de dónde salió

| Archivo | Paquete | Versión | Origen exacto |
|---|---|---|---|
| `browser-image-compression.js` | browser-image-compression | **2.0.2** | `https://cdn.jsdelivr.net/npm/browser-image-compression@2.0.2/dist/browser-image-compression.js` |
| `heic2any.min.js` | heic2any | **0.0.4** | `https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js` |
| `exifr.umd.js` | exifr | **7.1.3** | `https://cdn.jsdelivr.net/npm/exifr@7.1.3/dist/full.umd.js` |
| `sweetalert2.all.min.js` | sweetalert2 | **11.26.25** | `https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js` |

### sha256 (para verificar que nadie los tocó)

```
c6713a21756570af4c230f706faac4f0187845928bd14fd5910210d1cdc6fb87  browser-image-compression.js
0963cfa50e9e1e7e6af929a40a81e3e898a673f1270eafa6917dd137e4968164  heic2any.min.js
2bd05117781c12ddd965dee846de3b3f986b5ecb4c7f6a78f2d2ec1db7c65ae7  exifr.umd.js
4e86f0e22e4771b5b8aac24c613c661806a678b1afa284d03cf6ad03d3e21a0a  sweetalert2.all.min.js
```

Comprobar con: `sha256sum app/assets/vendor/*.js`

## Importante: no cambió ni un byte de comportamiento

Las versiones se eligieron para que fueran **idénticas a lo que el CDN estaba sirviendo el
2026-09-03**, no para actualizar nada:

- `browser-image-compression@latest` resolvía a `2.0.2` — archivo byte a byte igual.
- `exifr/dist/full.umd.js` (sin fijar) resolvía a `7.1.3` — byte a byte igual.
- `sweetalert2@11` resolvía a `11.26.25` — byte a byte igual.
- `heic2any` ya venía fijado en `0.0.4`.

Así, si algo se rompe tras el despliegue, la causa no es un cambio de versión de librería.

## Quién carga qué

| Archivo | Lo usan |
|---|---|
| `browser-image-compression.js` | `gestionarPruebas.php`, `gestionar.php` |
| `heic2any.min.js` | `gestionarPruebas.php` |
| `exifr.umd.js` | `gestionarPruebas.php`, `gestionar.php`, `gestionarIW.php` |
| `sweetalert2.all.min.js` | `login.php`, `login_pruebas.php` |

`sweetalert2` **no** va precacheado a propósito: sólo lo usa el login, y al login no se
llega sin red.

## Cómo actualizar una de estas librerías

1. Descargar la versión nueva **con la versión en la URL**, nunca `@latest`.
2. Recalcular el sha256 y actualizar la tabla de arriba.
3. Subir la versión en los **tres** sitios a la vez: `lib/asset_version.php`,
   `sw.js` (`VERSION`) y `assets/js/v2_cache.js` (`SW_VERSION`). Si no, el service worker
   sigue entregando la copia vieja desde `static-<VERSION>`.
4. Probar en modo avión: sacar una foto de ≥5 MB y confirmar que se comprime y conserva EXIF.

## Por qué estos archivos no llevan `?v=`

Los assets que están en `STATIC_ASSETS` de `sw.js` se piden **sin** query, igual que
`db.js` u `offline-queue.js`. La clave de caché incluye el query string: si la página
pidiera `exifr.umd.js?v=v7.4.0` y el service worker hubiera precacheado
`exifr.umd.js`, no habría acierto y offline daría 503. El cache-busting lo da el nombre
del caché (`static-v7.4.0`), que cambia con cada versión.
