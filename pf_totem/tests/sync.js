/* Prueba de la sincronización contra un servidor simulado, replicando el
   escenario real del evento: señal mala e intermitente.
   Verifica que nada se pierda, que reintente solo y que reenviar no duplique.
   Uso: node tests/sync.js   (desde pf_totem/) */
const { chromium } = require('playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');

const RAIZ = path.resolve(__dirname, '..');
const TOKEN = 'token-de-prueba';
let fallos = 0;
const check = (c, m, x) => {
  console.log((c ? '  OK   ' : '  FALLA') + ' ' + m + (x !== undefined ? '  → ' + JSON.stringify(x) : ''));
  if (!c) fallos++;
};

// ---- servidor: sirve los archivos y hace de endpoint de sync ----
const recibido = { sesiones: new Map(), eventos: new Map(), ganadores: new Map() };
let lotes = 0, tokensMalos = 0;
let modo = 'ok';           // 'ok' | 'caido' (500) | 'sinred' (corta la conexión)

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css',
               '.ttf': 'font/ttf', '.png': 'image/png', '.jpeg': 'image/jpeg', '.json': 'application/json' };

const server = http.createServer((req, res) => {
  if (req.url.startsWith('/sync')) {
    if (modo === 'sinred') { req.destroy(); return; }
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      res.setHeader('Access-Control-Allow-Origin', '*');
      res.setHeader('Content-Type', 'application/json');
      if (req.headers['x-pf-token'] !== TOKEN) {
        tokensMalos++; res.statusCode = 401; res.end(JSON.stringify({ ok: false, error_code: 'TOKEN_INVALIDO' })); return;
      }
      if (modo === 'caido') { res.statusCode = 500; res.end(JSON.stringify({ ok: false })); return; }
      const j = JSON.parse(body || '{}');
      if (j.ping) { res.end(JSON.stringify({ ok: true, ping: true, server_time: 'x' })); return; }
      lotes++;
      // upsert por uuid, igual que el ON DUPLICATE KEY UPDATE del PHP
      for (const k of ['sesiones', 'eventos', 'ganadores']) {
        (j[k] || []).forEach((r) => recibido[k].set(r.uuid, r));
      }
      res.end(JSON.stringify({ ok: true, server_time: new Date().toISOString() }));
    });
    return;
  }
  const f = path.join(RAIZ, req.url === '/' ? 'index.html' : decodeURIComponent(req.url.split('?')[0]));
  if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.statusCode = 404; res.end(); return; }
  res.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream');
  res.end(fs.readFileSync(f));
});

const pendientes = (page) => page.evaluate(() => PFSync.getStatus().pendientes);
const totalPend = (p) => p.sesiones + p.eventos + p.ganadores;

(async () => {
  await new Promise((r) => server.listen(0, r));
  const base = 'http://127.0.0.1:' + server.address().port;

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
  const errores = [];
  page.on('pageerror', (e) => errores.push(String(e)));

  // inyecta la URL y el token del sync en config.js (en producción se editan a mano)
  await page.route('**/js/config.js', async (route) => {
    const src = fs.readFileSync(path.join(RAIZ, 'js', 'config.js'), 'utf8')
      .replace("url: ''", `url: '${base}/sync'`)
      .replace("token: ''", `token: '${TOKEN}'`)
      .replace('intervaloSeg: 120', 'intervaloSeg: 30');
    route.fulfill({ status: 200, contentType: 'text/javascript', body: src });
  });

  console.log('\n--- Sin red: el juego debe seguir funcionando ---');
  modo = 'sinred';
  await page.goto(base + '/index.html');
  await page.waitForTimeout(800);

  /* extraMs se suma al tiempo justo de ESTA partida: la línea se sortea en
     cada una, así que no existe un tiempo fijo que sirva siempre. */
  const jugar = async (extraMs) => {
    await page.click('#s-attract'); await page.waitForTimeout(350);
    await page.click('[data-momento="encuentro"]'); await page.waitForTimeout(250);
    await page.click('[data-variedad="tinto"]'); await page.waitForTimeout(250);
    await page.click('#btn-servir'); await page.waitForTimeout(350);
    const ms = await page.evaluate(() => Math.round(PFGame.tiempoPara(PFGame.centro()) * 1000));
    const b = await page.locator('#servir-stage').boundingBox();
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
    await page.mouse.down(); await page.waitForTimeout(ms + (extraMs || 0)); await page.mouse.up();
    await page.waitForTimeout(1800);
  };

  await jugar(700);   // se pasa: pierde
  check(await page.evaluate(() => (document.querySelector('.screen.active') || {}).id) === 's-lose',
    'se puede jugar completo sin conexión');
  await page.click('#btn-fin-lose'); await page.waitForTimeout(400);

  await page.evaluate(() => PFSync.tick());
  await page.waitForTimeout(1200);
  let p = await pendientes(page);
  check(totalPend(p) > 0, 'sin red, todo queda pendiente y nada se pierde', p);
  check(recibido.sesiones.size === 0, 'el servidor no recibió nada (correcto)');

  console.log('\n--- Servidor caído (500): reintenta, no descarta ---');
  modo = 'caido';
  await page.evaluate(() => PFSync.tick());
  await page.waitForTimeout(1200);
  p = await pendientes(page);
  check(totalPend(p) > 0, 'con error 500 los datos siguen pendientes', p);
  const err = await page.evaluate(() => PFSync.getStatus().ultimoError);
  check(!!err, 'queda registrado el último error', err);

  console.log('\n--- Vuelve la señal: sube el backlog solo ---');
  modo = 'ok';
  await page.evaluate(() => PFSync.tick());
  await page.waitForTimeout(2500);
  p = await pendientes(page);
  check(totalPend(p) === 0, 'al volver la red sube todo el backlog', p);
  check(recibido.sesiones.size === 1, 'llegó la partida jugada sin conexión', recibido.sesiones.size);
  const ses = [...recibido.sesiones.values()][0];
  check(ses.resultado === 'perdio' && ses.momento === 'encuentro' && !!ses.fin,
    'la partida llegó completa', { r: ses.resultado, m: ses.momento });
  check(recibido.eventos.size > 8, 'llegó el log de acciones', recibido.eventos.size);

  console.log('\n--- Partida con premio: el ganador sube de inmediato ---');
  const lotesAntes = lotes;
  await jugar(0);   // en el centro de la zona: gana
  check(await page.evaluate(() => (document.querySelector('.screen.active') || {}).id) === 's-win', 'ganó');
  await page.click('#btn-reclamar'); await page.waitForTimeout(400);
  await page.click('#inp-nombre');
  for (const c of ['a', 'n', 'a']) await page.click(`.osk-key[data-key="${c}"]`);
  await page.click('#inp-email');
  for (const c of ['a', '@', 'b', '.cl']) await page.click(`.osk-key[data-key="${c}"]`);
  await page.click('#chk-consent');
  await page.click('#btn-enviar-datos');
  await page.waitForTimeout(2500);
  check(recibido.ganadores.size === 1, 'el ganador llegó al servidor sin esperar el intervalo', recibido.ganadores.size);
  const g = [...recibido.ganadores.values()][0];
  check(g.nombre === 'Ana' && g.email === 'a@b.cl' && !!g.codigo && g.consentimiento === 1,
    'el ganador llegó con todos sus datos', { n: g.nombre, e: g.email });
  check(lotes > lotesAntes, 'se disparó un envío inmediato al reclamar el premio');

  console.log('\n--- Idempotencia: reenviar no duplica ---');
  const antes = { s: recibido.sesiones.size, e: recibido.eventos.size, g: recibido.ganadores.size };
  // se fuerza el reenvío marcando todo como pendiente otra vez, como si la
  // respuesta del servidor se hubiera perdido en el camino
  await page.evaluate(() => new Promise((res) => {
    const r = indexedDB.open('pf_totem');
    r.onsuccess = () => {
      const db = r.result;
      const t = db.transaction(['sesiones', 'eventos', 'ganadores'], 'readwrite');
      ['sesiones', 'eventos', 'ganadores'].forEach((n) => {
        const st = t.objectStore(n);
        st.getAll().onsuccess = (e) => e.target.result.forEach((x) => { x.synced = 0; st.put(x); });
      });
      t.oncomplete = res;
    };
  }));
  await page.evaluate(() => PFSync.tick());
  await page.waitForTimeout(2500);
  check(recibido.sesiones.size === antes.s && recibido.eventos.size === antes.e
    && recibido.ganadores.size === antes.g,
    'reenviar el mismo lote no duplica nada (upsert por uuid)',
    { antes, ahora: { s: recibido.sesiones.size, e: recibido.eventos.size, g: recibido.ganadores.size } });
  check(totalPend(await pendientes(page)) === 0, 'tras el reenvío queda todo sincronizado');

  console.log('\n--- Token ---');
  check(tokensMalos === 0, 'siempre se envió el token correcto');

  console.log('\n--- Errores de JS ---');
  check(errores.length === 0, 'sin errores en consola', errores.slice(0, 3));

  await browser.close();
  server.close();
  console.log('\n' + (fallos === 0 ? '✅ SYNC OK' : '❌ ' + fallos + ' fallas'));
  process.exit(fallos === 0 ? 0 : 1);
})();
