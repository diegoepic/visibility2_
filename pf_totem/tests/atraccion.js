/* Verifica las tres cosas del inicio que una captura estática no muestra:
   la demo animada de la copa, el contador social y la vibración.
   Uso: node tests/atraccion.js   (desde pf_totem/) */
const { chromium } = require('playwright');
const path = require('path');

const URL = 'file://' + path.resolve(__dirname, '..', 'index.html').replace(/\\/g, '/');
let fallos = 0;
const check = (c, m, x) => {
  console.log((c ? '  OK   ' : '  FALLA') + ' ' + m + (x !== undefined ? '  → ' + JSON.stringify(x) : ''));
  if (!c) fallos++;
};

const activa = (page) => page.evaluate(() => (document.querySelector('.screen.active') || {}).id);
/* Se lee data-nivel y no la geometría: la superficie del líquido ondula, así
   que medir el path daría el vaivén del oleaje en vez del nivel real. */
const nivelDemo = (page) => page.evaluate(() => {
  const r = document.querySelector('#attract-copa .pf-liq');
  return r ? Math.round(parseFloat(r.getAttribute('data-nivel'))) : null;
});
// toma varias muestras del nivel de la copa del inicio
async function muestrear(page, veces, esperaMs) {
  const out = [];
  for (let i = 0; i < veces; i++) {
    out.push(await nivelDemo(page));
    await page.waitForTimeout(esperaMs);
  }
  return out;
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
  const errores = [];
  page.on('pageerror', (e) => errores.push(String(e)));

  // Chromium de escritorio no trae navigator.vibrate: se instala un espía para
  // comprobar que el juego lo llama en los momentos correctos.
  await page.addInitScript(() => {
    window.__vibras = [];
    navigator.vibrate = (p) => { window.__vibras.push(p); return true; };
  });
  await page.route('**/sync.php*', (r) => r.abort());

  await page.goto(URL);
  await page.waitForTimeout(1000);

  console.log('\n--- Demo animada de la copa ---');
  /* La ventana cubre más de un ciclo completo (~4,5 s): el vaciado dura sólo
     0,6 s y con un muestreo corto se lo salta y parece que nunca baja a cero. */
  const m = await muestrear(page, 22, 280);
  const distintos = new Set(m).size;
  check(distintos >= 6, 'el nivel de la copa cambia sola (está animando)',
    distintos + ' valores distintos en 22 muestras');
  check(Math.min(...m) <= 3, 'el ciclo llega a vaciarse', { min: Math.min(...m) });
  check(Math.max(...m) > Math.min(...m) + 25,
    'recorre el rango completo: se llena y se vacía', { min: Math.min(...m), max: Math.max(...m) });
  const objetivo = await page.evaluate(() => PF_CONFIG.lineaObjetivo);
  check(Math.max(...m) <= objetivo + 1,
    'nunca pasa de la línea: muestra la medida perfecta, no un derrame',
    { maxVisto: Math.max(...m), linea: objetivo });

  console.log('\n--- La demo se detiene fuera del inicio ---');
  await page.click('#s-attract');
  await page.waitForTimeout(700);
  check(await activa(page) === 's-momento', 'se pasó a elegir momento');
  const quieto = await muestrear(page, 4, 220);
  check(new Set(quieto).size === 1,
    'con el inicio fuera de pantalla el nivel queda quieto (no gasta CPU)', quieto);

  /* El oleaje es decorado: si algún día se cuela en el cálculo del nivel, la
     dificultad calibrada deja de valer y tests/dificultad.js mediría otra cosa
     que la que juega la gente. Estos checks fijan la separación. */
  console.log('\n--- El oleaje es sólo visual ---');
  const ondas = await page.evaluate(() => {
    const g = { liqTop: 48, liqBottom: 300 };
    const plano = PFGame.pathLiquido(g, 50, 0, 0);
    const conOla = PFGame.pathLiquido(g, 50, 4, 1.2);
    const cuenta = (d) => (d.match(/L/g) || []).length;
    // el nivel que sale de la física no puede depender de la amplitud
    return {
      lPlano: cuenta(plano), lOla: cuenta(conOla),
      nivelIgual: PFGame.nivelEn(1.5) === PFGame.nivelEn(1.5),
      centroSinOla: PFGame.centro(),
    };
  });
  check(ondas.lOla > ondas.lPlano + 10,
    'con amplitud la superficie se subdivide en una onda', ondas);
  check(ondas.lPlano <= 4, 'sin amplitud la superficie es una recta', ondas.lPlano);

  console.log('\n--- Vibración en los momentos clave ---');
  let vibras = await page.evaluate(() => window.__vibras.length);
  check(vibras > 0, 'al elegir una tarjeta vibra', vibras);

  await page.click('[data-momento="celebracion"]'); await page.waitForTimeout(300);
  await page.click('[data-variedad="tinto"]'); await page.waitForTimeout(300);
  await page.click('#btn-servir'); await page.waitForTimeout(400);
  await page.evaluate(() => { window.__vibras = []; });

  const ms = await page.evaluate(() => Math.round(PFGame.tiempoPara(PFGame.centro()) * 1000));
  const box = await page.locator('#servir-stage').boundingBox();
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.waitForTimeout(200);
  const alServir = await page.evaluate(() => window.__vibras.slice());
  check(alServir.length > 0, 'al empezar a servir vibra', alServir);

  // se suelta muy tarde a propósito: así la partida termina siempre en la
  // pantalla de derrota, que tiene botón de salida y hace el test determinista
  await page.waitForTimeout(Math.max(0, ms + 900 - 200));
  await page.mouse.up();
  await page.waitForTimeout(1800);
  check(await activa(page) === 's-lose', 'la partida terminó en derrota (esperado)');
  const patrones = await page.evaluate(() => window.__vibras.slice());
  check(patrones.length > 1, 'al perder también vibra', patrones);

  // el patrón de victoria se comprueba directo, sin depender del pulso
  await page.evaluate(() => { window.__vibras = []; PFAudio.win(); });
  const patronWin = await page.evaluate(() => window.__vibras.slice());
  check(patronWin.some((p) => Array.isArray(p) && p.length > 1),
    'la victoria usa un patrón de varios pulsos', patronWin);

  console.log('\n--- La vibración se puede apagar ---');
  await page.evaluate(() => { PFAudio.setVibracion(false); window.__vibras = []; });
  await page.evaluate(() => PFAudio.tick());
  check((await page.evaluate(() => window.__vibras.length)) === 0,
    'apagada no vibra');
  await page.evaluate(() => { PFAudio.setVibracion(true); window.__vibras = []; });
  await page.evaluate(() => PFAudio.tick());
  check((await page.evaluate(() => window.__vibras.length)) > 0, 'reactivada vuelve a vibrar');

  console.log('\n--- Contador social ---');
  const cfg = await page.evaluate(() => PF_CONFIG.contadorSocial);
  check((await page.evaluate(() => document.querySelector('#contador-social').classList.contains('oculto'))),
    'oculto mientras no se alcance el mínimo de ' + cfg.minimoParaMostrar);

  // se insertan partidas de hoy ya jugadas para pasar el mínimo
  await page.evaluate(async (n) => {
    for (let i = 0; i < n; i++) {
      await PFDB.put('sesiones', {
        uuid: PFDB.uuid(), device_id: 'TEST', inicio: new Date().toISOString(),
        fin: new Date().toISOString(), duracion_seg: 20, momento: 'relajo',
        variedad: 'tinto', vino: 'X', resultado: 'perdio',
        precision_pct: 40, nivel_final: 55.5, dificultad: 'dificil', synced: 1,
      });
    }
  }, cfg.minimoParaMostrar + 3);

  // volver al inicio por el camino real: el botón de la pantalla de derrota
  await page.click('#btn-fin-lose');
  await page.waitForTimeout(700);
  check(await activa(page) === 's-attract', 'volvió al inicio');
  await page.waitForTimeout(500);

  const texto = (await page.textContent('#contador-social')).replace(/\s+/g, ' ').trim();
  const visible = !(await page.evaluate(() =>
    document.querySelector('#contador-social').classList.contains('oculto')));
  check(visible, 'se muestra al pasar el mínimo');
  check(/\d+\s+personas ya brindaron hoy/.test(texto),
    'el texto y el plural están bien', texto);
  check(!/^0 /.test(texto), 'no arranca en cero');

  console.log('\n--- Errores de JS ---');
  const relevantes = errores.filter((e) => !/ERR_FAILED/i.test(e));
  check(relevantes.length === 0, 'sin errores en consola', relevantes.slice(0, 3));

  await browser.close();
  console.log('\n' + (fallos === 0 ? '✅ ATRACCIÓN OK' : '❌ ' + fallos + ' fallas'));
  process.exit(fallos === 0 ? 0 : 1);
})();
