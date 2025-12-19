const { spawn } = require('child_process');
const assert = require('assert');

const ROOT = '/workspace';
const PORT = 8001;
const BASE = `http://127.0.0.1:${PORT}/visibility2/app`;

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function request(path, options = {}, cookieJar = {}) {
  const headers = Object.assign({ Accept: 'application/json' }, options.headers || {});
  if (cookieJar.cookie) headers.Cookie = cookieJar.cookie;
  const res = await fetch(`${BASE}${path}`, {
    method: options.method || 'GET',
    headers,
    body: options.body
  });
  const setCookie = res.headers.get('set-cookie');
  if (setCookie) cookieJar.cookie = setCookie.split(';')[0];
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch (_) {}
  return { res, json, text };
}

async function run() {
  const server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', ROOT], {
    env: { ...process.env, V2_TEST_MODE: '1' },
    stdio: 'inherit'
  });

  try {
    await sleep(500);
    const jar = {};

    const pingNoSession = await request('/ping.php', {}, jar);
    assert.strictEqual(pingNoSession.res.status, 401);
    assert.strictEqual(pingNoSession.json.ok, false);

    const session = await request('/api/test_session.php', {}, jar);
    assert.strictEqual(session.res.status, 200);
    assert.strictEqual(session.json.ok, true);
    const csrf = session.json.csrf_token;

    const pingOk = await request('/ping.php', {}, jar);
    assert.strictEqual(pingOk.res.status, 200);
    assert.strictEqual(pingOk.json.ok, true);

    const csrfRefresh = await request('/csrf_refresh.php', {}, jar);
    assert.strictEqual(csrfRefresh.res.status, 200);
    assert.strictEqual(csrfRefresh.json.ok, true);

    const idKey = 'test-idempo-1';
    const body = new URLSearchParams({
      id_formulario: '1',
      id_local: '1',
      csrf_token: csrf,
      client_guid: 'guid-1'
    });
    const visita1 = await request('/create_visita_pruebas.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Idempotency-Key': idKey },
      body: body.toString()
    }, jar);
    assert.strictEqual(visita1.json.ok, true);

    const visita2 = await request('/create_visita_pruebas.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Idempotency-Key': idKey },
      body: body.toString()
    }, jar);
    assert.strictEqual(visita2.json.ok, true);
    assert.strictEqual(visita2.json.visita_id, visita1.json.visita_id);

    const gestionBody = new URLSearchParams({
      idCampana: '1',
      idLocal: '1',
      csrf_token: csrf,
      estadoGestion: 'completa'
    });
    const gestion = await request('/procesar_gestion_pruebas.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: gestionBody.toString()
    }, jar);
    assert.strictEqual(gestion.json.ok, true);

    const upload = await request('/upload_material_foto_pruebas.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ csrf_token: csrf }).toString()
    }, jar);
    assert.strictEqual(upload.json.ok, true);

    console.log('Integration tests passed.');
  } finally {
    server.kill('SIGTERM');
  }
}

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
