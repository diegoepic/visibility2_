import { test, expect } from '@playwright/test';

test('arranca navegación y muestra HUD', async ({ page }) => {
  await page.goto('http://localhost:5173'); // vite dev
  await expect(page.locator('text=ETA')).toBeVisible();
  await expect(page.locator('text=Recentrar')).toBeVisible();
});
