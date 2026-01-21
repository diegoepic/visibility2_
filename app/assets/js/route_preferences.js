/**
 * Route Preferences Manager
 * Gestiona las preferencias de ruta persistentes en localStorage
 * @version 2.0.0
 */
(function(window) {
  'use strict';

  const PREFS_KEY = 'v2_route_preferences';
  const EXCLUDED_KEY = 'v2_excluded';
  const CUSTOM_ORDER_KEY = 'v2_custom_order';

  const DEFAULTS = {
    // Opciones de optimización
    optimize: true,
    autoRecalc: true,

    // Opciones de voz
    voiceEnabled: false,
    voiceRate: 1.0,
    voiceVolume: 1.0,
    voiceLang: 'es-CL',

    // Modo de transporte
    travelMode: 'DRIVE', // DRIVE | BICYCLE | WALK | TWO_WHEELER

    // Opciones de ruta
    avoidTolls: false,
    avoidHighways: false,
    avoidFerries: false,

    // Tráfico
    trafficEnabled: false,
    trafficModel: 'BEST_GUESS', // BEST_GUESS | PESSIMISTIC | OPTIMISTIC

    // Hora de salida
    departureOffset: 'now', // now | +15 | +30 | +60 | custom
    customDepartureTime: null,

    // UI
    drawerOpen: false,
    configPanelOpen: false,

    // Modo local (prog/reag)
    mode: 'prog',

    // Fecha seleccionada por modo
    dateProg: '',
    dateReag: ''
  };

  let current = { ...DEFAULTS };

  /**
   * Carga las preferencias desde localStorage
   */
  function load() {
    try {
      const stored = localStorage.getItem(PREFS_KEY);
      if (stored) {
        const parsed = JSON.parse(stored);
        current = { ...DEFAULTS, ...parsed };
      }
    } catch (e) {
      console.warn('[RoutePreferences] Error loading preferences:', e);
    }
    return current;
  }

  /**
   * Guarda las preferencias en localStorage
   */
  function save() {
    try {
      localStorage.setItem(PREFS_KEY, JSON.stringify(current));
    } catch (e) {
      console.warn('[RoutePreferences] Error saving preferences:', e);
    }
  }

  /**
   * Obtiene una preferencia específica
   */
  function get(key) {
    return current[key] !== undefined ? current[key] : DEFAULTS[key];
  }

  /**
   * Establece una preferencia y guarda
   */
  function set(key, value) {
    const oldValue = current[key];
    current[key] = value;
    save();

    // Emitir evento de cambio
    window.dispatchEvent(new CustomEvent('route-prefs-changed', {
      detail: { key, value, oldValue, all: { ...current } }
    }));

    return value;
  }

  /**
   * Establece múltiples preferencias a la vez
   */
  function setMultiple(prefs) {
    const changes = {};
    Object.entries(prefs).forEach(([key, value]) => {
      if (current[key] !== value) {
        changes[key] = { oldValue: current[key], newValue: value };
        current[key] = value;
      }
    });

    if (Object.keys(changes).length > 0) {
      save();
      window.dispatchEvent(new CustomEvent('route-prefs-changed', {
        detail: { changes, all: { ...current } }
      }));
    }

    return current;
  }

  /**
   * Obtiene todas las preferencias
   */
  function getAll() {
    return { ...current };
  }

  /**
   * Resetea a valores por defecto
   */
  function reset() {
    current = { ...DEFAULTS };
    save();
    window.dispatchEvent(new CustomEvent('route-prefs-reset', {
      detail: { all: { ...current } }
    }));
    return current;
  }

  /**
   * Genera configuración para el motor de rutas
   */
  function getRouteConfig() {
    let departureTime = null;

    if (current.departureOffset === 'now') {
      departureTime = 'now';
    } else if (current.departureOffset === 'custom' && current.customDepartureTime) {
      departureTime = current.customDepartureTime;
    } else if (current.departureOffset.startsWith('+')) {
      const minutes = parseInt(current.departureOffset.substring(1), 10);
      departureTime = new Date(Date.now() + minutes * 60 * 1000).toISOString();
    }

    return {
      travelMode: current.travelMode,
      avoidTolls: current.avoidTolls,
      avoidHighways: current.avoidHighways,
      avoidFerries: current.avoidFerries,
      departureTime: departureTime,
      trafficModel: current.trafficEnabled ? current.trafficModel : null,
      languageCode: 'es-CL'
    };
  }

  // ==================== EXCLUDED MANAGEMENT ====================

  let excludedSet = new Set();

  /**
   * Carga los locales excluidos
   */
  function loadExcluded() {
    try {
      const stored = localStorage.getItem(EXCLUDED_KEY);
      if (stored) {
        excludedSet = new Set(JSON.parse(stored));
      }
    } catch (e) {
      excludedSet = new Set();
    }
    return excludedSet;
  }

  /**
   * Guarda los locales excluidos
   */
  function saveExcluded() {
    try {
      localStorage.setItem(EXCLUDED_KEY, JSON.stringify(Array.from(excludedSet)));
    } catch (e) {
      console.warn('[RoutePreferences] Error saving excluded:', e);
    }
  }

  /**
   * Genera clave de exclusión
   */
  function excludedKey(mode, fecha, localId) {
    return `${mode}|${fecha}|${localId}`;
  }

  /**
   * Verifica si un local está excluido
   */
  function isExcluded(mode, fecha, localId) {
    return excludedSet.has(excludedKey(mode, fecha, localId));
  }

  /**
   * Agrega un local a excluidos
   */
  function addExcluded(mode, fecha, localId) {
    excludedSet.add(excludedKey(mode, fecha, localId));
    saveExcluded();
  }

  /**
   * Remueve un local de excluidos
   */
  function removeExcluded(mode, fecha, localId) {
    excludedSet.delete(excludedKey(mode, fecha, localId));
    saveExcluded();
  }

  /**
   * Alterna exclusión de un local
   */
  function toggleExcluded(mode, fecha, localId) {
    const key = excludedKey(mode, fecha, localId);
    if (excludedSet.has(key)) {
      excludedSet.delete(key);
    } else {
      excludedSet.add(key);
    }
    saveExcluded();
    return excludedSet.has(key);
  }

  /**
   * Obtiene todos los excluidos
   */
  function getExcluded() {
    return new Set(excludedSet);
  }

  /**
   * Cuenta excluidos para una fecha y modo
   */
  function countExcluded(mode, fecha) {
    let count = 0;
    excludedSet.forEach(key => {
      if (key.startsWith(`${mode}|${fecha}|`)) {
        count++;
      }
    });
    return count;
  }

  // ==================== CUSTOM ORDER MANAGEMENT ====================

  /**
   * Guarda un orden personalizado de paradas
   */
  function saveCustomOrder(mode, fecha, orderArray) {
    try {
      const key = `${CUSTOM_ORDER_KEY}_${mode}_${fecha}`;
      localStorage.setItem(key, JSON.stringify(orderArray));
    } catch (e) {
      console.warn('[RoutePreferences] Error saving custom order:', e);
    }
  }

  /**
   * Carga un orden personalizado de paradas
   */
  function loadCustomOrder(mode, fecha) {
    try {
      const key = `${CUSTOM_ORDER_KEY}_${mode}_${fecha}`;
      const stored = localStorage.getItem(key);
      return stored ? JSON.parse(stored) : null;
    } catch (e) {
      return null;
    }
  }

  /**
   * Elimina un orden personalizado
   */
  function clearCustomOrder(mode, fecha) {
    try {
      const key = `${CUSTOM_ORDER_KEY}_${mode}_${fecha}`;
      localStorage.removeItem(key);
    } catch (e) {
      console.warn('[RoutePreferences] Error clearing custom order:', e);
    }
  }

  // ==================== INITIALIZATION ====================

  // Cargar al iniciar
  load();
  loadExcluded();

  // Exportar API
  window.RoutePreferences = {
    // Preferencias generales
    load,
    save,
    get,
    set,
    setMultiple,
    getAll,
    reset,
    getRouteConfig,
    DEFAULTS,

    // Excluidos
    loadExcluded,
    saveExcluded,
    isExcluded,
    addExcluded,
    removeExcluded,
    toggleExcluded,
    getExcluded,
    countExcluded,
    excludedKey,

    // Orden personalizado
    saveCustomOrder,
    loadCustomOrder,
    clearCustomOrder
  };

})(window);
