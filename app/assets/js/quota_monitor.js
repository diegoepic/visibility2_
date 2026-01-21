/**
 * API Quota Monitor
 * Monitorea y gestiona las cuotas de API de Google Maps
 * @version 2.0.0
 */
(function(window) {
  'use strict';

  // ==================== CONFIGURACIÓN ====================

  const STORAGE_KEY = 'v2_api_quotas';
  const DAILY_RESET_HOUR = 0; // Medianoche

  // Límites diarios (aproximados basados en 10k/mes free tier)
  const DAILY_LIMITS = {
    routes: 333,        // Routes API v2
    directions: 333,    // Directions API (fallback)
    matrix: 333,        // Distance Matrix API
    geocode: 333,       // Geocoding API
    places: 333         // Places API
  };

  // Umbrales de advertencia
  const WARNING_THRESHOLDS = {
    low: 0.7,      // 70% usado - advertencia
    high: 0.9,     // 90% usado - crítico
    exceeded: 1.0  // 100% - bloqueado
  };

  // ==================== ESTADO ====================

  let quotas = {
    date: null,
    routes: { used: 0, limit: DAILY_LIMITS.routes },
    directions: { used: 0, limit: DAILY_LIMITS.directions },
    matrix: { used: 0, limit: DAILY_LIMITS.matrix },
    geocode: { used: 0, limit: DAILY_LIMITS.geocode },
    places: { used: 0, limit: DAILY_LIMITS.places }
  };

  let listeners = [];

  // ==================== PERSISTENCIA ====================

  /**
   * Carga las cuotas desde localStorage
   */
  function load() {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored) {
        const parsed = JSON.parse(stored);
        // Verificar si es del mismo día
        if (parsed.date === getTodayString()) {
          quotas = { ...quotas, ...parsed };
        } else {
          // Nuevo día, resetear
          reset();
        }
      }
    } catch (e) {
      console.warn('[QuotaMonitor] Error loading quotas:', e);
    }
    return quotas;
  }

  /**
   * Guarda las cuotas en localStorage
   */
  function save() {
    try {
      quotas.date = getTodayString();
      localStorage.setItem(STORAGE_KEY, JSON.stringify(quotas));
    } catch (e) {
      console.warn('[QuotaMonitor] Error saving quotas:', e);
    }
  }

  /**
   * Resetea las cuotas diarias
   */
  function reset() {
    quotas = {
      date: getTodayString(),
      routes: { used: 0, limit: DAILY_LIMITS.routes },
      directions: { used: 0, limit: DAILY_LIMITS.directions },
      matrix: { used: 0, limit: DAILY_LIMITS.matrix },
      geocode: { used: 0, limit: DAILY_LIMITS.geocode },
      places: { used: 0, limit: DAILY_LIMITS.places }
    };
    save();
    notifyListeners('reset', quotas);
  }

  /**
   * Obtiene la fecha actual como string
   */
  function getTodayString() {
    return new Date().toISOString().split('T')[0];
  }

  // ==================== GESTIÓN DE CUOTAS ====================

  /**
   * Verifica si se puede hacer una request
   */
  function canMakeRequest(apiType) {
    checkDayReset();

    const quota = quotas[apiType];
    if (!quota) return true; // API no monitoreada

    return quota.used < quota.limit;
  }

  /**
   * Registra una request
   */
  function recordRequest(apiType, count = 1) {
    checkDayReset();

    if (!quotas[apiType]) {
      quotas[apiType] = { used: 0, limit: DAILY_LIMITS[apiType] || 1000 };
    }

    quotas[apiType].used += count;
    save();

    // Verificar estado y notificar
    const level = getWarningLevel(apiType);
    if (level !== 'ok') {
      notifyListeners('warning', { apiType, level, quota: quotas[apiType] });
    }

    notifyListeners('request', { apiType, count, quota: quotas[apiType] });

    return quotas[apiType];
  }

  /**
   * Verifica si hay que resetear por nuevo día
   */
  function checkDayReset() {
    const today = getTodayString();
    if (quotas.date !== today) {
      reset();
    }
  }

  /**
   * Obtiene el nivel de advertencia para una API
   */
  function getWarningLevel(apiType) {
    const quota = quotas[apiType];
    if (!quota) return 'ok';

    const usage = quota.used / quota.limit;

    if (usage >= WARNING_THRESHOLDS.exceeded) return 'exceeded';
    if (usage >= WARNING_THRESHOLDS.high) return 'critical';
    if (usage >= WARNING_THRESHOLDS.low) return 'warning';
    return 'ok';
  }

  /**
   * Obtiene el nivel de advertencia global
   */
  function getGlobalWarningLevel() {
    const levels = ['ok', 'warning', 'critical', 'exceeded'];
    let maxLevel = 'ok';

    Object.keys(quotas).forEach(apiType => {
      if (apiType === 'date') return;
      const level = getWarningLevel(apiType);
      if (levels.indexOf(level) > levels.indexOf(maxLevel)) {
        maxLevel = level;
      }
    });

    return maxLevel;
  }

  /**
   * Obtiene estadísticas de uso
   */
  function getStats() {
    checkDayReset();

    const stats = {
      date: quotas.date,
      apis: {},
      totalUsed: 0,
      totalLimit: 0,
      globalLevel: getGlobalWarningLevel()
    };

    Object.keys(quotas).forEach(apiType => {
      if (apiType === 'date') return;
      const quota = quotas[apiType];
      stats.apis[apiType] = {
        used: quota.used,
        limit: quota.limit,
        remaining: Math.max(0, quota.limit - quota.used),
        percentage: Math.round((quota.used / quota.limit) * 100),
        level: getWarningLevel(apiType)
      };
      stats.totalUsed += quota.used;
      stats.totalLimit += quota.limit;
    });

    stats.totalPercentage = Math.round((stats.totalUsed / stats.totalLimit) * 100);

    return stats;
  }

  /**
   * Obtiene cuota específica
   */
  function getQuota(apiType) {
    checkDayReset();
    return quotas[apiType] ? { ...quotas[apiType] } : null;
  }

  /**
   * Obtiene cuota restante
   */
  function getRemaining(apiType) {
    const quota = getQuota(apiType);
    return quota ? Math.max(0, quota.limit - quota.used) : null;
  }

  // ==================== AJUSTE DE LÍMITES ====================

  /**
   * Ajusta el límite para una API (para configuración personalizada)
   */
  function setLimit(apiType, limit) {
    if (!quotas[apiType]) {
      quotas[apiType] = { used: 0, limit: limit };
    } else {
      quotas[apiType].limit = limit;
    }
    save();
  }

  /**
   * Ajusta todos los límites
   */
  function setLimits(limits) {
    Object.entries(limits).forEach(([apiType, limit]) => {
      setLimit(apiType, limit);
    });
  }

  // ==================== LISTENERS ====================

  /**
   * Agrega un listener de eventos
   */
  function addListener(callback) {
    if (typeof callback === 'function') {
      listeners.push(callback);
    }
  }

  /**
   * Remueve un listener
   */
  function removeListener(callback) {
    listeners = listeners.filter(l => l !== callback);
  }

  /**
   * Notifica a los listeners
   */
  function notifyListeners(event, data) {
    listeners.forEach(listener => {
      try {
        listener(event, data);
      } catch (e) {
        console.warn('[QuotaMonitor] Listener error:', e);
      }
    });

    // También emitir evento global
    window.dispatchEvent(new CustomEvent('quota-monitor-event', {
      detail: { event, data }
    }));
  }

  // ==================== UI HELPER ====================

  /**
   * Genera HTML para mostrar estado de cuotas
   */
  function getStatusHTML() {
    const stats = getStats();
    const levelColors = {
      ok: '#28a745',
      warning: '#ffc107',
      critical: '#fd7e14',
      exceeded: '#dc3545'
    };

    let html = `<div class="quota-monitor-status">`;
    html += `<div class="quota-global" style="color:${levelColors[stats.globalLevel]}">`;
    html += `<strong>Uso de API:</strong> ${stats.totalPercentage}%`;
    html += `</div>`;

    Object.entries(stats.apis).forEach(([api, data]) => {
      html += `<div class="quota-api">`;
      html += `<span class="quota-api-name">${api}:</span>`;
      html += `<span class="quota-api-value" style="color:${levelColors[data.level]}">`;
      html += `${data.used}/${data.limit} (${data.percentage}%)`;
      html += `</span>`;
      html += `</div>`;
    });

    html += `</div>`;
    return html;
  }

  /**
   * Muestra advertencia de cuota si es necesario
   */
  function showWarningIfNeeded() {
    const level = getGlobalWarningLevel();

    if (level === 'exceeded' && window.RouteErrorManager) {
      window.RouteErrorManager.showError(
        'Se ha alcanzado el límite diario de consultas. Intenta mañana o usa Google Maps directamente.'
      );
    } else if (level === 'critical' && window.RouteErrorManager) {
      window.RouteErrorManager.showWarning(
        'Quedan pocas consultas disponibles hoy. Usa con moderación.'
      );
    }
  }

  // ==================== INTEGRACIÓN CON ROUTE ENGINE ====================

  /**
   * Wrapper para requests con control de cuota
   */
  async function withQuotaCheck(apiType, requestFn) {
    if (!canMakeRequest(apiType)) {
      throw new Error(`QUOTA_EXCEEDED: Daily limit reached for ${apiType}`);
    }

    try {
      const result = await requestFn();
      recordRequest(apiType);
      return result;
    } catch (error) {
      // Si el error es de cuota de Google, registrarlo
      if (String(error).includes('OVER_QUERY_LIMIT') ||
          String(error).includes('quota') ||
          String(error).includes('rate limit')) {
        // Marcar como excedido
        quotas[apiType].used = quotas[apiType].limit;
        save();
        notifyListeners('quota_exceeded', { apiType });
      }
      throw error;
    }
  }

  // ==================== INICIALIZACIÓN ====================

  // Cargar al iniciar
  load();

  // Verificar reset cada hora
  setInterval(checkDayReset, 3600000);

  // Listener de estadísticas del RouteEngine
  window.addEventListener('route-engine-stats', (e) => {
    const stats = e.detail;
    if (stats.routes_api_requests !== undefined) {
      // Sincronizar con estadísticas del engine
      const diff = stats.routes_api_requests - (quotas.routes.used || 0);
      if (diff > 0) {
        quotas.routes.used = stats.routes_api_requests;
        save();
      }
    }
    if (stats.directions_fallback_requests !== undefined) {
      const diff = stats.directions_fallback_requests - (quotas.directions.used || 0);
      if (diff > 0) {
        quotas.directions.used = stats.directions_fallback_requests;
        save();
      }
    }
  });

  // ==================== EXPORTAR API ====================

  window.QuotaMonitor = {
    // Gestión de cuotas
    canMakeRequest,
    recordRequest,
    getQuota,
    getRemaining,
    getStats,
    getWarningLevel,
    getGlobalWarningLevel,

    // Configuración
    setLimit,
    setLimits,
    reset,

    // Listeners
    addListener,
    removeListener,

    // UI
    getStatusHTML,
    showWarningIfNeeded,

    // Integración
    withQuotaCheck,

    // Constantes
    DAILY_LIMITS,
    WARNING_THRESHOLDS
  };

})(window);
