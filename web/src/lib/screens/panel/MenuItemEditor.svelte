<script>
  import { api, BASE } from '../../services/api.js';
  import MenuPhoto from '../../components/MenuPhoto.svelte';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';

  // O painel lateral da tela 11.3: criar ou editar um item e publicar.
  // Separado de MenuScreen.svelte (auditoria ARQ-02) -- a lista cuida de
  // filtro e disponibilidade; aqui mora o rascunho.
  //
  // `draft` vem da lista e é editado no lugar (bindable): o "editando agora"
  // da lista lê o mesmo objeto. O rascunho só vive no navegador; o servidor
  // conhece o item publicado. Fechar sem publicar perde a edição, e o painel
  // pergunta antes.
  //
  // onClose: fechar o painel (a lista zera o draft).
  // onChanged: algo foi salvo no servidor (publicar, foto) -- recarregar.
  let { draft = $bindable(), onClose, onChanged } = $props();

  let saving = $state(false);
  let uploadingPhoto = $state(false);

  function touch() {
    if (draft) draft.dirty = true;
  }

  function close() {
    if (draft?.dirty && !confirm('As alterações ainda não foram publicadas. Descartar?')) return;
    onClose();
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

  async function uploadPhoto(event) {
    const file = event.currentTarget.files?.[0];
    event.currentTarget.value = '';
    if (!file || !draft?.id) return;
    uploadingPhoto = true;
    try {
      const form = new FormData();
      form.append('menu_item_id', String(draft.id));
      form.append('photo', file);
      const res = await fetch(`${BASE}/restaurants/menu_photo.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${staffToken()}` },
        body: form,
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message ?? 'Não deu pra subir a foto.');
      draft.photo_key = data.photo_key;
      await onChanged();
      toastr.success('Foto atualizada — já aparece no app.');
    } catch (e) {
      toastr.error(e.message);
    } finally {
      uploadingPhoto = false;
    }
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
      onClose();
      await onChanged();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra publicar o item.');
    } finally {
      saving = false;
    }
  }
</script>

  <aside class="editor">
    <p class="editor-title">{draft.id > 0 ? draft.name : 'Novo item'}</p>
    <p class="editor-sub">
      {draft.dirty ? 'rascunho · publique para valer no app' : 'publicado — igual ao que o cliente vê'}
    </p>

    <!-- Foto: sobe na hora pra o item já salvo (restaurants/menu_photo.php),
         fora do rascunho -- foto não muda preço nem regra. Item novo
         precisa ser publicado antes, porque a foto é presa ao id. -->
    <label class="photo-slot" class:has-photo={draft.photo_key}>
      <MenuPhoto photoKey={draft.photo_key} alt={draft.name} />
      {#if draft.id > 0}
        <span class="photo-action">{uploadingPhoto ? 'Enviando…' : draft.photo_key ? 'Trocar foto' : 'Adicionar foto'}</span>
        <input type="file" accept="image/jpeg,image/png,image/webp" hidden disabled={uploadingPhoto} onchange={uploadPhoto} />
      {:else}
        <span class="photo-action">publique o item pra adicionar a foto</span>
      {/if}
    </label>

    <label class="field">
      <span>Nome</span>
      <input type="text" maxlength="80" bind:value={draft.name} oninput={touch} />
    </label>
    <label class="field">
      <span>Descrição</span>
      <textarea rows="3" maxlength="500" bind:value={draft.description} oninput={touch}></textarea>
    </label>
    <div class="field-row">
      <label class="field">
        <span>Categoria</span>
        <input type="text" maxlength="40" bind:value={draft.category} oninput={touch} />
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
        <input class="v-group" type="text" placeholder="Grupo" maxlength="40" bind:value={variant.group_name} oninput={touch} />
        <input class="v-name" type="text" placeholder="Nome" maxlength="60" bind:value={variant.name} oninput={touch} />
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

<style>
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
  .photo-slot :global(i) {
    font-size: 22px;
  }
  .photo-slot {
    position: relative;
    overflow: hidden;
    cursor: pointer;
  }
  .photo-slot.has-photo {
    height: 160px;
    padding: 0;
  }
  .photo-action {
    font-weight: 600;
  }
  .photo-slot.has-photo .photo-action {
    position: absolute;
    right: 8px;
    bottom: 8px;
    background: var(--fuu-white);
    color: var(--fuu-ink-1);
    border-radius: var(--fuu-radius-pill);
    padding: 4px 10px;
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
    .editor {
      width: 100%;
      border-left: none;
      border-top: 1px solid var(--fuu-line-3);
      min-height: 0;
    }
  }
</style>
