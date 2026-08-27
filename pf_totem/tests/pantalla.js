/* Botón de pantalla completa (vive en el panel admin → bloque "Juego").
   Lo que importa verificar acá no es que el navegador entre —headless miente
   sobre eso— sino que el botón diga la verdad sobre el estado y no quede
   ofreciendo "activar" cuando ya está activa, o al revés.
   Uso:  node tests/pantalla.js        (desde pf_totem/) */
const { chromium } = require('playwright');
const path = require('path');

const URL = 'file://' + path.resolve(__dirname, '..', 'index.html').replace(/\\/g, '/');
const PIN = '2435';   // = adminPin en js/config.js
let fallos = 0;

function check(cond, msg, extra) {
  console.log((cond ? '  OK   ' : '  FALLA') + ' ' + msg + (extra !== undefined ? '  → ' + JSON.stringify(extra) : ''));
  if (!cond) fallos++;
}

const activa = (page) => page.evaluate(() => (document.querySelector('.screen.active') || {}).id);
const btn = (page) => page.evaluate(() => {
  const b = document.querySelector('#btn-fullscreen');
  return {
    existe: !!b,
    oculto: !!b && b.classList.contains('oculto'),
    texto: b ? b.textContent.trim() : null,
    nota: (document.querySelector('#admin-fs-nota') || {}).textContent || '',
  };
});

/* Se simulan AMBAS propiedades: game.js consulta fullscreenElement y también la
   variante webkit (por los WebView Android viejos). Pisar sólo una deja el
   estado a medias — y si el navegador concedió pantalla completa de verdad, la
   que quedó sin pisar sigue devolviendo el elemento. */
const simularFS = (page, dentro) => page.evaluate((d) => {
  ['fullscreenElement', 'webkitFullscreenElement'].forEach((prop) => {
    Object.defineProperty(document, prop, {
      configurable: true, get: () => (d ? document.documentElement : null),
    });
  });
  document.dispatchEvent(new Event('fullscreenchange'));
}, dentro);

async function abrirAdmin(page) {
  for (let i = 0; i < 5; i++) { await page.click('#admin-trigger'); await page.waitForTimeout(80); }
  await page.waitForTimeout(200);
  for (const d of PIN) await page.click(`.pin-pad button[data-d="${d}"]`);
  await page.click('.pin-pad button[data-d="OK"]');
  await page.waitForTimeout(400);
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
  const errores = [];
  page.on('pageerror', (e) => errores.push(String(e)));
  await page.route('**/sync.php*', (r) => r.abort());

  await page.goto(URL);
  await page.waitForTimeout(700);

  console.log('\n--- No está a la vista del público ---');
  /* Si el botón viviera sobre el attract, cualquier visitante podría sacar el
     tótem del modo kiosko de un toque. Tiene que estar dentro del panel. */
  const enAttract = await page.evaluate(() => {
    const b = document.querySelector('#btn-fullscreen');
    return !!b && !!b.closest('#admin-overlay');
  });
  check(enAttract, 'el botón vive dentro del panel admin, no sobre el juego');
  check(await activa(page) === 's-attract', 'arranca en el inicio');

  console.log('\n--- Dentro del panel ---');
  await abrirAdmin(page);
  check(await page.evaluate(() => !document.querySelector('#admin-panel').classList.contains('oculto')),
    'el panel admin se abrió con el PIN');

  const soportado = await page.evaluate(() =>
    !!(document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen));
  check(soportado, 'el navegador soporta la API (si no, el botón se esconde)');

  await simularFS(page, false);
  await page.waitForTimeout(150);
  let b1 = await btn(page);
  check(b1.existe && !b1.oculto, 'el botón está visible en el panel', b1);
  check(/activar/i.test(b1.texto), 'fuera de pantalla completa ofrece ACTIVAR', b1.texto);

  console.log('\n--- Refleja el estado real ---');
  await simularFS(page, true);
  await page.waitForTimeout(150);
  let b2 = await btn(page);
  check(/salir/i.test(b2.texto), 'en pantalla completa ofrece SALIR', b2.texto);
  check(/kiosko/i.test(b2.nota), 'la nota explica cómo lograr que arranque sola', b2.nota);

  await simularFS(page, false);
  await page.waitForTimeout(150);
  let b3 = await btn(page);
  check(/activar/i.test(b3.texto), 'al salir con ESC vuelve a ofrecer ACTIVAR', b3.texto);

  console.log('\n--- El juego sigue intacto ---');
  await page.click('#btn-admin-cerrar');
  await page.waitForTimeout(300);
  await page.click('#s-attract');
  await page.waitForTimeout(500);
  check(await activa(page) === 's-momento', 'tocar la pantalla arranca la partida');

  console.log('\n--- Errores de JS ---');
  check(errores.length === 0, 'sin errores en consola', errores.slice(0, 4));

  await browser.close();
  console.log('\n' + (fallos === 0 ? '✅ PANTALLA COMPLETA OK' : '❌ ' + fallos + ' fallas'));
  process.exit(fallos === 0 ? 0 : 1);
})();
