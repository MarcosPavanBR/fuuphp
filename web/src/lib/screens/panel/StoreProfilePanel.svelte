<script>
  import { api, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import StoreLogo from '../../components/StoreLogo.svelte';

  // A vitrine da loja no app do cliente: logo e categoria (aba Loja).
  // restaurants/store_profile.php e restaurants/logo.php. O logo é o que o
  // cliente vê no card da Home e no topo da loja; a categoria é o atalho da
  // Home em que a loja aparece.
  let profile = $state(null);
  let category = $state('');
  let uploading = $state(false);
  let saving = $state(false);
  let fileInput = $state();

  async function load() {
    try {
      profile = await api.get('/restaurants/store_profile.php', { token: staffToken() });
      category = profile.category ?? '';
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar a vitrine da loja.');
    }
  }

  $effect(() => {
    load();
  });

  async function upload(event) {
    // Guarda o campo agora: depois do await, event.currentTarget é null.
    const input = event.currentTarget;
    const file = input.files?.[0];
    if (!file) return;
    uploading = true;
    try {
      const form = new FormData();
      form.append('logo', file);
      const res = await api.post('/restaurants/logo.php', { token: staffToken(), form });
      profile = { ...profile, logo_key: res.logo_key };
      toastr.success('Logo atualizado. É o que o cliente vê na Home.');
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra subir o logo.');
    } finally {
      uploading = false;
      input.value = '';
    }
  }

  async function saveCategory() {
    saving = true;
    try {
      await api.post('/restaurants/store_profile.php', { token: staffToken(), body: { category } });
      profile = { ...profile, category };
      toastr.success(`Sua loja aparece em "${category}" na Home.`);
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra trocar a categoria.');
    } finally {
      saving = false;
    }
  }
</script>

<section class="fuu-card vitrine">
  <p class="section-label">VITRINE NO APP</p>
  {#if profile === null}
    <p class="muted">Carregando…</p>
  {:else}
    <div class="row-top">
      <StoreLogo logoKey={profile.logo_key} name={profile.name} size={72} />
      <div class="logo-actions">
        <strong>{profile.name}</strong>
        <p class="muted">
          Logo quadrado, JPEG, PNG ou WEBP, até 8 MB. O centro da imagem é o que aparece.
        </p>
        <input type="file" accept="image/jpeg,image/png,image/webp" bind:this={fileInput} onchange={upload} hidden />
        <button type="button" class="ghost" disabled={uploading} onclick={() => fileInput.click()}>
          <i class="bi bi-upload"></i> {uploading ? 'Enviando…' : profile.logo_key ? 'Trocar logo' : 'Enviar logo'}
        </button>
      </div>
    </div>
    <label class="cat">
      <span>Categoria na Home</span>
      <div class="cat-row">
        <select bind:value={category}>
          <option value="" disabled>Escolha</option>
          {#each profile.categories as c (c)}<option value={c}>{c}</option>{/each}
        </select>
        <button
          type="button"
          class="btn-fuu-primary"
          disabled={saving || !category || category === profile.category}
          onclick={saveCategory}
        >
          {saving ? 'Salvando…' : 'Salvar'}
        </button>
      </div>
    </label>
  {/if}
</section>

<style>
  .vitrine {
    padding: 16px;
    margin-bottom: 14px;
    display: grid;
    gap: 14px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  .row-top {
    display: flex;
    gap: 14px;
    align-items: center;
  }
  .logo-actions {
    display: grid;
    gap: 4px;
    justify-items: start;
    min-width: 0;
  }
  .muted {
    font-size: 12.5px;
    color: var(--fuu-ink-4);
    margin: 0;
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
    margin-top: 4px;
  }
  .cat {
    display: grid;
    gap: 6px;
    font-size: 13px;
    color: var(--fuu-ink-3);
  }
  .cat-row {
    display: flex;
    gap: 8px;
  }
  select {
    flex: 1;
    min-width: 0;
    border: 1px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 9px 12px;
    font-family: inherit;
    font-size: 14px;
    background: var(--fuu-white);
  }
</style>
