// Tela 4.2 — tokenização do cartão no navegador (MercadoPago.js).
//
// "Campos montados pelo SDK com Public Key; o Access Token fica só no PHP."
// O número e o CVV do cartão vão direto do navegador pro Mercado Pago; o
// nosso servidor só recebe o token de uso único que volta. Isso vale pro
// cartão novo e pro salvo -- "pagar com ele ainda exige CVV e gera novo token
// de uso único" (6.2).
//
// Dois modos, decididos pelo servidor (payments/config.php):
//   live  → carrega o SDK oficial (sdk.mercadopago.com/js/v2) uma vez e chama
//           mp.createCardToken(); NÃO validado neste ambiente, que não
//           alcança o Mercado Pago.
//   fake  → não carrega nada: devolve um token de teste local. O backend em
//           MERCADOPAGO_MODE=fake aprova qualquer token (e recusa os de teste
//           que começam com OTHE/CONT/FUND, como o sandbox do MP).
//
// Falha fechado: se não der pra saber o modo (config indisponível) ou o
// servidor estiver em live sem Public Key, o pagamento com cartão é
// BLOQUEADO com erro -- nunca cai pro modo de teste (que mandaria o número do
// cartão como "token").

import { api } from './api.js';

const SDK_URL = 'https://sdk.mercadopago.com/js/v2';

let configPromise = null;
let sdkPromise = null;

function config() {
  configPromise ??= api.get('/payments/config.php').catch((e) => {
    configPromise = null; // tenta de novo na próxima vez
    throw new Error('Não deu pra falar com o pagamento agora. Confira a conexão e tente de novo.', { cause: e });
  });
  return configPromise;
}

/** O modo de teste só vale se o SERVIDOR disser que está em fake. */
async function liveKeyOrTest() {
  const cfg = await config();
  if (cfg.mode === 'fake') return null;
  if (!cfg.public_key) throw new Error('Pagamento com cartão indisponível no momento.');
  return cfg.public_key;
}

function loadSdk(publicKey) {
  sdkPromise ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = SDK_URL;
    script.onload = () => resolve(new window.MercadoPago(publicKey, { locale: 'pt-BR' }));
    script.onerror = () => reject(new Error('Não deu pra carregar o Mercado Pago. Confira a conexão.'));
    document.head.appendChild(script);
  });
  return sdkPromise;
}

/** O app está em modo de teste (sem cobrança real)? */
export async function paymentsInTestMode() {
  try {
    return (await config()).mode === 'fake';
  } catch {
    return false;
  }
}

/**
 * Token de um cartão digitado agora.
 * @param {{number:string, name:string, month:string, year:string, cvv:string, cpf:string}} card
 */
export async function tokenizeNewCard(card) {
  const key = await liveKeyOrTest();
  if (key === null) {
    // Teste: o próprio número vira o "token", como o backend fake espera.
    return card.number;
  }
  const mp = await loadSdk(key);
  const token = await mp.createCardToken({
    cardNumber: card.number,
    cardholderName: card.name,
    cardExpirationMonth: card.month,
    cardExpirationYear: card.year.length === 2 ? `20${card.year}` : card.year,
    securityCode: card.cvv,
    identificationType: 'CPF',
    identificationNumber: card.cpf,
  });
  return token.id;
}

/**
 * Token de um cartão salvo: o id do cartão no Mercado Pago + o CVV digitado
 * agora (o CVV nunca é guardado, nem aqui nem no nosso servidor).
 */
export async function tokenizeSavedCard(mpCardId, cvv) {
  const key = await liveKeyOrTest();
  if (key === null) {
    return `SAVED-${mpCardId}`;
  }
  const mp = await loadSdk(key);
  const token = await mp.createCardToken({ cardId: mpCardId, securityCode: cvv });
  return token.id;
}
