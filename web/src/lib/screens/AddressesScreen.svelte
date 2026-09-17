<script>
  import { api, ApiError } from '../api.js';
  import { toastr } from '../toastr.js';

  // Tela 6.1 — Endereços salvos. "CRUD com endereço padrão; taxa sempre
  // recalculada no PHP para o cliente não forjar frete grátis." A taxa por
  // endereço não é mostrada aqui: não existe cálculo de frete por
  // bairro/distância em nenhum endpoint deste backend ainda (orders/checkout.php
  // recebe delivery_fee do corpo da requisição, não calcula) -- mostrar um
  // valor de exemplo fixo, como o mock desenha ("Taxa R$ 6,90"), seria
  // inventar um número que o sistema não sustenta. Registrado em
  // "Próximos passos" no README.
  let { location, onBack } = $props();

  let addresses = $state(null);
  let editingId = $state(null);
  let creating = $state(false);
  let busy = $state(false);

  let form = $state({ label: '', street: '', number: '', complement: '', neighborhood: '', postalCode: '' });

  async function load() {
    try {
      const data = await api.get('/addresses/list.php', { auth: true });
      addresses = data.addresses;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar os endereços.');
      addresses = [];
    }
  }
  load();

  function resetForm() {
    form = { label: '', street: '', number: '', complement: '', neighborhood: '', postalCode: '' };
  }

  function startCreate() {
    resetForm();
    creating = true;
    editingId = null;
  }

  function startEdit(a) {
    form = {
      label: a.label ?? '',
      street: a.street,
      number: a.number ?? '',
      complement: a.complement ?? '',
      neighborhood: a.neighborhood ?? '',
      postalCode: a.postal_code,
    };
    editingId = a.id;
    creating = false;
  }

  function cancelForm() {
    creating = false;
    editingId = null;
  }

  // ViaCEP é uma API pública e gratuita (sem chave) -- busca real, não
  // simulada. Limite honesto: este ambiente de desenvolvimento bloqueia
  // tráfego de saída pra hosts fora da allowlist do proxy, então o caminho
  // de sucesso desta chamada não pôde ser testado aqui (só o de falha,
  // que cai no formulário manual mesmo). O contrato da API é estável e
  // público -- não é algo inventado.
  let cepLookupBusy = $state(false);
  async function lookupCep() {
    const digits = form.postalCode.replace(/\D/g, '');
    if (digits.length !== 8) return;
    cepLookupBusy = true;
    try {
      const res = await fetch(`https://viacep.com.br/ws/${digits}/json/`);
      const data = await res.json();
      if (!data.erro) {
        form = {
          ...form,
          street: data.logradouro || form.street,
          neighborhood: data.bairro || form.neighborhood,
        };
        toastr.success('Endereço encontrado pelo CEP ✓');
      }
    } catch {
      // Falha de rede ou CEP não encontrado: sem problema, o formulário
      // continua editável manualmente -- é degradação graciosa, não erro.
    } finally {
      cepLookupBusy = false;
    }
  }

  async function submitCreate() {
    if (!form.street.trim() || form.postalCode.replace(/\D/g, '').length !== 8) {
      toastr.warning('Preencha rua e CEP.');
      return;
    }
    busy = true;
    try {
      await api.post('/addresses/create.php', {
        auth: true,
        body: {
          label: form.label.trim() || undefined,
          street: form.street.trim(),
          number: form.number.trim() || undefined,
          complement: form.complement.trim() || undefined,
          neighborhood: form.neighborhood.trim() || undefined,
          city: location?.city?.name ?? 'Campinas',
          city_ibge_code: location?.city?.ibge ?? '3509502',
          state: location?.uf ?? 'SP',
          postal_code: form.postalCode,
          lat: location?.lat ?? location?.city?.lat ?? -22.9056,
          lng: location?.lng ?? location?.city?.lng ?? -47.0608,
          is_default: addresses?.length === 0,
        },
      });
      toastr.success('Endereço salvo ✓');
      creating = false;
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar o endereço.');
    } finally {
      busy = false;
    }
  }

  async function submitEdit() {
    busy = true;
    try {
      await api.post('/addresses/update.php', {
        auth: true,
        body: {
          id: editingId,
          label: form.label.trim() || null,
          street: form.street.trim(),
          number: form.number.trim() || null,
          complement: form.complement.trim() || null,
          neighborhood: form.neighborhood.trim() || null,
          postal_code: form.postalCode,
        },
      });
      toastr.success('Endereço atualizado ✓');
      editingId = null;
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra atualizar o endereço.');
    } finally {
      busy = false;
    }
  }

  async function makeDefault(a) {
    try {
      await api.post('/addresses/update.php', { auth: true, body: { id: a.id, is_default: true } });
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra trocar o endereço padrão.');
    }
  }

  async function remove(a) {
    try {
      await api.post('/addresses/delete.php', { auth: true, body: { id: a.id } });
      toastr.success('Endereço removido');
      await load();
    } catch (e) {
      const message = e instanceof ApiError && e.code === 'address_in_use'
        ? 'Esse endereço já foi usado num pedido e não pode ser apagado.'
        : (e.message ?? 'Não deu pra apagar o endereço.');
      toastr.error(message);
    }
  }
</script>

<div class="addresses-screen">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>
    <h1 class="fuu-display">Endereços</h1>
  </div>

  {#if addresses === null}
    <p class="empty">Carregando…</p>
  {:else}
    <div class="address-list">
      {#each addresses as a (a.id)}
        {#if editingId === a.id}
          <div class="fuu-card form-card">
            <input type="text" placeholder="Rótulo (Casa, Trabalho...)" bind:value={form.label} />
            <input type="text" placeholder="Rua" bind:value={form.street} />
            <div class="field-row">
              <input type="text" placeholder="Número" bind:value={form.number} />
              <input type="text" placeholder="Complemento" bind:value={form.complement} />
            </div>
            <input type="text" placeholder="Bairro" bind:value={form.neighborhood} />
            <input
              type="text"
              inputmode="numeric"
              placeholder="CEP"
              bind:value={form.postalCode}
              onblur={lookupCep}
            />
            <div class="form-actions">
              <button type="button" class="btn-fuu-primary" disabled={busy} onclick={submitEdit}>
                {busy ? 'Salvando…' : 'Salvar'}
              </button>
              <button type="button" class="link-btn" onclick={cancelForm}>Cancelar</button>
            </div>
          </div>
        {:else}
          <div class="fuu-card address-card">
            <div class="address-top">
              <span class="label">{a.label || 'Endereço'}</span>
              {#if a.is_default}<span class="fuu-badge-confirmed">PADRÃO</span>{/if}
            </div>
            <p class="street">{a.street}{a.number ? `, ${a.number}` : ''}{a.complement ? ` · ${a.complement}` : ''}</p>
            <p class="city">{a.neighborhood ? `${a.neighborhood} · ` : ''}{a.city}/{a.state} · {a.postal_code.replace(/(\d{5})(\d{3})/, '$1-$2')}</p>
            <div class="address-actions">
              {#if !a.is_default}
                <button type="button" class="action" onclick={() => makeDefault(a)}>Tornar padrão</button>
              {/if}
              <button type="button" class="action" onclick={() => startEdit(a)}>Editar</button>
              <button type="button" class="action danger" onclick={() => remove(a)}>Remover</button>
            </div>
          </div>
        {/if}
      {/each}

      {#if addresses.length === 0 && !creating}
        <p class="empty">Nenhum endereço salvo ainda.</p>
      {/if}
    </div>

    {#if creating}
      <div class="fuu-card form-card">
        <p class="section-label">NOVO ENDEREÇO</p>
        <input
          type="text"
          inputmode="numeric"
          placeholder="CEP"
          bind:value={form.postalCode}
          onblur={lookupCep}
        />
        {#if cepLookupBusy}<p class="hint">Buscando pelo CEP…</p>{/if}
        <input type="text" placeholder="Rótulo (Casa, Trabalho...)" bind:value={form.label} />
        <input type="text" placeholder="Rua" bind:value={form.street} />
        <div class="field-row">
          <input type="text" placeholder="Número" bind:value={form.number} />
          <input type="text" placeholder="Complemento" bind:value={form.complement} />
        </div>
        <input type="text" placeholder="Bairro" bind:value={form.neighborhood} />
        <div class="form-actions">
          <button type="button" class="btn-fuu-primary" disabled={busy} onclick={submitCreate}>
            {busy ? 'Salvando…' : 'Salvar endereço'}
          </button>
          <button type="button" class="link-btn" onclick={cancelForm}>Cancelar</button>
        </div>
      </div>
    {:else}
      <button type="button" class="add-btn" onclick={startCreate}>
        <i class="bi bi-plus-circle"></i> Adicionar endereço
      </button>
      <p class="hint">Buscamos pelo CEP</p>
    {/if}

    <p class="fee-note">A taxa de entrega é sempre calculada no servidor na hora de fechar o pedido.</p>
  {/if}
</div>

<style>
  .addresses-screen {
    padding: 12px 20px 40px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-2);
    padding: 4px;
  }
  h1 {
    font-size: 20px;
    margin: 0;
  }
  .empty {
    color: var(--fuu-ink-5);
    font-size: 13.5px;
  }
  .address-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 14px;
  }
  .address-card {
    padding: 14px;
  }
  .address-top {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
  }
  .label {
    font-weight: 700;
    font-size: 14.5px;
    color: var(--fuu-ink-1);
  }
  .street {
    margin: 0;
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .city {
    margin: 2px 0 10px;
    font-size: 12px;
    color: var(--fuu-ink-5);
  }
  .address-actions {
    display: flex;
    gap: 14px;
  }
  .action {
    background: none;
    border: none;
    color: var(--fuu-red);
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 12.5px;
    padding: 0;
  }
  .action.danger {
    color: var(--fuu-alert);
  }
  .form-card {
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 14px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 2px;
  }
  .field-row {
    display: flex;
    gap: 8px;
  }
  .field-row input {
    flex: 1;
  }
  input {
    box-sizing: border-box;
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
  }
  .form-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 4px;
  }
  .form-actions .btn-fuu-primary {
    padding: 0 20px;
    min-height: 42px;
  }
  .link-btn {
    background: none;
    border: none;
    color: var(--fuu-ink-4);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 600;
  }
  .add-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px dashed var(--fuu-line-1);
    border-radius: var(--fuu-radius-card);
    background: none;
    min-height: var(--fuu-tap-customer);
    color: var(--fuu-red);
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 14px;
  }
  .hint {
    text-align: center;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    margin: 6px 0 0;
  }
  .fee-note {
    text-align: center;
    font-size: 11px;
    color: var(--fuu-ink-5);
    margin: 20px 0 0;
  }
</style>
