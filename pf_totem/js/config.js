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

  /* Matriz momento × variedad → POOL de 2 vinos recomendados (antes era 1 fijo
     por celda; con pool, dos personas que elijan lo mismo no ven siempre la
     misma botella — se sortea uno del pool en cada partida, ver el click de
     .card-variedad en game.js). Datos, fotos y `linea` reales del catálogo de
     puntiferrer.com (ver assets/img/catalogo-puntiferrer/catalogo.json para
     los vinos que quedaron fuera de estos pools). `foto` es la imagen real
     del producto (PNG con canal alfa) que se muestra en "Tu vino ideal"; si
     algún día se agrega una combinación sin `foto`, el juego cae solo a la
     copa dibujada en SVG. `linea` alimenta el badge (Signature/Reserva/Gran
     Reserva/Premium/Espumantes/Innovation Series) sobre el nombre del vino.

     ⚠ El emparejamiento momento↔vino (qué línea/varietal representa cada
     "encuentro", "relajo", etc.) es un criterio editorial nuestro a partir de
     las notas de cata reales, NO algo que haya definido el cliente — conviene
     que marketing/ventas de Puntí Ferrer lo revise antes del evento. Los
     nombres y descripciones sí son reales (adaptados de las notas de cata del
     sitio, no inventados). El pool de espumante es el mismo en las 4 celdas:
     el catálogo real sólo tiene 2 (País Brut y Xtra Brut), no da para variar
     por momento como en tinto/blanco. */
  matriz: {
    'encuentro|tinto': [
      { nombre: 'Carménère Reserva', linea: 'Reserva', desc: 'Especiado y redondo, con fruta roja y un toque de pimienta — perfecto para una mesa que se alarga.', foto: 'assets/img/catalogo-puntiferrer/reserva-carmenere.png' },
      { nombre: 'Cabernet Sauvignon Reserva', linea: 'Reserva', desc: 'Estructura, cassis y un final largo — ideal para compartir en una buena mesa.', foto: 'assets/img/catalogo-puntiferrer/reserva-cabernet.png' },
    ],
    'relajo|tinto': [
      { nombre: 'Merlot Signature', linea: 'Signature', desc: 'Ciruela, guinda y un cuerpo redondo y amable, el compañero perfecto de una pausa.', foto: 'assets/img/catalogo-puntiferrer/signature-merlot.png' },
      { nombre: 'Malbec Signature', linea: 'Signature', desc: 'Frutos negros, violetas y una textura aterciopelada, fácil de disfrutar sin apuro.', foto: 'assets/img/catalogo-puntiferrer/signature-malbec.png' },
    ],
    'celebracion|tinto': [
      { nombre: 'Cabernet Sauvignon Gran Reserva', linea: 'Gran Reserva', desc: 'Concentrado, con cassis, cedro y crianza en barrica — estructura y carácter para los grandes momentos.', foto: 'assets/img/catalogo-puntiferrer/granreserva-cabernet.png' },
      { nombre: 'Conforme · Carménère Premium', linea: 'Premium', desc: 'Selección de las mejores uvas, máxima guarda y complejidad — para celebrar en grande.', foto: 'assets/img/catalogo-puntiferrer/premium-carmenere.png' },
    ],
    'desconexion|tinto': [
      { nombre: 'Pinot Noir Reserva', linea: 'Reserva', desc: 'Frutos rojos, delicado y sedoso: para desconectar de verdad.', foto: 'assets/img/catalogo-puntiferrer/reserva-pinotnoir.png' },
      { nombre: 'Serie Tinajas · Malbec', linea: 'Innovation Series', desc: 'Vinificado en tinaja de greda: textura, frescura y pura fruta, distinto a todo lo demás.', foto: 'assets/img/catalogo-puntiferrer/innovation-tinajas-malbec.png' },
    ],
    'encuentro|blanco': [
      { nombre: 'Sauvignon Blanc Signature', linea: 'Signature', desc: 'Cítricos, hierba fresca y acidez vibrante — rompe el hielo por ti.', foto: 'assets/img/catalogo-puntiferrer/signature-sauvignonblanc.png' },
      { nombre: 'Sauvignon Blanc Gran Reserva', linea: 'Gran Reserva', desc: 'Fresco y mineral, de clima costero, con una acidez que despierta los sentidos.', foto: 'assets/img/catalogo-puntiferrer/granreserva-sauvignonblanc.png' },
    ],
    'relajo|blanco': [
      { nombre: 'Chardonnay Signature', linea: 'Signature', desc: 'Fruta blanca, un toque de vainilla y nuez tostada, como una tarde sin apuro.', foto: 'assets/img/catalogo-puntiferrer/signature-chardonnay.png' },
      { nombre: 'Chardonnay Reserva', linea: 'Reserva', desc: 'Cremoso y fresco, con fruta blanca y un toque de tostado — perfecto sin ninguna prisa.', foto: 'assets/img/catalogo-puntiferrer/reserva-chardonnay.png' },
    ],
    'celebracion|blanco': [
      { nombre: 'Chardonnay Gran Reserva', linea: 'Gran Reserva', desc: 'Untuoso, con fruta blanca y una crianza elegante para brindar en grande.', foto: 'assets/img/catalogo-puntiferrer/granreserva-chardonnay.png' },
      { nombre: 'Sauvignon Blanc Gran Reserva', linea: 'Gran Reserva', desc: 'Fresco y mineral, de clima costero — luminoso para un brindis en grande.', foto: 'assets/img/catalogo-puntiferrer/granreserva-sauvignonblanc.png' },
    ],
    'desconexion|blanco': [
      { nombre: 'Huevos del Loco', linea: 'Innovation Series', desc: 'Cítrico, herbal y vibrante — un descubrimiento premiado (Tim Atkin MW) para un momento solo tuyo.', foto: 'assets/img/catalogo-puntiferrer/innovation-huevosdelloco.png' },
      { nombre: 'Sauvignon Blanc Reserva', linea: 'Reserva', desc: 'Fresco y cítrico, con buena acidez — liviano y fácil de querer.', foto: 'assets/img/catalogo-puntiferrer/reserva-sauvignonblanc.png' },
    ],
    'encuentro|espumante': [
      { nombre: 'Espumante País Brut', linea: 'Espumantes', desc: 'Fresco, frutal y con identidad propia — burbujas para encuentros que chispean.', foto: 'assets/img/catalogo-puntiferrer/espumantes-pais.png' },
      { nombre: 'Xtra Brut Sparkling', linea: 'Espumantes', desc: 'Burbuja elegante y seca, fina y persistente — el clásico de toda celebración real.', foto: 'assets/img/catalogo-puntiferrer/espumantes-xtrabrut.png' },
    ],
    'relajo|espumante': [
      { nombre: 'Espumante País Brut', linea: 'Espumantes', desc: 'Fresco y frutal, con la identidad de la cepa País — ideal para soltar el día.', foto: 'assets/img/catalogo-puntiferrer/espumantes-pais.png' },
      { nombre: 'Xtra Brut Sparkling', linea: 'Espumantes', desc: 'Burbuja fina y persistente, elegante y seca, para no pensar en nada.', foto: 'assets/img/catalogo-puntiferrer/espumantes-xtrabrut.png' },
    ],
    'celebracion|espumante': [
      { nombre: 'Xtra Brut Sparkling', linea: 'Espumantes', desc: 'Burbuja elegante y seca, fina y persistente — el clásico de toda celebración real.', foto: 'assets/img/catalogo-puntiferrer/espumantes-xtrabrut.png' },
      { nombre: 'Espumante País Brut', linea: 'Espumantes', desc: 'Fresco, frutal y con identidad propia — para celebrar sin ponerse solemne.', foto: 'assets/img/catalogo-puntiferrer/espumantes-pais.png' },
    ],
    'desconexion|espumante': [
      { nombre: 'Xtra Brut Sparkling', linea: 'Espumantes', desc: 'Burbuja fina, elegante y seca: tu propio momento de calma.', foto: 'assets/img/catalogo-puntiferrer/espumantes-xtrabrut.png' },
      { nombre: 'Espumante País Brut', linea: 'Espumantes', desc: 'Fresco y frutal, con la identidad de la cepa País — para perderse un rato.', foto: 'assets/img/catalogo-puntiferrer/espumantes-pais.png' },
    ],
  },

  textos: {
    claim: 'Brindemos lo real',
    tocaParaComenzar: 'Toca la pantalla para comenzar',
    premioTitulo: '20% OFF',
    premioBajada: 'en tu próxima compra en tienda.puntiferrer.cl',
    consentimiento: 'Acepto que Puntí Ferrer utilice mis datos para gestionar mi descuento y enviarme comunicaciones de la marca.', // [PLACEHOLDER] validar texto legal
  },
};
