// Sessão do ENTREGADOR, separada da do cliente e da loja: outra chave no
// localStorage, outro estado reativo. O mesmo aparelho pode ter as três sem
// um login derrubar o outro -- e no caso dele isso importa mais, porque o
// mock diz que "entregador troca de celular com frequência".
import { api } from '../services/api.js';

const TOKEN_KEY = 'fuu_courier_token';

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
    // aba privada / storage bloqueado: a sessão só não sobrevive ao reload
  }
}

let accessToken = $state(read());

export function isCourierAuthenticated() {
  return accessToken !== null;
}

export function courierToken() {
  return accessToken;
}

export async function courierLogin({ cpf, code, deviceId }) {
  const data = await api.post('/auth/partner_login.php', {
    body: { kind: 'courier', login_code: cpf, secret: code, device_id: deviceId },
  });
  accessToken = data.access_token;
  write(data.access_token);
  return data;
}

export function courierLogout() {
  accessToken = null;
  write(null);
}
