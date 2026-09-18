<script>
  import { api } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { staffToken } from '../../staffSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Tela 11.3 — "Cardápio: item, preço e disponibilidade".
  //
  // "Esgotar é um toque na lista; editar abre painel lateral sem trocar de
  // tela. Cada item mostra quanto vendeu na semana — é esse número que
  // decide preço."
  //
  // O rascunho ("alterações não publicadas") vive aqui no navegador: o
  // servidor só conhece o item publicado. Fechar o painel sem publicar perde
  // a edição, e a tela diz isso antes de deixar fechar.
  let data = $state(null);
  let category = $state(null);
  let query = $state('');
  let draft = $state(null);
  let busyId = $state(null);
  let saving = $state(false);

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
      variants: [],
      dirty: true,
    };
  }

  function touch() {
    if (draft) draft.dirty = true;
  }

  function close() {
    if (draft?.dirty && !confirm('As alterações ainda não foram publicadas. Descartar?')) return;
    draft = null;
  }

  function addVariant() {
    draft.variants = [
      ...draft.variants,
      { group_name: 'Tamanho', name: '', price_delta: 0, required: true, max_selections: 1, position: draft.variants.length },
    ];
    touch();
  }

  function removeVariant(index) {
    draft.variants = draft.variants.filter((_, i) => i !== index);
    touch();
  }

  async function publish() {
    if (draft.name.trim() === '') {
      toastr.warning('O item precisa de um nome.');
      return;
    }
    saving = true;
    try {
      const body = {
        name: draft.name.trim(),
        description: draft.description.trim() === '' ? null : draft.description.trim(),
        category: draft.category.trim() === '' ? null : draft.category.trim(),
        price: Number(draft.price),
        available: draft.available,
        variants: draft.variants.map((v, i) => ({ ...v, position: i })),
      };
      if (draft.id > 0) body.id = draft.id;
      await api.post('/restaurants/menu_item.php', { token: staffToken(), body });
      toastr.success(`${body.name} publicado — já vale no app.`);
      draft = null;
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra publicar o item.');
    } finally {
      saving = false;
    }
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
      <aside class="editor">
        <p class="editor-title">{draft.id > 0 ? draft.name : 'Novo item'}</p>
        <p class="editor-sub">
          {draft.dirty ? 'rascunho · publique para valer no app' : 'publicado — igual ao que o cliente vê'}
        </p>

        <div class="photo-slot">
          <i class="bi bi-image"></i>
          <!-- Upload de foto do item não foi construído: o esquema tem
               menu_items.photo_key, mas não existe endpoint de upload de
               imagem de cardápio (o único upload do projeto é o comprovante
               de Pix). Um seletor que não sobe nada seria pior. -->
          <span>foto do item ainda não sobe por aqui</span>
        </div>

        <label class="field">
          <span>Nome</span>
          <input type="text" bind:value={draft.name} oninput={touch} />
        </label>
        <label class="field">
          <span>Descrição</span>
          <textarea rows="3" bind:value={draft.description} oninput={touch}></textarea>
        </label>
        <div class="field-row">
          <label class="field">
            <span>Categoria</span>
            <input type="text" bind:value={draft.category} oninput={touch} />
          </label>
          <label class="field">
            <span>Preço base</span>
            <input type="number" step="0.01" min="0" bind:value={draft.price} oninput={touch} class="fuu-mono" />
          </label>
        </div>

        <p class="field-label">
          Tamanhos e adicionais
          <button type="button" class="add" onclick={addVariant}><i class="bi bi-plus-lg"></i> Adicionar</button>
        </p>
        {#each draft.variants as variant, i (i)}
          <div class="variant">
            <input class="v-group" type="text" placeholder="Grupo" bind:value={variant.group_name} oninput={touch} />
            <input class="v-name" type="text" placeholder="Nome" bind:value={variant.name} oninput={touch} />
            <input
              class="v-delta fuu-mono"
              type="number"
              step="0.01"
              bind:value={variant.price_delta}
              oninput={touch}
            />
            <button type="button" class="v-del" aria-label="Remover" onclick={() => removeVariant(i)}>
              <i class="bi bi-trash"></i>
            </button>
          </div>
        {:else}
          <p class="no-variants">Sem variações — o item é vendido pelo preço base.</p>
        {/each}

        <label class="switch-row big">
          <input type="checkbox" bind:checked={draft.available} onchange={touch} />
          <span class="switch"></span>
          <span class="switch-label">Disponível agora</span>
        </label>

        <div class="editor-actions">
          <button type="button" class="discard" onclick={close}>Descartar</button>
          <button type="button" class="btn-fuu-primary publish" disabled={saving} onclick={publish}>
            {saving ? 'Publicando…' : 'Publicar'}
          </button>
        </div>
        <p class="tech fuu-mono">
          menu_items + item_variants<br />
          preço vem do servidor no checkout<br />
          cache de borda: ver README
        </p>
      </aside>
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
  .switch-row.big {
    margin-top: 16px;
  }
  .switch-label {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--fuu-ink-1);
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
  .editor {
    width: 350px;
    flex: none;
    background: var(--fuu-white);
    border-left: 1px solid var(--fuu-line-3);
    padding: 20px;
    min-height: calc(100vh - 63px);
  }
  .editor-title {
    font-size: 16px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    margin: 0;
  }
  .editor-sub {
    font-size: 12px;
    color: var(--fuu-ink-6);
    margin: 2px 0 0;
  }
  .photo-slot {
    height: 96px;
    border-radius: 11px;
    margin-top: 14px;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-5);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 11.5px;
    text-align: center;
    padding: 0 16px;
  }
  .photo-slot i {
    font-size: 22px;
  }
  .field {
    display: block;
    margin-top: 14px;
  }
  .field span {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-2);
    margin-bottom: 6px;
  }
  .field input,
  .field textarea {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 11px 12px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    color: var(--fuu-ink-1);
    background: var(--fuu-white);
  }
  .field-row {
    display: flex;
    gap: 10px;
  }
  .field-row .field {
    flex: 1;
  }
  .field-label {
    display: flex;
    align-items: center;
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-2);
    margin: 16px 0 8px;
  }
  .add {
    margin-left: auto;
    background: none;
    border: none;
    font-family: var(--fuu-font-body);
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-red);
  }
  .variant {
    display: flex;
    gap: 6px;
    margin-bottom: 7px;
  }
  .variant input {
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 9px 10px;
    font-family: var(--fuu-font-body);
    font-size: 12.5px;
    color: var(--fuu-ink-1);
    min-width: 0;
  }
  .v-group {
    width: 80px;
  }
  .v-name {
    flex: 1;
  }
  .v-delta {
    width: 78px;
  }
  .v-del {
    background: var(--fuu-line-5);
    border: none;
    border-radius: 9px;
    width: 34px;
    color: var(--fuu-ink-4);
  }
  .no-variants {
    font-size: 12px;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  .editor-actions {
    display: flex;
    gap: 10px;
    margin-top: 18px;
  }
  .discard {
    flex: 1;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 15px;
    font-family: var(--fuu-font-body);
    font-weight: 700;
    font-size: 14px;
    color: var(--fuu-ink-2);
  }
  .publish {
    flex: 1.4;
    padding: 15px;
  }
  .tech {
    font-size: 10px;
    color: var(--fuu-ink-6);
    margin-top: 14px;
    line-height: 1.6;
  }
  @media (max-width: 1100px) {
    .menu-layout {
      flex-wrap: wrap;
    }
    .editor {
      width: 100%;
      border-left: none;
      border-top: 1px solid var(--fuu-line-3);
      min-height: 0;
    }
  }
</style>
