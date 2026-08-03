/* Verifica el escenario real del evento: el tótem carga el juego por HTTP
   desde el servidor, y al día siguiente arranca SIN señal. Después del
   primer arranque tiene que seguir funcionando igual.
   Uso: node tests/offline.js   (desde pf_totem/) */
const { chromium } = require('playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');

const RAIZ = path.resolve(__dirname, '..');
let fallos = 0;
const check = (c, m, x) => {
  console.log((c ? '  OK   ' : '  FALLA') + ' ' + m + (x !== undefined ? '  → ' + JSON.stringify(x) : ''));
  if (!c) fallos++;
};

const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css',
               '.ttf': 'font/ttf', '.png': 'image/png', '.jpeg': 'image/jpeg', '.json': 'application/json' };

let servidorCaido = false;
let pedidos = 0;

const server = http.createServer((req, res) => {
  if (servidorCaido) { req.destroy(); return; }
  pedidos++;
  const rel = req.url === '/' ? 'index.html' : decodeURIComponent(req.url.split('?')[0]);
  const f = path.join(RAIZ, rel);
  if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) {
    res.statusCode = 404; res.end(); return;
  }
  res.setHeader('Content-Type', MIME[path.extname(f)] || 'application/octet-stream');
  // sin caché de navegador: así se prueba el service worker y no el caché HTTP
  res.setHeader('Cache-Control', 'no-store');
  res.end(fs.readFileSync(f));
});

const activa = (page) => page.evaluate(() => (document.querySelector('.screen.active') || {}).id);

(async () => {
  await new Promise((r) => server.listen(0, r));
  const base = 'http://127.0.0.1:' + server.address().port;

  const browser = await chromium.launch();
  // un solo contexto durante toda la prueba: el service worker y su caché
  // viven ahí, igual que en el tótem entre un día y el otro
  const ctx = await browser.newContext({ viewport: { width: 1080, height: 1920 } });
  const page = await ctx.newPage();
  const errores = [];
  page.on('pageerror', (e) => errores.push(String(e)));

  console.log('\n--- Primer arranque, con red ---');
  await page.goto(base + '/index.html');
  await page.waitForTimeout(1200);
  check(await activa(page) === 's-attract', 'el juego carga por HTTP');

  // esperar a que el service worker termine de guardar todo
  await page.waitForFunction(
    () => window.PFOffline && PFOffline.getStatus().listo === true,
    null, { timeout: 20000 },
  ).catch(() => {});
  const st = await page.evaluate(() => PFOffline.refrescar());
  check(st.soportado, 'el service worker quedó registrado');
  check(st.listo, 'el juego quedó descargado completo', st.cacheados + ' de ' + st.total + ' archivos');

  console.log('\n--- Se cae la red y el tótem se reinicia ---');
  servidorCaido = true;
  const pedidosAntes = pedidos;
  await page.goto(base + '/index.html');
  await page.waitForTimeout(1800);
  check(await activa(page) === 's-attract', 'el juego arranca igual sin nada de red');
  check(pedidos === pedidosAntes, 'no necesitó pedirle NADA al servidor', pedidos - pedidosAntes);

  console.log('\n--- Se puede jugar completo sin red ---');
  await page.click('#s-attract'); await page.waitForTimeout(400);
  await page.click('[data-momento="celebracion"]'); await page.waitForTimeout(300);
  check(await activa(page) === 's-variedad', 'avanza a elegir variedad');
  await page.click('[data-variedad="tinto"]'); await page.waitForTimeout(300);
  const vino = await page.textContent('#vino-nombre');
  check(!!vino && vino !== '—', 'muestra el vino recomendado', vino);
  await page.click('#btn-servir'); await page.waitForTimeout(400);

  const ms = await page.evaluate(() => Math.round(PFGame.tiempoPara(PFGame.centro()) * 1000));
  const b = await page.locator('#servir-stage').boundingBox();
  await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
  await page.mouse.down(); await page.waitForTimeout(ms); await page.mouse.up();
  await page.waitForTimeout(1800);
  check(await activa(page) === 's-win', 'se puede ganar sin red', await activa(page));

  await page.click('#btn-reclamar'); await page.waitForTimeout(400);
  await page.click('#inp-nombre');
  for (const c of ['a', 'n', 'a']) await page.click(`.osk-key[data-key="${c}"]`);
  await page.click('#inp-email');
  for (const c of ['a', '@', 'b', '.cl']) await page.click(`.osk-key[data-key="${c}"]`);
  await page.click('#chk-consent');
  await page.click('#btn-enviar-datos'); await page.waitForTimeout(1000);
  check(await activa(page) === 's-premio', 'entrega el premio sin red');
  const qr = await page.evaluate(() => !!document.querySelector('#premio-qr svg'));
  check(qr, 'el QR se genera sin red');

  console.log('\n--- Las tipografías también quedaron guardadas ---');
  const fuentes = await page.evaluate(() => document.fonts.check('16px Cinzel'));
  check(fuentes, 'la tipografía de marca carga desde el caché');

  console.log('\n--- Vuelve la red: config.js se relee ---');
  servidorCaido = false;
  const antesCfg = pedidos;
  await page.goto(base + '/index.html');
  await page.waitForTimeout(1500);
  check(pedidos > antesCfg, 'con red vuelve a pedir config.js para tomar cambios del servidor',
    pedidos - antesCfg);
  check(await activa(page) === 's-attract', 'sigue arrancando bien');

  console.log('\n--- Errores de JS ---');
  const relevantes = errores.filter((e) => !/favicon/i.test(e));
  check(relevantes.length === 0, 'sin errores en consola', relevantes.slice(0, 3));

  await browser.close();
  server.close();
  console.log('\n' + (fallos === 0 ? '✅ OFFLINE OK' : '❌ ' + fallos + ' fallas'));
  process.exit(fallos === 0 ? 0 : 1);
})();
