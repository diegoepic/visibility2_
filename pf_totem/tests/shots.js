/* Captura cada pantalla del tótem a PNG para revisar el diseño.
   Uso: node tests/shots.js [carpeta-destino] */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const URL = 'file://' + path.resolve(__dirname, '..', 'index.html').replace(/\\/g, '/');
const OUT = process.argv[2] || path.resolve(__dirname, 'shots');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 }, deviceScaleFactor: 0.5 });
  await page.goto(URL);
  await page.waitForTimeout(900);

  const shot = async (n) => {
    await page.screenshot({ path: path.join(OUT, n + '.png') });
    console.log('  ' + n + '.png');
  };
  const activa = () => page.evaluate(() => (document.querySelector('.screen.active') || {}).id);

  // vuelve al inicio desde donde sea
  const alInicio = async () => {
    for (let i = 0; i < 6 && (await activa()) !== 's-attract'; i++) {
      await page.evaluate(() => {
        const act = document.querySelector('.screen.active');
        const b = act && act.querySelector('#btn-fin-lose, #btn-fin-premio');
        if (b) b.click();
        else if (window.PFGame) document.querySelector('#s-attract').click();
      });
      await page.waitForTimeout(350);
      if ((await activa()) !== 's-attract') {
        await page.evaluate(() => {
          const a = document.querySelector('#s-attract');
          a.classList.add('active');
          document.querySelectorAll('.screen').forEach((s) => { if (s !== a) s.classList.remove('active'); });
        });
        await page.waitForTimeout(200);
      }
    }
  };

  // recorre hasta la pantalla de servir con el momento/variedad pedidos
  const hastaServir = async (momento, variedad) => {
    await page.click('#s-attract'); await page.waitForTimeout(400);
    await page.click(`[data-momento="${momento}"]`); await page.waitForTimeout(300);
    await page.click(`[data-variedad="${variedad}"]`); await page.waitForTimeout(300);
    await page.click('#btn-servir'); await page.waitForTimeout(400);
    // ms para caer en el centro de la zona de ESTA partida (la línea se sortea)
    return page.evaluate(() => Math.round(PFGame.tiempoPara(PFGame.centro()) * 1000));
  };
  const presionar = async () => {
    const b = await page.locator('#servir-stage').boundingBox();
    await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
    await page.mouse.down();
  };

  await shot('1-attract');
  await page.click('#s-attract'); await page.waitForTimeout(600);
  await shot('2-momento');
  await page.click('[data-momento="celebracion"]'); await page.waitForTimeout(600);
  await shot('3-variedad');
  await page.click('[data-variedad="tinto"]'); await page.waitForTimeout(600);
  await shot('4-vino');
  await page.click('#btn-servir'); await page.waitForTimeout(600);
  await shot('5-servir-vacia');

  /* La captura del llenado va en su propia partida: sacar el PNG toma unos
     cientos de ms con el dedo apretado, y con el margen actual eso alcanza
     para pasarse. El resultado de esta partida da igual. */
  await presionar();
  await page.waitForTimeout(1100);
  await shot('6-servir-llenando');
  await page.mouse.up();
  await page.waitForTimeout(1700);
  await alInicio();

  // partida ganadora, sin capturas intermedias que descuadren el tiempo
  let ms = await hastaServir('celebracion', 'tinto');
  await presionar();
  await page.waitForTimeout(ms);
  await page.mouse.up();
  await page.waitForTimeout(1800);
  if ((await activa()) !== 's-win') console.log('  (aviso: no ganó, reintentando)');
  if ((await activa()) !== 's-win') {
    await alInicio();
    ms = await hastaServir('celebracion', 'tinto');
    await presionar(); await page.waitForTimeout(ms); await page.mouse.up();
    await page.waitForTimeout(1800);
  }
  await shot('7-gano');

  await page.click('#btn-reclamar'); await page.waitForTimeout(500);
  await page.click('#inp-nombre'); await page.waitForTimeout(200);
  for (const c of ['d', 'i', 'e', 'g', 'o']) await page.click(`.osk-key[data-key="${c}"]`);
  await shot('8-datos-teclado');
  await page.click('#inp-email');
  for (const c of ['d', 'i', 'e', 'g', 'o', '@', 'm', 'a', 'i', 'l', '.com']) await page.click(`.osk-key[data-key="${c}"]`);
  await page.click('#chk-consent');
  await page.click('#btn-enviar-datos'); await page.waitForTimeout(900);
  await shot('9-premio');
  await alInicio();

  // espumante llenándose (burbujas)
  await hastaServir('relajo', 'espumante');
  await presionar();
  await page.waitForTimeout(1000);
  await shot('10-espumante-burbujas');
  await page.mouse.up();
  await page.waitForTimeout(1800);
  await alInicio();

  // derrota por pasarse, para ver el detalle de cuánto faltó
  const ms2 = await hastaServir('relajo', 'tinto');
  await presionar();
  await page.waitForTimeout(ms2 + 320);
  await page.mouse.up();
  await page.waitForTimeout(1800);
  await shot('11-perdio');
  await alInicio();

  // panel admin
  for (let i = 0; i < 5; i++) { await page.click('#monograma'); await page.waitForTimeout(90); }
  await page.waitForTimeout(400);
  await shot('12-admin-pin');
  for (const d of ['2', '4', '6', '8']) await page.click(`.pin-pad button[data-d="${d}"]`);
  await page.click('.pin-pad button[data-d="OK"]');
  await page.waitForTimeout(700);
  await shot('13-admin-panel');

  await browser.close();
  console.log('\nCapturas en: ' + OUT);
})();
