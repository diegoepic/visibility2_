/**
 * Route Error Manager
 * Gestión centralizada de errores con UI amigable y retry automático
 * @version 2.0.0
 */
(function(window) {
  'use strict';

  // ==================== CONFIGURACIÓN DE ERRORES ====================

  const ERROR_TYPES = {
    OFFLINE: {
      code: 'OFFLINE',
      message: 'Sin conexión a internet. Mostrando última ruta guardada.',
      icon: 'fa-wifi',
      severity: 'warning',
      retry: true,
      retryOnOnline: true,
      fallback: 'cache'
    },
    QUOTA_EXCEEDED: {
      code: 'QUOTA_EXCEEDED',
      message: 'Se ha superado el límite de consultas. Intenta en unos minutos.',
      icon: 'fa-exclamation-triangle',
      severity: 'error',
      retry: false,
      fallback: 'cache',
      offerGoogleMaps: true
    },
    NETWORK_ERROR: {
      code: 'NETWORK_ERROR',
      message: 'Error de red. Reintentando...',
      icon: 'fa-plug',
      severity: 'warning',
      retry: true,
      retryDelay: 3000,
      maxRetries: 3,
      fallback: 'cache'
    },
    INVALID_REQUEST: {
      code: 'INVALID_REQUEST',
      message: 'Los datos de la ruta son inválidos. Verifica los puntos seleccionados.',
      icon: 'fa-times-circle',
      severity: 'error',
      retry: false,
      fallback: null
    },
    ZERO_RESULTS: {
      code: 'ZERO_RESULTS',
      message: 'No se encontró una ruta entre los puntos seleccionados.',
      icon: 'fa-map-o',
      severity: 'warning',
      retry: false,
      fallback: null
    },
    GEOLOCATION_DENIED: {
      code: 'GEOLOCATION_DENIED',
      message: 'Permiso de ubicación denegado. Actívalo en la configuración del navegador.',
      icon: 'fa-location-arrow',
      severity: 'error',
      retry: false,
      fallback: null
    },
    GEOLOCATION_UNAVAILABLE: {
      code: 'GEOLOCATION_UNAVAILABLE',
      message: 'No se pudo obtener tu ubicación. Intenta de nuevo.',
      icon: 'fa-location-arrow',
      severity: 'warning',
      retry: true,
      retryDelay: 2000
    },
    API_KEY_MISSING: {
      code: 'API_KEY_MISSING',
      message: 'Falta la clave de Google Maps. Contacta al administrador.',
      icon: 'fa-key',
      severity: 'error',
      retry: false,
      fallback: null
    },
    WAYPOINTS_LIMIT: {
      code: 'WAYPOINTS_LIMIT',
      message: 'Se excedió el límite de paradas (máx. 24). Se usarán las primeras 24.',
      icon: 'fa-list-ol',
      severity: 'warning',
      retry: false,
      fallback: null
    },
    EXPORT_LIMIT: {
      code: 'EXPORT_LIMIT',
      message: 'Google Maps solo permite 10 paradas en la URL. ¿Cómo deseas proceder?',
      icon: 'fa-external-link',
      severity: 'warning',
      retry: false,
      fallback: null,
      requiresChoice: true
    },
    UNKNOWN: {
      code: 'UNKNOWN',
      message: 'Ocurrió un error inesperado. Intenta de nuevo.',
      icon: 'fa-question-circle',
      severity: 'error',
      retry: true,
      retryDelay: 2000,
      fallback: 'cache'
    }
  };

  // ==================== ESTADO ====================

  let toastContainer = null;
  let activeToasts = new Map();
  let retryQueue = [];
  let isRetrying = false;

  // ==================== FUNCIONES AUXILIARES ====================

  /**
   * Clasifica un error según su tipo
   */
  function classifyError(error) {
    if (!navigator.onLine) return ERROR_TYPES.OFFLINE;

    const msg = String(error?.message || error || '').toLowerCase();

    if (msg.includes('quota') || msg.includes('rate limit')) {
      return ERROR_TYPES.QUOTA_EXCEEDED;
    }
    if (msg.includes('zero_results') || msg.includes('no route')) {
      return ERROR_TYPES.ZERO_RESULTS;
    }
    if (msg.includes('invalid') || msg.includes('bad request')) {
      return ERROR_TYPES.INVALID_REQUEST;
    }
    if (msg.includes('api key') || msg.includes('key missing')) {
      return ERROR_TYPES.API_KEY_MISSING;
    }
    if (msg.includes('permission denied') || msg.includes('user denied')) {
      return ERROR_TYPES.GEOLOCATION_DENIED;
    }
    if (msg.includes('position unavailable')) {
      return ERROR_TYPES.GEOLOCATION_UNAVAILABLE;
    }
    if (msg.includes('network') || msg.includes('fetch') || msg.includes('failed to fetch')) {
      return ERROR_TYPES.NETWORK_ERROR;
    }

    return ERROR_TYPES.UNKNOWN;
  }

  /**
   * Asegura que existe el contenedor de toasts
   */
  function ensureToastContainer() {
    if (toastContainer && document.body.contains(toastContainer)) return;

    toastContainer = document.createElement('div');
    toastContainer.id = 'route-toast-container';
    toastContainer.className = 'route-toast-container';
    document.body.appendChild(toastContainer);
  }

  /**
   * Genera ID único para toast
   */
  function generateToastId() {
    return 'toast-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
  }

  // ==================== UI DE ERRORES ====================

  /**
   * Muestra un toast de notificación
   */
  function showToast(message, options = {}) {
    ensureToastContainer();

    const {
      severity = 'info',
      icon = 'fa-info-circle',
      duration = 5000,
      closeable = true,
      actions = [],
      id = generateToastId()
    } = options;

    // Remover toast existente con mismo ID
    if (activeToasts.has(id)) {
      removeToast(id);
    }

    const toast = document.createElement('div');
    toast.id = id;
    toast.className = `route-toast route-toast--${severity}`;

    let actionsHtml = '';
    if (actions.length > 0) {
      actionsHtml = '<div class="route-toast__actions">' +
        actions.map((a, i) => `<button class="route-toast__action" data-action="${i}">${a.label}</button>`).join('') +
        '</div>';
    }

    toast.innerHTML = `
      <div class="route-toast__content">
        <i class="fa ${icon} route-toast__icon"></i>
        <span class="route-toast__message">${message}</span>
      </div>
      ${actionsHtml}
      ${closeable ? '<button class="route-toast__close"><i class="fa fa-times"></i></button>' : ''}
    `;

    // Event listeners
    const closeBtn = toast.querySelector('.route-toast__close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => removeToast(id));
    }

    toast.querySelectorAll('.route-toast__action').forEach(btn => {
      btn.addEventListener('click', (e) => {
        const actionIndex = parseInt(e.target.dataset.action, 10);
        if (actions[actionIndex]?.onClick) {
          actions[actionIndex].onClick();
        }
        removeToast(id);
      });
    });

    toastContainer.appendChild(toast);
    activeToasts.set(id, { toast, timeout: null });

    // Trigger animation
    requestAnimationFrame(() => {
      toast.classList.add('route-toast--visible');
    });

    // Auto-remove
    if (duration > 0) {
      const timeout = setTimeout(() => removeToast(id), duration);
      activeToasts.get(id).timeout = timeout;
    }

    return id;
  }

  /**
   * Remueve un toast
   */
  function removeToast(id) {
    const entry = activeToasts.get(id);
    if (!entry) return;

    if (entry.timeout) {
      clearTimeout(entry.timeout);
    }

    entry.toast.classList.remove('route-toast--visible');
    entry.toast.classList.add('route-toast--hiding');

    setTimeout(() => {
      if (entry.toast.parentNode) {
        entry.toast.parentNode.removeChild(entry.toast);
      }
      activeToasts.delete(id);
    }, 300);
  }

  /**
   * Muestra un modal de confirmación
   */
  function showModal(title, message, options = {}) {
    return new Promise((resolve) => {
      const {
        buttons = [{ label: 'Aceptar', value: true, primary: true }],
        icon = 'fa-question-circle',
        closeable = true
      } = options;

      const modalId = 'route-error-modal-' + Date.now();
      const modal = document.createElement('div');
      modal.id = modalId;
      modal.className = 'route-modal-overlay';

      const buttonsHtml = buttons.map((b, i) =>
        `<button class="btn ${b.primary ? 'btn-primary' : 'btn-default'}" data-value="${i}">${b.label}</button>`
      ).join('');

      modal.innerHTML = `
        <div class="route-modal">
          <div class="route-modal__header">
            <i class="fa ${icon}"></i>
            <h4>${title}</h4>
            ${closeable ? '<button class="route-modal__close"><i class="fa fa-times"></i></button>' : ''}
          </div>
          <div class="route-modal__body">
            ${message}
          </div>
          <div class="route-modal__footer">
            ${buttonsHtml}
          </div>
        </div>
      `;

      const cleanup = (result) => {
        modal.classList.remove('route-modal-overlay--visible');
        setTimeout(() => {
          if (modal.parentNode) {
            modal.parentNode.removeChild(modal);
          }
        }, 300);
        resolve(result);
      };

      modal.querySelectorAll('.route-modal__footer button').forEach(btn => {
        btn.addEventListener('click', (e) => {
          const index = parseInt(e.target.dataset.value, 10);
          cleanup(buttons[index]?.value);
        });
      });

      const closeBtn = modal.querySelector('.route-modal__close');
      if (closeBtn) {
        closeBtn.addEventListener('click', () => cleanup(null));
      }

      modal.addEventListener('click', (e) => {
        if (e.target === modal && closeable) {
          cleanup(null);
        }
      });

      document.body.appendChild(modal);
      requestAnimationFrame(() => {
        modal.classList.add('route-modal-overlay--visible');
      });
    });
  }

  // ==================== MANEJO DE ERRORES ====================

  /**
   * Maneja un error de ruta
   */
  async function handle(error, context = {}) {
    const errorType = classifyError(error);

    // Log para diagnóstico
    console.error('[RouteError]', {
      type: errorType.code,
      error: error,
      context: context,
      timestamp: new Date().toISOString()
    });

    // Emitir evento
    window.dispatchEvent(new CustomEvent('route-error', {
      detail: {
        type: errorType,
        error: error,
        context: context
      }
    }));

    // Mostrar notificación según tipo
    if (errorType.requiresChoice) {
      return handleChoiceError(errorType, context);
    }

    const toastId = showToast(errorType.message, {
      severity: errorType.severity,
      icon: errorType.icon,
      duration: errorType.retry ? 3000 : 5000,
      actions: errorType.offerGoogleMaps ? [{
        label: 'Abrir en Google Maps',
        onClick: () => {
          if (context.exportToGoogleMaps) {
            context.exportToGoogleMaps();
          }
        }
      }] : []
    });

    // Intentar fallback
    if (errorType.fallback === 'cache' && context.cacheKey) {
      const cached = await tryLoadFromCache(context.cacheKey);
      if (cached) {
        showToast('Usando ruta en caché', {
          severity: 'info',
          icon: 'fa-database',
          duration: 2000
        });
        return { success: true, data: cached, fromCache: true };
      }
    }

    // Programar retry si aplica
    if (errorType.retry && context.retryFn) {
      scheduleRetry(errorType, context);
    }

    return { success: false, errorType: errorType };
  }

  /**
   * Maneja errores que requieren elección del usuario
   */
  async function handleChoiceError(errorType, context) {
    if (errorType.code === 'EXPORT_LIMIT') {
      const choice = await showModal(
        'Límite de Paradas',
        `<p>Tienes <strong>${context.waypointCount || 'más de 10'}</strong> paradas, pero Google Maps solo permite 10 waypoints en la URL pública.</p>
         <p>Selecciona cómo deseas proceder:</p>`,
        {
          icon: 'fa-map-marker',
          buttons: [
            { label: 'Exportar primeras 10', value: 'first10', primary: true },
            { label: 'Dividir en rutas', value: 'split' },
            { label: 'Cancelar', value: null }
          ]
        }
      );

      return { success: choice !== null, choice: choice };
    }

    return { success: false };
  }

  /**
   * Intenta cargar desde caché
   */
  async function tryLoadFromCache(cacheKey) {
    if (window.RouteEngine && window.RouteEngine._getCached) {
      return window.RouteEngine._getCached(cacheKey);
    }
    return null;
  }

  /**
   * Programa un reintento
   */
  function scheduleRetry(errorType, context) {
    const retryInfo = {
      errorType: errorType,
      context: context,
      attempts: (context.attempts || 0) + 1,
      maxAttempts: errorType.maxRetries || 3
    };

    if (retryInfo.attempts > retryInfo.maxAttempts) {
      showToast('No se pudo completar la operación después de varios intentos.', {
        severity: 'error',
        icon: 'fa-times-circle',
        duration: 5000
      });
      return;
    }

    if (errorType.retryOnOnline && !navigator.onLine) {
      // Esperar a que vuelva la conexión
      const onOnline = async () => {
        window.removeEventListener('online', onOnline);
        await executeRetry(retryInfo);
      };
      window.addEventListener('online', onOnline);

      showToast('Reintentando cuando vuelva la conexión...', {
        severity: 'info',
        icon: 'fa-clock-o',
        duration: 0,
        id: 'waiting-online'
      });
    } else {
      // Retry con delay
      const delay = errorType.retryDelay || 2000;
      setTimeout(() => executeRetry(retryInfo), delay);
    }
  }

  /**
   * Ejecuta un reintento
   */
  async function executeRetry(retryInfo) {
    removeToast('waiting-online');

    if (retryInfo.context.retryFn) {
      try {
        const result = await retryInfo.context.retryFn();
        if (result) {
          showToast('Operación completada exitosamente', {
            severity: 'success',
            icon: 'fa-check-circle',
            duration: 2000
          });
        }
      } catch (error) {
        // Recursively handle the new error
        handle(error, {
          ...retryInfo.context,
          attempts: retryInfo.attempts
        });
      }
    }
  }

  // ==================== HELPERS PÚBLICOS ====================

  /**
   * Muestra error de geolocalización
   */
  function handleGeolocationError(error) {
    let errorType;
    switch (error.code) {
      case 1: // PERMISSION_DENIED
        errorType = ERROR_TYPES.GEOLOCATION_DENIED;
        break;
      case 2: // POSITION_UNAVAILABLE
        errorType = ERROR_TYPES.GEOLOCATION_UNAVAILABLE;
        break;
      case 3: // TIMEOUT
        errorType = ERROR_TYPES.GEOLOCATION_UNAVAILABLE;
        errorType.message = 'La ubicación tardó demasiado. Intenta de nuevo.';
        break;
      default:
        errorType = ERROR_TYPES.UNKNOWN;
    }

    return handle(error, { originalError: error });
  }

  /**
   * Muestra advertencia de límite de waypoints
   */
  function warnWaypointLimit(count, max = 24) {
    if (count > max) {
      showToast(`Se seleccionaron ${count} paradas. Se usarán las primeras ${max}.`, {
        severity: 'warning',
        icon: 'fa-list-ol',
        duration: 4000
      });
      return true;
    }
    return false;
  }

  /**
   * Muestra advertencia de exportación
   */
  async function warnExportLimit(waypointCount) {
    if (waypointCount <= 10) return { proceed: true, method: 'direct' };

    return handle({ message: 'EXPORT_LIMIT' }, {
      waypointCount: waypointCount
    });
  }

  /**
   * Muestra mensaje de éxito
   */
  function showSuccess(message, duration = 2000) {
    return showToast(message, {
      severity: 'success',
      icon: 'fa-check-circle',
      duration: duration
    });
  }

  /**
   * Muestra mensaje informativo
   */
  function showInfo(message, duration = 3000) {
    return showToast(message, {
      severity: 'info',
      icon: 'fa-info-circle',
      duration: duration
    });
  }

  /**
   * Muestra mensaje de advertencia
   */
  function showWarning(message, duration = 4000) {
    return showToast(message, {
      severity: 'warning',
      icon: 'fa-exclamation-triangle',
      duration: duration
    });
  }

  /**
   * Muestra mensaje de error
   */
  function showError(message, duration = 5000) {
    return showToast(message, {
      severity: 'error',
      icon: 'fa-times-circle',
      duration: duration
    });
  }

  // ==================== EXPORTAR API ====================

  window.RouteErrorManager = {
    // Manejo principal
    handle,
    classifyError,

    // UI
    showToast,
    removeToast,
    showModal,

    // Helpers
    handleGeolocationError,
    warnWaypointLimit,
    warnExportLimit,

    // Mensajes rápidos
    showSuccess,
    showInfo,
    showWarning,
    showError,

    // Tipos de error
    ERROR_TYPES
  };

})(window);
