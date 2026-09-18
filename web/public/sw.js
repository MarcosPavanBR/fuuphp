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
const VERSION = 'fuu-v1';
const SHELL = `${VERSION}-shell`;
const DATA = `${VERSION}-data`;

const SHELL_URLS = ['/', '/index.html', '/manifest.webmanifest', '/icon-192.png', '/icon-512.png'];

// Só estes GETs da API entram em cache: cardápio, loja, lista e busca são
// públicos e valem offline. Qualquer outra rota (perfil, pedidos, carrinho,
// pagamento, painel, entregador) passa direto pra rede.
const CACHEABLE_API = [
  '/api/v1/restaurants/list.php',
  '/api/v1/restaurants/show.php',
  '/api/v1/restaurants/menu.php',
  '/api/v1/restaurants/search_products.php',
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
