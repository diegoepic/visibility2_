  /* Prueba de humo del tótem: recorre el flujo completo en Chromium headless
    y verifica que quede registrado lo que el cliente va a querer reportar.
    Uso:  node tests/smoke.js        (desde pf_totem/)
    Requiere playwright instalado globalmente (npx playwright). */
  const { chromium } = require('playwright');
  const path = require('path');

  const URL = 'file://' + path.resolve(__dirname, '..', 'index.html').replace(/\\/g, '/');
  let fallos = 0;

  function check(cond, msg, extra) {
    console.log((cond ? '  OK   ' : '  FALLA') + ' ' + msg + (extra !== undefined ? '  → ' + JSON.stringify(extra) : ''));
    if (!cond) fallos++;
  }

  // Mantiene presionado sobre la copa el tiempo pedido y suelta
  async function servir(page, ms) {
    const box = await page.locator('#servir-stage').boundingBox();
    const x = box.x + box.width / 2, y = box.y + box.height / 2;
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.waitForTimeout(ms);
    await page.mouse.up();
  }

  const activa = (page) => page.evaluate(() =>
    (document.querySelector('.screen.active') || {}).id);

  /* Con la línea sorteada en cada partida ya no hay un tiempo fijo: hay que
    leer la línea de ESTA partida desde la copa dibujada y resolver la curva
    de llenado (que además acelera). Es lo mismo que hace un jugador que mira
    la pantalla, sólo que sin error humano. */
  const msParaGanar = (page) => page.evaluate(() => ({
    ms: Math.round(PFGame.tiempoPara(PFGame.centro()) * 1000),
    linea: PFGame.linea(),
    centro: Math.round(PFGame.centro() * 10) / 10,
    tol: PF_CONFIG.dificultades[PFGame.dificultad()].tolerancia,
  }));

  const dump = (page) => page.evaluate(() => new Promise((res) => {
    const r = indexedDB.open('pf_totem');
    r.onsuccess = () => {
      const db = r.result;
      const t = db.transaction(['sesiones', 'eventos', 'ganadores'], 'readonly');
      const out = {};
      t.objectStore('sesiones').getAll().onsuccess = (e) => { out.sesiones = e.target.result; };
      t.objectStore('eventos').getAll().onsuccess = (e) => { out.eventos = e.target.result; };
      t.objectStore('ganadores').getAll().onsuccess = (e) => { out.ganadores = e.target.result; };
      t.oncomplete = () => res(out);
    };
  }));

  (async () => {
    const browser = await chromium.launch();
    const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
    const errores = [];
    page.on('pageerror', (e) => errores.push(String(e)));
    page.on('console', (m) => {
      // el sync bloqueado a propósito (ver page.route de abajo) no es un error
      if (m.type() === 'error' && !/ERR_FAILED|Failed to load resource/i.test(m.text())) {
        errores.push('console: ' + m.text());
      }
    });

    /* config.js apunta al servidor de producción. Sin este corte, correr los
      tests llenaría la base real con partidas de mentira. Sólo tests/sync.js
      habla con un endpoint, y es uno simulado. */
    await page.route('**/sync.php*', (r) => r.abort());

    await page.goto(URL);
    await page.waitForTimeout(700);

    console.log('\n--- Arranque ---');
    check(await activa(page) === 's-attract', 'arranca en la pantalla de inicio');

    const nivelCfg = await page.evaluate(() => PFGame.dificultad());
    console.log('  info   dificultad configurada: ' + nivelCfg);

    console.log('\n--- Partida 1: ganar ---');
    await page.click('#s-attract');
    await page.waitForTimeout(500);
    check(await activa(page) === 's-momento', 'inicio → elegir momento');

    await page.click('[data-momento="celebracion"]');
    await page.waitForTimeout(400);
    check(await activa(page) === 's-variedad', 'momento → elegir variedad');

    await page.click('[data-variedad="espumante"]');
    await page.waitForTimeout(400);
    check(await activa(page) === 's-vino', 'variedad → vino recomendado');
    const vino = await page.textContent('#vino-nombre');
    check(!!vino && vino !== '—', 'muestra el vino recomendado', vino);
    const linea = await page.textContent('#vino-linea');
    check(!!linea && linea.trim() !== '', 'muestra la línea del vino (Signature/Reserva/...)', linea);

    // "celebracion|espumante" trae foto real en la matriz (ver config.js): debe
    // mostrarse la botella, no la copa SVG de respaldo
    const vinoVisual = await page.evaluate(() => ({
      fotoVisible: !document.querySelector('#vino-foto-img').classList.contains('oculto'),
      fotoSrc: document.querySelector('#vino-foto-img').getAttribute('src'),
      copaOculta: document.querySelector('#vino-copa').classList.contains('oculto'),
    }));
    check(vinoVisual.fotoVisible && !!vinoVisual.fotoSrc && vinoVisual.copaOculta,
      'con foto real en la matriz se muestra la botella, no la copa dibujada', vinoVisual);

    await page.click('#btn-servir');
    await page.waitForTimeout(400);
    check(await activa(page) === 's-servir', 'vino → servir');
    // la copa del juego debe ser flauta (espumante), no la del attract
    const copaOk = await page.evaluate(() => {
      const svg = document.querySelector('#copa-svg');
      return { liq: !!svg.querySelector('.pf-liq'), bubs: !!svg.querySelector('.pf-bubs'),
              grad: !!svg.querySelector('#gvino_juego') };
    });
    check(copaOk.liq && copaOk.grad, 'la copa del juego tiene sus propios nodos y gradiente', copaOk);
    check(copaOk.bubs, 'el espumante trae burbujas');

    const plan = await msParaGanar(page);
    console.log('  info   línea sorteada ' + plan.linea + '% · centro ' + plan.centro +
      '% · margen ±' + plan.tol + '% · soltar a los ' + plan.ms + ' ms');
    await servir(page, plan.ms);
    await page.waitForTimeout(1600);
    check(await activa(page) === 's-win', 'soltar en el centro de la zona → GANA', await activa(page));
    check(await page.evaluate(() => document.querySelector('#servir-stage').classList.contains('pf-pulso')),
      'soltar la copa deja el pulso táctil aplicado (confirma el toque con el cuerpo, no sólo con sonido)');

    await page.click('#btn-reclamar');
    await page.waitForTimeout(400);
    check(await activa(page) === 's-datos', 'ganar → formulario de datos');

    // escribir con el teclado en pantalla
    await page.click('#inp-nombre');
    for (const c of ['d', 'i', 'e', 'g', 'o']) await page.click(`.osk-key[data-key="${c}"]`);
    const nombre = await page.inputValue('#inp-nombre');
    check(nombre === 'Diego', 'el teclado escribe con mayúscula inicial', nombre);

    await page.click('#inp-email');
    for (const c of ['d', '@', 'm', '.', 'c', 'l']) await page.click(`.osk-key[data-key="${c}"]`);
    const email = await page.inputValue('#inp-email');
    check(email === 'd@m.cl', 'el teclado de email escribe @ y punto', email);

    await page.click('#btn-enviar-datos');
    await page.waitForTimeout(300);
    check((await page.textContent('#datos-error')).includes('aceptar'),
      'sin marcar consentimiento no deja continuar');

    await page.click('#chk-consent');
    await page.click('#btn-enviar-datos');
    await page.waitForTimeout(700);
    check(await activa(page) === 's-premio', 'datos válidos → pantalla de premio');
    const qr = await page.evaluate(() => {
      const s = document.querySelector('#premio-qr svg');
      return { hay: !!s, codigo: document.querySelector('#premio-codigo').textContent };
    });
    check(qr.hay, 'el QR se generó offline', qr.codigo);

    // la bajada del premio se personaliza con el vino que ganó (ver
    // ultimoVinoGanado en game.js): debe mencionar el mismo nombre que se
    // mostró en la pantalla "Tu vino ideal", no el texto genérico de siempre
    const bajada = await page.textContent('#premio-bajada');
    check(bajada.includes(vino), 'el premio menciona el vino real que ganó', { vino, bajada });

    await page.click('#btn-fin-premio');
    await page.waitForTimeout(500);
    check(await activa(page) === 's-attract', 'premio → vuelve al inicio');

    console.log('\n--- Partida 2: perder (pasarse) ---');
    await page.click('#s-attract');
    await page.waitForTimeout(400);
    await page.click('[data-momento="relajo"]');
    await page.waitForTimeout(300);
    await page.click('[data-variedad="tinto"]');
    await page.waitForTimeout(300);
    await page.click('#btn-servir');
    await page.waitForTimeout(400);
    const plan2 = await msParaGanar(page);
    await servir(page, plan2.ms + 700);   // se pasa claramente
    await page.waitForTimeout(1600);
    check(await activa(page) === 's-lose', 'pasarse de la línea → PIERDE', await activa(page));
    const detalle = (await page.textContent('#lose-detalle')).replace(/\s+/g, ' ').trim();
    check(/(pasaste por|faltó por|borde)/i.test(detalle) && /margen para ganar/i.test(detalle),
      'al perder se muestra por cuánto falló y cuál era el margen', detalle);

    /* La copa del resultado debe mostrar la zona ganadora aunque el nivel de
      dificultad la oculte durante el juego: ahí deja de ser una ayuda y pasa a
      ser la explicación de por qué se perdió. */
    const copaRes = await page.evaluate(() => {
      const svg = document.querySelector('#lose-copa');
      if (!svg) return null;
      return {
        vino: !!svg.querySelector('.pf-liq'),
        banda: !!svg.querySelector('rect[fill^="url(#gbanda"]'),
        marca: !!svg.querySelector('line[stroke-dasharray]'),
        recortada: svg.getAttribute('viewBox') !== '0 0 300 470',
      };
    });
    check(copaRes && copaRes.vino && copaRes.marca,
      'la copa del resultado marca hasta dónde llegó el vino', copaRes);
    check(copaRes && copaRes.banda,
      'muestra la zona ganadora aunque al jugar estuviera oculta');
    check(copaRes && copaRes.recortada,
      'el viewBox se recorta al bowl para que la diferencia se note');
    const leyenda = (await page.textContent('.copa-leyenda')).replace(/\s+/g, ' ').trim();
    check(/serviste/i.test(leyenda) && /medida perfecta/i.test(leyenda),
      'hay leyenda que explica las dos líneas', leyenda);

    await page.click('#btn-fin-lose');
    await page.waitForTimeout(400);

    console.log('\n--- Partida 3: toque accidental permite reintentar ---');
    await page.click('#s-attract');
    await page.waitForTimeout(400);
    await page.click('[data-momento="encuentro"]');
    await page.waitForTimeout(300);
    await page.click('[data-variedad="blanco"]');
    await page.waitForTimeout(300);
    await page.click('#btn-servir');
    await page.waitForTimeout(400);
    await servir(page, 60);                      // toque muy corto
    await page.waitForTimeout(500);
    check(await activa(page) === 's-servir', 'un toque accidental NO cuenta como derrota');
    const plan3 = await msParaGanar(page);
    await servir(page, plan3.ms);
    await page.waitForTimeout(1600);
    check(['s-win', 's-lose'].includes(await activa(page)), 'tras reintentar sí resuelve la partida');

    console.log('\n--- Datos registrados ---');
    const data = await dump(page);
    const s = data.sesiones;
    check(s.length === 3, 'quedaron 3 partidas registradas', s.length);
    check(s.every((x) => x.momento && x.variedad && x.vino), 'todas guardan momento, variedad y vino');
    check(s.filter((x) => x.resultado === 'gano').length >= 1, 'hay al menos una ganada');
    check(s.filter((x) => x.resultado === 'perdio').length >= 1, 'hay al menos una perdida');
    check(s.every((x) => x.fin && x.duracion_seg !== null), 'todas quedaron cerradas con duración');
    check(data.ganadores.length === 1, 'hay 1 ganador con sus datos', data.ganadores.length);
    const g = data.ganadores[0];
    check(g && g.nombre === 'Diego' && g.email === 'd@m.cl' && !!g.codigo && g.consentimiento === 1,
      'el ganador guarda nombre, email, código y consentimiento', g && { n: g.nombre, e: g.email, c: g.codigo });
    check(g && s.some((x) => x.uuid === g.session_uuid), 'el ganador queda ligado a su partida');
    check(data.eventos.length > 25, 'el log de acciones tiene detalle', data.eventos.length);
    const tipos = [...new Set(data.eventos.map((e) => e.tipo))];
    check(['momento_elegido', 'variedad_elegida', 'servida_fin', 'gano', 'perdio', 'codigo_asignado']
      .every((t) => tipos.includes(t)), 'se registran los eventos clave del embudo', tipos.length + ' tipos');
    check(data.eventos.every((e) => e.synced === 0), 'todo queda pendiente de sync (nada se pierde sin red)');

    check(s.every((x) => typeof x.linea_objetivo === 'number'),
      'cada partida guarda la línea que le tocó');

    /* Lo que impide que la gente gane copiando el tiempo del jugador anterior:
      si la línea se repitiera, bastaría con contar los segundos. */
    console.log('\n--- La línea no se puede memorizar ---');
    const lineas = [];
    for (let i = 0; i < 12; i++) {
      await page.evaluate(() => {
        ['#btn-fin-lose', '#btn-fin-premio'].forEach((sel) => {
          const b = document.querySelector(sel);
          if (b && b.closest('.screen').classList.contains('active')) b.click();
        });
      });
      await page.waitForTimeout(200);
      await page.evaluate(() => document.querySelector('#s-attract').click());
      await page.waitForTimeout(250);
      await page.click('[data-momento="relajo"]'); await page.waitForTimeout(150);
      await page.click('[data-variedad="tinto"]'); await page.waitForTimeout(150);
      await page.click('#btn-servir'); await page.waitForTimeout(250);
      lineas.push(await page.evaluate(() => PFGame.linea()));
      await page.evaluate(() => document.querySelector('#s-attract').click());
      await page.waitForTimeout(150);
    }
    const distintas = new Set(lineas).size;
    const rango = { min: Math.min(...lineas), max: Math.max(...lineas) };
    const cfgRango = await page.evaluate(() => PF_CONFIG.lineaAleatoria);
    check(distintas >= 10, 'la línea cambia en cada partida', distintas + ' valores distintos de 12');
    check(rango.min >= cfgRango.min && rango.max <= cfgRango.max,
      'la línea se mantiene dentro del rango configurado', rango);
    check(rango.max - rango.min > 10,
      'el rango sorteado es amplio, no se concentra en un punto', rango);

    console.log('\n--- Errores de JS ---');
    check(errores.length === 0, 'sin errores en consola', errores.slice(0, 4));

    await browser.close();
    console.log('\n' + (fallos === 0 ? '✅ TODO OK' : '❌ ' + fallos + ' fallas'));
    process.exit(fallos === 0 ? 0 : 1);
  })();
