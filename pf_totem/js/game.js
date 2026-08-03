/* ============================================================
   PF Game — orquestador del tótem "Brindemos lo real".
   Flujo: attract → momento → variedad → vino ideal → servir →
   gana (datos → premio QR) / pierde → attract.
   Todo queda registrado en PFDB; PFSync lo sube cuando puede.
   ============================================================ */
(function () {
  'use strict';

  var C = window.PF_CONFIG;
  function $(sel) { return document.querySelector(sel); }
  function $$(sel) { return Array.prototype.slice.call(document.querySelectorAll(sel)); }

  // ---------- estado global ----------
  var deviceId = null;
  var session = null;          // partida en curso
  var ultimaSessionUuid = null; // uuid de la última partida cerrada (para ligar al ganador)
  var pantalla = 's-attract';
  var difActual = C.dificultad;
  var timers = { inactividad: null, autoVolver: null, autoVolverInt: null };
  var PASOS = { 's-momento': 1, 's-variedad': 2, 's-vino': 2, 's-servir': 3, 's-win': 4, 's-lose': 4, 's-datos': 4, 's-premio': 5 };

  // ---------- utilidades ----------
  function ahora() { return new Date().toISOString(); }

  function logEvent(tipo, data) {
    return PFDB.put('eventos', {
      uuid: PFDB.uuid(),
      session_uuid: session ? session.uuid : null,
      device_id: deviceId,
      ts: ahora(),
      tipo: tipo,
      data: data || null,
      synced: 0,
    }).catch(function (e) { console.warn('logEvent', e); });
  }

  function dif() { return C.dificultades[difActual] || C.dificultades.facil; }

  /* ---------- física del vertido ----------
     El vino acelera: v(t) = velocidad * (1 + aceleracion * t), así que el
     nivel es la integral: nivel(t) = v0*t + v0*a*t²/2. Ambas funciones son
     inversas entre sí y se usan para ubicar la línea y la zona ganadora. */
  function nivelEn(t) {
    var d = dif(), a = d.aceleracion || 0;
    return d.velocidad * t + d.velocidad * a * t * t / 2;
  }

  function tiempoPara(nivel) {
    var d = dif(), a = d.aceleracion || 0;
    if (a <= 0) return nivel / d.velocidad;
    return (Math.sqrt(1 + 2 * a * nivel / d.velocidad) - 1) / a;
  }

  /* Altura de la línea dorada de ESTA partida. Se sortea al preparar la copa
     (ver prepararServir) para que no se pueda memorizar el tiempo. */
  var lineaPartida = C.lineaObjetivo;

  function sortearLinea() {
    var r = C.lineaAleatoria;
    lineaPartida = (r && r.activo)
      ? Math.round((r.min + Math.random() * (r.max - r.min)) * 10) / 10
      : C.lineaObjetivo;
    return lineaPartida;
  }

  /* Centro real de la zona ganadora: el nivel al que llega quien suelta
     'compensacion' segundos después de ver el vino cruzar la línea. Se
     calcula sobre el tiempo (no como velocidad × latencia) porque con
     vertido acelerado esos dos valores dejan de ser lo mismo. */
  function centroEfectivo() {
    var d = dif();
    return Math.min(96, nivelEn(tiempoPara(lineaPartida) + (d.compensacion || 0)));
  }

  // ---------- pantallas ----------
  function showScreen(id) {
    pantalla = id;
    $$('.screen').forEach(function (s) { s.classList.toggle('active', s.id === id); });
    var footer = $('#paso-footer');
    var paso = PASOS[id];
    footer.classList.toggle('oculto', !paso || id === 's-datos');
    if (paso) {
      $$('#paso-footer span').forEach(function (sp) {
        sp.classList.toggle('act', parseInt(sp.dataset.paso, 10) === paso);
      });
    }
    logEvent('pantalla', { id: id });
  }

  // ---------- sesión (partida) ----------
  function startSession() {
    session = {
      uuid: PFDB.uuid(),
      device_id: deviceId,
      inicio: ahora(),
      fin: null,
      duracion_seg: null,
      momento: null,
      variedad: null,
      vino: null,
      resultado: null,
      precision_pct: null,
      nivel_final: null,
      dificultad: difActual,
      synced: 0,
    };
    saveSession();
    logEvent('inicio_partida');
  }

  function saveSession() {
    if (!session) return Promise.resolve();
    session.synced = 0;
    return PFDB.put('sesiones', session).catch(function (e) { console.warn('saveSession', e); });
  }

  /* Cierra la partida. Si ya tenía resultado (p.ej. ganó y se fue sin
     reclamar), el resultado original se conserva.
     El uuid sobrevive en ultimaSessionUuid porque el ganador entrega sus
     datos DESPUÉS de que la partida se cerró, y hay que poder ligarlos. */
  function finalizeSession(resultado) {
    if (!session) return Promise.resolve();
    ultimaSessionUuid = session.uuid;
    session.resultado = session.resultado || resultado;
    session.fin = ahora();
    session.duracion_seg = Math.round((Date.now() - new Date(session.inicio).getTime()) / 1000);
    var p = saveSession().then(function () {
      logEvent('fin_partida', { resultado: session.resultado });
      session = null;
      PFSync.tick();
    });
    return p;
  }

  // ---------- inactividad ----------
  function resetInactividad() {
    clearTimeout(timers.inactividad);
    timers.inactividad = setTimeout(onInactivo, C.inactividadSeg * 1000);
  }

  function onInactivo() {
    cerrarAdmin();
    if (pantalla === 's-attract') { resetInactividad(); return; }
    if (session) {
      logEvent('abandono_inactividad', { pantalla: pantalla });
      finalizeSession('abandono');
    }
    goHome();
  }

  function goHome() {
    detenerServido(true);
    PFKeyboard.detach();
    clearTimeout(timers.autoVolver);
    clearInterval(timers.autoVolverInt);
    limpiarFormulario();
    showScreen('s-attract');
    resetInactividad();
  }

  /* Cuenta regresiva "volviendo al inicio" de las pantallas finales */
  function autoVolver(el, segundos) {
    clearTimeout(timers.autoVolver);
    clearInterval(timers.autoVolverInt);
    var restante = segundos;
    el.textContent = 'Volviendo al inicio en ' + restante + 's';
    timers.autoVolverInt = setInterval(function () {
      restante--;
      if (restante > 0) el.textContent = 'Volviendo al inicio en ' + restante + 's';
    }, 1000);
    timers.autoVolver = setTimeout(goHome, segundos * 1000);
  }

  /* ============================================================
     COPA SVG — dibujo y animación del servido
     ============================================================ */
  /* Geometría de las copas (viewBox 300×470).
     Sigue la forma de las fotos que entregó el cliente: borde más angosto
     que la panza, no al revés. liqTop/liqBottom delimitan el interior útil
     del bowl — son la escala sobre la que se mide el % de llenado. */
  var GEO = {
    copa: { // tipo Bordeaux, para tinto y blanco
      outer: 'M88,40 C74,92 71,126 76,164 C82,232 108,292 150,308 ' +
             'C192,292 218,232 224,164 C229,126 226,92 212,40 Z',
      inner: 'M94,46 C81,95 78,127 82,163 C88,227 111,283 150,299 ' +
             'C189,283 212,227 218,163 C222,127 219,95 206,46 Z',
      rim: { cx: 150, cy: 40, rx: 62 },
      liqTop: 48, liqBottom: 300,
      lineX1: 58, lineX2: 242,
      stem: 'M150,308 L150,430',
      baseY: 442, baseRx: 66,
    },
    flauta: { // para espumante: alta y estrecha, la burbuja luce
      outer: 'M122,32 C116,110 114,190 120,262 C124,318 138,352 150,366 ' +
             'C162,352 176,318 180,262 C186,190 184,110 178,32 Z',
      inner: 'M127,38 C122,112 120,190 125,260 C129,313 140,344 150,357 ' +
             'C160,344 171,313 175,260 C180,190 178,112 173,38 Z',
      rim: { cx: 150, cy: 32, rx: 28 },
      liqTop: 40, liqBottom: 358,
      lineX1: 92, lineX2: 208,
      stem: 'M150,366 L150,430',
      baseY: 442, baseRx: 56,
    },
  };

  /* Genera el SVG de una copa.
     opts.uid es obligatorio en la práctica: en el documento conviven la copa
     decorativa del attract y la del juego, y los ids de <defs> se resuelven a
     nivel de documento. Sin sufijo único, url(#gvino) de la copa del juego
     tomaría el gradiente de la del attract (siempre tinto) y el clipPath de la
     forma equivocada al elegir espumante. Los elementos animados van con clase,
     no con id, y se buscan dentro de su propio <svg>. */
  var uidSeq = 0;

  function buildCopaSvg(varDef, opts) {
    opts = opts || {};
    var uid = opts.uid || ('c' + (++uidSeq));
    var idGrad = 'gvino_' + uid;
    var idClip = 'clip_' + uid;
    var idBanda = 'gbanda_' + uid;
    var idChorro = 'gchorro_' + uid;
    var g = GEO[varDef.copa] || GEO.copa;
    var linea = opts.decorativa ? C.lineaObjetivo : lineaPartida;
    var centro = centroEfectivo();
    var tol = dif().tolerancia;
    var H = g.liqBottom - g.liqTop;
    var yLinea = g.liqBottom - H * linea / 100;
    // la banda marca la zona ganadora real, por eso va sobre el centro
    // efectivo y no sobre la línea; la línea queda dentro de ella
    var bandaSup = Math.min(100, centro + tol);
    var bandaInf = Math.max(0, centro - tol);
    var yBandaTop = g.liqBottom - H * bandaSup / 100;
    var hBanda = H * (bandaSup - bandaInf) / 100;
    var esEspumante = varDef.id === 'espumante';
    var nivelIni = opts.nivelInicial || 0;
    var hIni = H * nivelIni / 100;

    var burbujas = '';
    if (esEspumante) {
      var xs = [132, 145, 158, 168, 140, 152];
      burbujas = '<g class="pf-bubs" clip-path="url(#' + idClip + ')" style="display:none">' +
        xs.map(function (x, i) {
          return '<circle class="bub" cx="' + x + '" cy="' + (g.liqBottom - 6 - i * 4) + '" r="' + (2.2 + (i % 3)) + '" fill="#fff" opacity=".7"/>';
        }).join('') + '</g>';
    }

    return '' +
      '<defs>' +
      '  <linearGradient id="' + idGrad + '" x1="0" y1="0" x2="0" y2="1">' +
      '    <stop offset="0" stop-color="' + varDef.colorVino[0] + '"/>' +
      '    <stop offset="1" stop-color="' + varDef.colorVino[1] + '"/>' +
      '  </linearGradient>' +
      /* La banda se desvanece arriba y abajo: un rectángulo plano dejaba dos
         bordes duros que sobre el negro se leían como una mancha gris. */
      '  <linearGradient id="' + idBanda + '" x1="0" y1="0" x2="0" y2="1">' +
      '    <stop offset="0" stop-color="#c9a45c" stop-opacity="0"/>' +
      '    <stop offset="0.5" stop-color="#e8cf94" stop-opacity="0.16"/>' +
      '    <stop offset="1" stop-color="#c9a45c" stop-opacity="0"/>' +
      '  </linearGradient>' +
      '  <linearGradient id="' + idChorro + '" x1="0" y1="0" x2="0" y2="1">' +
      '    <stop offset="0" stop-color="' + varDef.colorVino[0] + '" stop-opacity="0.15"/>' +
      '    <stop offset="1" stop-color="' + varDef.colorVino[0] + '" stop-opacity="0.75"/>' +
      '  </linearGradient>' +
      '  <clipPath id="' + idClip + '"><path d="' + g.inner + '"/></clipPath>' +
      '</defs>' +
      // banda de tolerancia (zona donde se gana), dentro de la copa
      (opts.decorativa || dif().mostrarBanda === false ? '' :
        '<rect x="' + g.lineX1 + '" width="' + (g.lineX2 - g.lineX1) + '" y="' + yBandaTop +
        '" height="' + hBanda + '" fill="url(#' + idBanda + ')" clip-path="url(#' + idClip + ')"/>') +
      // vino
      '<rect class="pf-liq" x="60" width="180" y="' + (g.liqBottom - hIni) + '" height="' + hIni +
      '" fill="url(#' + idGrad + ')" clip-path="url(#' + idClip + ')"/>' +
      burbujas +
      // chorro al servir
      '<rect class="pf-chorro" x="147.5" width="5" rx="2.5" y="' + (g.rim.cy - 30) + '" height="0" fill="url(#' + idChorro + ')"/>' +
      // línea dorada objetivo (es parte del juego: las copas de catálogo la omiten)
      (opts.sinLinea ? '' :
        '<line x1="' + g.lineX1 + '" x2="' + g.lineX2 + '" y1="' + yLinea + '" y2="' + yLinea +
        '" stroke="#e8cf94" stroke-width="9" opacity="0.18" stroke-linecap="round"/>' +
        '<line x1="' + g.lineX1 + '" x2="' + g.lineX2 + '" y1="' + yLinea + '" y2="' + yLinea +
        '" stroke="#e8cf94" stroke-width="2.5" stroke-linecap="round"/>' +
        '<path d="M' + (g.lineX1 - 8) + ',' + yLinea + ' l6,-4 l0,8 Z" fill="#e8cf94"/>' +
        '<path d="M' + (g.lineX2 + 8) + ',' + yLinea + ' l-6,-4 l0,8 Z" fill="#e8cf94"/>') +
      // cristal de la copa
      '<path d="' + g.outer + '" fill="none" stroke="#c9a45c" stroke-width="2"/>' +
      '<ellipse cx="' + g.rim.cx + '" cy="' + g.rim.cy + '" rx="' + g.rim.rx + '" ry="7" fill="none" stroke="#c9a45c" stroke-width="1.4" opacity="0.55"/>' +
      '<path d="' + g.stem + '" stroke="#c9a45c" stroke-width="2.4" fill="none"/>' +
      '<ellipse cx="150" cy="' + g.baseY + '" rx="' + g.baseRx + '" ry="11" fill="none" stroke="#c9a45c" stroke-width="2"/>';
  }

  // ---------- estado del servido ----------
  var serve = {
    estado: 'ready', nivel: 0, tServido: 0, raf: null, tPrev: 0, geo: null, varDef: null,
    elLiq: null, elChorro: null, elBubs: null,
  };

  function prepararServir() {
    var varDef = C.variedades.find(function (v) { return v.id === session.variedad; });
    serve.varDef = varDef;
    serve.geo = GEO[varDef.copa] || GEO.copa;
    serve.estado = 'ready';
    serve.nivel = 0;
    serve.tServido = 0;
    sortearLinea();   // altura distinta en cada partida: no se puede memorizar
    var svg = $('#copa-svg');
    svg.innerHTML = buildCopaSvg(varDef, { uid: 'juego' });
    /* Referencias dentro de ESTE svg: en el documento vive también la copa
       decorativa del attract con los mismos nodos, y de paso evitamos
       consultar el DOM en cada frame de la animación. */
    serve.elLiq = svg.querySelector('.pf-liq');
    serve.elChorro = svg.querySelector('.pf-chorro');
    serve.elBubs = svg.querySelector('.pf-bubs');
    $('#servir-hint').textContent = ' ';
    $('#servir-hint').classList.remove('err');
    $('#servir-instr').style.opacity = '1';
  }

  function pintarNivel() {
    if (!serve.elLiq) return;
    var g = serve.geo;
    var H = g.liqBottom - g.liqTop;
    var h = H * serve.nivel / 100;
    serve.elLiq.setAttribute('y', g.liqBottom - h);
    serve.elLiq.setAttribute('height', h);
    if (serve.elChorro) {
      var visible = serve.estado === 'pouring';
      serve.elChorro.setAttribute('height', visible ? Math.max(0, (g.liqBottom - h) - (g.rim.cy - 30)) : 0);
    }
    if (serve.elBubs) serve.elBubs.style.display = serve.nivel > 15 ? '' : 'none';
    PFAudio.pourLevel(serve.nivel);
  }

  function iniciarServido() {
    if (serve.estado !== 'ready' || pantalla !== 's-servir') return;
    serve.estado = 'pouring';
    serve.tPrev = performance.now();
    PFAudio.pourStart();
    logEvent('servida_inicio', { nivel: serve.nivel });
    $('#servir-instr').style.opacity = '0.35';
    function paso(t) {
      if (serve.estado !== 'pouring') return;
      var dt = Math.min(0.05, (t - serve.tPrev) / 1000);
      serve.tPrev = t;
      /* Se acumula el tiempo servido y el nivel sale de la curva, en vez de
         sumar velocidad×dt: así el llenado coincide exactamente con lo que
         asume centroEfectivo(), incluso si el tótem pierde algún frame. */
      serve.tServido += dt;
      serve.nivel = nivelEn(serve.tServido);
      if (serve.varDef.id === 'espumante' && Math.random() < 0.06) PFAudio.bubble();
      if (serve.nivel >= 100) {
        serve.nivel = 100;
        pintarNivel();
        detenerServido();
        evaluarServida(true);
        return;
      }
      pintarNivel();
      serve.raf = requestAnimationFrame(paso);
    }
    serve.raf = requestAnimationFrame(paso);
  }

  function detenerServido(silencioso) {
    if (serve.raf) { cancelAnimationFrame(serve.raf); serve.raf = null; }
    if (serve.estado === 'pouring') serve.estado = 'stopped';
    PFAudio.pourStop(silencioso);
    pintarNivel();
  }

  function evaluarServida(derrame) {
    if (!session) return;
    var nivel = serve.nivel;

    // toque accidental: muy poco líquido → se permite reintentar
    if (!derrame && nivel < C.nivelMinimoValido) {
      logEvent('servida_corta', { nivel: Math.round(nivel * 10) / 10 });
      var hint = $('#servir-hint');
      hint.textContent = '¡Muy poco! Mantén presionado y suelta en la línea';
      hint.classList.add('err');
      serve.nivel = 0;
      serve.tServido = 0;
      serve.estado = 'ready';
      pintarNivel();
      $('#servir-instr').style.opacity = '1';
      return;
    }

    serve.estado = 'done';
    var centro = centroEfectivo();
    var tol = dif().tolerancia;
    var diff = nivel - centro;
    var absd = Math.abs(diff);
    var gano = !derrame && absd <= tol;

    var precision;
    if (gano) precision = Math.max(86, Math.round(100 - (absd / tol) * 14));
    else precision = Math.max(20, Math.round(85 - (absd - tol) * 6));

    session.nivel_final = Math.round(nivel * 10) / 10;
    session.precision_pct = precision;
    session.resultado = gano ? 'gano' : 'perdio';
    session.linea_objetivo = lineaPartida;
    saveSession();
    logEvent('servida_fin', {
      nivel: session.nivel_final,
      linea: lineaPartida,
      centro: Math.round(centro * 10) / 10,
      diferencia: Math.round(diff * 10) / 10,
      derrame: !!derrame,
      resultado: session.resultado,
      precision: precision,
      dificultad: difActual,
    });

    var hint = $('#servir-hint');
    if (gano) {
      hint.textContent = '¡EN LA LÍNEA!';
      hint.classList.remove('err');
    } else {
      hint.textContent = derrame ? '¡Se derramó!' : (diff > 0 ? 'Te pasaste de la línea' : 'Te faltó un poco');
      hint.classList.add('err');
    }

    setTimeout(function () {
      if (gano) {
        PFAudio.win();
        $('#win-precision').textContent = precision + '%';
        logEvent('gano');
        /* Se cierra la partida YA, sin esperar a que reclame el premio: si se
           corta la luz en esta pantalla (pasa cada noche del evento) el "ganó"
           igual queda firme, y el conteo del reporte no depende del timeout.
           Reclamar el premio es un hecho aparte (tabla ganadores). */
        finalizeSession('gano');
        showScreen('s-win');
        // sin auto-volver acá: la inactividad se encarga si no reclama
      } else {
        PFAudio.lose();
        /* Con un juego difícil el jugador TIENE que ver por cuánto falló, si
           no se va convencido de que fue al azar. Mostrarle "te faltó 1,8%"
           convierte la derrota en "casi lo tenía" y es lo que lo deja con
           ganas, que es justamente lo que la marca quiere que sienta. */
        // el encabezado se ajusta a lo lejos que quedó: felicitar un fallo de
        // 15% con un "¡casi!" suena a burla
        var tag = derrame ? '¡SE DERRAMÓ!'
          : absd <= tol * 2.5 ? '¡POR MUY POCO!'
          : absd <= tol * 6 ? '¡CASI!'
          : 'NO FUE ESTA VEZ';
        $('#lose-tag').textContent = tag;
        $('#lose-msg').innerHTML = derrame
          ? 'La medida se pasó<br><em>de lo real</em>'
          : (diff > 0 ? 'Te pasaste<br><em>de la medida</em>' : 'Te faltó<br><em>un poco</em>');
        $('#lose-detalle').innerHTML =
          (derrame ? 'Llenaste la copa hasta el <b>borde</b>'
                   : 'Te ' + (diff > 0 ? 'pasaste' : 'faltó') + ' por <b>' +
                     absd.toFixed(1).replace('.', ',') + '%</b>') +
          '<br><span class="lose-margen">el margen para ganar era de ' +
          tol.toFixed(1).replace('.', ',') + '%</span>';
        logEvent('perdio', { motivo: derrame ? 'derrame' : (diff > 0 ? 'pasado' : 'corto') });
        finalizeSession('perdio');
        showScreen('s-lose');
        autoVolver($('#lose-count'), 12);
      }
    }, 1000);
  }

  /* ============================================================
     DATOS DEL GANADOR + PREMIO
     ============================================================ */
  function limpiarFormulario() {
    ['#inp-nombre', '#inp-email', '#inp-telefono'].forEach(function (s) { var i = $(s); if (i) i.value = ''; });
    var c = $('#chk-consent'); if (c) c.checked = false;
    $('#datos-error').textContent = ' ';
  }

  function validarDatos() {
    var nombre = $('#inp-nombre').value.trim();
    var email = $('#inp-email').value.trim();
    var tel = $('#inp-telefono').value.trim();
    if (nombre.length < 2) return { ok: false, msg: 'Escribe tu nombre para continuar' };
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/.test(email)) return { ok: false, msg: 'Revisa tu email, parece incompleto' };
    if (tel && tel.replace(/\D/g, '').length < 8) return { ok: false, msg: 'El teléfono parece incompleto (o déjalo vacío)' };
    if (!$('#chk-consent').checked) return { ok: false, msg: 'Debes aceptar el uso de tus datos para recibir el código' };
    return { ok: true, nombre: nombre, email: email, telefono: tel };
  }

  function asignarCodigo() {
    if (C.premio.modo === 'pool') {
      return PFDB.poolTomar().then(function (codigo) {
        if (codigo) return { codigo: codigo, modo: 'pool' };
        logEvent('pool_agotado');
        return { codigo: C.premio.codigoGenerico, modo: 'generico_fallback' };
      });
    }
    return Promise.resolve({ codigo: C.premio.codigoGenerico, modo: 'generico' });
  }

  function renderQR(codigo) {
    var cont = $('#premio-qr');
    var url = (C.premio.qrTemplate || C.premio.urlTienda).replace('{CODE}', encodeURIComponent(codigo));
    cont.innerHTML = '';
    try {
      var qr = window.qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      cont.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
    } catch (e) {
      cont.innerHTML = '<div class="qr-fallback">Usa el código de abajo en<br><b>' + C.premio.urlTienda.replace('https://', '') + '</b></div>';
    }
  }

  function enviarDatos() {
    var v = validarDatos();
    var errEl = $('#datos-error');
    if (!v.ok) { errEl.textContent = v.msg; PFAudio.tick(); return; }
    errEl.textContent = ' ';
    // la partida ya se cerró al ganar, así que el uuid viene de ultimaSessionUuid
    var sessionUuid = session ? session.uuid : ultimaSessionUuid;

    asignarCodigo().then(function (premio) {
      var ganador = {
        uuid: PFDB.uuid(),
        session_uuid: sessionUuid,
        device_id: deviceId,
        nombre: v.nombre,
        email: v.email,
        telefono: v.telefono || null,
        codigo: premio.codigo,
        consentimiento: 1,
        ts: ahora(),
        synced: 0,
      };
      return PFDB.put('ganadores', ganador).then(function () {
        logEvent('codigo_asignado', { codigo: premio.codigo, modo: premio.modo });
        PFKeyboard.detach();
        renderQR(premio.codigo);
        $('#premio-codigo').textContent = premio.codigo;
        PFSync.tick();   // el ganador es el dato más valioso: se intenta subir de inmediato
        showScreen('s-premio');
        autoVolver($('#premio-count'), 45);
      });
    }).catch(function (e) {
      console.error(e);
      errEl.textContent = 'Ocurrió un problema guardando tus datos, intenta de nuevo';
    });
  }

  /* ============================================================
     PANEL ADMIN
     ============================================================ */
  var admin = { taps: 0, tapTimer: null, pin: '' };

  function abrirPin() {
    admin.pin = '';
    $('#pin-display').textContent = '····';
    $('#admin-overlay').classList.remove('oculto');
    $('#admin-pin').classList.remove('oculto');
    $('#admin-panel').classList.add('oculto');
  }

  function cerrarAdmin() {
    $('#admin-overlay').classList.add('oculto');
  }

  function fmtHora(iso) {
    if (!iso) return '—';
    try { return new Date(iso).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
    catch (e) { return iso; }
  }

  function statsDe(sesiones) {
    var st = { jugadas: 0, ganadas: 0, perdidas: 0, abandonos: 0, enCurso: 0 };
    sesiones.forEach(function (s) {
      st.jugadas++;
      if (s.resultado === 'gano') st.ganadas++;
      else if (s.resultado === 'perdio') st.perdidas++;
      else if (s.resultado === 'abandono') st.abandonos++;
      else st.enCurso++;
    });
    return st;
  }

  function tablaStats(st) {
    return '<tr><td>Jugadas</td><td>' + st.jugadas + '</td></tr>' +
      '<tr><td>Ganadas</td><td>' + st.ganadas + '</td></tr>' +
      '<tr><td>Perdidas</td><td>' + st.perdidas + '</td></tr>' +
      '<tr><td>Abandonos</td><td>' + st.abandonos + '</td></tr>';
  }

  function refrescarPanel() {
    PFDB.getAll('sesiones').then(function (todas) {
      var hoy = new Date().toDateString();
      var deHoy = todas.filter(function (s) { return new Date(s.inicio).toDateString() === hoy; });
      $('#admin-stats-hoy').innerHTML = tablaStats(statsDe(deHoy));
      $('#admin-stats-total').innerHTML = tablaStats(statsDe(todas));
    });
    renderSync(PFSync.getStatus());
    PFSync.refreshPendientes();
    renderOffline(PFOffline.getStatus());
    PFOffline.refrescar();
    PFDB.poolDisponibles().then(function (n) {
      $('#admin-pool-info').textContent = 'Modo premio: ' + C.premio.modo + ' · Códigos disponibles: ' + n;
    });
    $('#admin-dif').value = difActual;
    $('#admin-sonido').checked = PFAudio.isEnabled();
    $('#admin-meta').textContent = 'Dispositivo ' + deviceId + ' · v' + C.version + ' · ' + C.evento;
  }

  function renderSync(st) {
    var p = st.pendientes;
    $('#admin-sync').innerHTML = !st.habilitado
      ? 'Sync <b>desactivado</b> (URL vacía en config.js). Los datos quedan en el tótem; usa "Exportar respaldo".'
      : 'Pendientes: <b>' + p.sesiones + '</b> sesiones · <b>' + p.eventos + '</b> eventos · <b>' + p.ganadores + '</b> ganadores<br>' +
        'Último intento: <b>' + fmtHora(st.ultimoIntento) + '</b> · Último éxito: <b>' + fmtHora(st.ultimoExito) + '</b>' +
        (st.ultimoError ? '<br>Último error: <b>' + st.ultimoError + '</b>' : '');
  }

  /* Lo que importa acá es que quien está a cargo del stand pueda confirmar
     de un vistazo que el tótem ya aguanta sin señal antes de que llegue
     gente: si el juego se sirve por HTTP y el precache no terminó, un corte
     de red deja la pantalla en blanco al siguiente arranque. */
  function renderOffline(st) {
    var el = $('#admin-offline');
    if (!el) return;
    var txt;
    if (!st.soportado) {
      txt = st.motivo || 'No aplica';
    } else if (st.listo) {
      txt = '<b>Listo para funcionar sin red</b><br>' +
        st.cacheados + ' de ' + st.total + ' archivos guardados · ' + (st.version || '');
    } else if (st.activo) {
      txt = 'Descarga incompleta: <b>' + st.cacheados + ' de ' + st.total + '</b><br>' +
        'Recarga la página con red disponible antes de abrir el stand.';
    } else {
      txt = st.motivo || 'Preparando la copia sin red…';
    }
    if (st.hayActualizacion) {
      txt += '<br><b>Hay una versión nueva lista para instalar.</b>';
    }
    el.innerHTML = txt;
    $('#btn-offline-update').classList.toggle('oculto', !st.hayActualizacion);
  }

  function exportarRespaldo() {
    Promise.all([
      PFDB.getAll('sesiones'), PFDB.getAll('eventos'), PFDB.getAll('ganadores'),
    ]).then(function (r) {
      var blob = new Blob([JSON.stringify({
        device_id: deviceId, exportado: ahora(), version: C.version,
        sesiones: r[0], eventos: r[1], ganadores: r[2],
      }, null, 1)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'pf_totem_respaldo_' + new Date().toISOString().slice(0, 16).replace(/[:T]/g, '-') + '.json';
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
    });
  }

  /* ============================================================
     ARRANQUE Y EVENTOS
     ============================================================ */
  function bindEventos() {
    // cualquier toque reinicia el reloj de inactividad
    document.addEventListener('pointerdown', resetInactividad, true);

    // kiosko: sin menú contextual ni zoom por doble toque
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    document.addEventListener('dblclick', function (e) { e.preventDefault(); });

    // ---- attract → empezar
    $('#s-attract').addEventListener('click', function (e) {
      if (e.target.closest('#monograma')) return; // los toques del monograma son para admin
      PFAudio.ensure();
      pedirWakeLock();
      if (C.pantallaCompleta && document.documentElement.requestFullscreen && !document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(function () {});
      }
      PFAudio.select();
      startSession();
      showScreen('s-momento');
    });

    // ---- monograma: 5 toques → PIN admin
    $('#monograma').addEventListener('click', function () {
      admin.taps++;
      clearTimeout(admin.tapTimer);
      admin.tapTimer = setTimeout(function () { admin.taps = 0; }, 4000);
      if (admin.taps >= 5) { admin.taps = 0; abrirPin(); }
    });

    // ---- momento
    $$('.card-momento').forEach(function (card) {
      card.addEventListener('click', function () {
        if (!session) return;
        PFAudio.select();
        session.momento = card.dataset.momento;
        saveSession();
        logEvent('momento_elegido', { momento: session.momento });
        showScreen('s-variedad');
      });
    });

    // ---- variedad → vino ideal
    $$('.card-variedad').forEach(function (card) {
      card.addEventListener('click', function () {
        if (!session || !session.momento) return;
        PFAudio.select();
        session.variedad = card.dataset.variedad;
        var vino = C.matriz[session.momento + '|' + session.variedad] ||
          { nombre: card.dataset.variedad, desc: '' };
        session.vino = vino.nombre;
        saveSession();
        logEvent('variedad_elegida', { variedad: session.variedad, vino: vino.nombre });
        var varDef = C.variedades.find(function (v) { return v.id === session.variedad; });
        $('#vino-nombre').textContent = vino.nombre;
        $('#vino-desc').textContent = vino.desc;
        $('#vino-copa').innerHTML = buildCopaSvg(varDef,
          { uid: 'vino', decorativa: true, sinLinea: true, nivelInicial: 58 });
        showScreen('s-vino');
      });
    });

    // ---- ir a servir
    $('#btn-servir').addEventListener('click', function () {
      if (!session) return;
      PFAudio.select();
      prepararServir();
      showScreen('s-servir');
    });

    // ---- mecánica de servido (mantener presionado)
    var stage = $('#servir-stage');
    stage.addEventListener('pointerdown', function (e) {
      e.preventDefault();
      iniciarServido();
    });
    window.addEventListener('pointerup', function () {
      if (serve.estado === 'pouring') { detenerServido(); evaluarServida(false); }
    });
    window.addEventListener('pointercancel', function () {
      if (serve.estado === 'pouring') { detenerServido(); evaluarServida(false); }
    });

    // ---- ganó → formulario
    $('#btn-reclamar').addEventListener('click', function () {
      PFAudio.select();
      limpiarFormulario();
      showScreen('s-datos');
      PFKeyboard.attach($('#inp-nombre'), 'text');
    });

    // ---- formulario: foco de inputs con teclado propio
    $$('.osk-input').forEach(function (inp) {
      inp.addEventListener('pointerdown', function (e) {
        e.preventDefault();
        PFKeyboard.attach(inp, inp.dataset.osk);
      });
    });
    $('#btn-enviar-datos').addEventListener('click', enviarDatos);

    // ---- cierres
    $('#btn-fin-premio').addEventListener('click', goHome);
    $('#btn-fin-lose').addEventListener('click', goHome);

    // ---- admin: PIN
    $$('.pin-pad button').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = b.dataset.d;
        PFAudio.tick();
        if (d === 'C') { admin.pin = ''; }
        else if (d === 'OK') {
          if (admin.pin === C.adminPin) {
            $('#admin-pin').classList.add('oculto');
            $('#admin-panel').classList.remove('oculto');
            logEvent('admin_abierto');
            refrescarPanel();
          } else {
            admin.pin = '';
            $('#pin-display').textContent = 'PIN incorrecto';
            setTimeout(function () { $('#pin-display').textContent = '····'; }, 900);
            return;
          }
        } else if (admin.pin.length < 8) { admin.pin += d; }
        $('#pin-display').textContent = admin.pin ? '•'.repeat(admin.pin.length) : '····';
      });
    });
    $('#btn-pin-salir').addEventListener('click', cerrarAdmin);
    $('#btn-admin-cerrar').addEventListener('click', cerrarAdmin);

    // ---- admin: acciones
    $('#btn-sync-now').addEventListener('click', function () {
      $('#btn-sync-now').textContent = 'Sincronizando…';
      PFSync.tick().then(function () {
        $('#btn-sync-now').textContent = 'Sincronizar ahora';
        refrescarPanel();
      });
    });
    $('#btn-probar').addEventListener('click', function () {
      var b = $('#btn-probar');
      b.textContent = 'Probando…';
      PFSync.probar().then(function (r) {
        b.textContent = r.msg;
        setTimeout(function () { b.textContent = 'Probar conexión'; }, 5000);
      });
    });
    $('#btn-export').addEventListener('click', exportarRespaldo);
    $('#admin-dif').addEventListener('change', function () {
      difActual = this.value;
      PFDB.kvSet('dificultad', difActual);
      logEvent('admin_dificultad', { dificultad: difActual });
    });
    $('#admin-sonido').addEventListener('change', function () {
      PFAudio.setEnabled(this.checked);
      PFDB.kvSet('sonido', this.checked);
    });
    $('#btn-pool-import').addEventListener('click', function () {
      var raw = $('#admin-pool-text').value.trim();
      if (!raw) return;
      var codigos;
      try { codigos = JSON.parse(raw); if (!Array.isArray(codigos)) throw 0; }
      catch (e) { codigos = raw.split(/[\s,;]+/); }
      PFDB.poolImportar(codigos).then(function (n) {
        $('#admin-pool-text').value = '';
        logEvent('admin_pool_import', { nuevos: n });
        refrescarPanel();
      });
    });

    // ---- admin: copia sin red
    $('#btn-offline-check').addEventListener('click', function () {
      var b = $('#btn-offline-check');
      b.textContent = 'Buscando…';
      PFOffline.buscarActualizacion().then(function (hay) {
        b.textContent = hay ? 'Hay versión nueva' : 'Ya está al día';
        PFOffline.refrescar();
        setTimeout(function () { b.textContent = 'Buscar actualización'; }, 4000);
      });
    });
    $('#btn-offline-update').addEventListener('click', function () {
      $('#btn-offline-update').textContent = 'Instalando…';
      logEvent('admin_actualizar_sw');
      PFOffline.actualizarAhora();
    });

    // estado de sync y de la copia sin red, en vivo mientras el panel está abierto
    window.addEventListener('pf:sync', function (e) {
      if (!$('#admin-panel').classList.contains('oculto')) renderSync(e.detail);
    });
    window.addEventListener('pf:offline', function (e) {
      if (!$('#admin-panel').classList.contains('oculto')) renderOffline(e.detail);
    });
  }

  // mantener pantalla despierta (donde el navegador lo permita)
  var wakeLock = null;
  function pedirWakeLock() {
    if (!('wakeLock' in navigator)) return;
    navigator.wakeLock.request('screen').then(function (wl) { wakeLock = wl; }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') pedirWakeLock();
  });

  /* Partidas que quedaron abiertas por un corte de luz / cierre:
     al arrancar se cierran como abandono para que el reporte cuadre. */
  function barrerSesionesColgadas() {
    return PFDB.getAll('sesiones').then(function (todas) {
      var colgadas = todas.filter(function (s) { return !s.fin; });
      return Promise.all(colgadas.map(function (s) {
        s.resultado = s.resultado || 'abandono';
        s.fin = s.inicio;
        s.synced = 0;
        return PFDB.put('sesiones', s);
      })).then(function () {
        if (colgadas.length) console.info('Sesiones colgadas cerradas:', colgadas.length);
      });
    });
  }

  function boot() {
    PFDB.init().then(function () {
      deviceId = localStorage.getItem('pf_device_id');
      if (!deviceId) {
        deviceId = 'PF-TOTEM-' + Math.random().toString(36).slice(2, 6).toUpperCase();
        localStorage.setItem('pf_device_id', deviceId);
      }
      return Promise.all([PFDB.kvGet('dificultad', C.dificultad), PFDB.kvGet('sonido', C.sonido.activado)]);
    }).then(function (r) {
      difActual = r[0];
      PFAudio.init({ activado: r[1], volumen: C.sonido.volumen });

      $('#consent-text').textContent = C.textos.consentimiento;
      $('#attract-claim').textContent = C.textos.claim;
      $('#premio-bajada').textContent = C.textos.premioBajada;

      // copa decorativa del attract (tinto servido en la línea)
      $('#attract-copa').innerHTML = buildCopaSvg(C.variedades[0],
        { uid: 'attract', decorativa: true, nivelInicial: C.lineaObjetivo });

      // copas de la pantalla de variedades, una por variedad y con su color
      $$('.copa-var svg').forEach(function (svg) {
        var vd = C.variedades.find(function (v) { return v.id === svg.dataset.copa; });
        if (vd) svg.innerHTML = buildCopaSvg(vd, { uid: 'var_' + vd.id, decorativa: true, sinLinea: true, nivelInicial: 58 });
      });

      // pool de códigos desde archivo (si el navegador permite leerlo)
      if (C.premio.modo === 'pool') {
        fetch(C.premio.archivoPool).then(function (r) { return r.json(); })
          .then(function (arr) { if (Array.isArray(arr)) return PFDB.poolImportar(arr); })
          .catch(function () { /* file:// suele bloquearlo; importar desde admin */ });
      }

      return barrerSesionesColgadas();
    }).then(function () {
      PFSync.init(C.sync, deviceId);
      /* Si el juego se sirve por HTTP, deja una copia completa en el tótem
         para que siga funcionando cuando la señal se caiga. Con file:// no
         hace nada: los archivos ya están en el disco. */
      PFOffline.init().then(function (st) {
        logEvent('offline_estado', {
          soportado: st.soportado, listo: st.listo,
          cacheados: st.cacheados, total: st.total,
        });
      });
      PFKeyboard.init($('#osk'), function (input) {
        // "Listo": pasar al siguiente campo, o cerrar teclado en el último
        if (input.id === 'inp-nombre') PFKeyboard.attach($('#inp-email'), 'email');
        else if (input.id === 'inp-email') PFKeyboard.attach($('#inp-telefono'), 'tel');
        else PFKeyboard.detach();
      });
      bindEventos();
      logEvent('boot', { version: C.version, dificultad: difActual });
      resetInactividad();
      showScreen('s-attract');
    }).catch(function (e) {
      console.error('Error de arranque', e);
      document.body.innerHTML = '<div style="color:#f2e7d3;font-family:sans-serif;padding:40px;text-align:center">' +
        'Error iniciando el tótem: ' + String(e && e.message || e) + '<br><br>Revisa la consola.</div>';
    });
  }

  /* API de sólo lectura para diagnosticar desde la consola del tótem
     (y para las pruebas automatizadas). No muta nada del juego. */
  window.PFGame = {
    linea: function () { return lineaPartida; },
    centro: centroEfectivo,
    dificultad: function () { return difActual; },
    nivelEn: nivelEn,
    tiempoPara: tiempoPara,
    nivelActual: function () { return serve.nivel; },
  };

  boot();
})();
