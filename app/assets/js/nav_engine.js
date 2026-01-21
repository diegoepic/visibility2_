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
    PREVIEW_DISTANCE_NOW: 50     // metros para instrucción inmediata
  };

  // Iconos de maniobra
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
    'merge': 'fa-compress',
    'fork-right': 'fa-code-fork',
    'fork-left': 'fa-code-fork',
    'ramp-right': 'fa-sign-out',
    'ramp-left': 'fa-sign-out',
    'straight': 'fa-long-arrow-up',
    'destination': 'fa-flag-checkered',
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

  function getManeuverIcon(maneuver) {
    if (!maneuver) return MANEUVER_ICONS.default;
    const key = Object.keys(MANEUVER_ICONS).find(k =>
      maneuver.toLowerCase().includes(k.replace('-', ''))
    );
    return MANEUVER_ICONS[key] || MANEUVER_ICONS.default;
  }

  function speedToZoom(kmh) {
    if (kmh <= 25) return CONFIG.ZOOM_SLOW;
    if (kmh <= 60) return CONFIG.ZOOM_MEDIUM;
    return CONFIG.ZOOM_FAST;
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

      // Estado de cámara
      this.cameraTracking = true;

      // Listener de drag
      this._dragListener = null;
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
        this.stepIdx = 0;
        this.path = decode(route.polyline?.encodedPolyline || '');
        this.waypoints = waypoints || [];
        this.waypointIdx = 0;

        this.active = true;
        this.paused = false;
        this.offRouteSince = null;
        this.lastRerouteAt = 0;
        this.cameraTracking = true;

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

        // Emitir evento
        window.dispatchEvent(new CustomEvent('navigation-started', {
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

      this.route = null;
      this.steps = [];
      this.stepIdx = 0;
      this.path = [];
      this.offRouteSince = null;

      if (this.hooks.onStop) {
        this.hooks.onStop();
      }

      // Anunciar fin
      if (window.VoiceController) {
        VoiceController.speak('Navegación finalizada', 'normal');
      }

      window.dispatchEvent(new CustomEvent('navigation-stopped'));
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

      // Calcular velocidad
      let speedKmh = 0;
      if (this.lastPos && this._lastTime) {
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

      // Calcular heading
      if (this.path.length > 1 && speedKmh > 3) {
        // Buscar el punto más cercano en la ruta y calcular heading hacia el siguiente
        const nearestIdx = this._findNearestPathIndex(cur);
        if (nearestIdx < this.path.length - 1) {
          this.lastHeading = bearing(this.path[nearestIdx], this.path[nearestIdx + 1]);
        }
      }

      // Verificar precisión mínima
      if (acc > CONFIG.MIN_ACCURACY_M) {
        return;
      }

      // Notificar posición
      if (this.hooks.onPosition) {
        this.hooks.onPosition(cur, speedKmh, acc);
      }

      // Actualizar cámara
      if (this.cameraTracking && this.hooks.onCamera) {
        this.hooks.onCamera(cur, speedKmh, this.lastHeading);
      }

      // Avanzar paso si corresponde
      this._advanceStep(cur);

      // Verificar llegada a waypoint
      this._checkWaypointArrival(cur);

      // Verificar si está fuera de ruta
      if (!this._isOnRoute(cur, speedKmh)) {
        if (!this.offRouteSince) {
          this.offRouteSince = now;
        }
        if (now - this.offRouteSince > CONFIG.OFF_ROUTE_PERSIST_MS) {
          this._tryReroute(cur, speedKmh, acc);
        }
      } else {
        this.offRouteSince = null;
      }

      // Anunciar instrucción preventiva
      this._announcePreventive(cur);
    }

    _onPositionError(err) {
      console.warn('[Navigator3D] Position error:', err);
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
      if (this.lastPos && this.map) {
        this._updateCamera(this.lastPos, this.lastSpeed);
      }
    }

    _updateCamera(pos, speed) {
      if (!this.map || !this.cameraTracking) return;

      const zoom = speedToZoom(speed);
      this.map.moveCamera({
        center: pos,
        zoom: zoom,
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

    _advanceStep(cur) {
      const step = this.steps[this.stepIdx];
      if (!step) return;

      const distToEnd = haversine(cur, step.end);

      if (distToEnd < CONFIG.STEP_ADVANCE_DISTANCE) {
        this.stepIdx++;

        const nextStep = this.steps[this.stepIdx];
        if (this.hooks.onStep) {
          this.hooks.onStep(this.stepIdx, nextStep);
        }

        // Anunciar nuevo paso
        if (nextStep && window.VoiceController) {
          VoiceController.speak(nextStep.text, 'high');
        }

        // Verificar si llegamos al destino
        if (!nextStep && this.active) {
          this._onArrival();
        }
      }
    }

    _announcePreventive(cur) {
      const step = this.steps[this.stepIdx];
      if (!step) return;

      const distToEnd = haversine(cur, step.end);

      if (window.VoiceController) {
        VoiceController.speakNavigation(step.text, distToEnd, step.maneuver);
      }
    }

    _checkWaypointArrival(cur) {
      if (this.waypointIdx >= this.waypoints.length) return;

      const wp = this.waypoints[this.waypointIdx];
      const dist = haversine(cur, wp);

      if (dist < 50) {
        this.waypointIdx++;

        const remaining = this.waypoints.length - this.waypointIdx;
        if (window.VoiceController) {
          VoiceController.speakWaypointArrival(null, remaining);
        }

        if (this.hooks.onWaypointArrival) {
          this.hooks.onWaypointArrival(this.waypointIdx - 1, remaining);
        }
      }
    }

    _onArrival() {
      if (window.VoiceController) {
        VoiceController.speakArrival();
      }

      if (this.hooks.onArrival) {
        this.hooks.onArrival();
      }

      // Detener navegación después de un delay
      setTimeout(() => this.stop(), 3000);
    }

    // ==================== RUTA ====================

    _isOnRoute(point, speedKmh) {
      if (!this.path.length) return true;

      const tol = speedKmh > CONFIG.SPEED_THRESHOLD
        ? CONFIG.OFF_ROUTE_TOL_FAST
        : CONFIG.OFF_ROUTE_TOL_SLOW;

      // Usar la función de RouteEngine si está disponible
      if (window.RouteEngine?.isOnRoutePath) {
        return window.RouteEngine.isOnRoutePath(point, this.path, tol);
      }

      // Fallback: verificar con la API de Google
      const gll = new google.maps.LatLng(point.lat, point.lng);
      const poly = new google.maps.Polyline({
        path: this.path.map(p => new google.maps.LatLng(p.lat, p.lng))
      });
      return google.maps.geometry.poly.isLocationOnEdge(gll, poly, tol / 6378137);
    }

    _findNearestPathIndex(point) {
      let minDist = Infinity;
      let minIdx = 0;

      this.path.forEach((p, i) => {
        const d = haversine(point, p);
        if (d < minDist) {
          minDist = d;
          minIdx = i;
        }
      });

      return minIdx;
    }

    async _tryReroute(cur, speedKmh, acc) {
      const now = Date.now();

      // Verificar cooldown
      if (now - this.lastRerouteAt < CONFIG.REROUTE_COOLDOWN_MS) return;

      // Verificar velocidad y precisión mínimas
      if (speedKmh < CONFIG.MIN_SPEED_KMH) return;
      if (acc > CONFIG.MIN_ACCURACY_M) return;

      this.lastRerouteAt = now;

      // Notificar reroute
      if (window.RouteEngine) {
        window.RouteEngine.markReroute();
      }

      if (window.VoiceController) {
        VoiceController.speakReroute();
      }

      // Calcular paradas restantes
      const remainingSteps = this.steps.slice(this.stepIdx);
      const destination = remainingSteps.length ? remainingSteps[remainingSteps.length - 1].end : cur;
      const waypoints = remainingSteps.slice(0, -1).map(s => s.end);

      try {
        const route = await window.RouteEngine.computeRouteUnified({
          origin: cur,
          destination,
          waypoints,
          optimize: false,
          mode: 'nav'
        });

        this.route = route;
        this.steps = this._buildSteps(route);
        this.stepIdx = 0;
        this.path = decode(route.polyline?.encodedPolyline || '');
        this.offRouteSince = null;

        if (this.hooks.onRoute) {
          this.hooks.onRoute(route, this.steps, true);
        }

        window.dispatchEvent(new CustomEvent('navigation-rerouted', {
          detail: { route, steps: this.steps }
        }));
      } catch (error) {
        console.error('[Navigator3D] Reroute failed:', error);
      }
    }

    // ==================== GETTERS ====================

    getCurrentStep() {
      return this.steps[this.stepIdx] || null;
    }

    getNextStep() {
      return this.steps[this.stepIdx + 1] || null;
    }

    getRemainingDistance() {
      if (!this.route) return 0;
      let remaining = 0;
      for (let i = this.stepIdx; i < this.steps.length; i++) {
        remaining += this.steps[i].distanceMeters || 0;
      }
      return remaining;
    }

    getRemainingDuration() {
      if (!this.route) return 0;
      let remaining = 0;
      for (let i = this.stepIdx; i < this.steps.length; i++) {
        remaining += parseDuration(this.steps[i].staticDuration);
      }
      return remaining;
    }

    getETA() {
      const remainingSec = this.getRemainingDuration();
      return new Date(Date.now() + remainingSec * 1000);
    }

    getProgress() {
      if (!this.steps.length) return 0;
      return (this.stepIdx / this.steps.length) * 100;
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
      speedToZoom
    },
    CONFIG,
    MANEUVER_ICONS
  };

})(window);
