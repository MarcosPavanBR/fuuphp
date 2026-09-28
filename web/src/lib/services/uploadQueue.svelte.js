// Tela 7.1 — a fila de upload offline.
//
// "1 comprovante na fila. Vai subir sozinho quando a conexão voltar
// (background sync)." / "Pagamento nunca é enfileirado offline — só o
// upload do comprovante, que é idempotente por UUID."
//
// Como funciona:
//   1. Sem rede (ou com a rede caindo no meio), o comprovante vai pro
//      IndexedDB com um UUID gerado AQUI -- é esse UUID que o servidor usa
//      como `X-Idempotency-Key` (payments/upload_proof.php): reenviar o
//      mesmo item devolve o mesmo comprovante, nunca um segundo.
//   2. A fila esvazia sozinha em três ocasiões: o navegador avisa que a
//      rede voltou (`online`), o app abre, ou o service worker recebe o
//      evento `sync` (Background Sync, onde o navegador suporta) e pede pra
//      página esvaziar.
//   3. Quem envia é sempre a PÁGINA, não o service worker: é ela que tem o
//      token da sessão. Sem app aberto, o item espera; com Background Sync,
//      o navegador acorda o SW, que acorda a página se ela existir.
//
// Só comprovante entra aqui. Pagamento, pedido e qualquer outra escrita
// continuam falhando na hora quando não há rede -- de propósito.

import { BASE, getStoredToken } from './api.js';

const DB_NAME = 'fuu-offline';
const STORE = 'proof-uploads';
export const SYNC_TAG = 'fuu-upload-proofs';

let queued = $state(0);
let flushing = false;

/** Quantos comprovantes estão esperando rede agora (pra faixa da tela 7.1). */
export function queuedCount() {
  return queued;
}

function openDb() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

async function withStore(mode, fn) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE, mode);
    const result = fn(tx.objectStore(STORE));
    tx.oncomplete = () => resolve(result?.result ?? result);
    tx.onerror = () => reject(tx.error);
  });
}

async function refreshCount() {
  try {
    queued = await withStore('readonly', (s) => s.count());
  } catch {
    queued = 0;
  }
}

/**
 * Guarda um comprovante pra subir depois. Devolve o UUID do envio.
 * O arquivo vai inteiro (Blob) -- IndexedDB guarda binário direto.
 */
export async function enqueueProof(orderId, file) {
  const item = {
    id: crypto.randomUUID(),
    orderId,
    blob: file,
    name: file.name ?? 'comprovante.jpg',
    createdAt: Date.now(),
  };
  await withStore('readwrite', (s) => s.put(item));
  await refreshCount();

  // Background Sync: onde existe, o navegador avisa quando a rede voltar
  // mesmo com a aba em segundo plano. Onde não existe, o evento `online`
  // da página cobre.
  try {
    const reg = await navigator.serviceWorker?.ready;
    await reg?.sync?.register(SYNC_TAG);
  } catch {
    // sem Background Sync: o `online` resolve quando a página estiver aberta
  }
  return item.id;
}

/**
 * Tenta subir tudo que está na fila. Item que sobe (ou que o servidor
 * recusa por motivo que reenviar não conserta, como pedido cancelado) sai
 * da fila; item que falha por rede fica.
 *
 * Devolve quantos subiram.
 */
export async function flushProofs() {
  if (flushing || !navigator.onLine) return 0;
  flushing = true;
  let sent = 0;
  try {
    const items = await withStore('readonly', (s) => s.getAll());
    for (const item of items ?? []) {
      const form = new FormData();
      form.append('order_id', String(item.orderId));
      form.append('proof', new File([item.blob], item.name, { type: item.blob.type || 'image/jpeg' }));
      let res;
      try {
        res = await fetch(`${BASE}/payments/upload_proof.php`, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${getStoredToken() ?? ''}`,
            'X-Idempotency-Key': item.id,
          },
          body: form,
        });
      } catch {
        break; // a rede caiu de novo: o resto espera a próxima chance
      }
      // 401: sessão expirou -- fica na fila até a pessoa entrar de novo.
      if (res.status === 401) break;
      await withStore('readwrite', (s) => s.delete(item.id));
      if (res.ok) sent++;
    }
  } finally {
    flushing = false;
    await refreshCount();
  }
  return sent;
}

/** Liga os gatilhos de esvaziar a fila. Chamado uma vez no início do app. */
export function startUploadQueue(onSent) {
  if (typeof window === 'undefined' || !('indexedDB' in window)) return;
  refreshCount();
  const run = async () => {
    const n = await flushProofs();
    if (n > 0) onSent?.(n);
  };
  window.addEventListener('online', run);
  navigator.serviceWorker?.addEventListener('message', (e) => {
    if (e.data?.type === 'flush-uploads') run();
  });
  run();
}
