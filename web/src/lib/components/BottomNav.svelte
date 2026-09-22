<script>
  import { api } from '../services/api.js';
  import { toastr } from '../utils/toastr.js';

  // "Cinco abas em bi-house · bi-search · bi-cart · bi-star · bi-person.
  // Alvos de toque de 48 px na barra inferior; badge do carrinho vem do
  // store Svelte." (Fase 2, chip BottomNav.svelte)
  //
  // A aba do carrinho abre o carrinho com item mais recente (cart/show.php
  // sem loja). O carrinho mora dentro da loja (um por loja), então quem
  // desenha é o CartDrawer daquela loja -- `onOpenCart(restaurantId)`.
  let { active, onNavigate, onOpenCart } = $props();

  const tabs = [
    { key: 'home', icon: 'bi-house', label: 'Início' },
    { key: 'search', icon: 'bi-search', label: 'Buscar' },
    { key: 'cart', icon: 'bi-cart', label: 'Carrinho' },
    { key: 'loyalty', icon: 'bi-star', label: 'Fidelidade' },
    { key: 'profile', icon: 'bi-person', label: 'Perfil' },
  ];

  async function openLatestCart() {
    try {
      const data = await api.get('/cart/show.php', { auth: true });
      if (data.order) {
        onOpenCart(data.order.restaurant_id);
      } else {
        toastr.info('Seu carrinho está vazio.');
      }
    } catch {
      toastr.error('Não deu pra abrir o carrinho agora.');
    }
  }

  function go(tab) {
    if (tab.key === 'cart') {
      openLatestCart();
      return;
    }
    onNavigate(tab.key);
  }
</script>

<nav class="bottom-nav">
  {#each tabs as tab (tab.key)}
    <button
      type="button"
      class="tab"
      class:active={active === tab.key}
      onclick={() => go(tab)}
      aria-label={tab.label}
    >
      <i class={`bi ${tab.icon}`}></i>
      <span>{tab.label}</span>
    </button>
  {/each}
</nav>

<style>
  .bottom-nav {
    position: sticky;
    bottom: 0;
    display: flex;
    background: var(--fuu-white);
    border-top: 1px solid var(--fuu-line-3);
  }
  .tab {
    flex: 1;
    min-height: var(--fuu-tap-customer);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    background: none;
    border: none;
    color: var(--fuu-ink-5);
    font-family: var(--fuu-font-body);
    font-size: 10.5px;
  }
  .tab i {
    font-size: 19px;
  }
  .tab.active {
    color: var(--fuu-red);
  }
</style>
