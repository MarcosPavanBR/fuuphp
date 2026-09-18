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

  // Fase 1 (onboarding) -> Fase 2 (navegação principal, abas) -> Fase 3
  // (loja/item/carrinho, tela cheia por cima das abas -- o mock não mostra
  // a barra inferior em 3.1/3.3, é uma pilha própria com botão de voltar).
  let step = $state('splash');
  let uf = $state(null);
  let location = $state(null);
  let tab = $state('home');
  let restaurantId = $state(null);
  let cartOpen = $state(false);
  let paymentOpen = $state(false);
  let trackingOrderId = $state(null);

  if (isAuthenticated()) {
    loadProfile().catch(() => {});
  }

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
    <PaymentFlow {restaurantId} {location} onBack={() => (paymentOpen = false)} onOrderReady={openTracking} />
  </div>
{:else if restaurantId && cartOpen}
  <div class="page-shell">
    <CartDrawer {restaurantId} onBack={() => (cartOpen = false)} onCheckout={() => (paymentOpen = true)} />
  </div>
{:else if restaurantId}
  <div class="page-shell">
    <RestaurantPage {restaurantId} onBack={closeRestaurant} onOpenCart={() => (cartOpen = true)} />
  </div>
{:else}
  <div class="app-shell">
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
