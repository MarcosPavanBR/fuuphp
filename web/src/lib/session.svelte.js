// Sessão do usuário: um módulo .svelte.js pra poder usar runes fora de
// componente (Svelte 5). Login de verdade contra o módulo identity já
// existente (api/v1/auth/otp_*.php) -- não é mock.
//
// A Fase 10 (login com OTP e cadastro com LGPD) ainda não tem tela
// própria. As telas da Fase 2 que precisam de usuário logado (pedidos,
// perfil, fidelidade) usam QuickLogin.svelte -- um formulário mínimo com a
// MESMA chamada de API que a tela de verdade vai usar depois, sem o design
// completo da Fase 10.
import { api, getStoredToken, storeToken } from './api.js';

let accessToken = $state(getStoredToken());
let user = $state(null);

export function isAuthenticated() {
  return accessToken !== null;
}

export function currentUser() {
  return user;
}

export function currentToken() {
  return accessToken;
}

export async function requestOtp({ phone, email, fullName, purpose }) {
  return api.post('/auth/otp_request.php', {
    body: { purpose, phone, email, full_name: fullName },
  });
}

export async function verifyOtp({ phone, email, code, purpose }) {
  const data = await api.post('/auth/otp_verify.php', {
    body: { purpose, phone, email, code },
  });
  accessToken = data.access_token;
  storeToken(data.access_token);
  await loadProfile();
  return data;
}

export async function loadProfile() {
  if (!accessToken) return null;
  const data = await api.get('/profile/show.php', { auth: true });
  user = data.user;
  return data;
}

export function logout() {
  accessToken = null;
  user = null;
  storeToken(null);
}
