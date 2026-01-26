/**
 * sync_status_badge.js - Badges de estado de sincronización para UI
 *
 * Muestra el estado real de sincronización de cada local en el index:
 * - Sincronizado (verde)
 * - En cola / Sincronizando (amarillo)
 * - Error (rojo)
 * - Bloqueado por auth (naranja)
 *

 */

(function() {
  'use strict';

  const BADGE_UPDATE_INTERVAL = 5000; // Actualizar cada 5 segundos
  const BADGE_STYLES = {
    synced: { bg: '#4CAF50', text: 'white', icon: '✓' },
    pending: { bg: '#FFC107', text: 'black', icon: '⏳' },
    syncing: { bg: '#2196F3', text: 'white', icon: '↻' },
    error: { bg: '#F44336', text: 'white', icon: '⚠️' },
    blocked: { bg: '#FF9800', text: 'white', icon: '🔒' },
    unknown: { bg: '#9E9E9E', text: 'white', icon: '?' }
  };

  // Inyectar estilos CSS
  function injectStyles() {
    if (document.getElementById('sync-badge-styles')) return;

    const style = document.createElement('style');
    style.id = 'sync-badge-styles';
    style.textContent = `
      .v2-sync-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        white-space: nowrap;
        cursor: help;
        transition: all 0.2s ease;
        margin-left: 8px;
      }

      .v2-sync-badge:hover {
        transform: scale(1.05);
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
      }

      .v2-sync-badge.synced { background: ${BADGE_STYLES.synced.bg}; color: ${BADGE_STYLES.synced.text}; }
      .v2-sync-badge.pending { background: ${BADGE_STYLES.pending.bg}; color: ${BADGE_STYLES.pending.text}; }
      .v2-sync-badge.syncing { background: ${BADGE_STYLES.syncing.bg}; color: ${BADGE_STYLES.syncing.text}; }
      .v2-sync-badge.error { background: ${BADGE_STYLES.error.bg}; color: ${BADGE_STYLES.error.text}; }
      .v2-sync-badge.blocked { background: ${BADGE_STYLES.blocked.bg}; color: ${BADGE_STYLES.blocked.text}; }
      .v2-sync-badge.unknown { background: ${BADGE_STYLES.unknown.bg}; color: ${BADGE_STYLES.unknown.text}; }

      .v2-sync-badge .badge-icon {
        font-size: 12px;
      }

      .v2-sync-badge.syncing .badge-icon {
        animation: v2-spin 1s linear infinite;
      }

      @keyframes v2-spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
      }

      /* Alerta de sesión bloqueada */
      .v2-auth-alert {
        position: fixed;
        top: 10px;
        left: 50%;
        transform: translateX(-50%);
        background: #FF9800;
        color: white;
        padding: 12px 24px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 500;
      }

      .v2-auth-alert button {
        background: white;
        color: #FF9800;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
      }

      .v2-auth-alert button:hover {
        background: #f5f5f5;
      }

      /* Toast de éxito */
      .v2-sync-toast {
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: #4CAF50;
        color: white;
        padding: 16px 24px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 10000;
        animation: v2-slide-in 0.3s ease;
      }

      @keyframes v2-slide-in {
        from {
          transform: translateX(100%);
          opacity: 0;
        }
        to {
          transform: translateX(0);
          opacity: 1;
        }
      }
    `;
    document.head.appendChild(style);
  }

  /**
   * Obtiene el estado de sincronización para un local
   */
  async function getLocalSyncStatus(localId, formIds) {
    // Primero verificar en V2Cache si está marcado como done
    if (window.V2Cache && typeof V2Cache.isDone === 'function') {
      const ymd = new Date().toISOString().slice(0, 10);
      for (const formId of formIds) {
        if (V2Cache.isDone(formId, localId, ymd)) {
          return { state: 'synced', label: 'Sincronizado', detail: 'Gestión completada', jobs: [] };
        }
      }
    }

    // Usar Queue.getGestionStatus si está disponible
    if (window.OfflineQueue && typeof OfflineQueue.getGestionStatus === 'function') {
      try {
        return await OfflineQueue.getGestionStatus(localId, formIds);
      } catch (e) {
        console.warn('[SyncBadge] Error consultando estado:', e);
      }
    }

    // Fallback: consultar AppDB directamente
    if (window.AppDB) {
      try {
        const allJobs = await AppDB.listAll();
        const matchingJobs = allJobs.filter(job => {
          const f = job.fields || {};
          const jobLocalId = Number(f.id_local || f.idLocal || f.local_id || 0);
          const jobFormId = Number(f.id_formulario || f.idCampana || f.id_campana || 0);

          if (jobLocalId !== Number(localId)) return false;
          if (formIds.length > 0 && !formIds.map(Number).includes(jobFormId)) return false;
          return true;
        });

        if (matchingJobs.length === 0) {
          return { state: 'synced', label: '✓', detail: 'Sin pendientes', jobs: [] };
        }

        const hasError = matchingJobs.some(j => j.status === 'error' || j.status === 'failed_permanent');
        const hasBlocked = matchingJobs.some(j => j.status === 'blocked_auth' || j.status === 'blocked_csrf');
        const hasRunning = matchingJobs.some(j => j.status === 'running');
        const pending = matchingJobs.filter(j => j.status === 'queued').length;

        if (hasError) {
          const errorJob = matchingJobs.find(j => j.status === 'error' || j.status === 'failed_permanent');
          return {
            state: 'error',
            label: '⚠️ Error',
            detail: errorJob?.lastError?.message || 'Error de sincronización',
            jobs: matchingJobs
          };
        }

        if (hasBlocked) {
          return { state: 'blocked', label: '🔒', detail: 'Sesión expirada', jobs: matchingJobs };
        }

        if (hasRunning) {
          return { state: 'syncing', label: '↻', detail: 'Sincronizando...', jobs: matchingJobs };
        }

        return {
          state: 'pending',
          label: `⏳ ${pending}`,
          detail: `${pending} en cola`,
          jobs: matchingJobs
        };
      } catch (e) {
        console.warn('[SyncBadge] Error consultando AppDB:', e);
      }
    }

    return { state: 'unknown', label: '?', detail: 'Estado desconocido', jobs: [] };
  }

  /**
   * Crea o actualiza el badge de una fila
   */
  function updateRowBadge(row, status) {
    let badge = row.querySelector('.v2-sync-badge');

    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'v2-sync-badge';

      // Buscar donde insertar (después del nombre o al final de la primera celda)
      const nameCell = row.querySelector('td:first-child') || row.querySelector('td');
      if (nameCell) {
        nameCell.appendChild(badge);
      }
    }

    // Actualizar badge
    badge.className = `v2-sync-badge ${status.state}`;
    badge.innerHTML = `<span class="badge-icon">${BADGE_STYLES[status.state]?.icon || '?'}</span>`;
    if (status.state === 'pending' && status.jobs?.length) {
      badge.innerHTML += `<span>${status.jobs.length}</span>`;
    }
    badge.title = status.detail;
  }

  /**
   * Actualiza todos los badges del index
   */
  async function updateAllBadges() {
    const rows = document.querySelectorAll('tr[data-idlocal], tr[data-local-id], .local-row');

    for (const row of rows) {
      const localId = row.getAttribute('data-idlocal') ||
                      row.getAttribute('data-local-id') ||
                      row.dataset.localId;

      if (!localId) continue;

      // Obtener formIds de la fila (puede estar en data-campanas o data-form-ids)
      const formIdsStr = row.getAttribute('data-campanas') ||
                         row.getAttribute('data-form-ids') ||
                         row.dataset.formIds || '';
      const formIds = formIdsStr.split(',').filter(Boolean).map(Number);

      try {
        const status = await getLocalSyncStatus(localId, formIds);
        updateRowBadge(row, status);
      } catch (e) {
        console.warn('[SyncBadge] Error actualizando fila:', e);
      }
    }
  }

  /**
   * Muestra alerta de sesión bloqueada
   */
  function showAuthAlert() {
    if (document.getElementById('v2-auth-alert')) return;

    const alert = document.createElement('div');
    alert.id = 'v2-auth-alert';
    alert.className = 'v2-auth-alert';
    alert.innerHTML = `
      <span>🔒 Tu sesión expiró. Inicia sesión nuevamente para sincronizar.</span>
      <button onclick="location.reload()">Reintentar</button>
    `;
    document.body.appendChild(alert);

    // Auto-remover después de 30 segundos
    setTimeout(() => alert.remove(), 30000);
  }

  /**
   * Oculta alerta de sesión bloqueada
   */
  function hideAuthAlert() {
    const alert = document.getElementById('v2-auth-alert');
    if (alert) alert.remove();
  }

  /**
   * Muestra toast de éxito
   */
  function showSuccessToast(message) {
    const existing = document.querySelector('.v2-sync-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'v2-sync-toast';
    toast.textContent = message || '✓ Sincronización completada';
    document.body.appendChild(toast);

    setTimeout(() => toast.remove(), 4000);
  }

  /**
   * Previene cierre de ventana si hay jobs activos
   */
  function setupBeforeUnloadWarning() {
    window.addEventListener('beforeunload', async (e) => {
      if (!window.AppDB) return;

      try {
        const running = await AppDB.listByStatus('running');
        const queued = await AppDB.listByStatus('queued');

        if (running.length > 0 || queued.length > 5) {
          e.preventDefault();
          e.returnValue = 'Hay sincronización en progreso. Si cierras, podrías perder datos.';
          return e.returnValue;
        }
      } catch (_) {}
    });
  }

  /**
   * Inicializa el sistema de badges
   */
  function init() {
    injectStyles();

    // Actualizar badges al cargar
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', updateAllBadges);
    } else {
      updateAllBadges();
    }

    // Actualizar periódicamente
    setInterval(updateAllBadges, BADGE_UPDATE_INTERVAL);

    // Escuchar eventos de la cola
    window.addEventListener('queue:dispatch:success', (e) => {
      updateAllBadges();
      // Si es una gestión completa, mostrar toast
      if (e.detail?.job?.type === 'procesar_gestion') {
        showSuccessToast('Gestión sincronizada correctamente');
      }
    });

    window.addEventListener('queue:dispatch:error', (e) => {
      updateAllBadges();
    });

    window.addEventListener('queue:blocked', (e) => {
      if (e.detail?.reason === 'auth') {
        showAuthAlert();
      }
      updateAllBadges();
    });

    window.addEventListener('queue:unblocked', () => {
      hideAuthAlert();
      updateAllBadges();
    });

    // Actualizar cuando vuelve la conexión
    window.addEventListener('online', () => {
      setTimeout(updateAllBadges, 1000);
    });

    // Configurar warning de beforeunload
    setupBeforeUnloadWarning();

    // Escuchar broadcast de otras pestañas
    if ('BroadcastChannel' in window) {
      try {
        const bc = new BroadcastChannel('v2-events');
        bc.onmessage = (e) => {
          if (e.data?.type === 'gestion_completed') {
            updateAllBadges();
          }
        };
      } catch (_) {}
    }
  }

  // Exponer API
  window.SyncStatusBadge = {
    init,
    updateAllBadges,
    getLocalSyncStatus,
    showAuthAlert,
    hideAuthAlert,
    showSuccessToast
  };

  // Auto-inicializar
  init();
})();
