<script>
  import SplashScreen from '../lib/screens/customer/SplashScreen.svelte';
  import StatePickerScreen from '../lib/screens/customer/StatePickerScreen.svelte';
  import CityPickerScreen from '../lib/screens/customer/CityPickerScreen.svelte';
  import HomeScreen from '../lib/screens/customer/HomeScreen.svelte';
  import SearchScreen from '../lib/screens/customer/SearchScreen.svelte';
  import LoyaltyScreen from '../lib/screens/customer/LoyaltyScreen.svelte';
  import OrdersScreen from '../lib/screens/customer/OrdersScreen.svelte';
  import ProfileScreen from '../lib/screens/customer/ProfileScreen.svelte';
  import AddressesScreen from '../lib/screens/customer/AddressesScreen.svelte';
  import PaymentMethodsScreen from '../lib/screens/customer/PaymentMethodsScreen.svelte';
  import SettingsScreen from '../lib/screens/customer/SettingsScreen.svelte';
  import EditProfileScreen from '../lib/screens/customer/EditProfileScreen.svelte';
  import ReceiptsScreen from '../lib/screens/customer/ReceiptsScreen.svelte';
  import HelpScreen from '../lib/screens/customer/HelpScreen.svelte';
  import RestaurantScreen from '../lib/screens/customer/RestaurantScreen.svelte';
  import CartDrawer from '../lib/screens/customer/CartDrawer.svelte';
  import PaymentFlow from '../lib/screens/customer/PaymentFlow.svelte';
  import OrderTrackingScreen from '../lib/screens/customer/OrderTrackingScreen.svelte';
  import BottomNav from '../lib/components/BottomNav.svelte';
  import AuthFlow from '../lib/screens/customer/AuthFlow.svelte';
  import { isAuthenticated, loadProfile, signupPending } from '../lib/state/customerSession.svelte.js';
  import { startPwa, isOnline, canInstall, promptInstall, dismissInstall } from '../lib/state/pwa.svelte.js';
  import { startUploadQueue, queuedCount } from '../lib/services/uploadQueue.svelte.js';
  import { toastr } from '../lib/utils/toastr.js';
  import { loadServiceStates } from '../lib/services/cities.js';

  // Fase 1 (onboarding) -> Fase 2 (navegação principal, abas) -> Fase 3
  // (loja/item/carrinho, tela cheia por cima das abas -- o mock não mostra
  // a barra inferior em 3.1/3.3, é uma pilha própria com botão de voltar).
  // A praça escolhida na Fase 1 fica salva: sem isso, todo reload manda o
  // cliente refazer o onboarding -- e offline (7.1) isso seria fatal: a tela
  // de "onde você está" não é o que ele quer ver ao reabrir o app no metrô.
  const LOCATION_KEY = 'fuu_location';
  function storedLocation() {
    try {
      const raw = localStorage.getItem(LOCATION_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  // Lida uma vez na abertura: o estado inicial das telas e a conferência da
  // cidade logo abaixo partem dela.
  const initialLocation = storedLocation();
  let location = $state(initialLocation);
  let step = $state(initialLocation ? 'app' : 'splash');
  let uf = $state(initialLocation?.uf ?? null);
  let tab = $state('home');
  let restaurantId = $state(null);
  let cartOpen = $state(false);
  let paymentOpen = $state(false);
  let couponCode = $state(null);
  let trackingOrderId = $state(null);

  if (isAuthenticated()) {
    loadProfile().catch(() => {});
  }

  // A cidade salva pode ter sido desligada na aba Cidades do admin (ou ser
  // da lista antiga, fixa no app). Com a lista em mãos, cidade que saiu
  // manda o cliente escolher de novo; cidade que continua tem nome, centro e
  // bairros atualizados. Sem rede, fica o que está salvo.
  if (initialLocation) {
    loadServiceStates()
      .then((states) => {
        const city = states.flatMap((st) => st.cities.map((c) => ({ ...c, uf: st.uf })))
          .find((c) => c.ibge === location?.city?.ibge);
        if (!city) {
          location = null;
          try {
            localStorage.removeItem(LOCATION_KEY);
          } catch {
            // storage bloqueado: some só nesta sessão
          }
          step = 'state';
          toastr.info('Escolha de novo onde você está: a lista de cidades mudou.');
          return;
        }
        location = { ...location, uf: city.uf, city, lat: city.lat, lng: city.lng };
        try {
          localStorage.setItem(LOCATION_KEY, JSON.stringify(location));
        } catch {
          // idem
        }
      })
      .catch(() => {});
  }

  startPwa();
  // 7.1: a fila de comprovantes esvazia sozinha quando a rede volta.
  startUploadQueue((n) =>
    toastr.success(n === 1 ? 'O comprovante que estava na fila subiu ✓' : `${n} comprovantes da fila subiram ✓`)
  );

  function goToState() {
    step = 'state';
  }
  function goToCity(selectedUf) {
    uf = selectedUf;
    step = 'city';
  }
  function finishOnboarding(result) {
    location = {
      uf: result.uf,
      city: result.city,
      neighborhood: result.neighborhood,
      // lat/lng é o centro da cidade: é a referência de endereço novo.
      // `near` é a posição do aparelho, se o cliente deixou: só ordena as
      // lojas por distância -- nunca vira coordenada de endereço.
      lat: result.city.lat ?? null,
      lng: result.city.lng ?? null,
      near: result.coords ?? null,
    };
    try {
      localStorage.setItem(LOCATION_KEY, JSON.stringify(location));
    } catch {
      // storage bloqueado: o app funciona igual, só refaz o onboarding
    }
    step = 'app';
  }

  function openRestaurant(r) {
    restaurantId = r.id;
    cartOpen = false;
    paymentOpen = false;
  }
  // Abre direto o carrinho de uma loja: aba "Carrinho" e "Repetir" pedido.
  function openCartOf(id) {
    openRestaurant({ id });
    cartOpen = true;
  }
  function closeRestaurant() {
    restaurantId = null;
    cartOpen = false;
    paymentOpen = false;
  }
  function openTracking(orderId) {
    restaurantId = null;
    cartOpen = false;
    paymentOpen = false;
    trackingOrderId = orderId;
  }
  // 7.2: tocar na notificação abre o pedido. Com o app fechado, o SW abre
  // `/?order=ID`; com o app aberto, manda uma mensagem.
  $effect(() => {
    const fromUrl = new URLSearchParams(window.location.search).get('order');
    if (fromUrl && isAuthenticated()) {
      openTracking(Number(fromUrl));
      history.replaceState(null, '', window.location.pathname);
    }
    const onMessage = (e) => {
      if (e.data?.type === 'open-order' && e.data.orderId && isAuthenticated()) openTracking(Number(e.data.orderId));
    };
    navigator.serviceWorker?.addEventListener('message', onMessage);
    return () => navigator.serviceWorker?.removeEventListener('message', onMessage);
  });

  function closeTracking() {
    trackingOrderId = null;
    tab = 'home';
  }

  function openOrder(order) {
    openTracking(order.id);
  }

  const AUTH_REQUIRED_TABS = new Set(['loyalty', 'orders', 'profile']);

  // A Fase 10 não acaba quando o token chega: quem cria conta ainda passa
  // pelo cadastro (10.3), que só existe DEPOIS de estar autenticado. Por isso
  // a condição tem duas partes -- sem conta, ou com conta recém-criada que
  // ainda não completou o cadastro.
  let authOpen = $derived((AUTH_REQUIRED_TABS.has(tab) && !isAuthenticated()) || signupPending());
</script>

{#if !isOnline()}
  <!-- 7.1 — "Você está offline — mostrando o que está salvo". Fica por cima
       de qualquer tela, porque estar sem rede não depende de onde a pessoa
       está no app. -->
  <div class="offline-bar">
    <i class="bi bi-wifi-off"></i> Você está offline — mostrando o que está salvo
  </div>
{/if}
{#if queuedCount() > 0}
  <!-- 7.1: "1 comprovante na fila. Vai subir sozinho quando a conexão
       voltar (background sync)." -- número real, lido do IndexedDB. -->
  <div class="offline-bar queue-bar">
    <i class="bi bi-cloud-arrow-up"></i>
    {queuedCount() === 1 ? '1 comprovante na fila' : `${queuedCount()} comprovantes na fila`}. Vai subir sozinho
    quando a conexão voltar.
  </div>
{/if}

{#if step === 'splash'}
  <SplashScreen onContinue={goToState} />
{:else if step === 'state'}
  <StatePickerScreen onContinue={goToCity} />
{:else if step === 'city'}
  <CityPickerScreen {uf} onDone={finishOnboarding} />
{:else if trackingOrderId}
  <div class="page-shell">
    <OrderTrackingScreen orderId={trackingOrderId} onBack={closeTracking} onDone={closeTracking} />
  </div>
{:else if restaurantId && paymentOpen}
  <div class="page-shell">
    <PaymentFlow
      {restaurantId}
      {location}
      {couponCode}
      onBack={() => (paymentOpen = false)}
      onOrderReady={openTracking}
    />
  </div>
{:else if restaurantId && cartOpen}
  <div class="page-shell">
    <CartDrawer {restaurantId} onBack={() => (cartOpen = false)} onCheckout={(code) => {
        couponCode = code;
        paymentOpen = true;
      }} />
  </div>
{:else if restaurantId}
  <div class="page-shell">
    <RestaurantScreen {restaurantId} onBack={closeRestaurant} onOpenCart={() => (cartOpen = true)} />
  </div>
{:else}
  <div class="app-shell">
    {#if canInstall()}
      <div class="install-card fuu-card">
        <div class="install-mark"><i class="bi bi-bag-heart-fill"></i></div>
        <div class="install-text">
          <p class="t">Instalar o FUUdelivery</p>
          <p class="s">Abre mais rápido e funciona offline</p>
        </div>
        <div class="install-actions">
          <button type="button" class="btn-fuu-primary" onclick={promptInstall}>Instalar</button>
          <button type="button" class="later" onclick={dismissInstall}>Depois</button>
        </div>
      </div>
    {/if}

    <div class="app-content">
      {#if authOpen}
        <AuthFlow onSuccess={() => {}} onPartnerLogin={() => (window.location.href = '/painel.html')} />
      {:else if tab === 'home'}
        <HomeScreen {location} onOpenRestaurant={openRestaurant} onSearch={() => (tab = 'search')} />
      {:else if tab === 'search'}
        <SearchScreen {location} onOpenRestaurant={openRestaurant} />
      {:else if tab === 'loyalty'}
        <LoyaltyScreen />
      {:else if tab === 'orders'}
        <OrdersScreen onOpenOrder={openOrder} onBack={() => (tab = 'profile')} onOpenCart={openCartOf} />
      {:else if tab === 'profile'}
        <ProfileScreen
          onLoggedOut={() => (tab = 'home')}
          onOpenOrders={() => (tab = 'orders')}
          onOpenAddresses={() => (tab = 'addresses')}
          onOpenPaymentMethods={() => (tab = 'payment_methods')}
          onOpenSettings={() => (tab = 'settings')}
          onOpenHelp={() => (tab = 'help')}
          onOpenReceipts={() => (tab = 'receipts')}
          onEditProfile={() => (tab = 'edit_profile')}
        />
      {:else if tab === 'receipts'}
        <ReceiptsScreen onBack={() => (tab = 'profile')} />
      {:else if tab === 'edit_profile'}
        <EditProfileScreen onBack={() => (tab = 'profile')} />
      {:else if tab === 'addresses'}
        <AddressesScreen {location} onBack={() => (tab = 'profile')} />
      {:else if tab === 'payment_methods'}
        <PaymentMethodsScreen onBack={() => (tab = 'profile')} />
      {:else if tab === 'settings'}
        <SettingsScreen onBack={() => (tab = 'profile')} />
      {:else if tab === 'help'}
        <!-- 14.1 — a ajuda abre o pedido em vez de responder por ele: toda
             saída dela leva pro acompanhamento, onde está a conversa. -->
        <!-- openTracking, não openOrder: a ajuda conhece o id do pedido, não
             a linha inteira dele. -->
        <HelpScreen onBack={() => (tab = 'profile')} onOpenOrder={openTracking} />
      {/if}
    </div>
    <BottomNav
      active={tab}
      onNavigate={(t) => (tab = t)}
      onOpenCart={openCartOf}
    />
  </div>
{/if}

<style>
  .app-shell,
  .page-shell {
    max-width: 430px;
    margin: 0 auto;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: var(--fuu-paper);
  }
  .queue-bar {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .offline-bar {
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
    padding: 10px 18px;
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 12px;
    font-weight: 700;
  }
  .install-card {
    margin: 12px 16px 0;
    padding: 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }
  .install-mark {
    width: 46px;
    height: 46px;
    border-radius: 13px;
    background: var(--fuu-red);
    color: var(--fuu-white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex: none;
  }
  .install-text {
    flex: 1;
    min-width: 120px;
  }
  .install-text .t {
    font-weight: 800;
    font-size: 14px;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .install-text .s {
    font-size: 11px;
    color: var(--fuu-ink-4);
    margin: 2px 0 0;
  }
  .install-actions {
    display: flex;
    gap: 8px;
    align-items: center;
  }
  .install-actions .later {
    background: var(--fuu-line-5);
    border: none;
    border-radius: 12px;
    padding: 12px 16px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-2);
  }
  .app-content {
    flex: 1;
    overflow-y: auto;
    /* Coluna flex pra tela curta (login, código) poder esticar até o rodapé
       em vez de deixar faixa de fundo sobrando -- min-height:100% não resolve
       dentro de um item flex sem altura definida. */
    display: flex;
    flex-direction: column;
  }
</style>
