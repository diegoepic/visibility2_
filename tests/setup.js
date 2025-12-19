const { indexedDB, IDBKeyRange } = require('fake-indexeddb');

global.indexedDB = indexedDB;
global.IDBKeyRange = IDBKeyRange;

global.crypto = global.crypto || {
  randomUUID: () => 'uuid-' + Math.random().toString(16).slice(2)
};

global.navigator = global.navigator || {};
global.navigator.onLine = true;

global.fetch = async () => ({
  ok: true,
  status: 200,
  headers: { get: () => 'application/json' },
  text: async () => JSON.stringify({ ok: true, status: 'ok' })
});
