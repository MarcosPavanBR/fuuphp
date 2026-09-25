// Sessão do ENTREGADOR, separada da do cliente e da loja: outra chave no
// localStorage, outro estado reativo. O mesmo aparelho pode ter as três sem
// um login derrubar o outro -- e no caso dele isso importa mais, porque o
// mock diz que "entregador troca de celular com frequência".
import { api, registerSession, setSessionTokens, endSession } from '../services/api.js';

const TOKEN_KEY = 'fuu_courier_token';

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
registerSession('courier', {
  accessKey: TOKEN_KEY,
  refreshKey: 'fuu_courier_refresh',
  onChange: (token) => {
    accessToken = token;
  },
});

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
  setSessionTokens('courier', data);
  return data;
}

export function courierLogout() {
  endSession('courier');
}
