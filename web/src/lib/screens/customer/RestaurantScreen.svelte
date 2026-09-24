<script>
  import MenuPhoto from '../../components/MenuPhoto.svelte';
  import StoreLogo from '../../components/StoreLogo.svelte';
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { cartState, loadCart } from '../../state/cart.svelte.js';
  import ItemModal from '../../components/ItemModal.svelte';

  // Tela 3.1 — Página do restaurante. "Categorias em accordion; item
  // esgotado desabilitado no servidor, não escondido."
  // (ProductCard.svelte, api/menu.php, Cloudflare cache)
  let { restaurantId, onBack, onOpenCart } = $props();

  let restaurant = $state(null);
  let items = $state([]);
  let activeCategory = $state(null);
  let openItem = $state(null);
  let loading = $state(true);

  async function load() {
    loading = true;
    try {
      const [showData, menuData] = await Promise.all([
        api.get('/restaurants/show.php', { query: { id: restaurantId } }),
        api.get('/restaurants/menu.php', { query: { id: restaurantId } }),
      ]);
      restaurant = showData.restaurant;
      items = menuData.items;
      activeCategory = categories()[0] ?? null;
      await loadCart(restaurantId).catch(() => {});
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra abrir essa loja.');
    } finally {
      loading = false;
    }
  }
  load();

  function categories() {
    return [...new Set(items.map((i) => i.category ?? 'Outros'))];
  }

  let visibleItems = $derived(items.filter((i) => (i.category ?? 'Outros') === activeCategory));
  let cart = $derived(cartState());
  let cartCount = $derived(cart.items.reduce((n, i) => n + i.quantity, 0));

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function onItemAdded() {
    openItem = null;
  }
</script>

<div class="restaurant-page">
  <!-- Faixa da loja: a cor da marca em dose pequena e o logo por cima. -->
  <div class="banner">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
  </div>

  {#if loading}
    <p class="empty">Carregando…</p>
  {:else if restaurant}
    <div class="header">
      <div class="logo-slot"><StoreLogo logoKey={restaurant.logo_key} name={restaurant.name} size={72} /></div>
      <h1 class="fuu-display">{restaurant.name}</h1>
      {#if restaurant.category}<p class="category">{restaurant.category}</p>{/if}
      <p class="meta">
        <span class={restaurant.is_open ? 'fuu-badge-confirmed' : 'fuu-badge-wait'}>
          {restaurant.is_open ? 'Aberto' : 'Fechado'}
        </span>
      </p>
    </div>

    <div class="categories">
      {#each categories() as c (c)}
        <button type="button" class="tab" class:active={activeCategory === c} onclick={() => (activeCategory = c)}>
          {c}
        </button>
      {/each}
    </div>

    <p class="section-label">{activeCategory}</p>
    <div class="item-list">
      {#each visibleItems as item (item.id)}
        <button
          type="button"
          class="item-row"
          class:unavailable={!item.available}
          disabled={!item.available}
          onclick={() => (openItem = item)}
        >
          <div class="info">
            <p class="name">{item.name}</p>
            {#if !item.available}
              <p class="status">Indisponível hoje</p>
            {:else if item.description}
              <p class="description">{item.description}</p>
            {/if}
            <p class="price fuu-mono">{money(item.price)}</p>
          </div>
          <div class="photo"><MenuPhoto photoKey={item.photo_key} alt={item.name} /></div>
        </button>
      {/each}
    </div>
  {/if}
</div>

{#if openItem}
  <ItemModal item={openItem} {restaurantId} onClose={() => (openItem = null)} onAdded={onItemAdded} />
{/if}

{#if cartCount > 0}
  <div class="cart-bar">
    <span>{cartCount} {cartCount === 1 ? 'item' : 'itens'} · {money(cart.order.subtotal)}</span>
    <button type="button" class="btn-fuu-primary" onclick={onOpenCart}>Ver carrinho</button>
  </div>
{/if}

<style>
  .restaurant-page {
    padding-bottom: 90px;
  }
  .banner {
    height: 96px;
    background: var(--fuu-red-tint);
    position: relative;
  }
  .back {
    position: absolute;
    top: 14px;
    left: 14px;
    background: var(--fuu-white);
    border: none;
    border-radius: 50%;
    width: 34px;
    height: 34px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
  }
  .empty {
    padding: 20px;
    color: var(--fuu-ink-5);
  }
  .header {
    padding: 0 20px 6px;
  }
  /* O logo sobe metade pra dentro da faixa, com um anel de papel em volta. */
  .logo-slot {
    margin-top: -36px;
    margin-bottom: 8px;
    width: fit-content;
    border-radius: 14px;
    box-shadow: 0 0 0 3px var(--fuu-white);
    background: var(--fuu-white);
  }
  .category {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: -2px 0 8px;
  }
  h1 {
    font-size: 20px;
    margin: 0 0 6px;
  }
  .meta {
    margin: 0;
  }
  .categories {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding: 10px 20px;
  }
  .tab {
    flex: none;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 6px 4px;
    font-family: var(--fuu-font-body);
    font-size: 13.5px;
    font-weight: 600;
    color: var(--fuu-ink-4);
  }
  .tab.active {
    color: var(--fuu-red);
    border-bottom-color: var(--fuu-red);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 8px 20px;
  }
  .item-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 0 20px;
  }
  .item-row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 12px;
    text-align: left;
  }
  .item-row.unavailable {
    opacity: 0.55;
  }
  .info {
    flex: 1;
    min-width: 0;
  }
  .name {
    margin: 0;
    font-weight: 600;
    font-size: 14.5px;
    color: var(--fuu-ink-1);
  }
  .description {
    margin: 3px 0;
    font-size: 12.5px;
    color: var(--fuu-ink-5);
  }
  .status {
    margin: 3px 0;
    font-size: 12px;
    color: var(--fuu-alert);
  }
  .price {
    margin: 4px 0 0;
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .photo {
    width: 64px;
    height: 64px;
    border-radius: 10px;
    background: var(--fuu-line-5);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--fuu-ink-6);
    flex: none;
  }
  .cart-bar {
    position: fixed;
    left: 50%;
    bottom: 0;
    transform: translateX(-50%);
    width: 100%;
    max-width: 430px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--fuu-white);
    border-top: 1px solid var(--fuu-line-3);
    padding: 12px 20px;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--fuu-ink-1);
    z-index: 30;
  }
</style>
