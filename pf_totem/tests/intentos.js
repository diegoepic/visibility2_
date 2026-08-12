/* Prueba del nivel "imposible": sin línea visible, con reintentos y pista de
   dirección ("más lleno" / "más vacío"). Verifica que un fallo con intentos
   disponibles NO cierre la partida, que la línea de esa partida no cambie
   entre intentos (si cambiara, la pista no significaría nada), que se pueda
   ganar en cualquier intento y que perder tras agotarlos sí cierre la partida
   revelando recién ahí el margen exacto.
   Uso: node tests/intentos.js   (desde pf_totem/) */
const { chromium } = require('playwright');
const path = require('path');

const URL = 'file://' + path.resolve(__dirname, '..', 'index.html').replace(/\\/g, '/');
let fallos = 0;

function check(cond, msg, extra) {
  console.log((cond ? '  OK   ' : '  FALLA') + ' ' + msg + (extra !== undefined ? '  → ' + JSON.stringify(extra) : ''));
  if (!cond) fallos++;
}

const activa = (page) => page.evaluate(() => (document.querySelector('.screen.active') || {}).id);

async function servir(page, ms) {
  const box = await page.locator('#servir-stage').boundingBox();
  const x = box.x + box.width / 2, y = box.y + box.height / 2;
  await page.mouse.move(x, y);
  await page.mouse.down();
  await page.waitForTimeout(ms);
  await page.mouse.up();
}

// ms para soltar en un nivel (0-100) dado, resolviendo la curva real del juego
const msPara = (page, nivel) => page.evaluate((n) => Math.round(PFGame.tiempoPara(n) * 1000), nivel);

// abre el panel admin (5 toques + PIN de config.js) y deja la dificultad en "imposible"
async function activarImposible(page) {
  for (let i = 0; i < 5; i++) { await page.click('#admin-trigger'); await page.waitForTimeout(60); }
  await page.waitForTimeout(200);
  for (const d of ['2', '4', '3', '5', 'OK']) {
    await page.click(`.pin-pad button[data-d="${d}"]`);
    await page.waitForTimeout(60);
  }
  await page.waitForTimeout(300);
  await page.selectOption('#admin-dif', 'imposible');
  await page.click('#btn-admin-cerrar');
  await page.waitForTimeout(200);
}

const dump = (page) => page.evaluate(() => new Promise((res) => {
  const r = indexedDB.open('pf_totem');
  r.onsuccess = () => {
    const db = r.result;
    const t = db.transaction(['sesiones', 'eventos'], 'readonly');
    const out = {};
    t.objectStore('sesiones').getAll().onsuccess = (e) => { out.sesiones = e.target.result; };
    t.objectStore('eventos').getAll().onsuccess = (e) => { out.eventos = e.target.result; };
    t.oncomplete = () => res(out);
  };
}));

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
  const errores = [];
  page.on('pageerror', (e) => errores.push(String(e)));
  await page.route('**/sync.php*', (r) => r.abort());

  await page.goto(URL);
  await page.waitForTimeout(700);

  await activarImposible(page);
  check(await page.evaluate(() => PFGame.dificultad()) === 'imposible',
    'el panel admin dejó la dificultad en "imposible"');

  console.log('\n--- Partida 1: falla 2 veces, gana en el intento 3 ---');
  await page.click('#s-attract'); await page.waitForTimeout(400);
  await page.click('[data-momento="encuentro"]'); await page.waitForTimeout(300);
  await page.click('[data-variedad="tinto"]'); await page.waitForTimeout(300);
  await page.click('#btn-servir'); await page.waitForTimeout(400);

  const tieneLinea = await page.evaluate(() => {
    const svg = document.querySelector('#copa-svg');
    return [...svg.querySelectorAll('line')].some((l) => l.getAttribute('stroke') === '#e8cf94');
  });
  check(!tieneLinea, 'la copa del juego NO dibuja la línea dorada objetivo en "imposible"');
  const instr = await page.textContent('#servir-instr');
  check(!/línea dorada/i.test(instr), 'la instrucción no manda a mirar una línea que no existe', instr);

  const max = await page.evaluate(() => PF_CONFIG.dificultades.imposible.intentos);
  check(max === 3, 'esta partida tiene 3 intentos configurados (config.js)', max);
  let puntos = await page.evaluate(() => document.querySelectorAll('#servir-intentos .punto').length);
  check(puntos === 3, 'el indicador muestra un punto por intento', puntos);

  const centro = await page.evaluate(() => PFGame.centro());
  const tol = await page.evaluate(() => PF_CONFIG.dificultades.imposible.tolerancia);

  // intento 1: apunta bien lejos de largo (sobrellenar) para fallar seguro
  const msLejos1 = await msPara(page, Math.min(96, centro + tol + 25));
  await servir(page, msLejos1);
  await page.waitForTimeout(300);
  check(await activa(page) === 's-servir', 'fallar el intento 1 NO cierra la partida (sigue en "servir")');
  let hint = await page.textContent('#servir-hint');
  check(/más vacío/i.test(hint), 'sobrellenar avisa "más vacío"', hint);
  check(!/%/.test(hint), 'la pista de intentos intermedios no revela el número exacto', hint);

  await page.waitForTimeout(1600); // deja pasar el reset del intento (1400ms + margen)
  puntos = await page.evaluate(() => document.querySelectorAll('#servir-intentos .punto.usado').length);
  check(puntos === 1, 'el indicador marca 1 intento usado tras el primer fallo', puntos);

  const centro2 = await page.evaluate(() => PFGame.centro());
  check(centro2 === centro, 'la línea de la partida NO cambia entre intentos (si cambiara, la pista no serviría)',
    { centro, centro2 });

  // intento 2: apunta corto (subllenar) para fallar de nuevo, del otro lado
  const msLejos2 = await msPara(page, Math.max(4, centro - tol - 20));
  await servir(page, msLejos2);
  await page.waitForTimeout(300);
  check(await activa(page) === 's-servir', 'fallar el intento 2 tampoco cierra la partida (queda 1)');
  hint = await page.textContent('#servir-hint');
  check(/más lleno/i.test(hint), 'subllenar avisa "más lleno"', hint);
  await page.waitForTimeout(1600);

  // intento 3 (el último): apunta justo al centro para ganar
  const msCentro = await msPara(page, centro);
  await servir(page, msCentro);
  await page.waitForTimeout(1600);
  check(await activa(page) === 's-win', 'se puede ganar en el último intento disponible', await activa(page));

  let data = await dump(page);
  check(data.sesiones.length === 1, 'los 3 toques de la partida 1 quedaron como UNA sola sesión (no 3)', data.sesiones.length);
  let intentosLog = data.eventos.filter((e) => e.tipo === 'servida_intento');
  check(intentosLog.length === 2, 'quedaron 2 eventos "servida_intento" (los 2 fallos; el 3ro es servida_fin)',
    intentosLog.length);

  // completar el reclamo para volver limpio al inicio
  await page.click('#btn-reclamar'); await page.waitForTimeout(300);
  await page.click('#inp-nombre');
  for (const c of ['a', 'n', 'a']) await page.click(`.osk-key[data-key="${c}"]`);
  await page.click('#inp-email');
  for (const c of ['a', '@', 'a', '.', 'c', 'l']) await page.click(`.osk-key[data-key="${c}"]`);
  await page.click('#chk-consent');
  await page.click('#btn-enviar-datos');
  await page.waitForTimeout(700);
  check(await activa(page) === 's-premio', 'partida 1 se completa hasta el premio', await activa(page));
  await page.click('#btn-fin-premio'); await page.waitForTimeout(400);

  console.log('\n--- Partida 2: falla los 3 intentos → pierde, y ahí sí revela el margen ---');
  await page.click('#s-attract'); await page.waitForTimeout(400);
  await page.click('[data-momento="relajo"]'); await page.waitForTimeout(300);
  await page.click('[data-variedad="blanco"]'); await page.waitForTimeout(300);
  await page.click('#btn-servir'); await page.waitForTimeout(400);
  const centroB = await page.evaluate(() => PFGame.centro());
  const tolB = await page.evaluate(() => PF_CONFIG.dificultades.imposible.tolerancia);

  for (let k = 0; k < 3; k++) {
    const msFalla = await msPara(page, Math.min(96, centroB + tolB + 25)); // se pasa siempre, a propósito
    await servir(page, msFalla);
    await page.waitForTimeout(k < 2 ? 1700 : 1600);
  }
  check(await activa(page) === 's-lose', 'agotar los 3 intentos SÍ cierra la partida como perdida', await activa(page));
  const detalle = (await page.textContent('#lose-detalle')).replace(/\s+/g, ' ').trim();
  check(/pasaste por/i.test(detalle) && /margen para ganar/i.test(detalle) && /3 intentos/i.test(detalle),
    'el resultado final revela por cuánto falló, el margen y que usó sus 3 intentos', detalle);

  const lineaEnResultado = await page.evaluate(() => {
    const svg = document.querySelector('#lose-copa');
    return svg ? [...svg.querySelectorAll('line')].some((l) => l.getAttribute('stroke') === '#e8cf94') : false;
  });
  check(lineaEnResultado, 'la línea SÍ se revela en la pantalla de resultado final (aunque estuvo oculta al jugar)');

  data = await dump(page);
  check(data.sesiones.length === 2, 'siguen siendo sólo 2 sesiones en total (partida 1 + partida 2)', data.sesiones.length);
  intentosLog = data.eventos.filter((e) => e.tipo === 'servida_intento');
  check(intentosLog.length === 4, '2 fallos de la partida 1 + 2 fallos intermedios de la partida 2 = 4', intentosLog.length);

  console.log('\n--- Panel admin: desglose por dificultad ---');
  await page.click('#btn-fin-lose'); await page.waitForTimeout(400);
  for (let i = 0; i < 5; i++) { await page.click('#admin-trigger'); await page.waitForTimeout(60); }
  await page.waitForTimeout(200);
  for (const d of ['2', '4', '3', '5', 'OK']) { await page.click(`.pin-pad button[data-d="${d}"]`); await page.waitForTimeout(60); }
  await page.waitForTimeout(400); // refrescarPanel() lee IndexedDB async
  const panel = await page.evaluate(() => ({
    dificultad: document.querySelector('#admin-stats-dificultad').textContent.replace(/\s+/g, ' ').trim(),
    intentos: document.querySelector('#admin-imposible-intentos').textContent.trim(),
  }));
  check(/imposible/i.test(panel.dificultad), 'el panel admin desglosa "imposible" en la tabla por dificultad', panel.dificultad);
  // ambas partidas gastaron sus 3 intentos (una ganando en el 3ro, la otra perdiendo tras el 3ro): promedio = 3.0
  check(/3\.0/.test(panel.intentos) && /2 partidas/.test(panel.intentos),
    'muestra el promedio de intentos usados en "imposible"', panel.intentos);

  console.log('\n--- Errores de JS ---');
  check(errores.length === 0, 'sin errores en consola', errores.slice(0, 4));

  await browser.close();
  console.log('\n' + (fallos === 0 ? '✅ TODO OK' : '❌ ' + fallos + ' fallas'));
  process.exit(fallos === 0 ? 0 : 1);
})();
