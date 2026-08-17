/**
 * Navigation Engine v2.0
 * Motor de navegación 3D con seguimiento en tiempo real, HUD y voz
 * @version 2.0.0
 */
(function(window) {
  'use strict';

  // ==================== CONFIGURACIÓN ====================

  const CONFIG = {
    // Tolerancias de ruta
    OFF_ROUTE_TOL_SLOW: 40,      // metros a baja velocidad
    OFF_ROUTE_TOL_FAST: 80,      // metros a alta velocidad
    SPEED_THRESHOLD: 35,         // km/h para cambiar tolerancia

    // Cooldowns y tiempos
    REROUTE_COOLDOWN_MS: 30000,  // 30s entre rerouteos
    OFF_ROUTE_PERSIST_MS: 12000, // 12s fuera de ruta para recalcular
    STEP_ADVANCE_DISTANCE: 15,   // metros para avanzar al siguiente paso

    // Precisión GPS
    MIN_ACCURACY_M: 80,          // precisión mínima requerida
    MIN_SPEED_KMH: 3,            // velocidad mínima para reroute

    // Cámara
    NAV_TILT: 55,                // inclinación en navegación
    ZOOM_SLOW: 18,               // zoom a baja velocidad
    ZOOM_MEDIUM: 17,             // zoom a velocidad media
    ZOOM_FAST: 16,               // zoom a alta velocidad

    // Instrucciones preventivas
    PREVIEW_DISTANCE_FAR: 500,   // metros para preview lejano
    PREVIEW_DISTANCE_NEAR: 200,  // metros para preview cercano
    PREVIEW_DISTANCE_NOW: 50,    // metros para instrucción inmediata

    // Heading / GPS
    HEADING_MIN_SPEED: 5,        // km/h mínimos para confiar en pos.coords.heading
    HEADING_SMOOTH: 0.3,         // factor de suavizado del rumbo (0..1)
    GPS_WEAK_THROTTLE_MS: 8000   // anti-spam del evento nav:gps_weak
  };

  // Iconos de maniobra.
  // Las claves están en kebab-case: normalizeManeuver() convierte a este formato tanto el
  // vocabulario de Routes API (TURN_RIGHT) como el de DirectionsService (turn-right).
  const MANEUVER_ICONS = {
    'turn-right': 'fa-arrow-right',
    'turn-left': 'fa-arrow-left',
    'turn-slight-right': 'fa-long-arrow-right',
    'turn-slight-left': 'fa-long-arrow-left',
    'turn-sharp-right': 'fa-share',
    'turn-sharp-left': 'fa-reply',
    'uturn-right': 'fa-undo',
    'uturn-left': 'fa-repeat',
    'roundabout-right': 'fa-circle-o',
    'roundabout-left': 'fa-circle-o',
    'roundabout-clockwise': 'fa-circle-o',
    'roundabout-counterclockwise': 'fa-circle-o',
    'roundabout-exit': 'fa-circle-o',
    'merge': 'fa-compress',
    'fork-right': 'fa-code-fork',
    'fork-left': 'fa-code-fork',
    'ramp-right': 'fa-sign-out',
    'ramp-left': 'fa-sign-out',
    'straight': 'fa-long-arrow-up',
    'depart': 'fa-location-arrow',
    'name-change': 'fa-long-arrow-up',
    'ferry': 'fa-ship',
    'ferry-train': 'fa-train',
    'destination': 'fa-flag-checkered',
    'destination-right': 'fa-flag-checkered',
    'destination-left': 'fa-flag-checkered',
    'waypoint': 'fa-map-pin',
    'default': 'fa-location-arrow'
  };

  // ==================== UTILIDADES ====================

  function haversine(a, b) {
    const R = 6371000;
    const toRad = x => x * Math.PI / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const aa = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(aa));
  }

  function bearing(a, b) {
    const toRad = x => x * Math.PI / 180;
    const toDeg = x => x * 180 / Math.PI;
    const dLng = toRad(b.lng - a.lng);
    const lat1 = toRad(a.lat);
    const lat2 = toRad(b.lat);
    const y = Math.sin(dLng) * Math.cos(lat2);
    const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);
    return (toDeg(Math.atan2(y, x)) + 360) % 360;
  }

  // Suaviza el rumbo interpolando por el arco más corto (evita el salto 359°→0° y la vibración).
  function smoothHeading(prev, next, factor) {
    if (prev == null || isNaN(prev)) return next;
    factor = (factor == null) ? 0.3 : factor;
    const diff = ((next - prev + 540) % 360) - 180; // diferencia más corta en [-180, 180]
    return (prev + diff * factor + 360) % 360;
  }

  function decode(path) {
    if (!path || !google?.maps?.geometry?.encoding) return [];
    return google.maps.geometry.encoding.decodePath(path).map(ll => ({
      lat: ll.lat(),
      lng: ll.lng()
    }));
  }

  function formatDistance(meters) {
    if (meters >= 1000) {
      return (meters / 1000).toFixed(1) + ' km';
    }
    return Math.round(meters) + ' m';
  }

  function formatDuration(seconds) {
    if (seconds < 60) return 'Menos de 1 min';
    if (seconds < 3600) {
      const min = Math.round(seconds / 60);
      return min + ' min';
    }
    const hours = Math.floor(seconds / 3600);
    const mins = Math.round((seconds % 3600) / 60);
    return hours + ' h ' + mins + ' min';
  }

  function parseDuration(str) {
    if (typeof str === 'number') return str;
    if (typeof str === 'string' && str.endsWith('s')) {
      return parseFloat(str);
    }
    return 0;
  }

  // Los dos backends usan vocabularios distintos para la misma maniobra:
  // Routes API entrega TURN_RIGHT y DirectionsService turn-right. Se normalizan ambos a
  // kebab-case para poder hacer lookup exacto contra MANEUVER_ICONS.
  function normalizeManeuver(maneuver) {
    return String(maneuver || '').toLowerCase().replace(/_/g, '-').trim();
  }

  function getManeuverIcon(maneuver) {
    const key = normalizeManeuver(maneuver);
    return MANEUVER_ICONS[key] || MANEUVER_ICONS.default;
  }

  function speedToZoom(kmh) {
    if (kmh <= 25) return CONFIG.ZOOM_SLOW;
    if (kmh <= 60) return CONFIG.ZOOM_MEDIUM;
    return CONFIG.ZOOM_FAST;
  }

  /**
   * Proyecta el punto p sobre el segmento a→b.
   * Devuelve { t, dist, segLen }:
   *   t      fracción recorrida del segmento, recortada a [0,1]
   *   dist   distancia perpendicular de p al segmento, en metros
   *   segLen largo del segmento, en metros
   * Proyección equirectangular local (plano tangente en la latitud media del segmento): a escala
   * de un tramo de calle el error es despreciable y evita trigonometría por punto.
   * Función pura: no depende de google.maps, así que se puede probar desde consola.
   */
  function projectOnSegment(p, a, b) {
    const R = 6371000, toRad = x => x * Math.PI / 180;
    const cos0 = Math.cos(toRad((a.lat + b.lat) / 2));
    const px = R * toRad(p.lng) * cos0, py = R * toRad(p.lat);
    const ax = R * toRad(a.lng) * cos0, ay = R * toRad(a.lat);
    const bx = R * toRad(b.lng) * cos0, by = R * toRad(b.lat);
    const dx = bx - ax, dy = by - ay;
    const len2 = dx * dx + dy * dy;
    let t = len2 > 0 ? ((px - ax) * dx + (py - ay) * dy) / len2 : 0;
    t = t < 0 ? 0 : (t > 1 ? 1 : t);
    const qx = ax + t * dx, qy = ay + t * dy;
    return { t, dist: Math.hypot(px - qx, py - qy), segLen: Math.sqrt(len2) };
  }

  /**
   * Decodifica la polilínea de un paso UNA sola vez y precalcula distancias acumuladas.
   * Es perezoso a propósito: una ruta de 25 locales en HIGH_QUALITY son ~12k puntos, y sólo
   * hace falta la geometría de los pasos que el ejecutor realmente pisa.
   * Si el backend no entregó polilínea para el paso, se degrada al segmento start→end.
   */
  function ensureStepGeom(step) {
    if (step._geom) return step._geom;
    let pts = step.polyline ? decode(step.polyline) : null;
    if (!pts || pts.length < 2) pts = [step.start, step.end];
    const cum = new Float64Array(pts.length);
    for (let i = 1; i < pts.length; i++) {
      cum[i] = cum[i - 1] + haversine(pts[i - 1], pts[i]);
    }
    step._geom = { pts, cum, len: cum[cum.length - 1] || 0 };
    return step._geom;
  }

  // ==================== CLASE NAVIGATOR 3D ====================

  class Navigator3D {
    constructor(map, hooks = {}) {
      this.map = map;
      this.hooks = hooks;

      // Estado de navegación
      this.active = false;
      this.paused = false;
      this.route = null;
      this.steps = [];
      this.stepIdx = 0;
      this.path = [];
      this.waypoints = [];
      this.waypointIdx = 0;

      // Estado de posición
      this.geoWatch = null;
      this.lastPos = null;
      this.lastHeading = 0;
      this.lastSpeed = 0;
      this.lastAccuracy = 999;
      this._lastTime = null;

      // Estado de reroute
      this.offRouteSince = null;
      this.lastRerouteAt = 0;

      // Throttle del aviso de GPS débil
      this._lastGpsWeakAt = 0;

      // Estado de cámara
      this.cameraTracking = true;

      // Listener de drag
      this._dragListener = null;

      // Cursor sobre la ruta (lo mantiene _locateOnRoute/_applyLocate).
      // El avance de paso NO se decide por radio al punto final, sino proyectando la posición
      // sobre la polilínea del paso: así funciona igual a 5 km/h que a 100 km/h.
      this._segIdx = 0;          // índice del segmento dentro de la geometría del paso actual
      this._alongInStep = 0;     // metros recorridos dentro del paso actual
      this._snapped = null;      // posición proyectada sobre la vía (la que se pinta/sigue)
      this.lateral = 0;          // distancia perpendicular a la ruta, en metros
      this._lastWideScanAt = 0;  // throttle del barrido de rescate

      // Corrección de tráfico: cociente entre la duración real de la ruta (que sí considera
      // tráfico) y la suma de staticDuration por paso (que no). Ver _computeTrafficFactor.
      this._trafficFactor = 1;

      // Fix #14: estado de anuncios preventivos para evitar repetición
      this._lastAnnouncedThreshold = null;

      // Fix #8: destino original para reroutes correctos
      this.destination = null;
    }

    // ==================== INICIO/PARADA ====================

    async startFromSelection(params) {
      const { origin, destination, waypoints, optimize } = params;

      try {
        // Calcular ruta en modo navegación
        const route = await window.RouteEngine.computeRouteUnified({
          origin,
          destination,
          waypoints,
          optimize,
          mode: 'nav'
        });

        this.route = route;
        this.steps = this._buildSteps(route);
        this.path = decode(route.polyline?.encodedPolyline || '');
        this.waypoints = waypoints || [];
        this.waypointIdx = 0;
        // Fix #8: guardar destino original para reroutes
        this.destination = destination;
        this._resetRouteCursor(route);

        this.active = true;
        this.paused = false;
        this.offRouteSince = null;
        this.lastRerouteAt = 0;
        this.cameraTracking = true;
        this._rerouteFails = 0;
        clearTimeout(this._arrivalTimer); this._arrivalTimer = null;
        clearTimeout(this._retryTimer);   this._retryTimer = null;

        // Notificar inicio
        if (this.hooks.onRoute) {
          this.hooks.onRoute(route, this.steps, false);
        }

        // Anunciar inicio
        if (window.VoiceController) {
          VoiceController.speak('Navegación iniciada', 'high');
        }

        // Activar seguimiento GPS
        this._watchGps();

        // Configurar listener de drag
        this._setupDragListener();

        window.dispatchEvent(new CustomEvent('nav:started', {
          detail: { route, steps: this.steps }
        }));

        return true;
      } catch (error) {
        console.error('[Navigator3D] Error starting navigation:', error);
        if (this.hooks.onError) {
          this.hooks.onError(error);
        }
        throw error;
      }
    }

    stop() {
      this.active = false;
      this.paused = false;
      this._unwatchGps();
      this._removeDragListener();

      clearTimeout(this._arrivalTimer); this._arrivalTimer = null;
      clearTimeout(this._retryTimer);   this._retryTimer = null;

      this.route = null;
      this.steps = [];
      this.path = [];
      this._resetRouteCursor(null);
      this.offRouteSince = null;

      if (this.hooks.onStop) {
        this.hooks.onStop();
      }

      // Anunciar fin
      if (window.VoiceController) {
        VoiceController.speak('Navegación finalizada', 'normal');
      }

      window.dispatchEvent(new CustomEvent('nav:stopped'));
    }

    pause() {
      this.paused = true;
      if (window.VoiceController) {
        VoiceController.pause();
      }
    }

    resume() {
      this.paused = false;
      if (window.VoiceController) {
        VoiceController.resume();
      }
    }

    // ==================== GPS ====================

    _watchGps() {
      this._unwatchGps();

      if (!navigator.geolocation) {
        console.error('[Navigator3D] Geolocation not available');
        return;
      }

      this.geoWatch = navigator.geolocation.watchPosition(
        (pos) => this._onPosition(pos),
        (err) => this._onPositionError(err),
        {
          enableHighAccuracy: true,
          maximumAge: 1000,
          timeout: 10000
        }
      );
    }

    _unwatchGps() {
      if (this.geoWatch != null) {
        navigator.geolocation.clearWatch(this.geoWatch);
        this.geoWatch = null;
      }
    }

    _onPosition(pos) {
      if (!this.active || this.paused) return;

      const cur = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      const acc = pos.coords.accuracy || 999;
      const now = Date.now();

      // Velocidad: usar pos.coords.speed (m/s) si el dispositivo la entrega; si no, derivarla.
      let speedKmh = 0;
      if (typeof pos.coords.speed === 'number' && isFinite(pos.coords.speed) && pos.coords.speed >= 0) {
        speedKmh = pos.coords.speed * 3.6;
      } else if (this.lastPos && this._lastTime) {
        const dt = (now - this._lastTime) / 1000;
        if (dt > 0) {
          const dist = haversine(this.lastPos, cur);
          speedKmh = (dist / dt) * 3.6;
        }
      }

      this._lastTime = now;
      this.lastPos = cur;
      this.lastSpeed = speedKmh;
      this.lastAccuracy = acc;

      // GPS muy impreciso: se avisa y se descarta el fix. Con este error el cursor se movería
      // a un tramo equivocado, que es peor que quedarse quieto un tick.
      if (acc > CONFIG.MIN_ACCURACY_M * 2) {
        if (this.hooks.onGpsStatus) this.hooks.onGpsStatus('weak', acc);
        if (now - this._lastGpsWeakAt > CONFIG.GPS_WEAK_THROTTLE_MS) {
          this._lastGpsWeakAt = now;
          window.dispatchEvent(new CustomEvent('nav:gps_weak', { detail: { accuracy: acc } }));
        }
        return;
      }

      // GPS débil pero usable (túnel, calle angosta con edificios altos): se sigue guiando con
      // tolerancia ampliada, pero más abajo se prohíbe rerutear con esta confianza.
      const gpsWeak = acc > CONFIG.MIN_ACCURACY_M;
      if (this.hooks.onGpsStatus) this.hooks.onGpsStatus(gpsWeak ? 'weak' : 'ok', acc);
      if (gpsWeak && now - this._lastGpsWeakAt > CONFIG.GPS_WEAK_THROTTLE_MS) {
        this._lastGpsWeakAt = now;
        window.dispatchEvent(new CustomEvent('nav:gps_weak', { detail: { accuracy: acc } }));
      }

      // Localizar sobre la ruta y mover el cursor.
      const tol = this._tolFor(speedKmh, acc);
      let loc = this._locateOnRoute(cur, speedKmh);
      if (loc && loc.dist > tol && now - this._lastWideScanAt > 5000) {
        // Nada dentro de tolerancia con el presupuesto normal: antes de declarar off-route
        // (y gastar un reroute facturable) se barre la ruta restante completa.
        this._lastWideScanAt = now;
        const wide = this._wideScan(cur);
        if (wide && wide.dist <= tol) loc = wide;
      }
      this._applyLocate(loc, tol);

      // Heading: el rumbo del dispositivo sólo es fiable en movimiento; si no, se usa el rumbo
      // del tramo de vía sobre el que se está proyectado. Suavizado para que la cámara no vibre.
      let targetHeading = null;
      const devHeading = pos.coords.heading;
      if (typeof devHeading === 'number' && isFinite(devHeading) && speedKmh > CONFIG.HEADING_MIN_SPEED) {
        targetHeading = devHeading;
      } else if (speedKmh > 3) {
        targetHeading = this._pathHeading();
      }
      if (targetHeading != null) {
        this.lastHeading = smoothHeading(this.lastHeading, targetHeading, CONFIG.HEADING_SMOOTH);
      }

      // La posición que se pinta y se sigue es la proyectada sobre la vía: evita que el punto
      // aparezca sobre la vereda o dentro de una manzana por el error del GPS.
      const shown = (loc && loc.dist <= tol && this._snapped) ? this._snapped : cur;

      if (this.hooks.onPosition) {
        this.hooks.onPosition(shown, speedKmh, acc);
      }

      if (this.cameraTracking && this.hooks.onCamera) {
        this.hooks.onCamera(shown, speedKmh, this.lastHeading);
      }

      // Verificar llegada a waypoint
      this._checkWaypointArrival(cur);

      // Verificar si está fuera de ruta
      if (!this._isOnRoute(cur, speedKmh)) {
        if (!this.offRouteSince) {
          this.offRouteSince = now;
          window.dispatchEvent(new CustomEvent('nav:off_route', { detail: { position: cur } }));
        }
        // Con GPS débil no se recalcula: el desvío puede ser del sensor, no del conductor.
        if (!gpsWeak && now - this.offRouteSince > CONFIG.OFF_ROUTE_PERSIST_MS) {
          this._tryReroute(cur, speedKmh, acc);
        }
      } else if (this.offRouteSince) {
        this.offRouteSince = null;
        window.dispatchEvent(new CustomEvent('nav:on_route'));
      }

      // Anunciar instrucción preventiva
      this._announcePreventive(cur);
    }

    _onPositionError(err) {
      console.warn('[Navigator3D] Position error:', err);
      // UX-02: graceful degradation — emitir evento cuando GPS es denegado explícitamente
      if (err && err.code === 1 /* GeolocationPositionError.PERMISSION_DENIED */) {
        window.dispatchEvent(new CustomEvent('nav:gps_denied', {
          detail: {
            message: 'Activa la ubicación en Configuración → Privacidad → Ubicación para usar la navegación.',
            code: err.code
          }
        }));
      }
      if (this.hooks.onError) {
        this.hooks.onError(err);
      }
    }

    // ==================== CÁMARA ====================

    _setupDragListener() {
      if (!this.map) return;

      this._dragListener = this.map.addListener('dragstart', () => {
        this.cameraTracking = false;
        if (this.hooks.onCameraTrackingChanged) {
          this.hooks.onCameraTrackingChanged(false);
        }
      });
    }

    _removeDragListener() {
      if (this._dragListener) {
        google.maps.event.removeListener(this._dragListener);
        this._dragListener = null;
      }
    }

    recenter() {
      this.cameraTracking = true;
      if (this.hooks.onCameraTrackingChanged) {
        this.hooks.onCameraTrackingChanged(true);
      }
      const pos = this._snapped || this.lastPos;
      if (pos && this.map) {
        this._updateCamera(pos, this.lastSpeed);
      }
    }

    // Un solo dueño de la cámara: si NavCamera está corriendo, se le delega para no pelear con
    // su animación entre fixes. La rama directa queda para cuando el módulo no está cargado.
    _updateCamera(pos, speed) {
      if (!this.map || !this.cameraTracking) return;

      if (window.NavCamera && window.NavCamera.isRunning && window.NavCamera.isRunning()) {
        window.NavCamera.setTracking(true);
        window.NavCamera.update(pos, speed, this.lastHeading);
        return;
      }

      this.map.moveCamera({
        center: pos,
        zoom: speedToZoom(speed),
        tilt: CONFIG.NAV_TILT,
        heading: this.lastHeading
      });
    }

    // ==================== PASOS DE NAVEGACIÓN ====================

    _buildSteps(route) {
      const steps = [];
      (route.legs || []).forEach((leg, legIdx) => {
        (leg.steps || []).forEach((st, stepIdx) => {
          const instruction = st.navigationInstruction || {};
          steps.push({
            legIndex: legIdx,
            stepIndex: stepIdx,
            text: instruction.instructions || instruction.maneuver || 'Sigue la vía',
            maneuver: instruction.maneuver || '',
            distanceMeters: st.distanceMeters || 0,
            staticDuration: st.staticDuration || '0s',
            start: st.startLocation || { lat: 0, lng: 0 },
            end: st.endLocation || { lat: 0, lng: 0 },
            polyline: st.polyline?.encodedPolyline || null
          });
        });
      });
      return steps;
    }

    // Deja el cursor al inicio de una ruta recién recibida (inicio o reroute).
    _resetRouteCursor(route) {
      this.stepIdx = 0;
      this._segIdx = 0;
      this._alongInStep = 0;
      this._snapped = null;
      this.lateral = 0;
      this._lastWideScanAt = 0;
      this._lastAnnouncedThreshold = null;
      this._trafficFactor = this._computeTrafficFactor(route);
    }

    /**
     * La Routes API entrega route.duration YA con tráfico (mode 'nav' pide
     * TRAFFIC_AWARE_OPTIMAL), pero el staticDuration de cada paso NO lo considera. El cociente
     * entre ambos da una corrección aplicable a los tiempos por paso, sin ninguna llamada extra.
     * Se acota a [0.6, 3] para que una respuesta rara no distorsione el ETA del panel.
     */
    _computeTrafficFactor(route) {
      if (!route) return 1;
      let stat = 0;
      (route.legs || []).forEach(leg => {
        (leg.steps || []).forEach(st => { stat += parseDuration(st.staticDuration); });
      });
      const live = route.durationSeconds || parseDuration(route.duration);
      if (!stat || !live) return 1;
      return Math.min(3, Math.max(0.6, live / stat));
    }

    /**
     * Localiza la posición sobre la ruta proyectándola en la polilínea de los pasos, buscando
     * SIEMPRE hacia adelante desde el cursor actual (el cursor nunca retrocede, así el GPS ruidoso
     * no hace parpadear las indicaciones).
     * El presupuesto de búsqueda es proporcional a la velocidad: a 100 km/h con un fix cada 3 s
     * hay que mirar ~85 m adelante, mientras que detenido basta con el mínimo.
     * Devuelve null si no hay pasos.
     */
    _locateOnRoute(cur, speedKmh) {
      if (!this.steps.length) return null;

      const budget = Math.max(200, (speedKmh / 3.6) * 8);
      let best = null, walked = 0, done = false;

      // Se arranca un paso atrás para tolerar un fix que llega justo antes de la frontera.
      const fromStep = Math.max(0, this.stepIdx - 1);

      for (let si = fromStep; si < this.steps.length && !done; si++) {
        const g = ensureStepGeom(this.steps[si]);
        const seg0 = (si === this.stepIdx) ? Math.max(0, this._segIdx - 3) : 0;

        for (let i = seg0; i < g.pts.length - 1; i++) {
          const pr = projectOnSegment(cur, g.pts[i], g.pts[i + 1]);
          if (!best || pr.dist < best.dist) {
            best = {
              dist: pr.dist, stepIdx: si, segIdx: i, t: pr.t,
              along: g.cum[i] + pr.t * pr.segLen,
              a: g.pts[i], b: g.pts[i + 1]
            };
          }
          // El presupuesto sólo mide la geometría POR DELANTE del cursor: el paso anterior se
          // revisa como margen de seguridad y no debe consumirlo, o con pasos cortos el
          // presupuesto se agota antes de llegar al paso siguiente y el cursor se queda pegado.
          if (si >= this.stepIdx) {
            walked += pr.segLen;
            if (walked > budget) { done = true; break; }
          }
        }
      }

      return best;
    }

    /**
     * Barrido completo de la ruta restante. Sólo se usa cuando la búsqueda con presupuesto no
     * encontró nada dentro de tolerancia: cubre el caso del túnel o del GPS que reengancha
     * cientos de metros más adelante. Un reroute cuesta dinero; este barrido cuesta milisegundos.
     */
    _wideScan(cur) {
      let best = null;
      for (let si = this.stepIdx; si < this.steps.length; si++) {
        const g = ensureStepGeom(this.steps[si]);
        for (let i = 0; i < g.pts.length - 1; i++) {
          const pr = projectOnSegment(cur, g.pts[i], g.pts[i + 1]);
          if (!best || pr.dist < best.dist) {
            best = {
              dist: pr.dist, stepIdx: si, segIdx: i, t: pr.t,
              along: g.cum[i] + pr.t * pr.segLen,
              a: g.pts[i], b: g.pts[i + 1]
            };
          }
        }
      }
      return best;
    }

    /**
     * Aplica el resultado de la localización: mueve el cursor y, si corresponde, avanza de paso.
     * Puede avanzar VARIOS pasos de una vez (GPS lento o velocidad alta); `skipped` lo informa
     * para que la voz no anuncie una maniobra que ya quedó atrás.
     * Fuera de tolerancia se actualiza la proyección pero NO se avanza: el cursor sólo progresa
     * cuando de verdad se está sobre la ruta.
     */
    _applyLocate(loc, tol) {
      if (!loc) return;

      this.lateral = loc.dist;
      this._snapped = {
        lat: loc.a.lat + (loc.b.lat - loc.a.lat) * loc.t,
        lng: loc.a.lng + (loc.b.lng - loc.a.lng) * loc.t
      };

      if (loc.dist > tol) return;

      this._segIdx = loc.segIdx;
      this._alongInStep = loc.along;

      if (loc.stepIdx > this.stepIdx) {
        const from = this.stepIdx;
        const skipped = loc.stepIdx - from - 1;
        this.stepIdx = loc.stepIdx;
        this._lastAnnouncedThreshold = null;

        const step = this.steps[this.stepIdx];
        if (this.hooks.onStep) this.hooks.onStep(this.stepIdx, step, skipped);
        window.dispatchEvent(new CustomEvent('nav:step', {
          detail: { index: this.stepIdx, step, skipped }
        }));
      }

      // Se agotaron los pasos: llegada al destino final.
      if (this.stepIdx >= this.steps.length - 1 &&
          this.distToStepEnd() < CONFIG.STEP_ADVANCE_DISTANCE &&
          this.active && !this._arrivalTimer) {
        this._onArrival();
      }
    }

    // Rumbo del segmento sobre el que se está proyectado (más estable que el rumbo entre fixes).
    _pathHeading() {
      const s = this.steps[this.stepIdx];
      if (!s) return null;
      const g = ensureStepGeom(s);
      const i = Math.min(this._segIdx, g.pts.length - 2);
      if (i < 0) return null;
      return bearing(g.pts[i], g.pts[i + 1]);
    }

    // Metros que faltan para terminar el paso actual, medidos SOBRE LA VÍA (no en línea recta:
    // en una curva la línea recta subestima la distancia por decenas de metros).
    distToStepEnd() {
      const s = this.steps[this.stepIdx];
      if (!s) return 0;
      return Math.max(0, ensureStepGeom(s).len - (this._alongInStep || 0));
    }

    // Fracción [0,1] ya recorrida del paso actual.
    _fracInStep() {
      const s = this.steps[this.stepIdx];
      if (!s) return 0;
      const g = ensureStepGeom(s);
      return g.len > 0 ? Math.min(1, Math.max(0, (this._alongInStep || 0) / g.len)) : 0;
    }

    // Anuncia sólo al cruzar umbrales de distancia, no en cada tick GPS.
    // Es el ÚNICO dueño de la voz de navegación: ni _applyLocate ni el hook onStep de la página
    // deben hablar, o se pisan tres locuciones en cada cambio de paso.
    _announcePreventive(cur) {
      const step = this.steps[this.stepIdx];
      if (!step || !window.VoiceController) return;

      // Distancia sobre la vía: los umbrales 500/200/50 m tienen que medirse por el camino que
      // falta recorrer, no en línea recta, o en una curva se anuncian tarde.
      const distToEnd = this.distToStepEnd();

      let threshold = null;
      if (distToEnd <= CONFIG.PREVIEW_DISTANCE_NOW && this._lastAnnouncedThreshold !== 'now') {
        threshold = 'now';
      } else if (distToEnd <= CONFIG.PREVIEW_DISTANCE_NEAR
        && this._lastAnnouncedThreshold !== 'near'
        && this._lastAnnouncedThreshold !== 'now') {
        threshold = 'near';
      } else if (distToEnd <= CONFIG.PREVIEW_DISTANCE_FAR
        && this._lastAnnouncedThreshold !== 'far'
        && this._lastAnnouncedThreshold !== 'near'
        && this._lastAnnouncedThreshold !== 'now') {
        threshold = 'far';
      }

      if (threshold) {
        this._lastAnnouncedThreshold = threshold;
        VoiceController.speakNavigation(step.text, distToEnd, step.maneuver);
      }
    }

    _checkWaypointArrival(cur) {
      if (this.waypointIdx >= this.waypoints.length) return;

      const wp = this.waypoints[this.waypointIdx];
      const dist = haversine(cur, wp);

      if (dist < 50) {
        const arrived = wp;
        this.waypointIdx++;

        const remaining = this.waypoints.length - this.waypointIdx;
        if (window.VoiceController) {
          VoiceController.speakWaypointArrival(null, remaining);
        }

        if (this.hooks.onWaypointArrival) {
          this.hooks.onWaypointArrival(this.waypointIdx - 1, remaining);
        }

        window.dispatchEvent(new CustomEvent('nav:arrived_waypoint', {
          detail: { waypoint: arrived, index: this.waypointIdx - 1, remaining }
        }));
      }
    }

    _onArrival() {
      if (window.VoiceController) {
        VoiceController.speakArrival();
      }

      if (this.hooks.onArrival) {
        this.hooks.onArrival();
      }

      window.dispatchEvent(new CustomEvent('nav:arrived_destination'));

      // Detener tras un delay, guardando el handle: sin esto, reiniciar la navegación dentro de
      // esos 3 s hacía que el timer viejo matara la sesión nueva.
      clearTimeout(this._arrivalTimer);
      this._arrivalTimer = setTimeout(() => { this._arrivalTimer = null; this.stop(); }, 3000);
    }

    // ==================== RUTA ====================

    _isOnRoute(point, speedKmh) {
      if (!this.steps.length) return true;
      // La distancia lateral ya la calculó _locateOnRoute proyectando sobre la polilínea del
      // paso; no hace falta recorrer la polilínea completa otra vez en cada tick.
      return this.lateral <= this._tolFor(speedKmh, this.lastAccuracy);
    }

    // Tolerancia lateral vigente: se ensancha a alta velocidad (la vía es más ancha y el fix
    // llega más espaciado) y con GPS impreciso, para no declarar off-route por error del sensor.
    _tolFor(speedKmh, acc) {
      let tol = speedKmh > CONFIG.SPEED_THRESHOLD
        ? CONFIG.OFF_ROUTE_TOL_FAST
        : CONFIG.OFF_ROUTE_TOL_SLOW;
      if (acc && acc > tol) tol = Math.min(acc, CONFIG.MIN_ACCURACY_M * 2);
      return tol;
    }

    async _tryReroute(cur, speedKmh, acc) {
      const now = Date.now();

      // Verificar cooldown
      if (now - this.lastRerouteAt < CONFIG.REROUTE_COOLDOWN_MS) return;

      // Verificar velocidad y precisión mínimas
      if (speedKmh < CONFIG.MIN_SPEED_KMH) return;
      if (acc > CONFIG.MIN_ACCURACY_M) return;

      return this._reroute(cur);
    }

    // Recalcula desde `origin` hacia el destino con las paradas restantes. Lo usan el off-route
    // automático (_tryReroute, con cooldown/precisión) y el "saltar parada" manual (skipCurrentStop,
    // forzado). No reordena (optimize=false): durante la navegación se respeta el orden planificado.
    async _reroute(origin) {
      const cur = origin || this.lastPos;
      if (!cur) return;

      this.lastRerouteAt = Date.now();

      if (window.RouteEngine) {
        window.RouteEngine.markReroute();
      }
      if (window.VoiceController) {
        VoiceController.speakReroute();
      }

      window.dispatchEvent(new CustomEvent('nav:rerouting', { detail: { origin: cur } }));

      // Fix #8: usar waypoints originales restantes en lugar de endpoints de pasos
      const remainingWaypoints = this.waypoints.slice(this.waypointIdx);
      const dest = this.destination || cur;

      try {
        const route = await window.RouteEngine.computeRouteUnified({
          origin: cur,
          destination: dest,
          waypoints: remainingWaypoints,
          optimize: false,
          mode: 'nav'
        });

        this.route = route;
        this.steps = this._buildSteps(route);
        this.path = decode(route.polyline?.encodedPolyline || '');
        this._resetRouteCursor(route);
        this.offRouteSince = null;
        this._rerouteFails = 0;

        if (this.hooks.onRoute) {
          this.hooks.onRoute(route, this.steps, true);
        }

        window.dispatchEvent(new CustomEvent('nav:rerouted', {
          detail: { route, steps: this.steps }
        }));
      } catch (error) {
        // Antes esto sólo hacía console.error: el chip del HUD quedaba en "Recalculando..."
        // para siempre y no había reintento. Ahora se avisa y se reintenta con backoff.
        console.error('[Navigator3D] Reroute failed:', error);
        this._rerouteFails = (this._rerouteFails || 0) + 1;
        window.dispatchEvent(new CustomEvent('nav:reroute_failed', {
          detail: { error: String(error && error.message || error), attempt: this._rerouteFails }
        }));
        if (this._rerouteFails <= 3 && this.active) {
          const delay = 5000 * Math.pow(3, this._rerouteFails - 1); // 5s / 15s / 45s
          clearTimeout(this._retryTimer);
          this._retryTimer = setTimeout(() => {
            this._retryTimer = null;
            if (this.active) this._reroute(this.lastPos);
          }, delay);
        }
      }
    }

    // Saltar la parada actual: avanza el índice de waypoint y recalcula el resto (reroute forzado,
    // sin esperar el cooldown). Útil cuando el ejecutor decide omitir un local.
    skipCurrentStop() {
      if (!this.active) return false;
      if (this.waypointIdx < this.waypoints.length) this.waypointIdx++;
      this._reroute(this.lastPos);
      return true;
    }

    // ==================== GETTERS ====================

    getCurrentStep() {
      return this.steps[this.stepIdx] || null;
    }

    getNextStep() {
      return this.steps[this.stepIdx + 1] || null;
    }

    /**
     * Distancia que falta hasta el final de la ruta.
     * El paso actual se escala por la fracción ya recorrida, así el panel baja de forma continua
     * en vez de dar saltos de un paso entero.
     * Se escala distanceMeters (el dato de la API) y no el largo de la polilínea: la API es la
     * autoridad de distancia, y así el total del panel cuadra con el de la planificación.
     */
    getRemainingDistance() {
      const s = this.steps[this.stepIdx];
      if (!s) return 0;
      let rem = (s.distanceMeters || 0) * (1 - this._fracInStep());
      for (let i = this.stepIdx + 1; i < this.steps.length; i++) {
        rem += this.steps[i].distanceMeters || 0;
      }
      return rem;
    }

    getRemainingDuration() {
      const s = this.steps[this.stepIdx];
      if (!s) return 0;
      let rem = parseDuration(s.staticDuration) * (1 - this._fracInStep());
      for (let i = this.stepIdx + 1; i < this.steps.length; i++) {
        rem += parseDuration(this.steps[i].staticDuration);
      }
      return rem * this._trafficFactor;
    }

    getETA() {
      const remainingSec = this.getRemainingDuration();
      return new Date(Date.now() + remainingSec * 1000);
    }

    /**
     * Distancia y tiempo hasta la PRÓXIMA parada, no hasta el final de la jornada: al ejecutor
     * le sirve saber cuándo llega al local siguiente, no a la última de 25 paradas.
     * La frontera de parada es el cambio de leg, que _buildSteps ya guarda por paso.
     */
    getStopMetrics() {
      const cur = this.steps[this.stepIdx];
      if (!cur) return { dist: 0, sec: 0, legIndex: 0 };
      const leg = cur.legIndex || 0;
      const f = this._fracInStep();
      let d = (cur.distanceMeters || 0) * (1 - f);
      let s = parseDuration(cur.staticDuration) * (1 - f);
      for (let i = this.stepIdx + 1; i < this.steps.length && this.steps[i].legIndex === leg; i++) {
        d += this.steps[i].distanceMeters || 0;
        s += parseDuration(this.steps[i].staticDuration);
      }
      return { dist: d, sec: s * this._trafficFactor, legIndex: leg };
    }

    // Progreso continuo, derivado de la distancia restante (antes contaba pasos enteros y la
    // barra avanzaba a escalones).
    getProgress() {
      const total = (this.route && this.route.distanceMeters) || 0;
      if (!total) return 0;
      return Math.min(100, Math.max(0, ((total - this.getRemainingDistance()) / total) * 100));
    }
  }

  // ==================== FUNCIONES DE RENDERIZADO HUD ====================

  /**
   * Renderiza el HUD de navegación
   */
  function renderNavigationHUD(containerId, navigator) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const step = navigator.getCurrentStep();
    const nextStep = navigator.getNextStep();
    const remainingDist = navigator.getRemainingDistance();
    const remainingTime = navigator.getRemainingDuration();
    const eta = navigator.getETA();
    const progress = navigator.getProgress();

    const icon = step ? getManeuverIcon(step.maneuver) : 'fa-location-arrow';
    const instruction = step ? step.text : 'Preparando navegación...';
    const stepDist = step ? formatDistance(step.distanceMeters) : '--';
    const nextText = nextStep ? nextStep.text : '--';

    container.innerHTML = `
      <div class="nav-hud">
        <div class="nav-banner">
          <div class="nav-ic"><i class="fa ${icon}"></i></div>
          <div class="nav-info">
            <div class="nav-main">${instruction}</div>
            <div class="nav-sub">${stepDist}</div>
          </div>
        </div>
        ${nextStep ? `<div class="nav-nextnext">Próxima: ${nextText}</div>` : ''}
        <div class="nav-bottom">
          <div class="nav-stats">
            <div class="nav-stat">
              <small>Llegada</small>
              <span>${eta.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })}</span>
            </div>
            <div class="nav-stat">
              <small>Restante</small>
              <span>${formatDistance(remainingDist)}</span>
            </div>
            <div class="nav-stat">
              <small>Tiempo</small>
              <span>${formatDuration(remainingTime)}</span>
            </div>
          </div>
          <div class="nav-progress">
            <div class="nav-progress-bar" style="width: ${progress}%"></div>
          </div>
        </div>
      </div>
    `;
  }

  // ==================== SIMULADOR (verificación sin salir a terreno) ====================

  /**
   * Alimenta nav._onPosition() con fixes sintéticos recorriendo la geometría real de la ruta.
   * No hay test runner en el repo, así que esta es la forma de reproducir en el escritorio los
   * casos que de otro modo obligan a salir a manejar.
   *
   *   const stop = NavEngine.__sim(window.navigator3D, { speedKmh: 100, hz: 0.33 });
   *   stop();   // corta la simulación
   *
   * Casos útiles:
   *   { speedKmh: 100, hz: 0.33 }  velocidad alta con GPS lento (rompía el avance de paso)
   *   { jitterM: 30 }              GPS urbano ruidoso (el cursor no debe retroceder)
   *   { speedKmh: 5 }              detenido en un semáforo (el ETA no debe congelarse)
   */
  function __sim(nav, opts) {
    opts = opts || {};
    if (!nav || !nav.steps || !nav.steps.length) {
      console.warn('[NavEngine.__sim] Inicia la navegación antes de simular.');
      return function () {};
    }

    const mps = (opts.speedKmh == null ? 40 : opts.speedKmh) / 3.6;
    const hz  = opts.hz || 1;
    const acc = opts.accuracy || 8;
    const jit = opts.jitterM || 0;

    nav._unwatchGps(); // el GPS real no debe competir con el simulado

    const pts = [];
    nav.steps.forEach(s => { ensureStepGeom(s).pts.forEach(p => pts.push(p)); });

    let i = 0, frac = 0;
    const timer = setInterval(() => {
      if (i >= pts.length - 1) { clearInterval(timer); return; }

      // Avanzar por la polilínea el trecho que corresponde a este tick.
      let budget = mps / hz;
      while (budget > 0 && i < pts.length - 1) {
        const segLen = haversine(pts[i], pts[i + 1]) || 0.01;
        const rest = (1 - frac) * segLen;
        if (rest > budget) { frac += budget / segLen; budget = 0; }
        else { budget -= rest; i++; frac = 0; }
      }

      const a = pts[i], b = pts[Math.min(i + 1, pts.length - 1)];
      const dLat = jit / 111320;
      nav._onPosition({
        timestamp: Date.now(),
        coords: {
          latitude:  a.lat + (b.lat - a.lat) * frac + (Math.random() - 0.5) * dLat,
          longitude: a.lng + (b.lng - a.lng) * frac + (Math.random() - 0.5) * dLat,
          accuracy: acc,
          speed: mps,
          heading: bearing(a, b)
        }
      });
    }, 1000 / hz);

    return function () { clearInterval(timer); };
  }

  // ==================== EXPORTAR ====================

  window.NavEngine = {
    Navigator3D,
    renderNavigationHUD,
    utils: {
      haversine,
      bearing,
      decode,
      formatDistance,
      formatDuration,
      getManeuverIcon,
      normalizeManeuver,
      projectOnSegment,
      smoothHeading,
      speedToZoom
    },
    CONFIG,
    MANEUVER_ICONS,
    __sim
  };

})(window);
