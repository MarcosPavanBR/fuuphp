// Sessão do ADMIN. Diferente da loja e do entregador, admin não tem conta de
// parceiro: entra pelo mesmo OTP do cliente, porque é uma pessoa com conta,
// não um aparelho de balcão. O que muda é o papel no token.
import { api, registerSession, setSessionTokens, endSession, tokenPayload } from '../services/api.js';

const TOKEN_KEY = 'fuu_admin_token';

function read() {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

let accessToken = $state(read());

// Token de 15 min renovado sozinho (services/api.js); sessão morta zera o
// estado e o app volta pro login.
registerSession('admin', {
  accessKey: TOKEN_KEY,
  refreshKey: 'fuu_admin_refresh',
  onChange: (token) => {
    accessToken = token;
  },
});

export function isAdminAuthenticated() {
  return accessToken !== null;
}

export function adminToken() {
  return accessToken;
}

export async function adminRequestCode(phone) {
  return api.post('/auth/otp_request.php', { body: { purpose: 'login', phone } });
}

export async function adminVerify({ phone, code }) {
  const data = await api.post('/auth/otp_verify.php', { body: { purpose: 'login', phone, code } });
  // O papel vem no token; quem barra de verdade é o servidor a cada chamada
  // (require_admin). Esta checagem é só pra não deixar alguém logado numa
  // tela que vai dar 403 em tudo.
  const payload = tokenPayload(data.access_token) ?? {};
  if (payload.role !== 'admin') {
    const err = new Error('Essa conta não é do time da plataforma.');
    err.code = 'not_admin';
    throw err;
  }
  setSessionTokens('admin', data);
  return data;
}

export function adminLogout() {
  endSession('admin');
}
