// Cliente HTTP fino para a API PHP. Sem lib nova: fetch nativo.
export const BASE = import.meta.env.VITE_API_BASE ?? '/api/v1';

const TOKEN_KEY = 'fuu_access_token';

export function getStoredToken() {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

export function storeToken(token) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token);
    else localStorage.removeItem(TOKEN_KEY);
  } catch {
    // localStorage pode falhar (aba privada, storage bloqueado) -- a sessão
    // simplesmente não sobrevive a um reload, não é motivo pra quebrar a tela.
  }
}

// ── Renovação da sessão ─────────────────────────────────────────────────
// O access token vale 15 min (lib/bootstrap.php); o refresh token, 30 dias.
// Cada app registra a sua sessão ("realm": cliente, loja, entregador,
// admin -- chaves separadas no localStorage). Daqui pra frente:
//   • um minuto antes de vencer, o token é renovado sozinho (e de novo ao
//     voltar do descanso de tela, quando o timer pode ter dormido);
//   • se mesmo assim uma chamada voltar 401 invalid_token, request() renova
//     e repete a chamada uma vez;
//   • refresh recusado (sessão revogada, conta bloqueada) limpa a sessão, e
//     o app volta pro login.
// Sem isso, o tablet da cozinha parava de receber pedido aos 15 minutos.
//
// A rotação é de uso único e reuso revoga a família inteira (sessions.php),
// então duas renovações simultâneas derrubariam a sessão: uma por vez, por
// aba (promessa compartilhada) e entre abas (Web Locks, quando existe), e
// quem chega depois usa o token que a outra aba já gravou.
const REFRESH_EARLY_MS = 60_000;
const REFRESH_RETRY_MS = 30_000;
const realms = new Map();
const inflight = new Map();

function load(key) {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function save(key, value) {
  try {
    if (value) localStorage.setItem(key, value);
    else localStorage.removeItem(key);
  } catch {
    // storage bloqueado: a sessão só não sobrevive ao reload
  }
}

/** Payload do JWT sem validar (quem valida é o servidor); null se ilegível. */
export function tokenPayload(token) {
  try {
    const b64 = token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/');
    return JSON.parse(atob(b64));
  } catch {
    return null;
  }
}

function msUntilRefresh(token) {
  const exp = tokenPayload(token)?.exp;
  return exp ? exp * 1000 - Date.now() - REFRESH_EARLY_MS : 0;
}

function schedule(name, delay = null) {
  const realm = realms.get(name);
  if (!realm) return;
  clearTimeout(realm.timer);
  const access = load(realm.accessKey);
  if (!access || !load(realm.refreshKey)) return;
  realm.timer = setTimeout(
    () => refreshSession(name).catch(() => schedule(name, REFRESH_RETRY_MS)),
    Math.max(0, delay ?? msUntilRefresh(access))
  );
}

/**
 * Registra a sessão de um app. `onChange(accessToken | null)` avisa o módulo
 * de sessão (o estado reativo dele) quando o token é renovado aqui, por
 * outra aba, ou quando a sessão morre.
 */
export function registerSession(name, { accessKey, refreshKey, onChange }) {
  realms.set(name, { accessKey, refreshKey, onChange, timer: null });
  schedule(name);
}

/** Grava o par do login/refresh (ou limpa, com null) e reagenda. */
export function setSessionTokens(name, data) {
  const realm = realms.get(name);
  if (!realm) return;
  save(realm.accessKey, data?.access_token ?? null);
  save(realm.refreshKey, data?.refresh_token ?? null);
  realm.onChange(data?.access_token ?? null);
  if (data) schedule(name);
  else clearTimeout(realm.timer);
}

/**
 * Sair: revoga o refresh no servidor (sem isso ele valeria 30 dias no
 * aparelho) e limpa. Não espera a rede -- sair funciona offline.
 */
export function endSession(name) {
  const realm = realms.get(name);
  if (!realm) return;
  const refresh = load(realm.refreshKey);
  if (refresh) {
    fetch(new URL(BASE + '/auth/logout.php', window.location.origin), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refresh }),
      keepalive: true,
    }).catch(() => {});
  }
  setSessionTokens(name, null);
}

/**
 * Renova a sessão `name`. `failedToken` é o token que acabou de levar 401:
 * se o gravado já é outro, outra aba renovou e é só usar o dela.
 * Devolve o access token novo, ou null se a sessão morreu.
 */
export function refreshSession(name, failedToken = null) {
  if (inflight.has(name)) return inflight.get(name);
  const realm = realms.get(name);
  if (!realm) return Promise.resolve(null);

  const run = async () => {
    const current = load(realm.accessKey);
    if (current && current !== failedToken && msUntilRefresh(current) > 0) {
      realm.onChange(current);
      schedule(name);
      return current;
    }
    const refresh = load(realm.refreshKey);
    if (!refresh) {
      setSessionTokens(name, null);
      return null;
    }
    const res = await fetch(new URL(BASE + '/auth/refresh.php', window.location.origin), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refresh }),
    });
    if (res.ok) {
      const data = await res.json();
      setSessionTokens(name, data);
      return data.access_token;
    }
    if (res.status >= 400 && res.status < 500) {
      // Recusado de verdade (revogada, vencida, bloqueada): acabou.
      setSessionTokens(name, null);
      return null;
    }
    throw new Error(`refresh HTTP ${res.status}`); // 5xx: tenta de novo depois
  };

  const promise = (navigator.locks?.request ? navigator.locks.request(`fuu-refresh-${name}`, run) : run()).finally(
    () => inflight.delete(name)
  );
  inflight.set(name, promise);
  return promise;
}

/** Qual sessão emitiu este token (pelo token gravado, ou pelo mesmo sub+papel). */
function sessionOf(token) {
  const payload = tokenPayload(token);
  for (const [name, realm] of realms) {
    const stored = load(realm.accessKey);
    if (stored === token) return name;
    const other = stored ? tokenPayload(stored) : null;
    if (payload && other && other.sub === payload.sub && other.role === payload.role) return name;
  }
  return null;
}

if (typeof window !== 'undefined') {
  // Outra aba renovou ou saiu: esta acompanha.
  window.addEventListener('storage', (e) => {
    for (const [name, realm] of realms) {
      if (e.key === realm.accessKey) {
        realm.onChange(e.newValue);
        schedule(name);
      }
    }
  });
  // Tela voltou do descanso: o timer pode ter dormido junto.
  const wake = () => {
    if (document.visibilityState !== 'visible') return;
    for (const [name, realm] of realms) {
      const access = load(realm.accessKey);
      if (access && msUntilRefresh(access) <= 0) refreshSession(name).catch(() => schedule(name, REFRESH_RETRY_MS));
    }
  };
  document.addEventListener('visibilitychange', wake);
  window.addEventListener('online', wake);
}

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message ?? `Erro na API (HTTP ${status})`);
    this.status = status;
    this.code = body?.code;
    this.fields = body?.fields;
    this.body = body;
  }
}

async function request(method, path, opts = {}) {
  const { body, form, auth = false, query, token, headers: extraHeaders } = opts;
  const url = new URL(BASE + path, window.location.origin);
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, value);
      }
    }
  }

  // form (multipart, ex. upload_proof.php) não leva Content-Type manual --
  // o navegador escreve o boundary sozinho; JSON leva.
  const headers = form ? {} : { 'Content-Type': 'application/json' };
  Object.assign(headers, extraHeaders ?? {});
  const bearer = token ?? (auth ? getStoredToken() : null);
  if (bearer) headers.Authorization = `Bearer ${bearer}`;

  const res = await fetch(url, {
    method,
    headers,
    body: form ?? (body !== undefined ? JSON.stringify(body) : undefined),
  });

  const data = await res.json().catch(() => ({}));
  // Token vencido: renova e repete uma vez (a renovação agendada cobre o
  // normal; isto é pro aparelho que dormiu no meio).
  if (res.status === 401 && data?.code === 'invalid_token' && bearer && !opts.retried) {
    const name = sessionOf(bearer);
    const fresh = name ? await refreshSession(name, bearer).catch(() => null) : null;
    if (fresh) return request(method, path, { ...opts, token: fresh, retried: true });
  }
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}

export const api = {
  get: (path, opts) => request('GET', path, opts),
  post: (path, opts) => request('POST', path, opts),
};
