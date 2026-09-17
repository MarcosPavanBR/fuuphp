<script>
  import Splash from './lib/screens/Splash.svelte';
  import StateSelector from './lib/screens/StateSelector.svelte';
  import CityPicker from './lib/screens/CityPicker.svelte';
  import Home from './lib/screens/Home.svelte';
  import Search from './lib/screens/Search.svelte';
  import Loyalty from './lib/screens/Loyalty.svelte';
  import Orders from './lib/screens/Orders.svelte';
  import Profile from './lib/screens/Profile.svelte';
  import BottomNav from './lib/components/BottomNav.svelte';
  import QuickLogin from './lib/components/QuickLogin.svelte';
  import { isAuthenticated, loadProfile } from './lib/session.svelte.js';
  import { toastr } from './lib/toastr.js';

  // Fase 1 (onboarding) -> Fase 2 (navegação principal: home, busca,
  // fidelidade, pedidos, perfil). Cardápio/item/carrinho é Fase 3, ainda
  // não portada -- abrir um restaurante por enquanto só avisa disso.
  let step = $state('splash');
  let uf = $state(null);
  let location = $state(null);
  let tab = $state('home');

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
    toastr.info(`Fase 3 (cardápio de "${r.name}") ainda não foi portada.`);
  }

  function openOrder() {
    toastr.info('Detalhe de pedido ainda não foi construído nesta passada.');
  }

  const AUTH_REQUIRED_TABS = new Set(['loyalty', 'orders', 'profile']);
</script>

{#if step === 'splash'}
  <Splash onContinue={goToState} />
{:else if step === 'state'}
  <StateSelector onContinue={goToCity} />
{:else if step === 'city'}
  <CityPicker {uf} onDone={finishOnboarding} />
{:else}
  <div class="app-shell">
    <div class="app-content">
      {#if AUTH_REQUIRED_TABS.has(tab) && !isAuthenticated()}
        <QuickLogin onSuccess={() => {}} />
      {:else if tab === 'home'}
        <Home {location} onOpenRestaurant={openRestaurant} onSearch={() => (tab = 'search')} />
      {:else if tab === 'search'}
        <Search {location} onOpenRestaurant={openRestaurant} />
      {:else if tab === 'loyalty'}
        <Loyalty />
      {:else if tab === 'orders'}
        <Orders onOpenOrder={openOrder} onBack={() => (tab = 'profile')} />
      {:else if tab === 'profile'}
        <Profile onLoggedOut={() => (tab = 'home')} onOpenOrders={() => (tab = 'orders')} />
      {/if}
    </div>
    <BottomNav active={tab} onNavigate={(t) => (tab = t)} />
  </div>
{/if}

<style>
  .app-shell {
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
  }
</style>
