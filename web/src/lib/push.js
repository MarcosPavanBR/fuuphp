// Tela 7.2 (e o bloco "Notificações" da 6.3) — ligar o push neste aparelho.
//
// Três passos, todos do navegador de verdade: pedir permissão, criar a
// assinatura com a chave pública VAPID do servidor, e registrar a
// assinatura (com as preferências por tipo) na API. Nada disto roda sem
// https (ou localhost) e sem service worker -- e a tela diz isso em vez de
// mostrar um botão que não faz nada.

import { api } from './api.js';

/** Em que pé o push está neste aparelho: 'unsupported', 'denied', 'off' ou 'on'. */
export async function pushState() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    return 'unsupported';
  }
  if (Notification.permission === 'denied') return 'denied';
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();
  return sub ? 'on' : 'off';
}

function keyToBytes(base64url) {
  const pad = '='.repeat((4 - (base64url.length % 4)) % 4);
  const raw = atob((base64url + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

/** Liga o push com as preferências dadas. Lança Error com mensagem legível. */
export async function enablePush(prefs) {
  const config = await api.get('/push/config.php');
  if (!config.enabled) {
    throw new Error('O push ainda não está ligado neste servidor (falta a chave VAPID).');
  }
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error('Sem permissão de notificação neste navegador.');
  }
  const reg = await navigator.serviceWorker.ready;
  const sub =
    (await reg.pushManager.getSubscription()) ??
    (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyToBytes(config.public_key) }));
  await api.post('/push/subscribe.php', {
    auth: true,
    body: { action: 'subscribe', subscription: sub.toJSON(), prefs },
  });
}

/** Atualiza só as preferências por tipo (status / pagamento / promoção). */
export async function savePushPrefs(prefs) {
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();
  if (!sub) return;
  await api.post('/push/subscribe.php', { auth: true, body: { action: 'prefs', subscription: sub.toJSON(), prefs } });
}

/** Desliga o push neste aparelho (nos dois lados: navegador e servidor). */
export async function disablePush() {
  const reg = await navigator.serviceWorker.ready;
  const sub = await reg.pushManager.getSubscription();
  if (!sub) return;
  await api.post('/push/subscribe.php', { auth: true, body: { action: 'unsubscribe', subscription: sub.toJSON() } });
  await sub.unsubscribe();
}
