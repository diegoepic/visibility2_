/* ============================================================
   PFDB — capa de persistencia local (IndexedDB).
   Todo lo que pasa en el tótem queda acá primero; sync.js lo
   sube al servidor cuando hay señal. Sobrevive cortes de luz.
   Stores:
     sesiones  — una por partida (keyPath uuid, índice synced)
     eventos   — log granular de cada acción (uuid, índice synced)
     ganadores — datos + código de cada ganador (uuid, índice synced)
     pool      — códigos únicos de descuento (keyPath codigo, índice usado)
     kv        — misceláneos (device_id, overrides de admin)
   ============================================================ */
window.PFDB = (function () {
  'use strict';

  var DB_NAME = 'pf_totem';
  var DB_VER = 1;
  var db = null;

  function open() {
    return new Promise(function (resolve, reject) {
      var req = indexedDB.open(DB_NAME, DB_VER);
      req.onupgradeneeded = function (e) {
        var d = e.target.result;
        ['sesiones', 'eventos', 'ganadores'].forEach(function (name) {
          if (!d.objectStoreNames.contains(name)) {
            var st = d.createObjectStore(name, { keyPath: 'uuid' });
            st.createIndex('synced', 'synced', { unique: false });
          }
        });
        if (!d.objectStoreNames.contains('pool')) {
          var p = d.createObjectStore('pool', { keyPath: 'codigo' });
          p.createIndex('usado', 'usado', { unique: false });
        }
        if (!d.objectStoreNames.contains('kv')) {
          d.createObjectStore('kv', { keyPath: 'k' });
        }
      };
      req.onsuccess = function () { db = req.result; resolve(db); };
      req.onerror = function () { reject(req.error); };
    });
  }

  function tx(store, mode) { return db.transaction(store, mode).objectStore(store); }

  function reqP(request) {
    return new Promise(function (resolve, reject) {
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error); };
    });
  }

  function put(store, obj) { return reqP(tx(store, 'readwrite').put(obj)); }
  function get(store, key) { return reqP(tx(store, 'readonly').get(key)); }
  function getAll(store) { return reqP(tx(store, 'readonly').getAll()); }
  function count(store) { return reqP(tx(store, 'readonly').count()); }

  // Registros pendientes de subir (synced = 0)
  function unsynced(store, limit) {
    var idx = tx(store, 'readonly').index('synced');
    return reqP(idx.getAll(IDBKeyRange.only(0), limit || 500));
  }

  function countUnsynced(store) {
    var idx = tx(store, 'readonly').index('synced');
    return reqP(idx.count(IDBKeyRange.only(0)));
  }

  // Marca como sincronizados un lote de registros
  function markSynced(store, uuids) {
    return new Promise(function (resolve, reject) {
      var t = db.transaction(store, 'readwrite');
      var st = t.objectStore(store);
      uuids.forEach(function (id) {
        var g = st.get(id);
        g.onsuccess = function () {
          var rec = g.result;
          if (rec) { rec.synced = 1; st.put(rec); }
        };
      });
      t.oncomplete = function () { resolve(); };
      t.onerror = function () { reject(t.error); };
    });
  }

  function kvGet(k, def) {
    return get('kv', k).then(function (r) { return r ? r.v : def; });
  }
  function kvSet(k, v) { return put('kv', { k: k, v: v }); }

  // Toma el primer código libre del pool y lo marca como usado
  function poolTomar() {
    return new Promise(function (resolve, reject) {
      var st = tx('pool', 'readwrite');
      var cur = st.index('usado').openCursor(IDBKeyRange.only(0));
      cur.onsuccess = function () {
        var c = cur.result;
        if (!c) { resolve(null); return; }
        var rec = c.value;
        rec.usado = 1;
        rec.usado_ts = new Date().toISOString();
        c.update(rec);
        resolve(rec.codigo);
      };
      cur.onerror = function () { reject(cur.error); };
    });
  }

  function poolImportar(codigos) {
    return new Promise(function (resolve, reject) {
      var t = db.transaction('pool', 'readwrite');
      var st = t.objectStore('pool');
      var nuevos = 0;
      codigos.forEach(function (c) {
        c = String(c).trim();
        if (!c) return;
        var g = st.get(c);
        g.onsuccess = function () {
          if (!g.result) { st.put({ codigo: c, usado: 0 }); nuevos++; }
        };
      });
      t.oncomplete = function () { resolve(nuevos); };
      t.onerror = function () { reject(t.error); };
    });
  }

  function poolDisponibles() {
    var idx = tx('pool', 'readonly').index('usado');
    return reqP(idx.count(IDBKeyRange.only(0)));
  }

  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (ch) {
      var r = (Math.random() * 16) | 0;
      return (ch === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  return {
    init: open,
    put: put, get: get, getAll: getAll, count: count,
    unsynced: unsynced, countUnsynced: countUnsynced, markSynced: markSynced,
    kvGet: kvGet, kvSet: kvSet,
    poolTomar: poolTomar, poolImportar: poolImportar, poolDisponibles: poolDisponibles,
    uuid: uuid,
  };
})();
