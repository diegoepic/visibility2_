/* ============================================================
   PUNTÍ FERRER — "Brindemos lo real" · Tótem interactivo
   Configuración editable. Todo lo marcado [PLACEHOLDER] se
   reemplaza cuando el cliente entregue el material oficial.
   ============================================================ */
window.PF_CONFIG = {
  evento: 'Walmart Holiday Meeting 2026',
  version: '1.0.0',

  // PIN de la pantalla admin (se abre con 5 toques seguidos en el
  // monograma PF de la pantalla de inicio)
  adminPin: '2435',

  // Segundos sin tocar la pantalla antes de volver al inicio
  // (la partida en curso queda registrada como "abandono")
  inactividadSeg: 45,

  // Pedir pantalla completa al primer toque (activar para el evento;
  // en desarrollo es molesto)
  pantallaCompleta: false,

  sonido: { activado: true, volumen: 0.6 },

  /* Vibración en los momentos clave. Sólo existe en Android; en iOS y en PC
     navigator.vibrate no está y todo falla en silencio. */
  vibracion: true,

  /* Contador social del inicio ("127 personas ya brindaron hoy"). Cuenta las
     partidas del día que llegaron a servir, no los toques de pantalla, para
     que el número sea real. Se oculta hasta llegar al mínimo: arrancar el día
     con "2 personas ya brindaron" resta en vez de sumar. */
  contadorSocial: { activado: true, minimoParaMostrar: 5 },

  /* ----- Mecánica "Sirve la medida perfecta" -----
     La copa se llena mientras se mantiene presionado; hay que soltar
     sobre la línea dorada. Gana si el nivel cae dentro de la zona.
       tolerancia   — mitad del ancho de la zona ganadora, en % de copa
       velocidad    — % de copa por segundo al empezar a servir
       aceleracion  — cuánto se acelera el vertido por segundo (0 = constante)
       compensacion — segundos de latencia que se le perdonan al jugador
       mostrarBanda — dibujar la zona ganadora dentro de la copa
       mostrarLinea — dibujar la línea dorada objetivo (default: sí). En
                      `false` el jugador no ve dónde apuntar mientras juega;
                      la línea real igual se muestra al final (ganó, o agotó
                      los intentos) para que el resultado se explique solo.
       intentos     — cuántos toques puede fallar antes de que la partida se
                      cierre como perdida (default: 1, sin reintentos). Con
                      intentos > 1, cada fallo con intentos restantes NO
                      cierra la partida: sólo avisa la DIRECCIÓN ("más
                      lleno"/"más vacío"), nunca el número — eso es lo que
                      hace jugable un nivel sin línea sin que se sienta puro
                      azar (ver evaluarServida en game.js).

     Sobre 'compensacion': nadie suelta exactamente cuando decide hacerlo.
     Entre que el ojo ve la línea, el cerebro ordena soltar y la pantalla
     registra el evento pasan ~100 ms, así que TODO el mundo se pasa un
     poco. Por eso el centro de la zona ganadora va corrido hacia arriba:
     es el nivel al que llega quien reacciona en 'compensacion' segundos.
     No es regalar el premio — es no castigar al jugador por la física de
     su propio sistema nervioso. Cada nivel trae el valor que reparte los
     fallos parejo entre "se pasó" y "le faltó" (simulación de 50k partidas
     por nivel); si los fallos se cargan hacia un lado, el juego se siente
     roto en vez de difícil.

     Sobre 'aceleracion': el vino cae cada vez más rápido, como al vaciar
     una botella de verdad. Obliga a seguir el llenado todo el rato en vez
     de esperar sentado, y sube de 32% a 39% la proporción de jugadores que
     quedan cerca de la zona: más gente se va con la sensación de "por
     poquito" en lugar de "no entendí qué pasó". */
  dificultad: 'dificil', // facil | normal | dificil | experta (ajustable en admin)
  dificultades: {
    facil:    { tolerancia: 8.0, velocidad: 25, aceleracion: 0.45, compensacion: 0.12, mostrarBanda: true  }, // ~78% gana
    normal:   { tolerancia: 4.5, velocidad: 25, aceleracion: 0.45, compensacion: 0.10, mostrarBanda: true  }, // ~54% gana
    dificil:  { tolerancia: 3.0, velocidad: 25, aceleracion: 0.45, compensacion: 0.09, mostrarBanda: false }, // ~38% gana
    experta:  { tolerancia: 2.0, velocidad: 25, aceleracion: 0.45, compensacion: 0.09, mostrarBanda: false }, // ~27% gana
    // Sin línea ni banda: el jugador sirve "a ojo" y sólo sabe si se pasó o
    // le faltó después de soltar. `intentos` le da 3 toques por partida antes
    // de perder — el tercer valor de la fila de abajo, editable acá mismo.
    // ⚠ tolerancia sin calibrar todavía: es un punto de partida razonado (ver
    // README § "Cómo se gana"), no una medición. Correr `node tests/dificultad.js`
    // (trae un bloque informativo para este nivel) y ajustar antes del evento.
    imposible: { tolerancia: 2.0, velocidad: 25, aceleracion: 0.45, compensacion: 0.09, mostrarBanda: false, mostrarLinea: false, intentos: 3 },
  },

  /* La línea NO puede estar siempre a la misma altura. En un stand la gente
     ve jugar a los de adelante, y con una línea fija basta con memorizar
     "aguanto 2,5 segundos" para ganar sin siquiera mirar la pantalla.
     Sorteándola en cada partida el único camino es mirar y calcular. */
  lineaObjetivo: 62,   // usado solo si lineaAleatoria.activo = false
  lineaAleatoria: { activo: true, min: 45, max: 78 },

  nivelMinimoValido: 8, // una servida menor a esto cuenta como toque accidental
                        // y se permite reintentar (no como derrota)

  /* ----- Premio -----
     modo 'generico': un mismo cupón para todos (QR generado en pantalla).
     modo 'pool': códigos únicos por ganador, importados en el panel admin
     (o desde assets/codigos.json si el navegador lo permite). Si el pool
     se agota, cae automáticamente al código genérico. */
  premio: {
    modo: 'generico',
    codigoGenerico: 'BRINDEMOSLOREAL20', // [PLACEHOLDER] cupón real de la tienda
    urlTienda: 'https://tienda.puntiferrer.cl',
    // [PLACEHOLDER] formato del link del QR. {CODE} se reemplaza por el cupón.
    // El formato /discount/ es el de Shopify; confirmar plataforma de la tienda.
    qrTemplate: 'https://tienda.puntiferrer.cl/discount/{CODE}',
    archivoPool: 'assets/codigos.json',
  },

  /* ----- Sincronización con el servidor visibility -----
     URL vacía = sync desactivado (el juego funciona 100% offline igual).
     El token debe calzar con PF_TOTEM_TOKEN en el .env del servidor.

     ⚠ La ruta es /visibility2/pf_totem_api/sync.php — FUERA de app/.
     No sirve ponerlo dentro de app/: app/.user.ini aplica
     auto_prepend_file = _session_guard.php a todo lo que cuelga de ahí,
     y ese guard exige sesión web de portal. El tótem es un kiosko
     anónimo, así que el guard lo rechazaría antes de llegar al código. */
  sync: {
    url: 'https://visibility.cl/visibility2/pf_totem_api/sync.php',
    token: 'mc123',          // [PLACEHOLDER] debe ser igual a PF_TOTEM_TOKEN del .env
    intervaloSeg: 120,
    maxLote: 300,
  },

  /* ----- Contenido del juego ----- */
  momentos: [
    { id: 'encuentro',   nombre: 'Encuentro',   frase: 'Compartir con los tuyos' },
    { id: 'relajo',      nombre: 'Relajo',      frase: 'Una pausa merecida' },
    { id: 'celebracion', nombre: 'Celebración', frase: 'Un logro que brilla' },
    { id: 'desconexion', nombre: 'Desconexión', frase: 'Tiempo para ti' },
  ],

  /* `img` HOY NO SE USA: las copas se dibujan en SVG porque las fotos que
     entregó el cliente vienen con fondo blanco sólido sin canal alfa y sobre
     el negro del stand se ven como bloques que encandilan. El campo queda para
     cuando lleguen fotos de las botellas reales con fondo transparente.
     `copa` elige la forma dibujada y `colorVino` el degradado del líquido. */
  variedades: [
    { id: 'tinto',     nombre: 'Tinto',     img: 'assets/img/copa-tinto.jpeg',
      copa: 'copa',   colorVino: ['#8a2438', '#4e0f1e'] },
    { id: 'blanco',    nombre: 'Blanco',    img: 'assets/img/copa-blanco.jpeg',
      copa: 'copa',   colorVino: ['#f2e4a8', '#dcc272'] },
    { id: 'espumante', nombre: 'Espumante', img: 'assets/img/copa-espumante.jpeg',
      copa: 'flauta', colorVino: ['#f7edc4', '#e3cc86'] },
  ],

  /* [PLACEHOLDER] Matriz momento × variedad → vino recomendado.
     Reemplazar por los vinos reales del portafolio Puntí Ferrer
     cuando el cliente entregue la lista (nombre + descripción). */
  matriz: {
    'encuentro|tinto':     { nombre: 'Carmenère Reserva',          desc: 'Especiado y envolvente, hecho para conversaciones que se alargan.' },
    'relajo|tinto':        { nombre: 'Merlot',                      desc: 'Suave y redondo, el compañero perfecto de una pausa.' },
    'celebracion|tinto':   { nombre: 'Cabernet Sauvignon Gran Reserva', desc: 'Estructura y carácter para los grandes momentos.' },
    'desconexion|tinto':   { nombre: 'Pinot Noir',                  desc: 'Delicado y silencioso, para desconectar de verdad.' },
    'encuentro|blanco':    { nombre: 'Sauvignon Blanc',             desc: 'Fresco y vibrante, rompe el hielo por ti.' },
    'relajo|blanco':       { nombre: 'Chardonnay',                  desc: 'Cremoso y amable, como una tarde sin apuro.' },
    'celebracion|blanco':  { nombre: 'Chardonnay Reserva',          desc: 'Elegancia dorada para brindar en grande.' },
    'desconexion|blanco':  { nombre: 'Riesling',                    desc: 'Ligero y aromático, un momento solo tuyo.' },
    'encuentro|espumante': { nombre: 'Brut',                        desc: 'Burbujas finas para encuentros que chispean.' },
    'relajo|espumante':    { nombre: 'Demi-Sec',                    desc: 'Un dulzor sutil para soltar el día.' },
    'celebracion|espumante': { nombre: 'Extra Brut',                desc: 'El clásico de toda celebración real.' },
    'desconexion|espumante': { nombre: 'Rosé',                      desc: 'Burbujas rosadas para perderse un rato.' },
  },

  textos: {
    claim: 'Brindemos lo real',
    tocaParaComenzar: 'Toca la pantalla para comenzar',
    premioTitulo: '20% OFF',
    premioBajada: 'en tu próxima compra en tienda.puntiferrer.cl',
    consentimiento: 'Acepto que Puntí Ferrer utilice mis datos para gestionar mi descuento y enviarme comunicaciones de la marca.', // [PLACEHOLDER] validar texto legal
  },
};
