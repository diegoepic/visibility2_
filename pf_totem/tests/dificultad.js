/* Mide la dificultad REAL usando las funciones del juego (PFGame), no una
   reimplementación: si alguien cambia la física en game.js, este test lo
   detecta. Simula jugadores con tiempos de reacción humanos.
   Uso: node tests/dificultad.js   (desde pf_totem/) */
const { chromium } = require('playwright');
const path = require('path');

const URL = 'file://' + path.resolve(__dirname, '..', 'index.html').replace(/\\/g, '/');

// Tasas esperadas por nivel (de la calibración). Tolerancia ±4 puntos.
const ESPERADO = { facil: 78, normal: 54, dificil: 38, experta: 27 };
let fallos = 0;

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  // no tocar la base de produccion desde los tests
  await page.route('**/sync.php*', (r) => r.abort());

  await page.goto(URL);
  await page.waitForTimeout(800);

  const filas = await page.evaluate((niveles) => {
    function gauss() {
      let u = 0, v = 0;
      while (!u) u = Math.random();
      while (!v) v = Math.random();
      return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
    }
    // reparto de habilidad: error de timing en segundos
    const perfiles = [
      { sesgo: 0.05, sd: 0.08, peso: 0.30 },
      { sesgo: 0.10, sd: 0.13, peso: 0.45 },
      { sesgo: 0.18, sd: 0.22, peso: 0.25 },
    ];
    const jugador = () => {
      let r = Math.random(), acc = 0;
      for (const p of perfiles) { acc += p.peso; if (r <= acc) return p; }
      return perfiles[2];
    };

    const out = [];
    for (const nivel of niveles) {
      // se apunta la config al nivel a medir y se usan las funciones del juego
      PF_CONFIG.dificultad = nivel;
      const d = PF_CONFIG.dificultades[nivel];
      const rango = PF_CONFIG.lineaAleatoria;
      let gana = 0, paso = 0, falto = 0, derrame = 0, casi = 0, n = 0, sumT = 0;
      const N = 60000;
      for (let i = 0; i < N; i++) {
        const linea = rango.min + Math.random() * (rango.max - rango.min);
        PF_CONFIG.lineaObjetivo = linea;
        // ⚠ se usan las funciones REALES del juego
        const tLinea = PFGame.tiempoPara(linea);
        const centro = Math.min(96, PFGame.nivelEn(tLinea + (d.compensacion || 0)));
        const p = jugador();
        let t = tLinea + p.sesgo + gauss() * p.sd;
        if (t < 0) t = 0;
        const nivelFinal = PFGame.nivelEn(t);
        n++; sumT += tLinea;
        if (nivelFinal >= 100) { derrame++; continue; }
        const dd = nivelFinal - centro;
        if (Math.abs(dd) <= d.tolerancia) gana++;
        else {
          if (dd > 0) paso++; else falto++;
          if (Math.abs(dd) <= d.tolerancia * 2.5) casi++;
        }
      }
      out.push({
        nivel, g: 100 * gana / n, p: 100 * paso / n, c: 100 * falto / n,
        d: 100 * derrame / n, casi: 100 * casi / n, t: sumT / n,
        tol: d.tolerancia, banda: d.mostrarBanda !== false,
      });
    }
    return out;
  }, Object.keys(ESPERADO));

  console.log('\nDificultad real medida con las funciones del juego (60.000 partidas por nivel)\n');
  console.log('  nivel      margen  banda   GANA    se pasó  le faltó  derramó  "casi"  seg');
  for (const f of filas) {
    console.log('  ' + f.nivel.padEnd(11) + ('±' + f.tol + '%').padEnd(8) +
      (f.banda ? 'sí   ' : 'no   ').padEnd(8) +
      f.g.toFixed(1).padStart(5) + '%  ' + f.p.toFixed(1).padStart(6) + '%  ' +
      f.c.toFixed(1).padStart(7) + '%  ' + f.d.toFixed(1).padStart(6) + '%  ' +
      f.casi.toFixed(0).padStart(5) + '%  ' + f.t.toFixed(2));
  }

  console.log('');
  for (const f of filas) {
    const esp = ESPERADO[f.nivel];
    const ok = Math.abs(f.g - esp) <= 4;
    console.log((ok ? '  OK   ' : '  FALLA') + ' "' + f.nivel + '" da ' + f.g.toFixed(1) +
      '% (esperado ~' + esp + '%)');
    if (!ok) fallos++;
    // los fallos deben repartirse parejo: si se cargan a un lado, se siente injusto
    const desbalance = Math.abs((f.p + f.d) - f.c);
    const eq = desbalance <= 8;
    console.log((eq ? '  OK   ' : '  FALLA') + '   fallos repartidos parejo en "' + f.nivel +
      '" (diferencia ' + desbalance.toFixed(1) + ' puntos)');
    if (!eq) fallos++;
  }

  await browser.close();
  console.log('\n' + (fallos === 0 ? '✅ DIFICULTAD OK' : '❌ ' + fallos + ' fallas'));
  process.exit(fallos === 0 ? 0 : 1);
})();
