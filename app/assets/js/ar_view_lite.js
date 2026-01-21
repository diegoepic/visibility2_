/**
 * AR View Lite v2.0
 * Vista de realidad aumentada para navegación
 * @version 2.0.0
 */
(function(window) {
  'use strict';

  // ==================== CONFIGURACIÓN ====================

  const CONFIG = {
    // Distancias
    STEP_COMPLETE_DISTANCE: 15,  // metros para completar paso
    ARROW_SCALE_MIN: 0.5,
    ARROW_SCALE_MAX: 2.0,

    // Cámara
    VIDEO_CONSTRAINTS: {
      video: {
        facingMode: 'environment',
        width: { ideal: 1280 },
        height: { ideal: 720 }
      }
    }
  };

  // ==================== TEMPLATE HTML ====================

  const TEMPLATE = `
    <div class="ar-overlay" id="arOverlay">
      <video id="arVideo" autoplay playsinline muted></video>
      <canvas id="arCanvas"></canvas>
      <div class="ar-overlay__content">
        <div class="ar-instruction-panel">
          <div class="ar-arrow-container">
            <div class="ar-arrow" id="arArrow">
              <svg viewBox="0 0 100 100" class="ar-arrow-svg">
                <polygon points="50,10 90,90 50,70 10,90" fill="currentColor"/>
              </svg>
            </div>
          </div>
          <div class="ar-text-container">
            <div class="ar-instruction" id="arInstruction">Preparando...</div>
            <div class="ar-distance" id="arDistance">--</div>
            <div class="ar-next-instruction" id="arNextInstruction"></div>
          </div>
        </div>
        <div class="ar-bottom-panel">
          <div class="ar-minimap-container">
            <div class="ar-minimap" id="arMiniMap"></div>
          </div>
          <div class="ar-stats">
            <div class="ar-stat">
              <span class="ar-stat-value" id="arEta">--</span>
              <span class="ar-stat-label">Llegada</span>
            </div>
            <div class="ar-stat">
              <span class="ar-stat-value" id="arRemaining">--</span>
              <span class="ar-stat-label">Restante</span>
            </div>
            <div class="ar-stat">
              <span class="ar-stat-value" id="arSpeed">--</span>
              <span class="ar-stat-label">Velocidad</span>
            </div>
          </div>
          <button id="arClose" class="ar-close-btn">
            <i class="fa fa-times"></i> Salir
          </button>
        </div>
      </div>
      <div class="ar-compass" id="arCompass">
        <div class="ar-compass-needle" id="arCompassNeedle"></div>
        <span class="ar-compass-label">N</span>
      </div>
    </div>
  `;

  // ==================== ESTADO ====================

  let stream = null;
  let overlay = null;
  let canvas = null;
  let ctx = null;
  let miniMap = null;
  let miniPolyline = null;
  let miniMarker = null;

  let steps = [];
  let stepIdx = 0;
  let route = null;

  let heading = 0;
  let targetHeading = 0;
  let pitch = 0;
  let currentPosition = null;
  let currentSpeed = 0;

  let animationFrame = null;
  let isActive = false;

  // ==================== INICIALIZACIÓN ====================

  /**
   * Asegura que el overlay existe en el DOM
   */
  function ensureOverlay() {
    if (document.getElementById('arOverlay')) {
      overlay = document.getElementById('arOverlay');
      return;
    }

    const div = document.createElement('div');
    div.innerHTML = TEMPLATE;
    document.body.appendChild(div.firstElementChild);

    overlay = document.getElementById('arOverlay');
    canvas = document.getElementById('arCanvas');
    ctx = canvas ? canvas.getContext('2d') : null;

    // Eventos
    document.getElementById('arClose').addEventListener('click', stop);

    // Orientación del dispositivo
    setupOrientationListener();
  }

  /**
   * Configura el listener de orientación del dispositivo
   */
  function setupOrientationListener() {
    const handler = (e) => {
      if (e.absolute === false && e.alpha == null) return;
      heading = e.alpha || heading;
      pitch = e.beta || pitch;
      updateCompass();
      updateArrow();
    };

    // Solicitar permiso en iOS 13+
    if (typeof DeviceOrientationEvent !== 'undefined' &&
        typeof DeviceOrientationEvent.requestPermission === 'function') {
      DeviceOrientationEvent.requestPermission()
        .then(state => {
          if (state === 'granted') {
            window.addEventListener('deviceorientation', handler, true);
          }
        })
        .catch(console.error);
    } else {
      window.addEventListener('deviceorientation', handler, true);
    }
  }

  // ==================== CÁMARA ====================

  /**
   * Inicia la cámara
   */
  async function startCamera() {
    if (stream) return stream;

    try {
      stream = await navigator.mediaDevices.getUserMedia(CONFIG.VIDEO_CONSTRAINTS);
      const video = document.getElementById('arVideo');
      if (video) {
        video.srcObject = stream;

        // Ajustar canvas al tamaño del video
        video.onloadedmetadata = () => {
          if (canvas) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
          }
        };
      }
      return stream;
    } catch (error) {
      console.error('[ARView] Camera error:', error);
      throw error;
    }
  }

  /**
   * Detiene la cámara
   */
  function stopCamera() {
    if (stream) {
      stream.getTracks().forEach(track => track.stop());
      stream = null;
    }
  }

  // ==================== MINIMAPA ====================

  /**
   * Inicializa el minimapa
   */
  function initMiniMap() {
    if (!window.google || !window.google.maps) return;

    const container = document.getElementById('arMiniMap');
    if (!container) return;

    if (!miniMap) {
      miniMap = new google.maps.Map(container, {
        zoom: 17,
        disableDefaultUI: true,
        gestureHandling: 'none',
        styles: [
          { featureType: 'poi', stylers: [{ visibility: 'off' }] },
          { featureType: 'transit', stylers: [{ visibility: 'off' }] }
        ]
      });
    }
  }

  /**
   * Actualiza el minimapa
   */
  function updateMiniMap(routeData) {
    if (!miniMap || !routeData?.polyline?.encodedPolyline) return;

    const path = google.maps.geometry.encoding.decodePath(routeData.polyline.encodedPolyline);

    // Ajustar bounds
    const bounds = new google.maps.LatLngBounds();
    path.forEach(p => bounds.extend(p));
    miniMap.fitBounds(bounds);

    // Dibujar ruta
    if (miniPolyline) miniPolyline.setMap(null);
    miniPolyline = new google.maps.Polyline({
      path,
      strokeColor: '#4285F4',
      strokeWeight: 4,
      map: miniMap
    });
  }

  /**
   * Actualiza la posición en el minimapa
   */
  function updateMiniMapPosition(pos) {
    if (!miniMap) return;

    const latLng = new google.maps.LatLng(pos.lat, pos.lng);
    miniMap.setCenter(latLng);

    if (!miniMarker) {
      miniMarker = new google.maps.Marker({
        map: miniMap,
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 8,
          fillColor: '#4285F4',
          fillOpacity: 1,
          strokeColor: '#fff',
          strokeWeight: 2
        }
      });
    }
    miniMarker.setPosition(latLng);
  }

  // ==================== UI ====================

  /**
   * Actualiza la flecha de dirección
   */
  function updateArrow() {
    const arrow = document.getElementById('arArrow');
    if (!arrow) return;

    // Calcular rotación relativa al norte
    let rotation = targetHeading - heading;
    if (rotation < 0) rotation += 360;

    // Escalar según pitch (inclinación del dispositivo)
    const pitchFactor = Math.cos((pitch - 90) * Math.PI / 180);
    const scale = CONFIG.ARROW_SCALE_MIN + (CONFIG.ARROW_SCALE_MAX - CONFIG.ARROW_SCALE_MIN) * Math.abs(pitchFactor);

    arrow.style.transform = `rotate(${rotation}deg) scale(${scale})`;
  }

  /**
   * Actualiza la brújula
   */
  function updateCompass() {
    const needle = document.getElementById('arCompassNeedle');
    if (needle) {
      needle.style.transform = `rotate(${-heading}deg)`;
    }
  }

  /**
   * Actualiza el HUD
   */
  function updateHUD(step, nextStep) {
    const instructionEl = document.getElementById('arInstruction');
    const distanceEl = document.getElementById('arDistance');
    const nextEl = document.getElementById('arNextInstruction');

    if (instructionEl) {
      instructionEl.textContent = step ? step.text : 'Listo';
    }

    if (distanceEl) {
      distanceEl.textContent = step ? formatDistance(step.distanceMeters) : '--';
    }

    if (nextEl) {
      nextEl.textContent = nextStep ? `Próxima: ${nextStep.text}` : '';
      nextEl.style.display = nextStep ? 'block' : 'none';
    }
  }

  /**
   * Actualiza estadísticas
   */
  function updateStats(navigator) {
    if (!navigator) return;

    const etaEl = document.getElementById('arEta');
    const remainingEl = document.getElementById('arRemaining');
    const speedEl = document.getElementById('arSpeed');

    if (etaEl) {
      const eta = navigator.getETA();
      etaEl.textContent = eta.toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
    }

    if (remainingEl) {
      remainingEl.textContent = formatDistance(navigator.getRemainingDistance());
    }

    if (speedEl) {
      speedEl.textContent = `${Math.round(currentSpeed)} km/h`;
    }
  }

  /**
   * Formatea distancia
   */
  function formatDistance(meters) {
    if (!meters && meters !== 0) return '--';
    if (meters >= 1000) return (meters / 1000).toFixed(1) + ' km';
    return Math.round(meters) + ' m';
  }

  // ==================== CONTROL PRINCIPAL ====================

  /**
   * Inicia la vista AR
   */
  async function start(routeData, routeSteps, navigator = null) {
    ensureOverlay();

    route = routeData;
    steps = routeSteps || [];
    stepIdx = 0;

    // Calcular heading hacia el primer paso
    if (steps.length > 0 && steps[0].end) {
      if (currentPosition) {
        targetHeading = calculateBearing(currentPosition, steps[0].end);
      }
    }

    // Inicializar UI
    updateHUD(steps[0], steps[1]);
    initMiniMap();
    updateMiniMap(route);

    try {
      await startCamera();
      overlay.style.display = 'flex';
      overlay.classList.add('ar-overlay--active');
      isActive = true;

      // Iniciar loop de animación
      startAnimationLoop(navigator);

      window.dispatchEvent(new CustomEvent('ar-view-started'));
    } catch (error) {
      console.error('[ARView] Start error:', error);
      stop();
      throw error;
    }
  }

  /**
   * Detiene la vista AR
   */
  function stop() {
    isActive = false;

    if (animationFrame) {
      cancelAnimationFrame(animationFrame);
      animationFrame = null;
    }

    stopCamera();

    if (overlay) {
      overlay.classList.remove('ar-overlay--active');
      overlay.style.display = 'none';
    }

    if (miniPolyline) {
      miniPolyline.setMap(null);
      miniPolyline = null;
    }

    if (miniMarker) {
      miniMarker.setMap(null);
      miniMarker = null;
    }

    route = null;
    steps = [];
    stepIdx = 0;

    window.dispatchEvent(new CustomEvent('ar-view-stopped'));
  }

  /**
   * Loop de animación
   */
  function startAnimationLoop(navigator) {
    const loop = () => {
      if (!isActive) return;

      // Actualizar stats si hay navegador
      if (navigator) {
        updateStats(navigator);
      }

      // Dibujar en canvas si es necesario
      if (ctx && canvas) {
        drawOverlay();
      }

      animationFrame = requestAnimationFrame(loop);
    };

    animationFrame = requestAnimationFrame(loop);
  }

  /**
   * Dibuja el overlay en canvas
   */
  function drawOverlay() {
    if (!ctx || !canvas) return;

    // Limpiar canvas
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Aquí se pueden agregar elementos de AR adicionales
    // Por ahora el overlay se maneja con HTML/CSS
  }

  // ==================== ACTUALIZACIÓN DE POSICIÓN ====================

  /**
   * Actualiza la posición actual
   */
  function updatePosition(pos, speed = 0, accuracy = 999) {
    currentPosition = pos;
    currentSpeed = speed;

    // Actualizar minimapa
    updateMiniMapPosition(pos);

    // Verificar avance de paso
    const curStep = steps[stepIdx];
    if (!curStep) return;

    const dist = haversine(pos, curStep.end);

    // Actualizar distancia mostrada
    const distanceEl = document.getElementById('arDistance');
    if (distanceEl) {
      distanceEl.textContent = formatDistance(dist);
    }

    // Actualizar heading hacia el paso actual
    targetHeading = calculateBearing(pos, curStep.end);
    updateArrow();

    // Avanzar si llegamos
    if (dist < CONFIG.STEP_COMPLETE_DISTANCE) {
      stepIdx++;
      const nextStep = steps[stepIdx];
      const afterNext = steps[stepIdx + 1];

      updateHUD(nextStep, afterNext);

      if (nextStep) {
        targetHeading = calculateBearing(pos, nextStep.end);
      }
    }
  }

  // ==================== UTILIDADES ====================

  /**
   * Calcula la distancia entre dos puntos (Haversine)
   */
  function haversine(a, b) {
    const R = 6371000;
    const toRad = x => x * Math.PI / 180;
    const dLat = toRad(b.lat - a.lat);
    const dLng = toRad(b.lng - a.lng);
    const aa = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(aa));
  }

  /**
   * Calcula el bearing entre dos puntos
   */
  function calculateBearing(from, to) {
    const toRad = x => x * Math.PI / 180;
    const toDeg = x => x * 180 / Math.PI;

    const dLng = toRad(to.lng - from.lng);
    const lat1 = toRad(from.lat);
    const lat2 = toRad(to.lat);

    const y = Math.sin(dLng) * Math.cos(lat2);
    const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);

    return (toDeg(Math.atan2(y, x)) + 360) % 360;
  }

  /**
   * Verifica si el dispositivo soporta AR
   */
  function isSupported() {
    return !!(
      navigator.mediaDevices &&
      navigator.mediaDevices.getUserMedia &&
      'DeviceOrientationEvent' in window
    );
  }

  // ==================== EXPORTAR API ====================

  window.ARViewLite = {
    start,
    stop,
    updatePosition,
    isSupported,
    isActive: () => isActive
  };

})(window);
