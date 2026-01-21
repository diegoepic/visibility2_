/**
 * Voice Controller
 * Controlador de síntesis de voz avanzado con instrucciones preventivas
 * @version 2.0.0
 */
(function(window) {
  'use strict';

  // ==================== CONFIGURACIÓN ====================

  const CONFIG = {
    defaultLang: 'es-CL',
    fallbackLangs: ['es-ES', 'es-MX', 'es-AR', 'es'],
    defaultRate: 1.0,
    defaultVolume: 1.0,
    defaultPitch: 1.0,
    queueDelay: 200, // ms entre utterances
    preventiveDistances: {
      immediate: 50,    // metros - instrucción inmediata
      soon: 200,        // metros - "En X metros..."
      approaching: 500, // metros - "Próximamente..."
      far: 1000         // metros - solo si es cambio importante
    }
  };

  // ==================== ESTADO ====================

  let enabled = false;
  let rate = CONFIG.defaultRate;
  let volume = CONFIG.defaultVolume;
  let pitch = CONFIG.defaultPitch;
  let lang = CONFIG.defaultLang;
  let voice = null;
  let queue = [];
  let speaking = false;
  let currentUtterance = null;
  let voicesLoaded = false;
  let lastSpokenInstruction = null;
  let lastSpokenTime = 0;

  // ==================== INICIALIZACIÓN ====================

  /**
   * Inicializa el controlador de voz
   */
  function init() {
    // Cargar preferencias si RoutePreferences está disponible
    if (window.RoutePreferences) {
      enabled = window.RoutePreferences.get('voiceEnabled') || false;
      rate = window.RoutePreferences.get('voiceRate') || CONFIG.defaultRate;
      volume = window.RoutePreferences.get('voiceVolume') || CONFIG.defaultVolume;
      lang = window.RoutePreferences.get('voiceLang') || CONFIG.defaultLang;
    }

    // Cargar voces
    loadVoices();

    // Chrome necesita evento para cargar voces
    if (speechSynthesis.onvoiceschanged !== undefined) {
      speechSynthesis.onvoiceschanged = loadVoices;
    }

    console.log('[VoiceController] Initialized', { enabled, rate, volume, lang });
  }

  /**
   * Carga las voces disponibles
   */
  function loadVoices() {
    const voices = speechSynthesis.getVoices();
    if (voices.length === 0) return;

    voicesLoaded = true;

    // Buscar mejor voz en español
    voice = findBestVoice(voices, lang);

    if (voice) {
      console.log('[VoiceController] Selected voice:', voice.name, voice.lang);
    } else {
      console.warn('[VoiceController] No Spanish voice found, using default');
    }
  }

  /**
   * Encuentra la mejor voz disponible
   */
  function findBestVoice(voices, preferredLang) {
    // Intentar encontrar voz exacta
    let found = voices.find(v => v.lang === preferredLang);
    if (found) return found;

    // Intentar fallbacks
    for (const fallback of CONFIG.fallbackLangs) {
      found = voices.find(v => v.lang === fallback || v.lang.startsWith(fallback));
      if (found) return found;
    }

    // Intentar cualquier voz en español
    found = voices.find(v => v.lang.startsWith('es'));
    return found || null;
  }

  // ==================== CONTROL BÁSICO ====================

  /**
   * Habilita/deshabilita la voz
   */
  function setEnabled(value) {
    enabled = !!value;
    if (window.RoutePreferences) {
      window.RoutePreferences.set('voiceEnabled', enabled);
    }

    if (!enabled) {
      stop();
    } else {
      speak('Voz activada', 'interrupt');
    }

    window.dispatchEvent(new CustomEvent('voice-enabled-changed', {
      detail: { enabled }
    }));

    return enabled;
  }

  /**
   * Alterna el estado de la voz
   */
  function toggle() {
    return setEnabled(!enabled);
  }

  /**
   * Verifica si la voz está habilitada
   */
  function isEnabled() {
    return enabled;
  }

  /**
   * Establece la velocidad de habla
   */
  function setRate(value) {
    rate = Math.max(0.5, Math.min(2.0, parseFloat(value) || CONFIG.defaultRate));
    if (window.RoutePreferences) {
      window.RoutePreferences.set('voiceRate', rate);
    }
    return rate;
  }

  /**
   * Establece el volumen
   */
  function setVolume(value) {
    volume = Math.max(0, Math.min(1.0, parseFloat(value) || CONFIG.defaultVolume));
    if (window.RoutePreferences) {
      window.RoutePreferences.set('voiceVolume', volume);
    }
    return volume;
  }

  /**
   * Establece el idioma
   */
  function setLang(value) {
    lang = value || CONFIG.defaultLang;
    if (window.RoutePreferences) {
      window.RoutePreferences.set('voiceLang', lang);
    }
    // Recargar voz
    if (voicesLoaded) {
      voice = findBestVoice(speechSynthesis.getVoices(), lang);
    }
    return lang;
  }

  // ==================== HABLA ====================

  /**
   * Habla un texto
   * @param {string} text - Texto a hablar
   * @param {string} priority - 'normal' | 'high' | 'interrupt'
   */
  function speak(text, priority = 'normal') {
    if (!enabled || !text || typeof text !== 'string') return false;

    const trimmedText = text.trim();
    if (!trimmedText) return false;

    // Evitar repetición inmediata del mismo texto
    if (trimmedText === lastSpokenInstruction && Date.now() - lastSpokenTime < 5000) {
      return false;
    }

    const utterance = createUtterance(trimmedText);

    if (priority === 'interrupt') {
      // Interrumpir todo y hablar inmediatamente
      stop();
      speakNow(utterance, trimmedText);
    } else if (priority === 'high') {
      // Agregar al inicio de la cola
      queue.unshift(utterance);
      processQueue();
    } else {
      // Agregar al final de la cola
      queue.push(utterance);
      processQueue();
    }

    return true;
  }

  /**
   * Crea un SpeechSynthesisUtterance configurado
   */
  function createUtterance(text) {
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = lang;
    utterance.rate = rate;
    utterance.volume = volume;
    utterance.pitch = pitch;

    if (voice) {
      utterance.voice = voice;
    }

    return utterance;
  }

  /**
   * Habla inmediatamente
   */
  function speakNow(utterance, text) {
    speaking = true;
    currentUtterance = utterance;

    utterance.onend = () => {
      speaking = false;
      currentUtterance = null;
      lastSpokenInstruction = text;
      lastSpokenTime = Date.now();

      // Pequeño delay antes del siguiente
      setTimeout(processQueue, CONFIG.queueDelay);
    };

    utterance.onerror = (event) => {
      console.warn('[VoiceController] Speech error:', event.error);
      speaking = false;
      currentUtterance = null;
      setTimeout(processQueue, CONFIG.queueDelay);
    };

    try {
      speechSynthesis.speak(utterance);
    } catch (e) {
      console.error('[VoiceController] Error speaking:', e);
      speaking = false;
    }
  }

  /**
   * Procesa la cola de utterances
   */
  function processQueue() {
    if (speaking || queue.length === 0) return;

    const next = queue.shift();
    speakNow(next, next.text);
  }

  /**
   * Detiene toda la síntesis de voz
   */
  function stop() {
    try {
      speechSynthesis.cancel();
    } catch (e) {
      // Ignorar errores de cancel
    }
    queue = [];
    speaking = false;
    currentUtterance = null;
  }

  /**
   * Pausa la síntesis de voz
   */
  function pause() {
    try {
      speechSynthesis.pause();
    } catch (e) {
      // Ignorar
    }
  }

  /**
   * Reanuda la síntesis de voz
   */
  function resume() {
    try {
      speechSynthesis.resume();
    } catch (e) {
      // Ignorar
    }
  }

  // ==================== INSTRUCCIONES DE NAVEGACIÓN ====================

  /**
   * Habla una instrucción de navegación con contexto de distancia
   * @param {string} instruction - Texto de la instrucción
   * @param {number} distanceMeters - Distancia en metros
   * @param {string} maneuverType - Tipo de maniobra opcional
   */
  function speakNavigation(instruction, distanceMeters, maneuverType = null) {
    if (!enabled) return false;

    const distances = CONFIG.preventiveDistances;
    let text = '';
    let priority = 'normal';

    if (distanceMeters <= distances.immediate) {
      // Instrucción inmediata
      text = instruction;
      priority = 'interrupt';
    } else if (distanceMeters <= distances.soon) {
      // En X metros
      const rounded = Math.round(distanceMeters / 10) * 10;
      text = `En ${rounded} metros, ${instruction.toLowerCase()}`;
      priority = 'high';
    } else if (distanceMeters <= distances.approaching) {
      // Próximamente
      const rounded = Math.round(distanceMeters / 50) * 50;
      text = `En ${rounded} metros, ${instruction.toLowerCase()}`;
      priority = 'normal';
    } else if (distanceMeters <= distances.far) {
      // Solo maniobras importantes a larga distancia
      if (isImportantManeuver(maneuverType)) {
        const km = (distanceMeters / 1000).toFixed(1);
        text = `En ${km} kilómetros, ${instruction.toLowerCase()}`;
        priority = 'normal';
      }
    }

    if (text) {
      return speak(text, priority);
    }
    return false;
  }

  /**
   * Determina si una maniobra es importante para anunciarla con anticipación
   */
  function isImportantManeuver(maneuverType) {
    const important = ['uturn', 'roundabout', 'merge', 'ramp', 'ferry'];
    return maneuverType && important.some(t => maneuverType.toLowerCase().includes(t));
  }

  /**
   * Habla actualización de ruta
   */
  function speakRouteUpdate(distanceKm, durationMin) {
    if (!enabled) return false;

    const text = `Ruta actualizada. ${distanceKm.toFixed(1)} kilómetros, ${Math.round(durationMin)} minutos.`;
    return speak(text, 'normal');
  }

  /**
   * Habla llegada a destino
   */
  function speakArrival(destinationName = null) {
    if (!enabled) return false;

    const text = destinationName
      ? `Has llegado a ${destinationName}`
      : 'Has llegado a tu destino';
    return speak(text, 'interrupt');
  }

  /**
   * Habla llegada a parada intermedia
   */
  function speakWaypointArrival(waypointName = null, remaining = 0) {
    if (!enabled) return false;

    let text = waypointName
      ? `Llegando a ${waypointName}`
      : 'Llegando a la siguiente parada';

    if (remaining > 0) {
      text += `. Quedan ${remaining} paradas.`;
    }

    return speak(text, 'high');
  }

  /**
   * Habla recálculo de ruta
   */
  function speakReroute() {
    if (!enabled) return false;
    return speak('Recalculando ruta', 'interrupt');
  }

  /**
   * Habla advertencia de desvío
   */
  function speakOffRoute() {
    if (!enabled) return false;
    return speak('Te has salido de la ruta', 'interrupt');
  }

  /**
   * Habla información de tráfico
   */
  function speakTrafficInfo(delayMinutes) {
    if (!enabled || delayMinutes < 5) return false;

    const text = delayMinutes >= 15
      ? `Hay tráfico intenso. Demora estimada de ${Math.round(delayMinutes)} minutos.`
      : `Hay algo de tráfico. Demora de ${Math.round(delayMinutes)} minutos.`;

    return speak(text, 'normal');
  }

  // ==================== UTILIDADES ====================

  /**
   * Obtiene las voces disponibles
   */
  function getAvailableVoices() {
    return speechSynthesis.getVoices().filter(v => v.lang.startsWith('es'));
  }

  /**
   * Obtiene la configuración actual
   */
  function getConfig() {
    return {
      enabled,
      rate,
      volume,
      pitch,
      lang,
      voice: voice ? { name: voice.name, lang: voice.lang } : null
    };
  }

  /**
   * Establece toda la configuración
   */
  function setConfig(config) {
    if (config.enabled !== undefined) setEnabled(config.enabled);
    if (config.rate !== undefined) setRate(config.rate);
    if (config.volume !== undefined) setVolume(config.volume);
    if (config.lang !== undefined) setLang(config.lang);
    if (config.pitch !== undefined) {
      pitch = Math.max(0.5, Math.min(2.0, parseFloat(config.pitch) || CONFIG.defaultPitch));
    }
  }

  /**
   * Verifica si el navegador soporta síntesis de voz
   */
  function isSupported() {
    return 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  }

  // ==================== INICIALIZACIÓN AUTOMÁTICA ====================

  if (isSupported()) {
    // Inicializar cuando el DOM esté listo
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  } else {
    console.warn('[VoiceController] Speech synthesis not supported in this browser');
  }

  // ==================== EXPORTAR API ====================

  window.VoiceController = {
    // Control básico
    init,
    setEnabled,
    toggle,
    isEnabled,
    setRate,
    setVolume,
    setLang,
    getConfig,
    setConfig,

    // Habla
    speak,
    stop,
    pause,
    resume,

    // Navegación
    speakNavigation,
    speakRouteUpdate,
    speakArrival,
    speakWaypointArrival,
    speakReroute,
    speakOffRoute,
    speakTrafficInfo,

    // Utilidades
    getAvailableVoices,
    isSupported
  };

})(window);
