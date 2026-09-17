<script>
  import { api } from '../api.js';
  import { toastr } from '../toastr.js';

  // Endereço mínimo, funcional de verdade (chama /addresses/list.php e
  // /addresses/create.php reais) -- não é a tela de endereços desenhada da
  // Fase 6 (CRUD completo, rótulo, padrão, edição), que ainda não foi
  // portada. Existe só pra destravar o checkout da Fase 4: sem endereço
  // salvo, orders/checkout.php não tem pra onde levar o pedido.
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
          city: location?.city?.name ?? 'Campinas',
          city_ibge_code: location?.city?.ibge ?? '3509502',
          state: location?.uf ?? 'SP',
          postal_code: postalCode,
          lat: location?.lat ?? location?.city?.lat ?? -22.9056,
          lng: location?.lng ?? location?.city?.lng ?? -47.0608,
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
  <p class="badge-note">endereço mínimo · Fase 6 ainda não portada</p>

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
    <input type="text" placeholder="Rua" bind:value={street} />
    <input type="text" placeholder="Número" bind:value={number} />
    <input type="text" placeholder="Bairro" bind:value={neighborhood} />
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
  .badge-note {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    letter-spacing: 0.08em;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    border-radius: 999px;
    padding: 3px 10px;
    align-self: flex-start;
    margin: 0 0 6px;
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
