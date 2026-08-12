/* ============================================================
   PFAudio — sonidos sintetizados con WebAudio (cero archivos).
   El contexto se crea en el primer gesto del usuario (requisito
   de los navegadores). Si el dispositivo no soporta WebAudio,
   todo falla en silencio y el juego sigue.
   ============================================================ */
window.PFAudio = (function () {
  'use strict';

  var ctx = null;
  var master = null;
  var enabled = true;
  var volume = 0.6;
  var pour = null; // nodos del sonido de servido en curso

  function ensure() {
    if (ctx) {
      if (ctx.state === 'suspended') { try { ctx.resume(); } catch (e) {} }
      return ctx;
    }
    try {
      var AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      ctx = new AC();
      master = ctx.createGain();
      master.gain.value = volume;
      master.connect(ctx.destination);
    } catch (e) { ctx = null; }
    return ctx;
  }

  function now() { return ctx.currentTime; }

  function tone(freq, dur, type, gain, delay, freqEnd) {
    if (!enabled || !ensure()) return;
    try {
      var t0 = now() + (delay || 0);
      var osc = ctx.createOscillator();
      var g = ctx.createGain();
      osc.type = type || 'sine';
      osc.frequency.setValueAtTime(freq, t0);
      if (freqEnd) osc.frequency.exponentialRampToValueAtTime(freqEnd, t0 + dur);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(gain || 0.25, t0 + 0.015);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
      osc.connect(g); g.connect(master);
      osc.start(t0); osc.stop(t0 + dur + 0.05);
    } catch (e) {}
  }

  // Toque de UI: blip corto y discreto
  function tick() { tone(880, 0.08, 'sine', 0.12); vibrarToque(); }

  // Selección de tarjeta: dos notas suaves
  function select() { tone(660, 0.1, 'sine', 0.15); tone(990, 0.12, 'sine', 0.12, 0.07); vibrarToque(); }

  /* Servido: ruido blanco filtrado; el filtro sube de frecuencia con el
     nivel de la copa (como cuando se llena un vaso de verdad). */
  function pourStart() {
    // la vibración va antes del guard: son dos canales independientes y
    // apagar el sonido no debe apagar también el táctil
    vibrarServir();
    if (!enabled || !ensure()) return;
    try {
      pourStop(true);
      var len = ctx.sampleRate * 2;
      var buf = ctx.createBuffer(1, len, ctx.sampleRate);
      var data = buf.getChannelData(0);
      for (var i = 0; i < len; i++) data[i] = Math.random() * 2 - 1;
      var src = ctx.createBufferSource();
      src.buffer = buf; src.loop = true;
      var filt = ctx.createBiquadFilter();
      filt.type = 'bandpass';
      filt.frequency.value = 400;
      filt.Q.value = 1.2;
      var g = ctx.createGain();
      g.gain.setValueAtTime(0.0001, now());
      g.gain.exponentialRampToValueAtTime(0.16, now() + 0.1);
      src.connect(filt); filt.connect(g); g.connect(master);
      src.start();
      pour = { src: src, filt: filt, g: g };
    } catch (e) { pour = null; }
  }

  function pourLevel(level) { // level 0..100
    if (!pour) return;
    try { pour.filt.frequency.value = 350 + level * 14; } catch (e) {}
  }

  function pourStop(quick) {
    if (!pour) return;
    try {
      var p = pour; pour = null;
      var t = now();
      p.g.gain.cancelScheduledValues(t);
      p.g.gain.setValueAtTime(p.g.gain.value, t);
      p.g.gain.exponentialRampToValueAtTime(0.0001, t + (quick ? 0.03 : 0.15));
      p.src.stop(t + 0.2);
    } catch (e) { pour = null; }
  }

  // Victoria: arpegio de campana ascendente
  function win() {
    vibrarWin();
    var notas = [659.25, 830.61, 987.77, 1318.5]; // E5 G#5 B5 E6
    notas.forEach(function (f, i) {
      tone(f, 0.7, 'sine', 0.22, i * 0.12);
      tone(f * 2, 0.4, 'sine', 0.06, i * 0.12); // armónico brillante
    });
  }

  // Derrota: dos notas suaves descendentes (amable, sin drama)
  function lose() { vibrarLose(); tone(440, 0.35, 'sine', 0.18); tone(349.23, 0.5, 'sine', 0.16, 0.22); }

  /* Pista de dirección en niveles sin línea (ver dif().intentos en game.js):
     un glissando que sube pide más lleno, uno que baja pide más vacío. Es un
     canal de información aparte del texto en pantalla — útil con el stand
     ruidoso o con quien no está mirando fijo la pantalla en ese momento. */
  function masLleno() { vibrarToque(); tone(480, 0.24, 'sine', 0.18, 0, 760); }
  function masVacio() { vibrarToque(); tone(760, 0.24, 'sine', 0.18, 0, 480); }

  // Burbuja de espumante: pops cortos aleatorios (lo llama game.js)
  function bubble() { tone(1200 + Math.random() * 900, 0.05, 'sine', 0.05); }

  /* ---------- vibración ----------
     Vive acá porque es feedback sensorial igual que el sonido y comparte el
     mismo ciclo de vida. Sólo existe en Android; en iOS y en PC de escritorio
     navigator.vibrate no está definido y todo esto no hace nada. */
  var vibraOn = true;

  function vibrar(patron) {
    if (!vibraOn) return;
    if (!navigator.vibrate) return;
    try { navigator.vibrate(patron); } catch (e) {}
  }

  var PATRONES = {
    toque: 12,                       // tap de UI, apenas perceptible
    servir: 25,                      // arranca el vertido
    win: [45, 55, 45, 55, 160],      // remate del brindis logrado
    lose: 130,                       // un solo golpe seco
  };

  function vibrarToque()  { vibrar(PATRONES.toque); }
  function vibrarServir() { vibrar(PATRONES.servir); }
  function vibrarWin()    { vibrar(PATRONES.win); }
  function vibrarLose()   { vibrar(PATRONES.lose); }

  function setVibracion(v) { vibraOn = !!v; if (!v && navigator.vibrate) { try { navigator.vibrate(0); } catch (e) {} } }
  function vibracionSoportada() { return !!navigator.vibrate; }

  function setEnabled(v) { enabled = !!v; if (!v) pourStop(true); }
  function setVolume(v) { volume = v; if (master) master.gain.value = v; }
  function init(cfg) {
    enabled = cfg.activado;
    volume = cfg.volumen;
    if (cfg.vibracion !== undefined) vibraOn = !!cfg.vibracion;
  }

  return {
    init: init, ensure: ensure,
    tick: tick, select: select, win: win, lose: lose, bubble: bubble,
    masLleno: masLleno, masVacio: masVacio,
    pourStart: pourStart, pourLevel: pourLevel, pourStop: pourStop,
    setEnabled: setEnabled, setVolume: setVolume,
    isEnabled: function () { return enabled; },
    // vibración
    vibrarToque: vibrarToque, vibrarServir: vibrarServir,
    vibrarWin: vibrarWin, vibrarLose: vibrarLose,
    setVibracion: setVibracion, vibracionSoportada: vibracionSoportada,
    isVibracion: function () { return vibraOn; },
  };
})();
