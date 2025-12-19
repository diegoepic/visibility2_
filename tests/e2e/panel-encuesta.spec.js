const { test, expect } = require('@playwright/test');

function parseSetCookie(setCookie) {
  if (!setCookie) return null;
  const cookie = setCookie.split(';')[0];
  const [name, value] = cookie.split('=');
  return { name, value };
}

test('Panel Encuesta carga y responde a búsqueda básica', async ({ page, request }) => {
  const resp = await request.get('/visibility2/app/api/test_session.php');
  const cookie = parseSetCookie(resp.headers()['set-cookie']);
  if (cookie) {
    await page.context().addCookies([{ name: cookie.name, value: cookie.value, domain: '127.0.0.1', path: '/' }]);
  }

  await page.goto('/visibility2/portal/modulos/mod_panel_encuesta/panel_encuesta.php');
  await expect(page.getByRole('heading', { name: 'Panel de Encuesta' })).toBeVisible();

  const [dataResp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('panel_encuesta_data.php')),
    page.click('#btnBuscar')
  ]);

  expect(dataResp.status()).toBe(200);
  const json = await dataResp.json();
  expect(json.status).toBe('ok');
});
