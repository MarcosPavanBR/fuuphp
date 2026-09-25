<script>
  import { STORE_CATEGORIES } from '../../data/categories.js';
  import { api, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { isAuthenticated } from '../../state/customerSession.svelte.js';
  import StoreLogo from '../../components/StoreLogo.svelte';
  import MenuPhoto from '../../components/MenuPhoto.svelte';

  // Tela 2.1 — Home / Explorar. De cima pra baixo, como a vitrine do
  // concorrente (MaisDelivery), que é o padrão que o cliente da cidade já
  // conhece:
  //   - banners da plataforma (banners/list.php, aba Banners do admin);
  //   - "Queridinhos": os itens mais pedidos da cidade, de lojas abertas
  //     (restaurants/popular_items.php);
  //   - atalhos de categoria, só as que têm loja na cidade, mais "Favoritas";
  //   - as lojas, com logo, coração de favorita, nota, tempo e frete.
  //
  // Nota, tempo e frete vêm do servidor, das mesmas fontes do checkout
  // (lib/catalog/restaurant_facts.php): o que não dá pra calcular (sem
  // coordenada, sem avaliações) não aparece. Banner e queridinhos sem dado
  // não aparecem -- nada de vitrine vazia.
  let { location, onOpenRestaurant, onSearch } = $props();

  const FAVORITES = '__favoritas__';

  let category = $state(null);
  let restaurants = $state([]);
  let cityCategories = $state([]);
  let loading = $state(true);
  // A lista vem paginada (restaurants/list.php, 40 por vez, das mais perto
  // pras mais longe): "Ver mais lojas" busca a próxima página.
  let nextOffset = $state(null);
  let loadingMore = $state(false);
  let banners = $state([]);
  let popular = $state([]);
  let favorites = $state(new Set());
  let slide = $state(0);
  let carousel = $state();

  // Categoria e favoritas vão pro servidor (favoritas por ?ids=, pra achar a
  // loja mesmo que ela estivesse numa página que não foi carregada). O
  // filtro local só tira na hora a loja que a pessoa acabou de desfavoritar.
  let shown = $derived(
    category === FAVORITES ? restaurants.filter((r) => favorites.has(r.id)) : restaurants
  );
  let chips = $derived(STORE_CATEGORIES.filter((c) => cityCategories.includes(c)));

  function listQuery(offset) {
    return {
      city_ibge_code: location.city.ibge,
      category: category === FAVORITES ? null : category,
      ids: category === FAVORITES ? [...favorites].join(',') : null,
      lat: location.near?.lat ?? location.lat,
      lng: location.near?.lng ?? location.lng,
      offset: offset || null,
    };
  }

  async function loadStores() {
    loading = true;
    try {
      const data = await api.get('/restaurants/list.php', { query: listQuery(0) });
      restaurants = data.restaurants;
      cityCategories = data.categories ?? [];
      nextOffset = data.next_offset ?? null;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar as lojas.');
    } finally {
      loading = false;
    }
  }

  async function loadMore() {
    if (nextOffset === null || loadingMore) return;
    loadingMore = true;
    try {
      const data = await api.get('/restaurants/list.php', { query: listQuery(nextOffset) });
      const seen = new Set(restaurants.map((r) => r.id));
      restaurants = [...restaurants, ...data.restaurants.filter((r) => !seen.has(r.id))];
      nextOffset = data.next_offset ?? null;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar mais lojas.');
    } finally {
      loadingMore = false;
    }
  }

  $effect(() => {
    category; // dependência explícita: recarrega ao trocar categoria
    loadStores();
  });

  // Vitrine e favoritas: uma vez por cidade. Falha aqui não é erro na tela:
  // sem banner ou queridinho, a Home só não mostra a faixa.
  $effect(() => {
    const city = location.city.ibge;
    api
      .get('/banners/list.php', { query: { city_ibge_code: city } })
      .then((res) => {
        banners = res.banners ?? [];
        slide = 0;
      })
      .catch(() => (banners = []));
    api
      .get('/restaurants/popular_items.php', { query: { city_ibge_code: city } })
      .then((res) => (popular = res.items ?? []))
      .catch(() => (popular = []));
    if (isAuthenticated()) {
      api
        .get('/profile/favorites.php', { auth: true })
        .then((res) => (favorites = new Set(res.restaurant_ids)))
        .catch(() => {});
    }
  });

  // O carrossel anda sozinho a cada 5 s, a não ser que a pessoa prefira
  // menos movimento (acessibilidade) ou só haja um banner.
  $effect(() => {
    if (banners.length < 2) return;
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
    const t = setInterval(() => goTo((slide + 1) % banners.length), 5000);
    return () => clearInterval(t);
  });

  function goTo(i) {
    slide = i;
    carousel?.scrollTo({ left: carousel.clientWidth * i, behavior: 'smooth' });
  }

  function onCarouselScroll() {
    if (!carousel) return;
    slide = Math.round(carousel.scrollLeft / Math.max(1, carousel.clientWidth));
  }

  function openBanner(b) {
    if (b.restaurant_id) onOpenRestaurant({ id: b.restaurant_id });
  }

  async function toggleFavorite(r) {
    if (!isAuthenticated()) {
      toastr.info('Entre na sua conta (aba Perfil) pra guardar lojas favoritas.');
      return;
    }
    const want = !favorites.has(r.id);
    // Otimista: o coração muda na hora; se o servidor recusar, volta.
    const next = new Set(favorites);
    if (want) next.add(r.id);
    else next.delete(r.id);
    favorites = next;
    try {
      await api.post('/profile/favorites.php', { auth: true, body: { restaurant_id: r.id, favorite: want } });
    } catch (e) {
      const back = new Set(favorites);
      if (want) back.delete(r.id);
      else back.add(r.id);
      favorites = back;
      toastr.error(e.message ?? 'Não deu pra salvar a favorita.');
    }
  }

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  // "25–35 min": a estimativa vira faixa, porque ninguém lê "31 min" como
  // estimativa.
  function etaRange(m) {
    const low = Math.max(5, Math.round((m - 5) / 5) * 5);
    return `${low}–${low + 10} min`;
  }
</script>

<div class="home">
  <div class="top-bar">
    <i class="bi bi-geo-alt-fill"></i>
    <span>{location.neighborhood}, {location.city.name}</span>
  </div>

  <button type="button" class="search-bar" onclick={onSearch}>
    <i class="bi bi-search"></i>
    <span>Buscar loja ou produto</span>
  </button>

  {#if banners.length > 0}
    <section class="banners" aria-label="Destaques">
      <div class="carousel" bind:this={carousel} onscroll={onCarouselScroll}>
        {#each banners as b (b.id)}
          <button
            type="button"
            class="slide"
            class:linked={!!b.restaurant_id}
            onclick={() => openBanner(b)}
            aria-label={b.restaurant_id ? `${b.title} — abrir a loja` : b.title}
            tabindex={b.restaurant_id ? 0 : -1}
          >
            <img src={`${BASE}/banners/image.php?key=${encodeURIComponent(b.image_key)}`} alt={b.title} />
          </button>
        {/each}
      </div>
      {#if banners.length > 1}
        <div class="dots" role="tablist" aria-label="Escolher destaque">
          {#each banners as b, i (b.id)}
            <button
              type="button"
              class="dot"
              class:on={i === slide}
              role="tab"
              aria-selected={i === slide}
              aria-label={`Destaque ${i + 1} de ${banners.length}`}
              onclick={() => goTo(i)}
            ></button>
          {/each}
        </div>
      {/if}
    </section>
  {/if}

  {#if popular.length > 0}
    <section class="popular" aria-label="Queridinhos">
      <p class="section-title"><i class="bi bi-heart-fill"></i> Queridinhos da cidade</p>
      <div class="popular-row">
        {#each popular as item (item.id)}
          <button
            type="button"
            class="popular-card fuu-card"
            onclick={() => onOpenRestaurant({ id: item.restaurant_id, name: item.restaurant_name })}
          >
            <div class="popular-photo"><MenuPhoto photoKey={item.photo_key} alt={item.name} /></div>
            <p class="popular-name">{item.name}</p>
            <p class="popular-store">{item.restaurant_name}</p>
            <p class="popular-price">{money(item.price)}</p>
          </button>
        {/each}
      </div>
    </section>
  {/if}

  <div class="categories">
    <button type="button" class="chip" class:selected={category === null} onclick={() => (category = null)}>
      Tudo
    </button>
    {#if favorites.size > 0}
      <button type="button" class="chip" class:selected={category === FAVORITES} onclick={() => (category = FAVORITES)}>
        <i class="bi bi-heart-fill"></i> Favoritas
      </button>
    {/if}
    {#each chips as c (c)}
      <button type="button" class="chip" class:selected={category === c} onclick={() => (category = c)}>
        {c}
      </button>
    {/each}
  </div>

  <div class="section-header">
    <p class="section-label">{category === FAVORITES ? 'SUAS FAVORITAS ABERTAS AGORA' : 'ABERTOS AGORA'}</p>
  </div>

  {#if loading}
    <p class="empty">Carregando lojas…</p>
  {:else if shown.length === 0}
    <p class="empty">
      {#if category === FAVORITES}
        Nenhuma favorita aberta agora.
      {:else if category}
        Nenhuma loja de {category} aberta agora em {location.city.name}.
      {:else}
        Nenhuma loja aberta agora em {location.city.name}.
      {/if}
    </p>
  {:else}
    <div class="restaurant-list">
      {#each shown as r (r.id)}
        <div class="restaurant-card fuu-card">
          <button type="button" class="open" onclick={() => onOpenRestaurant(r)}>
            <StoreLogo logoKey={r.logo_key} name={r.name} size={54} />
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
          </button>
          <div class="side">
            <span class={r.is_open ? 'fuu-badge-confirmed' : 'fuu-badge-wait'}>
              {r.is_open ? 'Aberto' : 'Fechado'}
            </span>
            <button
              type="button"
              class="heart"
              class:on={favorites.has(r.id)}
              aria-pressed={favorites.has(r.id)}
              aria-label={favorites.has(r.id) ? `Tirar ${r.name} das favoritas` : `Favoritar ${r.name}`}
              onclick={() => toggleFavorite(r)}
            >
              <i class={favorites.has(r.id) ? 'bi bi-heart-fill' : 'bi bi-heart'}></i>
            </button>
          </div>
        </div>
      {/each}
    </div>
    {#if nextOffset !== null}
      <button type="button" class="more" disabled={loadingMore} onclick={loadMore}>
        {loadingMore ? 'Carregando…' : 'Ver mais lojas'}
      </button>
    {/if}
  {/if}
</div>

<style>
  .more {
    display: block;
    width: 100%;
    margin-top: 12px;
    padding: 12px;
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 12px;
    background: var(--fuu-white);
    font-family: inherit;
    font-weight: 700;
    font-size: 14px;
    color: var(--fuu-ink-2);
  }
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

  /* Banners: 8:3, o mesmo formato que o admin sobe (1200 × 450). */
  .banners {
    margin: 0 -20px 16px;
  }
  .carousel {
    display: flex;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scrollbar-width: none;
  }
  .carousel::-webkit-scrollbar {
    display: none;
  }
  .slide {
    flex: 0 0 100%;
    scroll-snap-align: center;
    padding: 0 20px;
    border: none;
    background: none;
    cursor: default;
  }
  .slide.linked {
    cursor: pointer;
  }
  .slide img {
    width: 100%;
    aspect-ratio: 8 / 3;
    object-fit: cover;
    border-radius: var(--fuu-radius-card);
    display: block;
    background: var(--fuu-line-5);
  }
  .dots {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 8px;
  }
  .dot {
    width: 7px;
    height: 7px;
    padding: 0;
    border-radius: 50%;
    border: none;
    background: var(--fuu-line-1);
  }
  .dot.on {
    width: 18px;
    border-radius: 4px;
    background: var(--fuu-ink-3);
  }

  /* Queridinhos: faixa com rolagem lateral, até 12 itens. */
  .popular {
    margin-bottom: 16px;
  }
  .section-title {
    font-family: var(--fuu-font-display);
    font-weight: 800;
    font-size: 16px;
    color: var(--fuu-ink-1);
    margin: 0 0 10px;
  }
  .section-title i {
    color: var(--fuu-red);
    font-size: 14px;
  }
  .popular-row {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    margin: 0 -20px;
    padding: 0 20px 4px;
    scrollbar-width: none;
  }
  .popular-card {
    flex: 0 0 132px;
    padding: 8px;
    text-align: left;
    display: grid;
    gap: 2px;
  }
  .popular-photo {
    width: 100%;
    aspect-ratio: 1;
    border-radius: 9px;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-5);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    overflow: hidden;
    margin-bottom: 6px;
  }
  .popular-name {
    margin: 0;
    font-weight: 600;
    font-size: 13px;
    color: var(--fuu-ink-1);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .popular-store {
    margin: 0;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .popular-price {
    margin: 2px 0 0;
    font-family: var(--fuu-font-mono);
    font-size: 13px;
    font-weight: 500;
    color: var(--fuu-ink-1);
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
  .chip i {
    font-size: 11px;
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
    gap: 8px;
    padding: 12px;
  }
  .open {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    text-align: left;
    background: none;
    border: none;
    padding: 0;
    font-family: inherit;
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
  .side {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
  }
  /* Coração em tinta, não em vermelho: vermelho cheio é da ação principal. */
  .heart {
    width: var(--fuu-tap-customer);
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    border: none;
    background: none;
    color: var(--fuu-ink-5);
    font-size: 19px;
    padding: 0;
  }
  .heart.on {
    color: var(--fuu-ink-1);
  }
</style>
