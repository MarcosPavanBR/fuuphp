// Sessão do usuário: um módulo .svelte.js pra poder usar runes fora de
// componente (Svelte 5). Login de verdade contra o módulo identity já
// existente (api/v1/auth/otp_*.php) -- não é mock.
//
// As telas da Fase 10 (AuthFlow -> LoginScreen, OtpScreen, SignupScreen)
// usam estas funções; quem precisa de usuário logado (pedidos, perfil,
// carrinho) monta o AuthFlow no lugar do conteúdo.
import { api, getStoredToken, storeToken } from '../services/api.js';

let accessToken = $state(getStoredToken());
let user = $state(null);
// Conta recém-criada pelo OTP ainda não passou pelo cadastro da tela 10.3
// (CPF, e-mail, consentimentos). É estado de SESSÃO, não de tela: quem
// decide se o cadastro aparece é este sinalizador, e não o instante em que
// o token chegou -- senão a aba troca o fluxo pela tela dela no meio do
// caminho e o cadastro nunca acontece.
let pendingSignup = $state(false);

export function isAuthenticated() {
  return accessToken !== null;
}

export function currentUser() {
  return user;
}

export function currentToken() {
  return accessToken;
}

export function signupPending() {
  return pendingSignup;
}

export function finishSignup() {
  pendingSignup = false;
}

export async function requestOtp({ phone, email, fullName, purpose, channel }) {
  return api.post('/auth/otp_request.php', {
    body: { purpose, phone, email, full_name: fullName, channel },
  });
}

export async function verifyOtp({ phone, email, code, purpose }) {
  const data = await api.post('/auth/otp_verify.php', {
    body: { purpose, phone, email, code },
  });
  accessToken = data.access_token;
  storeToken(data.access_token);
  pendingSignup = purpose === 'signup';
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
  pendingSignup = false;
  storeToken(null);
}
