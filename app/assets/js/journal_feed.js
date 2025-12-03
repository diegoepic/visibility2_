
(function(){
  'use strict';

  const BASE = '/visibility2/app';
  const API  = BASE + '/api/journal_feed.php';

  const DB = { name:'v2_journal_feed', ver:1 };

  function openDB(){
    return new Promise((resolve)=>{
      const req = indexedDB.open(DB.name, DB.ver);
      req.onupgradeneeded = ()=>{
        const db = req.result;
        const items = db.createObjectStore('items', { keyPath:'key' }); // key: ymd|local_id
        items.createIndex('by_ymd','ymd',{unique:false});
        db.createObjectStore('meta', { keyPath:'key' }); // {key:'etag', value:'...'} ; {key:'last_sync', ymd:'2025-..'}
      };
      req.onsuccess = ()=> resolve(req.result);
      req.onerror   = ()=> resolve(null);
    });
  }

  async function tx(store, mode, fn){
    const db = await openDB(); if (!db) return null;
    return new Promise((resolve,reject)=>{
      const t = db.transaction(store, mode);
      const os= t.objectStore(store);
      let r; try{ r = fn(os); }catch(e){ reject(e); return; }
      t.oncomplete = ()=> resolve(r);
      t.onerror    = ()=> reject(t.error||new Error('tx error'));
    });
  }

  async function putItem(rec){
    return tx('items','readwrite', os=> os.put(rec));
  }
  async function putMeta(key, val){
    return tx('meta','readwrite', os=> os.put({ key, value: val }));
  }
  async function getMeta(key){
    return tx('meta','readonly', os=> new Promise(res=>{
      const r = os.get(key); r.onsuccess=()=>res(r.result ? r.result.value : null); r.onerror=()=>res(null);
    }));
  }
  async function listByYMD(ymd){
    return tx('items','readonly', os=> new Promise(res=>{
      const idx = os.index('by_ymd'), out=[]; const req = idx.openCursor(IDBKeyRange.only(ymd));
      req.onsuccess=()=>{ const c=req.result; if(!c){ res(out); return; } out.push(c.value); c.continue(); };
      req.onerror=()=>res(out);
    }));
  }

  async function sync(fromYmd, toYmd){
    const etag = await getMeta('etag:'+fromYmd+'|'+toYmd);
    const headers = etag ? { 'If-None-Match': etag } : {};
    const url = `${API}?from=${encodeURIComponent(fromYmd)}&to=${encodeURIComponent(toYmd)}`;

    const r = await fetch(url, { credentials:'same-origin', cache:'no-store', headers });
    if (r.status === 304) return true;
    if (!r.ok) throw new Error('HTTP '+r.status);

    const js = await r.json();
    const items = Array.isArray(js.items) ? js.items : [];

    await Promise.all(items.map(it=>{
      const key = `${it.ymd}|${it.local?.id||0}`;
      return putItem({
        key,
        ymd: it.ymd,
        local_id: it.local?.id || 0,
        local: it.local || null,
        campaigns: it.campaigns || [],
        counts: it.counts || {},
        status: it.status || 'success',
        progress: typeof it.progress==='number' ? it.progress : 100,
        last_updated: it.last_updated || null
      });
    }));
    if (js.manifest?.etag) await putMeta('etag:'+fromYmd+'|'+toYmd, js.manifest.etag);
    await putMeta('last_sync', Date.now());
    return true;
  }

  async function refreshOne(ymd, local_id){
    // estrategia simple: sincronizar solo el día (from=to=ymd)
    try { await sync(ymd, ymd); } catch(_){}
    const list = await listByYMD(ymd);
    return list.find(x=> Number(x.local_id) === Number(local_id)) || null;
  }

  window.Feed = { sync, listByYMD, refreshOne };
})();
