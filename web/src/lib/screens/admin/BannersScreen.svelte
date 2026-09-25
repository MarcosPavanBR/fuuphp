<script>
  import swal from 'sweetalert';
  import { api, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Aba Banners: o carrossel da Home do cliente (admin/banners.php, migração
  // 037). É a vitrine que a plataforma pode vender pras lojas: banner com
  // link leva direto pra loja.
  //
  // Imagem larga, 1200 × 450 (8:3) de preferência: é a proporção em que a
  // Home mostra. Datas no dia da cidade do banner (Brasília quando é
  // de todas); sem data de fim, fica no ar até
  // ser desligado.
  const SITUATION = {
    no_ar: { label: 'No ar', cls: 'live' },
    agendado: { label: 'Agendado', cls: 'wait' },
    encerrado: { label: 'Encerrado', cls: 'off' },
    desligado: { label: 'Desligado', cls: 'off' },
  };
  const EMPTY = { title: '', city: '', store: '', starts: '', ends: '', position: 0 };

  let banners = $state(null);
  let maxLive = $state(8);
  let cities = $state([]);
  let stores = $state([]);
  let form = $state({ ...EMPTY });
  let file = $state(null);
  let preview = $state(null);
  let errors = $state({});
  let saving = $state(false);
  let busyId = $state(null);
  let fileInput = $state();

  let liveCount = $derived((banners ?? []).filter((b) => b.situation === 'no_ar').length);

  async function load() {
    try {
      const res = await api.get('/admin/banners.php', { token: adminToken() });
      banners = res.banners;
      maxLive = res.max_live;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar os banners.');
    }
  }

  $effect(() => {
    load();
    api
      .get('/admin/cities.php', { token: adminToken() })
      .then((res) => (cities = res.cities))
      .catch(() => {});
  });

  // As lojas do link: as aprovadas da cidade escolhida (a mesma lista da Home).
  $effect(() => {
    const city = form.city;
    form.store = '';
    stores = [];
    if (!city) return;
    api
      // limit 200: o seletor de loja do banner precisa de todas, não da 1ª página.
      .get('/restaurants/list.php', { query: { city_ibge_code: city, open_only: '0', limit: 200 } })
      .then((res) => {
        if (form.city === city) stores = res.restaurants;
      })
      .catch(() => {});
  });

  function pick(event) {
    const chosen = event.currentTarget.files?.[0] ?? null;
    file = chosen;
    if (preview) URL.revokeObjectURL(preview);
    preview = chosen ? URL.createObjectURL(chosen) : null;
  }

  function reset() {
    form = { ...EMPTY };
    file = null;
    if (preview) URL.revokeObjectURL(preview);
    preview = null;
    errors = {};
    if (fileInput) fileInput.value = '';
  }

  async function create(event) {
    event.preventDefault();
    errors = {};
    if (!file) {
      errors = { image: 'escolha a imagem' };
      return;
    }
    saving = true;
    try {
      const data = new FormData();
      data.append('title', form.title.trim());
      data.append('image', file);
      data.append('city_ibge_code', form.city);
      data.append('restaurant_id', form.store);
      data.append('starts_on', form.starts);
      data.append('ends_on', form.ends);
      data.append('position', String(form.position || 0));
      await api.post('/admin/banners.php', { token: adminToken(), form: data });
      toastr.success('Banner criado.');
      reset();
      await load();
    } catch (e) {
      errors = e.fields ?? {};
      toastr.error(e.message ?? 'Não deu pra criar o banner.');
    } finally {
      saving = false;
    }
  }

  async function act(banner, action, extra = {}) {
    busyId = banner.id;
    try {
      await api.post('/admin/banners.php', { token: adminToken(), body: { id: banner.id, action, ...extra } });
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar.');
    } finally {
      busyId = null;
    }
  }

  async function remove(banner) {
    const ok = await swal({
      title: 'Apagar banner?',
      text: `"${banner.title}" sai do app e da lista. Pra só tirar do ar, use Desligar.`,
      buttons: ['Cancelar', 'Apagar'],
    });
    if (ok) act(banner, 'delete');
  }

  function day(value) {
    const d = parsePgTimestamp(value);
    return d ? d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) : '';
  }
  function lastDay(value) {
    // ends_at é a meia-noite depois do último dia: mostra o último dia.
    const d = parsePgTimestamp(value);
    if (!d) return '';
    d.setMinutes(d.getMinutes() - 1);
    return d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
  }
</script>

<section class="banners">
  <div class="fuu-card block">
    <p class="section">NOVO BANNER</p>
    <form class="grid" onsubmit={create}>
      <div class="image-pick full" class:bad={errors.image}>
        <button type="button" class="drop" onclick={() => fileInput.click()} aria-label="Escolher imagem do banner">
          {#if preview}
            <img src={preview} alt="Prévia do banner" />
          {:else}
            <span><i class="bi bi-image"></i> Escolher imagem · 1200 × 450 (8:3), JPEG, PNG ou WEBP</span>
          {/if}
        </button>
        <input type="file" accept="image/jpeg,image/png,image/webp" bind:this={fileInput} onchange={pick} hidden />
        {#if errors.image}<small>{errors.image}</small>{/if}
      </div>
      <label class="full" class:bad={errors.title}>
        <span>Título (lido pelo leitor de tela; não aparece escrito)</span>
        <input maxlength="80" placeholder="Semana da pizza: 20% na Pizzaria Nonna" bind:value={form.title} />
        {#if errors.title}<small>{errors.title}</small>{/if}
      </label>
      <label class:bad={errors.city_ibge_code}>
        <span>Cidade</span>
        <select bind:value={form.city}>
          <option value="">Todas as cidades</option>
          {#each cities.filter((c) => c.active) as c (c.ibge)}<option value={c.ibge}>{c.name} — {c.uf}</option>{/each}
        </select>
        {#if errors.city_ibge_code}<small>{errors.city_ibge_code}</small>{/if}
      </label>
      <label class:bad={errors.restaurant_id}>
        <span>Leva pra loja (opcional)</span>
        <select bind:value={form.store} disabled={!form.city}>
          <option value="">{form.city ? 'Nenhuma: só a imagem' : 'Escolha a cidade antes'}</option>
          {#each stores as s (s.id)}<option value={s.id}>{s.name}</option>{/each}
        </select>
        {#if errors.restaurant_id}<small>{errors.restaurant_id}</small>{/if}
      </label>
      <label class:bad={errors.starts_on}>
        <span>Começa em</span>
        <input type="date" bind:value={form.starts} />
        {#if errors.starts_on}<small>{errors.starts_on}</small>{/if}
      </label>
      <label class:bad={errors.ends_on}>
        <span>Último dia (opcional)</span>
        <input type="date" bind:value={form.ends} />
        {#if errors.ends_on}<small>{errors.ends_on}</small>{/if}
      </label>
      <label class:bad={errors.position}>
        <span>Ordem (menor primeiro)</span>
        <input type="number" min="0" max="999" bind:value={form.position} />
        {#if errors.position}<small>{errors.position}</small>{/if}
      </label>
      <div class="actions full">
        <button type="submit" class="btn-fuu-primary" disabled={saving}>{saving ? 'Enviando…' : 'Criar banner'}</button>
        {#if file || form.title}<button type="button" class="ghost" onclick={reset}>Limpar</button>{/if}
      </div>
    </form>
  </div>

  <div class="fuu-card block">
    <p class="section">BANNERS · {liveCount} NO AR (A HOME MOSTRA ATÉ {maxLive})</p>
    {#if banners === null}
      <p class="empty">Carregando…</p>
    {:else if banners.length === 0}
      <p class="empty">Nenhum banner ainda. Sem banner, a Home vai direto pras lojas.</p>
    {:else}
      <div class="list">
        {#each banners as b (b.id)}
          {@const sit = SITUATION[b.situation] ?? SITUATION.desligado}
          <article class="item" class:dim={b.situation !== 'no_ar'}>
            <img
              class="thumb"
              src={`${BASE}/banners/image.php?key=${encodeURIComponent(b.image_key)}`}
              alt={b.title}
              loading="lazy"
            />
            <div class="info">
              <strong>{b.title}</strong>
              <span>{[b.city_name ?? 'Todas as cidades', b.restaurant_name ? `leva pra ${b.restaurant_name}` : null].filter(Boolean).join(' · ')}</span>
              <span class="fuu-mono">{`de ${day(b.starts_at)} ${b.ends_at ? `até ${lastDay(b.ends_at)}` : 'sem fim'} · ordem ${b.position}`}</span>
            </div>
            <span class={`chip ${sit.cls}`}>{sit.label}</span>
            <div class="buttons">
              <button type="button" class="ghost" disabled={busyId === b.id} onclick={() => act(b, 'position', { position: Math.max(0, b.position - 1) })} aria-label="Subir na ordem">
                <i class="bi bi-arrow-up"></i>
              </button>
              <button type="button" class="ghost" disabled={busyId === b.id} onclick={() => act(b, 'position', { position: Math.min(999, b.position + 1) })} aria-label="Descer na ordem">
                <i class="bi bi-arrow-down"></i>
              </button>
              <button type="button" class="ghost" disabled={busyId === b.id} onclick={() => act(b, 'toggle')}>
                {b.active ? 'Desligar' : 'Ligar'}
              </button>
              <button type="button" class="ghost danger" disabled={busyId === b.id} onclick={() => remove(b)}>Apagar</button>
            </div>
          </article>
        {/each}
      </div>
    {/if}
  </div>
</section>

<style>
  .banners {
    display: grid;
    gap: 12px;
  }
  .block {
    padding: 16px;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 12px;
  }
  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 10px 12px;
  }
  .full {
    grid-column: 1 / -1;
  }
  label {
    display: grid;
    gap: 4px;
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    min-width: 0;
  }
  input,
  select {
    border: 1px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 9px 12px;
    font-family: inherit;
    font-size: 14px;
    background: var(--fuu-white);
    width: 100%;
    min-width: 0;
  }
  .bad input,
  .bad select,
  .bad .drop {
    border-color: var(--fuu-alert);
  }
  small {
    color: var(--fuu-alert);
    font-size: 11.5px;
  }
  .drop {
    width: 100%;
    aspect-ratio: 8 / 3;
    max-height: 220px;
    border: 1.5px dashed var(--fuu-line-1);
    border-radius: var(--fuu-radius-card);
    background: var(--fuu-paper);
    color: var(--fuu-ink-4);
    font-family: inherit;
    font-size: 13px;
    overflow: hidden;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .drop img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .actions {
    display: flex;
    gap: 8px;
  }
  .ghost {
    border: 1.5px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: 10px;
    padding: 7px 12px;
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .ghost.danger {
    color: var(--fuu-alert);
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .list {
    display: grid;
    gap: 10px;
  }
  .item {
    display: grid;
    grid-template-columns: 160px minmax(0, 1fr) auto;
    grid-template-areas: 'thumb info chip' 'thumb buttons buttons';
    gap: 6px 12px;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--fuu-line-5);
  }
  .item.dim .thumb,
  .item.dim .info {
    opacity: 0.55;
  }
  .thumb {
    grid-area: thumb;
    width: 160px;
    aspect-ratio: 8 / 3;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--fuu-line-3);
  }
  .info {
    grid-area: info;
    display: grid;
    gap: 2px;
    min-width: 0;
  }
  .info span {
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .chip {
    grid-area: chip;
    font-size: 11.5px;
    font-weight: 700;
    padding: 4px 9px;
    border-radius: var(--fuu-radius-pill);
    white-space: nowrap;
  }
  .chip.live {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf);
  }
  .chip.wait {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .chip.off {
    background: var(--fuu-line-5);
    color: var(--fuu-ink-4);
  }
  .buttons {
    grid-area: buttons;
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }
  @media (max-width: 560px) {
    .item {
      grid-template-columns: minmax(0, 1fr) auto;
      grid-template-areas: 'thumb thumb' 'info chip' 'buttons buttons';
    }
    .thumb {
      width: 100%;
    }
  }
</style>
