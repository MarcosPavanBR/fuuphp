// Sessão da LOJA, separada da sessão do cliente de propósito: chave
// diferente no localStorage, estado reativo próprio. O mesmo navegador
// pode ter as duas (o dono testando o app dele enquanto o painel está
// aberto noutra aba) sem um login derrubar o outro.
//
// lib/api.js aceita `token` explícito por chamada -- é assim que o painel
// usa o token da loja sem mexer no getStoredToken() do cliente.
import { api, registerSession, setSessionTokens, endSession, tokenPayload } from '../services/api.js';

const TOKEN_KEY = 'fuu_staff_token';
const RESTAURANT_KEY = 'fuu_staff_restaurant';

function read(key) {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function write(key, value) {
  try {
    if (value) localStorage.setItem(key, value);
    else localStorage.removeItem(key);
  } catch {
    // aba privada / storage bloqueado: a sessão só não sobrevive a um
    // reload, não é motivo pra travar o painel.
  }
}

let accessToken = $state(read(TOKEN_KEY));
let restaurantId = $state(read(RESTAURANT_KEY));
let restaurantName = $state(null);

// O tablet da cozinha fica logado o turno inteiro: o token de 15 min é
// renovado sozinho (services/api.js). Sessão morta (suporte liberou troca
// de aparelho, conta bloqueada) zera o estado e o painel volta pro login.
registerSession('staff', {
  accessKey: TOKEN_KEY,
  refreshKey: 'fuu_staff_refresh',
  onChange: (token) => {
    accessToken = token;
    if (token === null) {
      restaurantId = null;
      restaurantName = null;
      write(RESTAURANT_KEY, null);
    }
  },
});

export function isStaffAuthenticated() {
  return accessToken !== null;
}

export function staffToken() {
  return accessToken;
}

export function staffRestaurantId() {
  return restaurantId;
}

export function staffRestaurantName() {
  return restaurantName;
}

export async function staffLogin({ cnpj, secret, deviceId }) {
  const data = await api.post('/auth/partner_login.php', {
    body: { kind: 'restaurant', login_code: cnpj, secret, device_id: deviceId },
  });
  setSessionTokens('staff', data);

  // O restaurant_id vem dentro do JWT (claim extra de partner_login), não
  // no corpo -- decodifica só o payload, sem validar assinatura: quem
  // valida é o servidor a cada chamada; aqui é só pra saber que loja
  // mostrar no cabeçalho.
  restaurantId = tokenPayload(data.access_token)?.restaurant_id ?? null;
  write(RESTAURANT_KEY, restaurantId);

  await loadRestaurant();
  return data;
}

export async function loadRestaurant() {
  if (!restaurantId) return null;
  try {
    const data = await api.get('/restaurants/show.php', { query: { id: restaurantId } });
    restaurantName = data.restaurant.name;
    return data.restaurant;
  } catch {
    return null;
  }
}

export function staffLogout() {
  endSession('staff');
}
