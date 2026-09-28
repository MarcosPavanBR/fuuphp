<script>
  import { api } from '../../services/api.js';
  import MenuItemEditor from './MenuItemEditor.svelte';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 11.3 — "Cardápio: item, preço e disponibilidade".
  //
  // "Esgotar é um toque na lista; editar abre painel lateral sem trocar de
  // tela. Cada item mostra quanto vendeu na semana — é esse número que
  // decide preço."
  //
  // O painel de edição (rascunho, variações, foto, publicar) é o
  // MenuItemEditor.svelte; aqui ficam a lista, o filtro e o esgotar.
  let data = $state(null);
  let category = $state(null);
  let query = $state('');
  let draft = $state(null);
  let busyId = $state(null);

  async function load() {
    try {
      data = await api.get('/restaurants/menu_admin.php', { token: staffToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar o cardápio.');
    }
  }

  $effect(() => {
    load();
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function parseVariants(raw) {
    if (raw === null || raw === undefined) return [];
    return typeof raw === 'string' ? JSON.parse(raw) : raw;
  }

  // "M R$ 46,90 · G R$ 58,90 · 6 adicionais": a linha do mock é o preço base
  // somado a cada variação de tamanho, porque é isso que o cliente paga --
  // mostrar só o price_delta ("+R$ 12,00") esconderia o número da decisão.
  function subtitle(item) {
    const variants = parseVariants(item.variants);
    const sizes = variants.filter((v) => /tamanho/i.test(v.group_name));
    const extras = variants.length - sizes.length;
    const parts = sizes.map(
      (v) => `${v.name} ${money(Number(item.price) + Number(v.price_delta))}`
    );
    if (parts.length === 0) parts.push(money(item.price));
    if (extras > 0) parts.push(`${extras} ${extras === 1 ? 'adicional' : 'adicionais'}`);
    return parts.join(' · ');
  }

  function soldOutLabel(item) {
    if (item.sold_out_at === null || item.sold_out_at === undefined) return 'Indisponível';
    const when = parsePgTimestamp(item.sold_out_at);
    const today = when && when.toDateString() === new Date().toDateString();
    return today ? 'Esgotada hoje' : `Esgotada desde ${when?.toLocaleDateString('pt-BR') ?? '—'}`;
  }

  let filtered = $derived(
    (data?.items ?? []).filter(
      (item) =>
        (category === null || (item.category ?? 'Sem categoria') === category) &&
        (query.trim() === '' || item.name.toLocaleLowerCase('pt-BR').includes(query.toLocaleLowerCase('pt-BR')))
    )
  );

  async function toggle(item) {
    busyId = item.id;
    try {
      await api.post('/restaurants/menu_availability.php', {
        token: staffToken(),
        body: { menu_item_id: item.id, available: !item.available },
      });
      toastr.success(item.available ? `${item.name} esgotado.` : `${item.name} de volta ao cardápio.`);
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra mudar a disponibilidade.');
    } finally {
      busyId = null;
    }
  }

  function edit(item) {
    draft = {
      id: item.id,
      name: item.name,
      description: item.description ?? '',
      category: item.category ?? '',
      price: Number(item.price),
      available: item.available,
      photo_key: item.photo_key ?? null,
      variants: parseVariants(item.variants).map((v) => ({ ...v, price_delta: Number(v.price_delta) })),
      dirty: false,
    };
  }

  function create() {
    draft = {
      id: 0,
      name: '',
      description: '',
      category: category ?? '',
      price: 0,
      available: true,
      photo_key: null,
      variants: [],
      dirty: true,
    };
  }
</script>

{#if data === null}
  <p class="loading">Carregando cardápio…</p>
{:else}
  <div class="menu-layout">
    <aside class="cats">
      <button type="button" class="cat" class:on={category === null} onclick={() => (category = null)}>
        Tudo <span class="count">{data.items.length}</span>
      </button>
      {#each data.categories as cat (cat.name)}
        <button type="button" class="cat" class:on={category === cat.name} onclick={() => (category = cat.name)}>
          {cat.name} <span class="count">{cat.count}</span>
        </button>
      {/each}
      <!-- "Nova categoria" do mock não é um botão próprio: categoria é texto
           em menu_items (não há tabela), então ela nasce no primeiro item que
           usar o nome. Um botão que só abrisse um campo vazio criaria
           categoria fantasma, sem item nenhum dentro. -->
      <p class="cat-note">Categoria nova nasce ao publicar um item com o nome dela.</p>
    </aside>

    <main class="list">
      <div class="list-head">
        <div class="search">
          <i class="bi bi-search"></i>
          <input type="text" placeholder="Buscar item" bind:value={query} />
        </div>
        <button type="button" class="btn-fuu-primary new" onclick={create}>
          <i class="bi bi-plus-lg"></i> Novo item
        </button>
      </div>

      <div class="items">
        {#each filtered as item (item.id)}
          <article class="item" class:editing={draft?.id === item.id} class:off={!item.available}>
            <button type="button" class="item-body" onclick={() => edit(item)}>
              <span class="photo"><i class="bi bi-image"></i></span>
              <span class="item-text">
                <span class="item-name">{item.name}</span>
                {#if draft?.id === item.id && draft.dirty}
                  <span class="item-sub editing-note">editando agora — alterações não publicadas</span>
                {:else if !item.available}
                  <span class="item-sub sold-out">{soldOutLabel(item)}</span>
                {:else}
                  <span class="item-sub">{subtitle(item)}</span>
                {/if}
              </span>
            </button>
            <span class="sold">
              {#if Number(item.sold_week) > 0}
                vendeu <strong>{item.sold_week}×</strong><span class="sold-sub">esta semana</span>
              {:else}
                <span class="sold-sub">sem venda esta semana</span>
              {/if}
            </span>
            <label class="switch-row" title={item.available ? 'Marcar como esgotado' : 'Voltar ao cardápio'}>
              <input
                type="checkbox"
                checked={item.available}
                disabled={busyId === item.id}
                onchange={() => toggle(item)}
              />
              <span class="switch"></span>
              <span class="sr-only">{item.available ? 'Disponível' : 'Esgotado'}</span>
            </label>
          </article>
        {:else}
          <p class="empty">Nenhum item nesse filtro.</p>
        {/each}
      </div>
    </main>

    {#if draft}
      <MenuItemEditor bind:draft onClose={() => (draft = null)} onChanged={load} />
    {/if}
  </div>
{/if}

<style>
  .menu-layout {
    display: flex;
    align-items: flex-start;
    min-height: calc(100vh - 63px);
  }
  .loading {
    padding: 40px 22px;
    color: var(--fuu-ink-5);
  }
  .cats {
    width: 210px;
    flex: none;
    background: var(--fuu-white);
    border-right: 1px solid var(--fuu-line-3);
    padding: 16px;
    min-height: calc(100vh - 63px);
  }
  .cat {
    display: flex;
    width: 100%;
    align-items: center;
    gap: 8px;
    border: none;
    background: none;
    border-radius: 10px;
    padding: 13px 15px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
    font-weight: 600;
    color: var(--fuu-ink-2);
    text-align: left;
  }
  .cat.on {
    background: var(--fuu-line-5);
    color: var(--fuu-ink-1);
    font-weight: 800;
  }
  .count {
    margin-left: auto;
    font-size: 12px;
    color: var(--fuu-ink-6);
  }
  .cat-note {
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    line-height: 1.5;
    border: 1.5px dashed var(--fuu-line-1);
    border-radius: 10px;
    padding: 12px;
    margin: 12px 0 0;
  }
  .list {
    flex: 1;
    padding: 20px;
    min-width: 0;
  }
  .list-head {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .search {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 0 14px;
    color: var(--fuu-ink-5);
  }
  .search input {
    flex: 1;
    border: none;
    outline: none;
    padding: 14px 0;
    font-family: var(--fuu-font-body);
    font-size: 14px;
    color: var(--fuu-ink-1);
    background: none;
  }
  .new {
    width: auto;
    padding: 14px 20px;
  }
  .items {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 16px;
  }
  .item {
    display: flex;
    align-items: center;
    gap: 14px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 14px;
  }
  .item.editing {
    border: 2px solid var(--fuu-red);
  }
  .item.off {
    background: var(--fuu-line-6);
  }
  .item-body {
    display: flex;
    align-items: center;
    gap: 14px;
    flex: 1;
    min-width: 0;
    border: none;
    background: none;
    text-align: left;
    padding: 0;
  }
  .photo {
    width: 64px;
    height: 64px;
    border-radius: 10px;
    flex: 0 0 auto;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-5);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
  }
  .item.off .photo {
    opacity: 0.55;
  }
  .item-text {
    min-width: 0;
  }
  .item-name {
    display: block;
    font-weight: 800;
    font-size: 15px;
    color: var(--fuu-ink-1);
  }
  .item.off .item-name {
    color: var(--fuu-ink-6);
  }
  .item-sub {
    display: block;
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    margin-top: 2px;
  }
  .item-sub.sold-out {
    color: var(--fuu-alert);
    font-weight: 700;
  }
  .item-sub.editing-note {
    color: var(--fuu-red);
    font-weight: 700;
  }
  .sold {
    text-align: right;
    font-size: 12px;
    color: var(--fuu-ink-2);
    line-height: 1.5;
    flex: none;
  }
  .sold strong {
    color: var(--fuu-ink-1);
  }
  .sold-sub {
    display: block;
    color: var(--fuu-ink-6);
  }
  .switch-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: none;
    cursor: pointer;
  }
  .switch-row input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
  }
  .switch {
    width: 46px;
    height: 27px;
    border-radius: 15px;
    background: var(--fuu-line-1);
    position: relative;
    flex: 0 0 auto;
    transition: background 0.15s;
  }
  .switch::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 21px;
    height: 21px;
    border-radius: 50%;
    background: var(--fuu-white);
    transition: left 0.15s;
  }
  .switch-row input:checked + .switch {
    background: var(--fuu-leaf);
  }
  .switch-row input:checked + .switch::after {
    left: 22px;
  }
  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  @media (max-width: 1100px) {
    .menu-layout {
      flex-wrap: wrap;
    }
  }
</style>
