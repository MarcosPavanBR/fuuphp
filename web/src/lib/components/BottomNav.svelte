<script>
  import { toastr } from '../toastr.js';

  // "Cinco abas em bi-house · bi-search · bi-cart · bi-star · bi-person.
  // Alvos de toque de 48 px na barra inferior; badge do carrinho vem do
  // store Svelte." (Fase 2, chip BottomNav.svelte)
  //
  // O carrinho é Fase 3, ainda não portada -- a aba existe (fidelidade ao
  // mock) mas avisa que não é clicável de verdade ainda, em vez de levar a
  // uma tela vazia fingindo que funciona.
  let { active, onNavigate } = $props();

  const tabs = [
    { key: 'home', icon: 'bi-house', label: 'Início' },
    { key: 'search', icon: 'bi-search', label: 'Buscar' },
    { key: 'cart', icon: 'bi-cart', label: 'Carrinho' },
    { key: 'loyalty', icon: 'bi-star', label: 'Fidelidade' },
    { key: 'profile', icon: 'bi-person', label: 'Perfil' },
  ];

  function go(tab) {
    if (tab.key === 'cart') {
      toastr.info('O carrinho é a Fase 3 das telas — ainda não portada.');
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
