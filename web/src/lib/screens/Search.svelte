<script>
  import MenuPhoto from '../components/MenuPhoto.svelte';
  import { api } from '../api.js';
  import { toastr } from '../toastr.js';

  // Tela 2.2 — Busca com filtros. "Busca por produto e por loja; filtros
  // em badge do Bootstrap, consulta com índice do PostgreSQL."
  // (api/search.php, PostgreSQL trigram)
  //
  // Os filtros (Entrega grátis / Até 30 min / 4,5+) são sobre a LOJA de
  // cada produto: search_products.php devolve frete, tempo e nota da loja,
  // calculados como no card da Home. Resultado sem o dado (loja sem
  // avaliações suficientes, sem coordenada) não passa no filtro -- "4,5+"
  // não pode incluir quem ainda não tem nota.
  let { location, onOpenRestaurant } = $props();

  const FILTERS = ['Tudo', 'Entrega grátis', 'Até 30 min', '4,5+'];

  let query = $state('');
  let filter = $state('Tudo');
  let products = $state([]);
  let searched = $state(false);
  let loading = $state(false);
  let debounceHandle;

  function onInput() {
    clearTimeout(debounceHandle);
    debounceHandle = setTimeout(runSearch, 350);
  }

  async function runSearch() {
    if (query.trim().length < 2) {
      products = [];
      searched = false;
      return;
    }
    loading = true;
    try {
      const data = await api.get('/restaurants/search_products.php', {
        query: { city_ibge_code: location.city.ibge, q: query.trim(), lat: location.lat, lng: location.lng },
      });
      products = data.products;
      searched = true;
    } catch (e) {
      toastr.error(e.message ?? 'Busca falhou.');
    } finally {
      loading = false;
    }
  }

  const FILTER_TEST = {
    Tudo: () => true,
    'Entrega grátis': (p) => p.delivery_fee === 0,
    'Até 30 min': (p) => p.eta_minutes != null && p.eta_minutes <= 30,
    '4,5+': (p) => p.restaurant_rating != null && p.restaurant_rating >= 4.5,
  };

  function pickFilter(f) {
    filter = f;
  }

  let visible = $derived(products.filter(FILTER_TEST[filter]));
</script>

<div class="search-screen">
  <div class="search-bar">
    <i class="bi bi-search"></i>
    <input
      type="search"
      placeholder="Buscar lanche, pizza, mercado…"
      bind:value={query}
      oninput={onInput}
    />
  </div>

  <div class="filters">
    {#each FILTERS as f (f)}
      <button type="button" class="chip" class:selected={filter === f} onclick={() => pickFilter(f)}>
        {f}
      </button>
    {/each}
  </div>

  {#if loading}
    <p class="empty">Buscando…</p>
  {:else if searched}
    <p class="section-label">{visible.length} RESULTADO{visible.length === 1 ? '' : 'S'} EM PRODUTOS</p>
    {#if visible.length === 0}
      <p class="empty">
        {products.length === 0 ? `Nada encontrado para "${query}".` : `Nenhum resultado com o filtro "${filter}".`}
      </p>
    {:else}
      <div class="product-list">
        {#each visible as p (p.id)}
          <button type="button" class="product-row" onclick={() => onOpenRestaurant({ id: p.restaurant_id, name: p.restaurant_name })}>
            <div class="photo"><MenuPhoto photoKey={p.photo_key} alt={p.name} /></div>
            <div class="info">
              <p class="name">{p.name}</p>
              <p class="restaurant">{p.restaurant_name}</p>
              <p class="price">R$ {Number(p.price).toFixed(2).replace('.', ',')}</p>
            </div>
          </button>
        {/each}
      </div>
    {/if}
  {:else}
    <p class="empty">Digite ao menos 2 letras pra buscar.</p>
  {/if}
</div>

<style>
  .search-screen {
    padding: 12px 20px 16px;
  }
  .search-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    margin-bottom: 12px;
  }
  .search-bar i {
    color: var(--fuu-ink-5);
  }
  .search-bar input {
    border: none;
    outline: none;
    flex: 1;
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
    background: transparent;
  }
  .filters {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 4px;
    margin-bottom: 14px;
  }
  .chip {
    flex: none;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 999px;
    padding: 7px 14px;
    font-family: var(--fuu-font-body);
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    white-space: nowrap;
  }
  .chip.selected {
    background: var(--fuu-red);
    border-color: var(--fuu-red);
    color: var(--fuu-white);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 10px;
  }
  .empty {
    color: var(--fuu-ink-5);
    font-size: 13.5px;
  }
  .product-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .product-row {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px;
    text-align: left;
  }
  .photo {
    width: 52px;
    height: 52px;
    border-radius: 10px;
    background: var(--fuu-line-5);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--fuu-ink-6);
    flex: none;
  }
  .info {
    min-width: 0;
  }
  .name {
    margin: 0;
    font-weight: 600;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .restaurant {
    margin: 2px 0;
    font-size: 12px;
    color: var(--fuu-ink-5);
  }
  .price {
    margin: 0;
    font-family: var(--fuu-font-mono);
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
</style>
