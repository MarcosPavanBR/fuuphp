// Sessão do ADMIN. Diferente da loja e do entregador, admin não tem conta de
// parceiro: entra pelo mesmo OTP do cliente, porque é uma pessoa com conta,
// não um aparelho de balcão. O que muda é o papel no token.
import { api } from './api.js';

const TOKEN_KEY = 'fuu_admin_token';

function read() {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

function write(value) {
  try {
    if (value) localStorage.setItem(TOKEN_KEY, value);
    else localStorage.removeItem(TOKEN_KEY);
  } catch {
    // storage bloqueado: a sessão só não sobrevive ao reload
  }
}

let accessToken = $state(read());

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
  const payload = JSON.parse(atob(data.access_token.split('.')[1]));
  if (payload.role !== 'admin') {
    const err = new Error('Essa conta não é do time da plataforma.');
    err.code = 'not_admin';
    throw err;
  }
  accessToken = data.access_token;
  write(data.access_token);
  return data;
}

export function adminLogout() {
  accessToken = null;
  write(null);
}
