<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { lookupCep } from '../../utils/viacep.js';
  import NewAddressForm from './NewAddressForm.svelte';

  // Tela 6.1 — endereços salvos: listar, editar, tornar padrão, remover.
  // O cadastro de endereço novo (tela 14.3, com área de entrega e taxa) é o
  // NewAddressForm.svelte, separado na auditoria ARQ-02.
  //
  // Endereço já usado em pedido não muda por baixo do pedido (migração 043):
  // editar cria uma versão nova (a lista recarrega com o id novo) e remover
  // arquiva. Pra quem usa a tela, é igual.
  let { location, onBack } = $props();

  let addresses = $state(null);
  let editingId = $state(null);
  let creating = $state(false);
  let busy = $state(false);
  // Formulário da edição (o de endereço novo mora no NewAddressForm).
  let form = $state({});

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

  function startCreate() {
    creating = true;
    editingId = null;
  }

  async function afterCreate() {
    creating = false;
    await load();
  }

  function startEdit(a) {
    form = {
      label: a.label ?? '',
      street: a.street,
      number: a.number ?? '',
      complement: a.complement ?? '',
      reference: a.reference ?? '',
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

  // O mesmo ViaCEP do endereço novo (utils/viacep.js).
  async function fillFromCep() {
    const found = await lookupCep(form.postalCode);
    if (found) {
      form = { ...form, street: found.street || form.street, neighborhood: found.neighborhood || form.neighborhood };
      toastr.success('Endereço encontrado pelo CEP ✓');
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
          reference: form.reference.trim() || null,
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
      // Endereço usado em pedido é arquivado, não recusado (migração 043).
      toastr.error(e.message ?? 'Não deu pra apagar o endereço.');
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
            <input type="text" maxlength="40" placeholder="Rótulo (Casa, Trabalho...)" bind:value={form.label} />
            <input type="text" maxlength="200" placeholder="Rua" bind:value={form.street} />
            <div class="field-row">
              <input type="text" maxlength="20" placeholder="Número" bind:value={form.number} />
              <input type="text" maxlength="100" placeholder="Complemento" bind:value={form.complement} />
            </div>
            <input type="text" maxlength="100" placeholder="Bairro" bind:value={form.neighborhood} />
            <input
              type="text"
              inputmode="numeric"
              placeholder="CEP"
              bind:value={form.postalCode}
              onblur={fillFromCep}
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
            {#if a.reference}<p class="city ref"><i class="bi bi-signpost"></i> {a.reference}</p>{/if}
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
      <NewAddressForm {location} isFirst={addresses.length === 0} onSaved={afterCreate} onCancel={cancelForm} />
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
  .ref {
    color: var(--fuu-ink-4);
  }
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
