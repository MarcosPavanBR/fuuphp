<script>
  import { HOME_CATEGORIES } from '../../data/categories.js';
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';

  // Tela 2.1 — Home / Explorar. "Carrossel de categorias com scroll-x;
  // cards do Bootstrap." (CategoryCarousel.svelte, BottomNav.svelte,
  // api/restaurants.php)
  //
  // O card mostra nota (4,8), tempo e frete como no mock -- calculados pelo
  // servidor das mesmas fontes que o checkout usa (lib/catalog/restaurant_facts.php):
  // nota das avaliações (só com 3 ou mais), frete da tarifa da política pro
  // lugar escolhido, tempo = preparo informado pela loja + viagem. O que não
  // dá pra calcular (sem coordenada, sem avaliações) simplesmente não aparece.
  let { location, onOpenRestaurant, onSearch } = $props();

  const CATEGORIES = HOME_CATEGORIES;

  let category = $state(null);
  let restaurants = $state([]);
  let loading = $state(true);

  async function load() {
    loading = true;
    try {
      const data = await api.get('/restaurants/list.php', {
        query: {
          city_ibge_code: location.city.ibge,
          category,
          lat: location.near?.lat ?? location.lat,
          lng: location.near?.lng ?? location.lng,
        },
      });
      restaurants = data.restaurants;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar as lojas.');
    } finally {
      loading = false;
    }
  }

  $effect(() => {
    category; // dependência explícita: recarrega ao trocar categoria
    load();
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  // "25–35 min": a estimativa vira faixa, porque ninguém lê "31 min" como
  // estimativa.
  function etaRange(m) {
    const low = Math.max(5, Math.round((m - 5) / 5) * 5);
    return `${low}–${low + 10} min`;
  }

  function initials(name) {
    return name
      .split(' ')
      .slice(0, 2)
      .map((w) => w[0])
      .join('')
      .toUpperCase();
  }
</script>

<div class="home">
  <div class="top-bar">
    <i class="bi bi-geo-alt-fill"></i>
    <span>{location.neighborhood}, {location.city.name}</span>
  </div>

  <button type="button" class="search-bar" onclick={onSearch}>
    <i class="bi bi-search"></i>
    <span>Buscar lanche, pizza, mercado…</span>
  </button>

  <div class="categories">
    <button type="button" class="chip" class:selected={category === null} onclick={() => (category = null)}>
      Tudo
    </button>
    {#each CATEGORIES as c (c)}
      <button type="button" class="chip" class:selected={category === c} onclick={() => (category = c)}>
        {c}
      </button>
    {/each}
  </div>

  <div class="section-header">
    <p class="section-label">ABERTOS AGORA</p>
  </div>

  {#if loading}
    <p class="empty">Carregando lojas…</p>
  {:else if restaurants.length === 0}
    <p class="empty">Nenhuma loja encontrada em {location.city.name} ainda.</p>
  {:else}
    <div class="restaurant-list">
      {#each restaurants as r (r.id)}
        <button type="button" class="restaurant-card fuu-card" onclick={() => onOpenRestaurant(r)}>
          <div class="logo">{initials(r.name)}</div>
          <div class="info">
            <p class="name">{r.name}</p>
            <p class="meta">
              {#if r.rating !== null && r.rating !== undefined}<span class="rating"><i class="bi bi-star-fill"></i> {String(r.rating).replace('.', ',')}</span> ·{/if}
              {r.category ?? 'Loja'}
              {#if r.distance_km !== null}· {String(r.distance_km).replace('.', ',')} km{/if}
            </p>
            {#if r.eta_minutes != null || r.delivery_fee != null || r.in_area === false}
              <p class="meta">
                {#if r.in_area === false}
                  Fora da área de entrega
                {:else}
                  {#if r.eta_minutes != null}{etaRange(r.eta_minutes)}{/if}
                  {#if r.eta_minutes != null && r.delivery_fee != null}·{/if}
                  {#if r.delivery_fee != null}{r.delivery_fee === 0 ? 'Entrega grátis' : money(r.delivery_fee)}{/if}
                {/if}
              </p>
            {/if}
          </div>
          <span class={r.is_open ? 'fuu-badge-confirmed' : 'fuu-badge-wait'}>
            {r.is_open ? 'Aberto' : 'Fechado'}
          </span>
        </button>
      {/each}
    </div>
  {/if}
</div>

<style>
  .home {
    padding: 4px 20px 16px;
  }
  .top-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--fuu-ink-2);
    font-size: 13.5px;
    font-weight: 600;
    padding: 6px 0 12px;
  }
  .top-bar i {
    color: var(--fuu-red);
  }
  .search-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    color: var(--fuu-ink-5);
    font-family: var(--fuu-font-body);
    font-size: 14px;
    text-align: left;
    margin-bottom: 14px;
  }
  .categories {
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
    padding: 8px 16px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
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
    letter-spacing: 0.12em;
    color: var(--fuu-ink-5);
    margin: 0 0 10px;
  }
  .empty {
    color: var(--fuu-ink-5);
    font-size: 13.5px;
  }
  .restaurant-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .restaurant-card {
    display: flex;
    align-items: center;
    gap: 12px;
    text-align: left;
    padding: 12px;
    width: 100%;
  }
  .logo {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--fuu-font-display);
    font-weight: 700;
    font-size: 15px;
    flex: none;
  }
  .info {
    flex: 1;
    min-width: 0;
  }
  .name {
    margin: 0;
    font-weight: 600;
    color: var(--fuu-ink-1);
    font-size: 14.5px;
  }
  .rating {
    color: var(--fuu-ink-1);
    font-weight: 600;
  }
  .rating i {
    color: #e0a100;
  }
  .meta {
    margin: 2px 0 0;
    color: var(--fuu-ink-5);
    font-size: 12.5px;
  }
</style>
