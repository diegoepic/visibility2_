const { test, expect } = require('@playwright/test');

function parseSetCookie(setCookie) {
  if (!setCookie) return null;
  const cookie = setCookie.split(';')[0];
  const [name, value] = cookie.split('=');
  return { name, value };
}

test('Reagendados -> Actualizar -> Programados -> mapa', async ({ page, request }) => {
  const resp = await request.get('/visibility2/app/api/test_session.php');
  const cookie = parseSetCookie(resp.headers()['set-cookie']);
  if (cookie) {
    await page.context().addCookies([{ name: cookie.name, value: cookie.value, domain: '127.0.0.1', path: '/' }]);
  }

  await page.goto('/visibility2/app/index_pruebas.php');
  await page.click('#btnVerReagendados');

  await page.click('#btnActualizar');
  await page.waitForLoadState('networkidle');

  const mode = await page.evaluate(() => window.modoLocal);
  expect(mode).toBe('prog');

  await page.click('button:has-text("Ver Mapa")');
  await page.waitForTimeout(500);

  const visibleMarkers = await page.evaluate(() => {
    const markers = window.markersProg || {};
    return Object.values(markers).filter(m => m.marker && m.marker.getMap && m.marker.getMap()).length;
  });
  expect(visibleMarkers).toBeGreaterThan(0);
});
