// Service worker do app do cliente (tela 7.1).
//
// Regra que organiza tudo: o que é NAVEGAÇÃO pode vir do cache; o que é
// DINHEIRO nunca. "Pagamento nunca é enfileirado offline" -- então só GET
// entra em cache, e mesmo entre os GETs ficam de fora os que dependem de
// sessão (perfil, pedidos, carrinho), porque servir dado de conta de outra
// pessoa que usou o mesmo aparelho seria pior que ficar sem dado.
//
// Duas estratégias:
//   - app shell (HTML, JS, CSS, ícones): cache primeiro, rede depois. É o
//     que faz abrir rápido e abrir offline.
//   - cardápio e listas públicas: rede primeiro com cópia no cache. Preço
//     velho é pior que espera, então a rede sempre ganha quando existe --
//     o cache é o plano B, e a tela avisa que está mostrando o que salvou.
const VERSION = 'fuu-v6';
const SHELL = `${VERSION}-shell`;
const DATA = `${VERSION}-data`;

const SHELL_URLS = ['/', '/index.html', '/manifest.webmanifest', '/favicon.svg', '/icon-192.png', '/icon-512.png'];

// Só estes GETs da API entram em cache: cardápio, loja, lista e busca são
// públicos e valem offline. Qualquer outra rota (perfil, pedidos, carrinho,
// pagamento, painel, entregador) passa direto pra rede.
const CACHEABLE_API = [
  // As cidades atendidas abrem o onboarding: sem elas, nem a primeira tela.
  '/api/v1/cities/list.php',
  '/api/v1/restaurants/list.php',
  '/api/v1/restaurants/show.php',
  '/api/v1/restaurants/menu.php',
  '/api/v1/restaurants/search_products.php',
  // Foto do item: URL por hash de conteúdo, nunca muda -- boa pra ver o
  // cardápio offline com as fotos.
  '/api/v1/restaurants/menu_photo.php',
  // Vitrine da Home (migração 037): banners, queridinhos e logos. As imagens
  // têm URL por hash de conteúdo, como a foto do item.
  '/api/v1/banners/list.php',
  '/api/v1/banners/image.php',
  '/api/v1/restaurants/popular_items.php',
  '/api/v1/restaurants/logo.php',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll(SHELL_URLS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Requisição autenticada não entra em cache mesmo em rota pública: o
  // header Authorization muda o que o servidor devolve, e o cache do SW não
  // varia por header.
  if (request.headers.has('Authorization')) return;

  if (CACHEABLE_API.some((path) => url.pathname === path)) {
    event.respondWith(networkFirst(request));
    return;
  }

  if (url.origin === self.location.origin) {
    event.respondWith(cacheFirst(request));
  }
});

async function networkFirst(request) {
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(DATA);
      cache.put(request, response.clone());
    }
    return response;
  } catch (err) {
    const cached = await caches.match(request);
    if (cached) return cached;
    throw err;
  }
}

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  try {
    const response = await fetch(request);
    if (response.ok && request.url.startsWith(self.location.origin)) {
      const cache = await caches.open(SHELL);
      cache.put(request, response.clone());
    }
    return response;
  } catch (err) {
    // Navegação offline sem cache da página: devolve a raiz, que é a
    // mesma SPA.
    if (request.mode === 'navigate') {
      const shell = await caches.match('/index.html');
      if (shell) return shell;
    }
    throw err;
  }
}

// ── Tela 7.2 — notificações push ─────────────────────────────────────────
//
// O push chega SEM conteúdo (lib/messaging/push.php explica por quê). Ao acordar, o
// SW busca o texto na API usando o endpoint da própria assinatura como
// credencial -- ele não tem o token da sessão, porque roda com o app
// fechado. O endereço da API vem na URL de registro (?api=), porque em dev
// ela mora em outra porta.
const API_BASE = new URL(self.location.href).searchParams.get('api') || '/api/v1';

self.addEventListener('push', (event) => {
  event.waitUntil(showPending());
});

async function showPending() {
  let items = [];
  try {
    const sub = await self.registration.pushManager.getSubscription();
    if (sub) {
      const res = await fetch(`${API_BASE}/push/pending.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint: sub.endpoint }),
      });
      items = (await res.json()).notifications ?? [];
    }
  } catch {
    items = [];
  }

  // O navegador exige mostrar ALGO a cada push (userVisibleOnly). Sem texto
  // novo -- outro aparelho já mostrou, ou a rede falhou --, um aviso
  // genérico e honesto em vez de nada.
  if (items.length === 0) {
    return self.registration.showNotification('FUUdelivery', {
      body: 'Seu pedido teve uma atualização.',
      icon: '/icon-192.png',
      tag: 'fuu-generic',
    });
  }

  await Promise.all(
    items.map((n) =>
      self.registration.showNotification(n.title, {
        body: n.body,
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        // Mesmo pedido, mesma "etiqueta": o aviso novo substitui o velho em
        // vez de empilhar três notificações do mesmo pedido.
        tag: n.order_id ? `fuu-order-${n.order_id}` : `fuu-${n.id}`,
        data: { orderId: n.order_id },
      })
    )
  );
}

// Tocar na notificação abre o app no pedido -- ou traz pra frente a aba que
// já estava aberta, em vez de abrir outra.
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const orderId = event.notification.data?.orderId;
  const target = orderId ? `/?order=${orderId}` : '/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      const open = windows.find((w) => new URL(w.url).origin === self.location.origin);
      if (open) {
        open.postMessage({ type: 'open-order', orderId });
        return open.focus();
      }
      return self.clients.openWindow(target);
    })
  );
});

// ── Tela 7.1 — fila de upload offline (Background Sync) ─────────────────
//
// Quando a rede volta, o navegador dispara `sync`. Quem sobe o arquivo é a
// PÁGINA (ela tem o token); o SW só avisa as abas abertas. Se nenhuma está
// aberta, o comprovante espera o app abrir -- e o `waitUntil` falha de
// propósito pra o navegador tentar o sync de novo mais tarde.
self.addEventListener('sync', (event) => {
  if (event.tag !== 'fuu-upload-proofs') return;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      if (windows.length === 0) throw new Error('sem aba aberta pra enviar a fila');
      windows.forEach((w) => w.postMessage({ type: 'flush-uploads' }));
    })
  );
});
