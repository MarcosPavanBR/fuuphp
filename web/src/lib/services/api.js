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

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message ?? `Erro na API (HTTP ${status})`);
    this.status = status;
    this.code = body?.code;
    this.fields = body?.fields;
    this.body = body;
  }
}

async function request(method, path, { body, form, auth = false, query, token, headers: extraHeaders } = {}) {
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
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}

export const api = {
  get: (path, opts) => request('GET', path, opts),
  post: (path, opts) => request('POST', path, opts),
};
