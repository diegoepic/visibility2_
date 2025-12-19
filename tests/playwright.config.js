const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 30000,
  use: {
    baseURL: 'http://127.0.0.1:8002',
    headless: true
  },
  webServer: {
    command: 'php -S 127.0.0.1:8002 -t /workspace',
    reuseExistingServer: false,
    timeout: 20000,
    env: {
      V2_TEST_MODE: '1'
    }
  }
});
