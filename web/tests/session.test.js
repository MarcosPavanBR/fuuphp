// Renovação da sessão (web/src/lib/services/api.js) -- a regressão que
// passou despercebida até a auditoria de 25/09/2026 (COE-01): o token de
// 15 min vencia e nenhum app renovava. Roda no Node, sem navegador e sem
// biblioteca nova (node:test): `npm test` em web/.
import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';

// ── um navegador de mentira, só o que api.js usa ─────────────────────────
const store = new Map();
globalThis.localStorage = {
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: (k) => store.delete(k),
};
globalThis.window = { location: { origin: 'http://app.test' }, addEventListener() {} };
globalThis.document = { visibilityState: 'visible', addEventListener() {} };
Object.defineProperty(globalThis, 'navigator', { value: {}, configurable: true }); // sem Web Locks: caminho sem trava entre abas

let calls = [];
let routes = {};
globalThis.fetch = async (url, init = {}) => {
  const path = new URL(url).pathname.replace('/api/v1', '');
  const body = init.body ? JSON.parse(init.body) : undefined;
  calls.push({ path, auth: init.headers?.Authorization ?? null, body });
  const handler = routes[path];
  const [status, data] = handler ? await handler({ auth: init.headers?.Authorization ?? null, body }) : [404, {}];
  return { ok: status >= 200 && status < 300, status, json: async () => data };
};

// JWT só com o payload que importa (o servidor é quem valida assinatura).
const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
const jwt = (payload) => `${b64({ alg: 'HS256' })}.${b64(payload)}.sig`;
const now = () => Math.floor(Date.now() / 1000);

const api = await import('../src/lib/services/api.js');

let changes = [];
beforeEach(() => {
  store.clear();
  calls = [];
  routes = {};
  changes = [];
  api.registerSession('staff', { accessKey: 'acc', refreshKey: 'ref', onChange: (t) => changes.push(t) });
});
afterEach(() => api.setSessionTokens('staff', null)); // limpa o timer da renovação agendada

test('tokenPayload lê base64url', () => {
  assert.deepEqual(api.tokenPayload(jwt({ sub: 'u1', restaurant_id: 'r-_1' })), { sub: 'u1', restaurant_id: 'r-_1' });
  assert.equal(api.tokenPayload('lixo'), null);
});

test('login grava o par e avisa o módulo de sessão', () => {
  const access = jwt({ sub: 'u1', role: 'restaurant_staff', exp: now() + 900 });
  api.setSessionTokens('staff', { access_token: access, refresh_token: 'R1' });
  assert.equal(store.get('acc'), access);
  assert.equal(store.get('ref'), 'R1');
  assert.deepEqual(changes, [access]);
});

test('401 invalid_token: renova UMA vez e repete a chamada com o token novo', async () => {
  const old = jwt({ sub: 'u1', role: 'restaurant_staff', exp: now() - 10 });
  const fresh = jwt({ sub: 'u1', role: 'restaurant_staff', exp: now() + 900 });
  api.setSessionTokens('staff', { access_token: old, refresh_token: 'R1' });
  routes['/auth/refresh.php'] = ({ body }) => [200, { access_token: fresh, refresh_token: body.refresh_token === 'R1' ? 'R2' : 'X' }];
  routes['/restaurants/orders.php'] = ({ auth }) =>
    auth === `Bearer ${fresh}` ? [200, { orders: [1] }] : [401, { code: 'invalid_token' }];

  const data = await api.api.get('/restaurants/orders.php', { token: old });
  assert.deepEqual(data, { orders: [1] });
  assert.equal(calls.filter((c) => c.path === '/auth/refresh.php').length, 1);
  assert.equal(store.get('ref'), 'R2', 'o refresh girou');
  assert.equal(store.get('acc'), fresh);
});

test('várias chamadas vencidas ao mesmo tempo: uma renovação só (o reuso derrubaria a sessão)', async () => {
  const old = jwt({ sub: 'u1', role: 'restaurant_staff', exp: now() - 10 });
  const fresh = jwt({ sub: 'u1', role: 'restaurant_staff', exp: now() + 900 });
  api.setSessionTokens('staff', { access_token: old, refresh_token: 'R1' });
  routes['/auth/refresh.php'] = async () => {
    await new Promise((r) => setTimeout(r, 20));
    return [200, { access_token: fresh, refresh_token: 'R2' }];
  };
  routes['/x/y.php'] = ({ auth }) => (auth === `Bearer ${fresh}` ? [200, { ok: 1 }] : [401, { code: 'invalid_token' }]);

  const results = await Promise.all([1, 2, 3, 4].map(() => api.api.get('/x/y.php', { token: old })));
  assert.equal(results.length, 4);
  assert.equal(calls.filter((c) => c.path === '/auth/refresh.php').length, 1);
});

test('refresh recusado (sessão revogada): limpa a sessão e a chamada falha com 401', async () => {
  const old = jwt({ sub: 'u1', role: 'restaurant_staff', exp: now() - 10 });
  api.setSessionTokens('staff', { access_token: old, refresh_token: 'R1' });
  routes['/auth/refresh.php'] = () => [401, { code: 'refresh_reused' }];
  routes['/x/y.php'] = () => [401, { code: 'invalid_token' }];

  await assert.rejects(api.api.get('/x/y.php', { token: old }), (e) => e.status === 401);
  assert.equal(store.has('acc'), false);
  assert.equal(store.has('ref'), false);
  assert.equal(changes.at(-1), null, 'o app é avisado pra voltar ao login');
});

test('erro de servidor no refresh (5xx) NÃO derruba a sessão: tenta de novo depois', async () => {
  const old = jwt({ sub: 'u1', role: 'restaurant_staff', exp: now() - 10 });
  api.setSessionTokens('staff', { access_token: old, refresh_token: 'R1' });
  routes['/auth/refresh.php'] = () => [503, {}];
  routes['/x/y.php'] = () => [401, { code: 'invalid_token' }];

  await assert.rejects(api.api.get('/x/y.php', { token: old }));
  assert.equal(store.get('ref'), 'R1', 'o refresh continua guardado');
});

test('401 que não é de token vencido não dispara renovação', async () => {
  routes['/x/y.php'] = () => [401, { code: 'unauthorized' }];
  await assert.rejects(api.api.get('/x/y.php', { token: 'qualquer' }));
  assert.equal(calls.filter((c) => c.path === '/auth/refresh.php').length, 0);
});

test('sair revoga o refresh no servidor e limpa o aparelho', async () => {
  api.setSessionTokens('staff', { access_token: jwt({ sub: 'u1', exp: now() + 900 }), refresh_token: 'R9' });
  routes['/auth/logout.php'] = () => [204, {}];
  api.endSession('staff');
  await new Promise((r) => setImmediate(r));
  const logout = calls.find((c) => c.path === '/auth/logout.php');
  assert.deepEqual(logout?.body, { refresh_token: 'R9' });
  assert.equal(store.has('acc'), false);
  assert.equal(store.has('ref'), false);
});
