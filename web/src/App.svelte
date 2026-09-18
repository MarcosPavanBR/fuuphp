<script>
  import Splash from './lib/screens/Splash.svelte';
  import StateSelector from './lib/screens/StateSelector.svelte';
  import CityPicker from './lib/screens/CityPicker.svelte';
  import Home from './lib/screens/Home.svelte';
  import Search from './lib/screens/Search.svelte';
  import Loyalty from './lib/screens/Loyalty.svelte';
  import Orders from './lib/screens/Orders.svelte';
  import Profile from './lib/screens/Profile.svelte';
  import AddressesScreen from './lib/screens/AddressesScreen.svelte';
  import PaymentMethods from './lib/screens/PaymentMethods.svelte';
  import SettingsScreen from './lib/screens/SettingsScreen.svelte';
  import RestaurantPage from './lib/screens/RestaurantPage.svelte';
  import CartDrawer from './lib/screens/CartDrawer.svelte';
  import PaymentFlow from './lib/screens/PaymentFlow.svelte';
  import OrderTracking from './lib/screens/OrderTracking.svelte';
  import BottomNav from './lib/components/BottomNav.svelte';
  import AuthFlow from './lib/screens/AuthFlow.svelte';
  import { isAuthenticated, loadProfile, signupPending } from './lib/session.svelte.js';
  import { startPwa, isOnline, canInstall, promptInstall, dismissInstall } from './lib/pwa.svelte.js';

  // Fase 1 (onboarding) -> Fase 2 (navegação principal, abas) -> Fase 3
  // (loja/item/carrinho, tela cheia por cima das abas -- o mock não mostra
  // a barra inferior em 3.1/3.3, é uma pilha própria com botão de voltar).
  // A praça escolhida na Fase 1 fica salva: sem isso, todo reload manda o
  // cliente refazer o onboarding -- e offline (7.1) isso seria fatal, porque
  // a lista de cidades é local mas a tela de "onde você está" não é o que
  // ele quer ver ao reabrir o app no metrô.
  const LOCATION_KEY = 'fuu_location';
  function storedLocation() {
    try {
      const raw = localStorage.getItem(LOCATION_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  let location = $state(storedLocation());
  let step = $state(location ? 'app' : 'splash');
  let uf = $state(location?.uf ?? null);
  let tab = $state('home');
  let restaurantId = $state(null);
  let cartOpen = $state(false);
  let paymentOpen = $state(false);
  let couponCode = $state(null);
  let trackingOrderId = $state(null);

  if (isAuthenticated()) {
    loadProfile().catch(() => {});
  }

  startPwa();

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
      lat: result.city.lat ?? null,
      lng: result.city.lng ?? null,
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

{#if step === 'splash'}
  <Splash onContinue={goToState} />
{:else if step === 'state'}
  <StateSelector onContinue={goToCity} />
{:else if step === 'city'}
  <CityPicker {uf} onDone={finishOnboarding} />
{:else if trackingOrderId}
  <div class="page-shell">
    <OrderTracking orderId={trackingOrderId} onBack={closeTracking} onDone={closeTracking} />
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
    <RestaurantPage {restaurantId} onBack={closeRestaurant} onOpenCart={() => (cartOpen = true)} />
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
        <Home {location} onOpenRestaurant={openRestaurant} onSearch={() => (tab = 'search')} />
      {:else if tab === 'search'}
        <Search {location} onOpenRestaurant={openRestaurant} />
      {:else if tab === 'loyalty'}
        <Loyalty />
      {:else if tab === 'orders'}
        <Orders onOpenOrder={openOrder} onBack={() => (tab = 'profile')} />
      {:else if tab === 'profile'}
        <Profile
          onLoggedOut={() => (tab = 'home')}
          onOpenOrders={() => (tab = 'orders')}
          onOpenAddresses={() => (tab = 'addresses')}
          onOpenPaymentMethods={() => (tab = 'payment_methods')}
          onOpenSettings={() => (tab = 'settings')}
        />
      {:else if tab === 'addresses'}
        <AddressesScreen {location} onBack={() => (tab = 'profile')} />
      {:else if tab === 'payment_methods'}
        <PaymentMethods onBack={() => (tab = 'profile')} />
      {:else if tab === 'settings'}
        <SettingsScreen onBack={() => (tab = 'profile')} />
      {/if}
    </div>
    <BottomNav active={tab} onNavigate={(t) => (tab = t)} />
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
