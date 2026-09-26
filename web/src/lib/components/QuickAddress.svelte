<script>
  import { api } from '../services/api.js';
  import { toastr } from '../utils/toastr.js';

  // Passo de endereço do checkout (Fase 4): escolhe um endereço salvo ou
  // cadastra um rápido. O cadastro completo -- rótulo, padrão, edição, CEP
  // e área de entrega -- é a tela de endereços do perfil (Fase 6.1 e 14.3,
  // AddressesScreen.svelte); aqui fica só o essencial pra não tirar o
  // cliente do checkout.
  let { location, onReady } = $props();

  let addresses = $state(null);
  let creating = $state(false);
  let busy = $state(false);

  let street = $state('');
  let number = $state('');
  // `location` é prop fixa pro tempo de vida deste componente (não muda
  // depois do onboarding) -- só serve de valor inicial editável, não
  // precisa reagir a mudança (mesmo padrão de ItemModal.svelte).
  let neighborhood = $state(location?.neighborhood ?? '');
  let postalCode = $state('');

  async function load() {
    try {
      const data = await api.get('/addresses/list.php', { auth: true });
      addresses = data.addresses;
      if (addresses.length === 0) creating = true;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar seus endereços.');
      addresses = [];
      creating = true;
    }
  }
  load();

  async function createAddress() {
    if (!street.trim() || !postalCode.trim()) {
      toastr.warning('Preencha rua e CEP.');
      return;
    }
    busy = true;
    try {
      const data = await api.post('/addresses/create.php', {
        auth: true,
        body: {
          street: street.trim(),
          number: number.trim() || undefined,
          neighborhood: neighborhood.trim() || undefined,
          city: location?.city?.name ?? '',
          city_ibge_code: location?.city?.ibge ?? '',
          state: location?.uf ?? '',
          postal_code: postalCode,
          // O centro da cidade escolhida; a API recusa se faltar.
          lat: location?.city?.lat ?? location?.lat ?? null,
          lng: location?.city?.lng ?? location?.lng ?? null,
          is_default: true,
        },
      });
      toastr.success('Endereço salvo ✓');
      onReady(data.id, `${street.trim()}, ${number.trim() || 's/n'}`);
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar o endereço.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="quick-address fuu-card">

  {#if addresses === null}
    <p class="hint">Carregando endereços…</p>
  {:else if !creating}
    <h2 class="fuu-display">Entregar em</h2>
    <div class="list">
      {#each addresses as a (a.id)}
        <button
          type="button"
          class="address-option"
          onclick={() => onReady(a.id, `${a.street}, ${a.number ?? 's/n'}`)}
        >
          <span>{a.street}, {a.number ?? 's/n'} — {a.neighborhood ?? a.city}</span>
          <i class="bi bi-chevron-right"></i>
        </button>
      {/each}
    </div>
    <button type="button" class="link-btn" onclick={() => (creating = true)}>+ Usar outro endereço</button>
  {:else}
    <h2 class="fuu-display">Novo endereço</h2>
    <input maxlength="200" type="text" placeholder="Rua" bind:value={street} />
    <input maxlength="20" type="text" placeholder="Número" bind:value={number} />
    <input maxlength="100" type="text" placeholder="Bairro" bind:value={neighborhood} />
    <input type="text" inputmode="numeric" placeholder="CEP" bind:value={postalCode} />
    <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={createAddress}>
      {busy ? 'Salvando…' : 'Salvar e continuar'}
    </button>
    {#if addresses && addresses.length > 0}
      <button type="button" class="link-btn" onclick={() => (creating = false)}>Usar um endereço salvo</button>
    {/if}
  {/if}
</div>

<style>
  .quick-address {
    max-width: 360px;
    margin: 24px auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  h2 {
    margin: 0;
    font-size: 19px;
  }
  .hint {
    color: var(--fuu-ink-4);
    font-size: 13px;
  }
  input {
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
  }
  .list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .address-option {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 0 14px;
    min-height: var(--fuu-tap-customer);
    font-family: var(--fuu-font-body);
    font-size: 13.5px;
    color: var(--fuu-ink-1);
    text-align: left;
  }
  .address-option i {
    color: var(--fuu-ink-5);
  }
  .link-btn {
    background: none;
    border: none;
    color: var(--fuu-red);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 600;
    padding: 6px 0;
    align-self: flex-start;
  }
</style>
