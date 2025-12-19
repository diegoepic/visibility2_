const fs = require('fs');
const path = require('path');
const vm = require('vm');

function loadScript(relPath) {
  const code = fs.readFileSync(path.join(__dirname, '..', '..', relPath), 'utf8');
  vm.runInThisContext(code, { filename: relPath });
}

beforeAll(() => {
  loadScript('app/assets/js/db.js');
  loadScript('app/assets/js/offline-queue.js');
});

test('classifyFailure marks auth blocks and HTML responses', () => {
  const { classifyFailure } = window.Queue._test;
  const authFail = classifyFailure({ response: { status: 401 }, parsed: { isHtml: false }, error: null });
  expect(authFail.blocked).toBe('auth');
  const htmlFail = classifyFailure({ response: { status: 200 }, parsed: { isHtml: true }, error: null });
  expect(htmlFail.blocked).toBe('auth');
});

test('classifyFailure marks csrf blocks', () => {
  const { classifyFailure } = window.Queue._test;
  const csrfFail = classifyFailure({ response: { status: 419 }, parsed: { isHtml: false }, error: null });
  expect(csrfFail.blocked).toBe('csrf');
});

test('computeBackoff uses jitter and caps', () => {
  const { computeBackoff } = window.Queue._test;
  const val = computeBackoff(1);
  expect(val).toBeGreaterThanOrEqual(2000);
  expect(val).toBeLessThanOrEqual(5 * 60 * 1000 + 1000);
});

test('recoverStaleRunning resets stale running jobs', async () => {
  const { recoverStaleRunning } = window.Queue._test;
  const old = Date.now() - 10 * 60 * 1000;
  await window.AppDB.add({
    id: 'job-stale',
    status: 'running',
    startedAt: old,
    url: '/visibility2/app/create_visita_pruebas.php'
  });
  await recoverStaleRunning();
  const job = await window.AppDB.get('job-stale');
  expect(job.status).toBe('queued');
  expect(job.lastError.code).toBe('STALE_RUNNING');
});

test('drain uses a mutex to avoid parallel runs', async () => {
  const originalFetch = global.fetch;
  global.fetch = () => new Promise(resolve => setTimeout(() => resolve({
    ok: true,
    status: 200,
    headers: { get: () => 'application/json' },
    text: async () => JSON.stringify({ ok: true, status: 'ok' })
  }), 50));

  const p1 = window.Queue.drain();
  const p2 = window.Queue.drain();
  expect(window.Queue._test.isDraining()).toBe(true);
  expect(p2).toBe(p1);
  await p1;
  global.fetch = originalFetch;
});
