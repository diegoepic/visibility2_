/**
 * Nav Camera
 * Cámara de navegación: mueve el mapa siguiendo al ejecutor de forma continua.
 *
 * Por qué existe: el GPS entrega ~1 fix por segundo, así que centrar el mapa directamente en
 * cada fix hace que el mapa "salte". Este módulo interpola entre el fix anterior y el actual con
 * requestAnimationFrame, de modo que el movimiento se ve fluido.
 *
 * Perspectiva 3D: inclinar y rotar el mapa (tilt/heading) SÓLO funciona en mapas vectoriales,
 * o sea creados con un mapId de Google Cloud configurado como Vector. En un mapa raster,
 * moveCamera() aplica center/zoom e ignora tilt/heading en silencio. El módulo detecta en cuál de
 * los dos está corriendo y usa la rama que corresponde, sin configuración:
 *   - vectorial -> moveCamera con tilt + heading (perspectiva de conducción)
 *   - raster    -> recentrado suave + flecha del marcador rotando hacia el avance
 *
 * @version 1.0.0
 */
(function (window) {
  'use strict';

  var FRAME_MS = 33;              // techo de ~30 fps: más no se nota y gasta batería
  var HEADING_SMOOTH = 0.15;      // suavizado de la rotación de cámara (más bajo = más suave)
  var ICON_ROT_MIN_DEG = 3;       // no redibujar el ícono por variaciones imperceptibles

  var map = null;
  var marker = null;
  var baseIcon = null;            // ícono original del marcador, para restaurarlo al salir
  var raf = null;
  var running = false;
  var tracking = true;
  var is3D = false;
  var capsListener = null;

  var from = null, to = null;
  var t0 = 0, dur = 1000, lastUpdate = 0, lastFrame = 0;
  var curHeading = 0, tgtHeading = 0;
  var curZoom = null, tgtZoom = 17;
  var lastIconHeading = null;

  function cfg() {
    return (window.NavEngine && window.NavEngine.CONFIG) || {};
  }

  function utils() {
    return (window.NavEngine && window.NavEngine.utils) || null;
  }

  /**
   * Detecta si el mapa es vectorial (única forma de que tilt/heading tengan efecto).
   * No se puede usar getTilt(): en raster con imagery a zoom alto devuelve 45 y engaña.
   */
  function detect3D(m) {
    try {
      var caps = m && m.getMapCapabilities && m.getMapCapabilities();
      if (caps && typeof caps.isWebGLOverlayViewAvailable === 'boolean') {
        return caps.isWebGLOverlayViewAvailable;
      }
    } catch (_) {}
    return false;
  }

  // Flecha del ejecutor apuntando al rumbo. En vector la cámara ya rota el mundo y los
  // marcadores quedan alineados a la pantalla, así que ahí la rotación del ícono es 0.
  function arrowIcon(rot) {
    return {
      path: 'M 0,-13 L 8.5,9 L 0,3.5 L -8.5,9 Z',
      rotation: rot || 0,
      fillColor: '#1a73e8',
      fillOpacity: 1,
      strokeColor: '#ffffff',
      strokeWeight: 2,
      scale: 1,
      anchor: (window.google && google.maps) ? new google.maps.Point(0, 0) : undefined
    };
  }

  function angleDiff(a, b) {
    return Math.abs(((a - b + 540) % 360) - 180);
  }

  function tick(now) {
    if (!running || !tracking || document.hidden) { raf = null; return; }
    raf = requestAnimationFrame(tick);

    if (!to || !from || !map) return;
    if (now - lastFrame < FRAME_MS) return;
    lastFrame = now;

    // Ease-out cuadrático hacia el último fix conocido. Se interpola HACIA el fix recibido, no
    // se predice más allá: la extrapolación se pasa de largo en las curvas y se ve peor.
    var k = Math.min(1, (now - t0) / dur);
    var e = k * (2 - k);
    var lat = from.lat + (to.lat - from.lat) * e;
    var lng = from.lng + (to.lng - from.lng) * e;

    var U = utils();
    if (U && U.smoothHeading) {
      curHeading = U.smoothHeading(curHeading, tgtHeading, HEADING_SMOOTH);
    } else {
      curHeading = tgtHeading;
    }

    if (is3D) {
      map.moveCamera({
        center: { lat: lat, lng: lng },
        zoom: tgtZoom,
        tilt: cfg().NAV_TILT || 55,
        heading: curHeading
      });
    } else {
      map.setCenter({ lat: lat, lng: lng });
      // setZoom fuera del bucle de animación: cada cambio de zoom dispara carga de tiles.
      if (curZoom !== tgtZoom) { map.setZoom(tgtZoom); curZoom = tgtZoom; }
    }

    if (marker) {
      marker.setPosition({ lat: lat, lng: lng });
      var want = is3D ? 0 : curHeading;
      if (lastIconHeading === null || angleDiff(want, lastIconHeading) > ICON_ROT_MIN_DEG) {
        lastIconHeading = want;
        try { marker.setIcon(arrowIcon(want)); } catch (_) {}
      }
    }
  }

  function ensureRaf() {
    if (!raf && running && tracking && !document.hidden) {
      lastFrame = 0;
      raf = requestAnimationFrame(tick);
    }
  }

  /**
   * Arranca la cámara.
   * @param {google.maps.Map} m
   * @param {google.maps.Marker} mk  marcador del ejecutor (opcional)
   */
  function start(m, mk) {
    map = m || null;
    marker = mk || null;
    if (!map) return false;

    is3D = detect3D(map);
    // getMapCapabilities puede responder false hasta que el mapa termina de inicializar.
    if (!is3D && map.addListener) {
      try {
        capsListener = map.addListener('mapcapabilities_changed', function () {
          is3D = detect3D(map);
          window.dispatchEvent(new CustomEvent('nav:camera_mode', { detail: { is3D: is3D } }));
        });
      } catch (_) {}
    }

    if (marker && !baseIcon) {
      try { baseIcon = marker.getIcon(); } catch (_) { baseIcon = null; }
    }

    running = true;
    tracking = true;
    from = to = null;
    curZoom = null;
    lastIconHeading = null;
    lastUpdate = 0;

    window.dispatchEvent(new CustomEvent('nav:camera_mode', { detail: { is3D: is3D } }));
    return true;
  }

  function stop() {
    running = false;
    if (raf) { cancelAnimationFrame(raf); raf = null; }
    if (capsListener) {
      try { google.maps.event.removeListener(capsListener); } catch (_) {}
      capsListener = null;
    }
    // Restaurar el ícono redondo: fuera de navegación no hay rumbo confiable que mostrar.
    if (marker && baseIcon) {
      try { marker.setIcon(baseIcon); } catch (_) {}
    }
    baseIcon = null;
    marker = null;
    from = to = null;
  }

  /**
   * Nuevo fix de posición. Se llama una vez por tick de GPS; la animación la lleva el rAF.
   * @param {{lat:number,lng:number}} pos  posición ya proyectada sobre la vía
   * @param {number} speedKmh
   * @param {number} heading  rumbo en grados
   */
  function update(pos, speedKmh, heading) {
    if (!running || !pos || !map) return;

    var now = (window.performance && performance.now) ? performance.now() : Date.now();
    from = to || pos;
    to = pos;
    // La duración de la interpolación es el intervalo real medido entre fixes: si el GPS baja a
    // un fix cada 3 s, la animación se estira en vez de terminar y quedarse congelada.
    dur = Math.min(2000, Math.max(400, lastUpdate ? (now - lastUpdate) : 1000));
    t0 = now;
    lastUpdate = now;

    if (typeof heading === 'number' && isFinite(heading)) tgtHeading = heading;

    var U = utils();
    tgtZoom = (U && U.speedToZoom) ? U.speedToZoom(speedKmh || 0) : 17;

    ensureRaf();
  }

  // Cuando el usuario arrastra el mapa se suelta el seguimiento (lo decide nav_engine); acá
  // sólo se corta la animación para no pelear con el gesto.
  function setTracking(on) {
    tracking = !!on;
    if (tracking) { from = to; ensureRaf(); }
    else if (raf) { cancelAnimationFrame(raf); raf = null; }
  }

  // Con la pestaña oculta no tiene sentido animar (batería). El GPS y la voz siguen corriendo:
  // el ejecutor puede llevar el teléfono en el soporte con la pantalla apagada y necesita oír
  // "en 200 metros, gire a la derecha".
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (raf) { cancelAnimationFrame(raf); raf = null; }
    } else {
      ensureRaf();
    }
  });

  window.NavCamera = {
    start: start,
    stop: stop,
    update: update,
    setTracking: setTracking,
    arrowIcon: arrowIcon,
    is3D: function () { return is3D; },
    isRunning: function () { return running; }
  };

})(window);
