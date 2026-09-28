<script>
  import { toastr } from '../../utils/toastr.js';
  import { api, ApiError } from '../../services/api.js';

  // Tela 6.2 — Cartões e métodos. "Nenhum dado sensível de cartão fica no
  // nosso banco: só bandeira, 4 últimos dígitos e o identificador do
  // cartão salvo no Mercado Pago." Mesmo limite já documentado em
  // CardPaymentScreen.svelte (Fase 4.2): sem MercadoPago.js real integrado neste
  // ambiente, o "token" é um placeholder (os dígitos do cartão) -- funciona
  // porque o backend está em MERCADOPAGO_MODE=fake.
  let { onBack } = $props();

  const BRAND_ICONS = { visa: 'VISA', mastercard: 'MC', elo: 'ELO' };
  const KIND_LABELS = { credit: 'Crédito', debit: 'Débito' };

  let cards = $state(null);
  let adding = $state(false);
  let busy = $state(false);

  let number = $state('');
  let name = $state('');
  let expiry = $state('');
  let cvv = $state('');
  let kind = $state('credit');

  async function load() {
    try {
      const data = await api.get('/cards/list.php', { auth: true });
      cards = data.cards;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar os cartões.');
      cards = [];
    }
  }
  load();

  function resetForm() {
    number = '';
    name = '';
    expiry = '';
    cvv = '';
    kind = 'credit';
  }

  async function submitCard() {
    const digits = number.replace(/\D/g, '');
    if (digits.length < 13 || !name.trim() || !/^\d{2}\/\d{2}$/.test(expiry) || cvv.length < 3) {
      toastr.warning('Confira os dados do cartão.');
      return;
    }
    busy = true;
    try {
      await api.post('/cards/create.php', { auth: true, body: { card_token: digits, kind } });
      toastr.success('Cartão salvo ✓');
      adding = false;
      resetForm();
      await load();
    } catch (e) {
      const message = e instanceof ApiError && e.code === 'card_already_saved'
        ? 'Esse cartão já está salvo.'
        : (e.message ?? 'Não deu pra salvar o cartão.');
      toastr.error(message);
    } finally {
      busy = false;
    }
  }

  async function makeDefault(card) {
    try {
      await api.post('/cards/update.php', { auth: true, body: { id: card.id, is_default: true } });
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra trocar o cartão padrão.');
    }
  }

  async function removeCard(card) {
    try {
      await api.post('/cards/delete.php', { auth: true, body: { id: card.id } });
      toastr.success('Cartão removido');
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra remover o cartão.');
    }
  }

  function explainMethod(what) {
    toastr.info(`${what}: você escolhe na hora de pagar, se a loja aceitar. Não precisa cadastrar.`);
  }
</script>

<div class="payment-methods-screen">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>
    <h1 class="fuu-display">Formas de pagamento</h1>
  </div>

  <p class="section-label">CARTÕES SALVOS</p>

  {#if cards === null}
    <p class="empty">Carregando…</p>
  {:else}
    <div class="card-list">
      {#each cards as c (c.id)}
        <div class="fuu-card card-row">
          <div class="card-icon">{BRAND_ICONS[c.brand] ?? c.brand.slice(0, 4).toUpperCase()}</div>
          <div class="card-info">
            <p class="card-number fuu-mono">•••• {c.last4}</p>
            <p class="card-meta">{KIND_LABELS[c.kind] ?? c.kind ?? ''} · vence {String(c.exp_month).padStart(2, '0')}/{String(c.exp_year).slice(-2)}</p>
          </div>
          {#if c.is_default}
            <span class="fuu-badge-confirmed">PADRÃO</span>
          {:else}
            <button type="button" class="action" onclick={() => makeDefault(c)}>Tornar padrão</button>
          {/if}
          <button type="button" class="remove" onclick={() => removeCard(c)} aria-label="Remover cartão">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      {/each}
      {#if cards.length === 0 && !adding}
        <p class="empty">Nenhum cartão salvo ainda.</p>
      {/if}
    </div>

    {#if adding}
      <div class="fuu-card form-card">
        <input type="text" inputmode="numeric" placeholder="Número do cartão" bind:value={number} />
        <input type="text" placeholder="Nome impresso no cartão" bind:value={name} />
        <div class="field-row">
          <input type="text" placeholder="MM/AA" maxlength="5" bind:value={expiry} />
          <input type="text" inputmode="numeric" placeholder="CVV" maxlength="4" bind:value={cvv} />
        </div>
        <div class="kind-row">
          <button type="button" class="kind-btn" class:selected={kind === 'credit'} onclick={() => (kind = 'credit')}>Crédito</button>
          <button type="button" class="kind-btn" class:selected={kind === 'debit'} onclick={() => (kind = 'debit')}>Débito</button>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-fuu-primary" disabled={busy} onclick={submitCard}>
            {busy ? 'Salvando…' : 'Salvar cartão'}
          </button>
          <button type="button" class="link-btn" onclick={() => (adding = false)}>Cancelar</button>
        </div>
      </div>
    {:else}
      <button type="button" class="add-btn" onclick={() => (adding = true)}>
        <i class="bi bi-plus-circle"></i> Adicionar cartão
      </button>
    {/if}

    <p class="pci-note">
      Guardamos apenas bandeira e 4 últimos dígitos. Número completo e CVV ficam no Mercado Pago — nunca no nosso banco.
    </p>

    <p class="section-label other">OUTROS MÉTODOS</p>
    <button type="button" class="other-method" onclick={() => explainMethod('Pix com comprovante')}>
      <span>Pix com comprovante</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" class="other-method" onclick={() => explainMethod('Dinheiro na entrega')}>
      <span>Dinheiro na entrega</span>
      <i class="bi bi-chevron-right"></i>
    </button>
  {/if}
</div>

<style>
  .payment-methods-screen {
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
    font-size: 19px;
    margin: 0;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 10px;
  }
  .section-label.other {
    margin-top: 20px;
  }
  .empty {
    color: var(--fuu-ink-5);
    font-size: 13.5px;
    margin-bottom: 12px;
  }
  .card-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 14px;
  }
  .card-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
  }
  .card-icon {
    width: 40px;
    height: 26px;
    border-radius: 5px;
    background: var(--fuu-line-5);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--fuu-font-mono);
    font-size: 9px;
    font-weight: 700;
    color: var(--fuu-ink-3);
    flex: none;
  }
  .card-info {
    flex: 1;
    min-width: 0;
  }
  .card-number {
    margin: 0;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .card-meta {
    margin: 2px 0 0;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
  }
  .action {
    background: none;
    border: none;
    color: var(--fuu-red);
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 11.5px;
    white-space: nowrap;
  }
  .remove {
    background: none;
    border: none;
    color: var(--fuu-ink-5);
    font-size: 15px;
    padding: 4px;
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
  .kind-row {
    display: flex;
    gap: 8px;
  }
  .kind-btn {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    background: var(--fuu-white);
    padding: 9px 0;
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .kind-btn.selected {
    border-color: var(--fuu-red);
    background: var(--fuu-red-tint);
    color: var(--fuu-red-hover);
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
  .pci-note {
    font-size: 11px;
    color: var(--fuu-ink-5);
    margin: 12px 0 0;
  }
  .other-method {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 0 14px;
    min-height: var(--fuu-tap-customer);
    font-family: var(--fuu-font-body);
    font-size: 14px;
    color: var(--fuu-ink-1);
    margin-bottom: 8px;
  }
  .other-method i {
    color: var(--fuu-ink-5);
  }
</style>
