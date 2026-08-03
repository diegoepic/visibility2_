# Tótem "Brindemos lo real" — Puntí Ferrer

Juego para tótem touch de la activación de Puntí Ferrer en el Walmart Holiday
Meeting (Espacio Riesco, fines de agosto, 2 días). El jugador elige su momento y
su variedad, recibe un vino recomendado y tiene que **servir la medida perfecta**
manteniendo presionada la copa y soltando en la línea dorada. Si acierta, gana un
20% OFF y se lleva un código QR.

Funciona **100% offline**. Los datos se guardan en el tótem y suben solos al
servidor visibility cuando hay señal.

---

## Estructura

```
pf_totem/                 ← esto va al tótem (carpeta completa)
  index.html              5 pantallas + panel admin oculto
  sw.js                   service worker: copia todo para uso sin red
  css/style.css
  js/config.js            ⚙ TODO lo editable está acá
  js/db.js                IndexedDB (sesiones, eventos, ganadores, códigos)
  js/game.js              flujo del juego, copa SVG, panel admin
  js/audio.js             sonidos sintetizados (no hay archivos de audio)
  js/keyboard.js          teclado táctil propio
  js/sync.js              subida por lotes al servidor
  js/offline.js           registra el service worker y reporta su estado
  js/vendor/qrcode.min.js
  assets/fonts/           Cinzel + Jost (SIL OFL, se pueden redistribuir)
  assets/img/             fotos de copas del cliente (hoy sin uso, ver abajo)
  tests/                  smoke · sync · offline · dificultad · shots

pf_totem_api/sync.php     ← esto va al SERVIDOR (no al tótem)
scripts/22_pf_totem_juego.sql  ← correr en MySQL antes del evento
```

---

## Puesta en marcha

### 1. Base de datos
Correr `scripts/22_pf_totem_juego.sql` en phpMyAdmin o `mysql` CLI. Crea
`pf_totem_sesion`, `pf_totem_evento` y `pf_totem_ganador`. Al final del archivo
están las consultas de reporte para el cliente.

### 2. Servidor
1. Subir `pf_totem_api/sync.php` a `public_html/visibility2/pf_totem_api/`.
2. Agregar a `app/.env`:
   ```
   PF_TOTEM_TOKEN=<token largo y aleatorio>
   ```

> **Por qué el endpoint no vive en `app/api/`:** `app/.user.ini` aplica
> `auto_prepend_file = _session_guard.php` a todo lo que cuelga de `app/`, y ese
> guard exige sesión web de portal. El tótem es un kiosko anónimo sin sesión, y
> el único bypass existente es para `app/api/mobile/`. Colgándolo de la raíz no
> pasa por el guard y no hay que tocar archivos compartidos.

### 3. Tótem
1. Copiar la carpeta `pf_totem/` completa al equipo.
2. Editar `js/config.js`:
   - `sync.url` → `https://SERVIDOR/visibility2/pf_totem_api/sync.php`
   - `sync.token` → el mismo valor de `PF_TOTEM_TOKEN`
   - `pantallaCompleta: true` (en desarrollo conviene dejarlo en `false`)
3. Abrir `index.html` en modo kiosko:
   - **Windows:** `chrome.exe --kiosk --disable-pinch --overscroll-history-navigation=0 "file:///C:/pf_totem/index.html"`
   - **Android:** empaquetar como WebView igual que `VisibilityWebWrapper`, o usar
     Fully Kiosk Browser apuntando al `index.html` local.
4. Verificar en el panel admin (5 toques en el monograma PF → PIN `2468`) que
   "Probar conexión" responda OK.

**Importante:** no borrar los datos del navegador entre los dos días del evento —
ahí vive todo lo que aún no se ha sincronizado.

### 3-bis. Si el tótem carga el juego por HTTP (no desde archivos locales)

Sirve igual: `sw.js` deja una copia completa del juego dentro del tótem en el
primer arranque, y desde ahí ya no depende de la señal. Pero hay tres
condiciones que cumplir o el respaldo no existe:

1. **Tiene que ser HTTPS.** Los service workers sólo se registran en `https://`
   (o en `localhost`). Servido por `http://` plano, el registro falla en
   silencio y el tótem queda dependiendo de la red — que es exactamente lo que
   se quería evitar.
2. **`sw.js` va en la raíz de `pf_totem/`**, no dentro de `js/`. Un service
   worker sólo controla su propia carpeta hacia abajo.
3. **Hay que abrirlo una vez con red antes del evento** y confirmar en el panel
   admin que dice *"Listo para funcionar sin red — 13 de 13 archivos"*. Si dice
   *"Descarga incompleta"*, recargar con red disponible.

`js/config.js` se pide siempre a la red primero (con el caché como respaldo), así
que editarlo en el servidor surte efecto en el siguiente arranque con señal sin
tener que tocar el service worker.

**Al cambiar cualquier archivo del juego hay que subir `VERSION` en `sw.js`**, o
los tótems seguirán usando la copia vieja. El service worker nuevo no se activa
en el momento (no interrumpe una partida en curso): entra en el siguiente
arranque, o de inmediato con *Instalar y reiniciar* en el panel admin.

---

## Panel admin

5 toques seguidos en el monograma **PF** de la pantalla de inicio → PIN (`2468`,
cambiar en `config.js` antes del evento). Permite:

- ver jugadas/ganadas/perdidas/abandonos del día y del total;
- forzar sincronización y probar la conexión;
- **confirmar que el juego ya funciona sin red** (bloque "Funcionamiento sin
  red") e instalar una versión nueva;
- **exportar respaldo JSON** (usar al cierre de cada día, es el plan B si el
  sync nunca llega a conectar);
- cambiar la dificultad en caliente;
- activar/desactivar el sonido;
- importar el pool de códigos únicos.

---

## Cómo se gana (y cómo calibrarlo)

La copa se llena mientras se mantiene presionado. Se gana si al soltar el nivel
cae dentro de la zona de tolerancia.

| Dificultad | Margen | Banda visible | Ganan aprox. | Quedan "cerca" |
|---|---|---|---|---|
| `facil` | ±8 % | sí | ~78 % | 20 % |
| `normal` | ±4,5 % | sí | ~54 % | 35 % |
| **`dificil` (por defecto)** | **±3 %** | **no** | **~38 %** | **37 %** |
| `experta` | ±2 % | no | ~27 % | 32 % |

Se cambia en caliente desde el panel admin, así que el día del evento se puede
ajustar según cómo reaccione la gente. Los números salen de `tests/dificultad.js`,
que mide usando las funciones reales del juego.

La dificultad se apoya en tres cosas, no sólo en achicar el margen:

**1. La línea cambia en cada partida** (entre 45 % y 78 %). Con una línea fija en
un stand la gente mira jugar a los de adelante, memoriza "aguanto 2,5 segundos" y
gana sin siquiera mirar la pantalla. Sorteándola, el único camino es mirar y
calcular. Esto no baja la tasa de acierto de quien juega honestamente — cierra
el atajo del que no juega.

**2. El vino acelera** (`aceleracion`), como al vaciar una botella de verdad.
Obliga a seguir el llenado todo el rato en vez de esperar sentado, y sube del
32 % al 39 % la proporción de jugadores que quedan cerca de la zona: más gente se
va con el "por poquito" en lugar del "no entendí qué pasó".

**3. La banda desaparece** en `dificil` y `experta`: queda sólo la línea dorada.

**Compensación de latencia.** Entre que el ojo ve la línea, el cerebro ordena
soltar y la pantalla registra el evento pasan ~100 ms, así que todo el mundo se
pasa un poco. Por eso el centro de la zona ganadora va corrido hacia arriba: es
el nivel al que llega quien reacciona en `compensacion` segundos. Cada nivel trae
el valor que reparte los fallos parejo entre "se pasó" y "le faltó" — hoy la
diferencia es de menos de 2 puntos en los cuatro niveles. **Esto no es opcional:**
sin compensar, el 22 % se pasa contra el 1,6 % que se queda corto, y el juego deja
de sentirse difícil para sentirse roto. Es lo mismo que hacen los juegos de ritmo
con la latencia del audio.

⚠ Si se cambia `velocidad` o `aceleracion` hay que **recalibrar `compensacion`**
y correr `node tests/dificultad.js`, que falla si los fallos se desbalancean.

**Al perder se muestra por cuánto falló** ("te pasaste por 2,4 % — el margen era
3 %"). En un juego difícil esto es lo que separa "estuve cerca, dame otra" de
"esto es al azar". El encabezado se gradúa: *¡POR MUY POCO!* / *¡CASI!* /
*NO FUE ESTA VEZ*.

Un toque muy corto (menos de 8 % de llenado) **no** cuenta como derrota: se
interpreta como toque accidental y deja reintentar. No hay reintento tras una
partida resuelta.

---

## Premio

Dos modos, en `config.js` → `premio.modo`:

- **`generico`** (actual): un mismo cupón para todos. Cero fricción, pero no se
  puede medir el canje real ni evitar que se comparta.
- **`pool`**: códigos únicos por ganador. Se cargan desde el panel admin (pegar
  uno por línea) o desde `assets/codigos.json` (ver `codigos.json.ejemplo`). Si el
  pool se agota, cae solo al código genérico y lo registra como `pool_agotado`.

`premio.qrTemplate` arma el link del QR; hoy usa el formato `/discount/{CODE}` de
Shopify. **Confirmar en qué plataforma corre tienda.puntiferrer.cl** antes del
evento.

---

## Qué falta del cliente

Todo lo marcado `[PLACEHOLDER]` en `config.js`:

1. **Matriz de vinos** — las 12 combinaciones momento × variedad tienen hoy
   nombres genéricos (Carmenère Reserva, Sauvignon Blanc…). Hay que reemplazarlas
   por los vinos reales del portafolio con su descripción.
2. **Cupón real** y plataforma de la tienda.
3. **Texto legal** del consentimiento y bases del concurso.
4. **Logo vectorial y manual de marca.** El monograma PF y la tipografía son una
   reconstrucción a partir del flyer del stand (negro mate, dorado, serif).
5. **Fotos de botellas** con fondo transparente u oscuro. Las que entregó el
   cliente (`assets/img/copa-*.jpeg|png`) son copas genéricas con **fondo blanco
   sólido, sin canal alfa**: sobre el negro del stand se convierten en bloques
   que encandilan. Por eso hoy las copas se dibujan en SVG. Las fotos siguen ahí
   por si se quieren usar con otro tratamiento.

---

## Pruebas

```bash
node tests/smoke.js       # recorre el juego completo y valida lo que queda registrado
node tests/sync.js        # señal intermitente, reintentos e idempotencia
node tests/offline.js     # servido por HTTP, arranca y se juega con la red caída
node tests/dificultad.js  # mide la tasa de acierto real de cada nivel
node tests/shots.js       # captura las 13 pantallas a tests/shots/
```

Requieren `playwright` (`npx playwright install chromium`).

- **smoke** — flujo ganador con formulario y QR, derrota por pasarse (con el
  detalle de cuánto faltó), toque accidental que permite reintentar, y que las 3
  partidas queden cerradas con su resultado, el ganador ligado a su partida y
  todo pendiente de sync. También verifica que la línea no se repita en 12
  partidas seguidas.
- **sync** — juega sin red, con el servidor caído (500) y con la señal de vuelta;
  comprueba que nada se pierda, que el contador de pendientes diga la verdad y
  que reenviar un lote no duplique nada.
- **offline** — carga el juego por HTTP, tira abajo el servidor y reinicia:
  verifica que arranque sin pedirle **nada** al servidor, que se pueda jugar y
  ganar, que el QR se genere y que las tipografías salgan del caché.
- **dificultad** — simula 60.000 partidas por nivel **usando las funciones reales
  del juego**, así que detecta si alguien cambia la física y descalibra las tasas.
  Falla si los fallos se desbalancean más de 8 puntos.

---

## Reporte para el cliente

Las consultas están al final de `scripts/22_pf_totem_juego.sql`: resumen general,
curva de tráfico por hora, preferencias momento × variedad, ranking de vinos,
embudo de abandono y base de leads.

El log de `pf_totem_evento` guarda cada acción con su timestamp, así que se puede
reconstruir cualquier partida y responder preguntas que aparezcan después.
