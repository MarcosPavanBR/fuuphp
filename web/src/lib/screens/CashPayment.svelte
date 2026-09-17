<script>
  import { toastr } from '../toastr.js';

  // Tela 4.5 — Dinheiro e troco. "O troco é campo do pedido e vai para a
  // impressora térmica ESC/POS -- evita erro e volta do motoboy."
  // O mínimo que o banco exige é change_for >= subtotal (CHECK
  // cash_change_valid, migração 004); esta tela valida contra o TOTAL
  // (subtotal + frete), que é o que o cliente realmente deve na entrega --
  // mais rigoroso que o mínimo do banco, nunca menos.
  let { total, onSubmit, onBack, busy } = $props();

  let needsChange = $state(false);
  let changeFor = $state(null);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  const QUICK_AMOUNTS = [60, 70, 100];

  let changeOwed = $derived(needsChange && changeFor ? Math.max(0, changeFor - total) : null);

  function submit() {
    if (needsChange && (!changeFor || changeFor < total)) {
      toastr.warning('O valor pra troco precisa ser maior ou igual ao total do pedido.');
      return;
    }
    onSubmit({ change_for: needsChange ? changeFor : undefined });
  }
</script>

<div class="cash-payment">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Dinheiro na entrega</h1>
  </div>

  <div class="total-box fuu-card">
    <p class="label">Total do pedido</p>
    <p class="value fuu-mono">{money(total)}</p>
  </div>

  <label class="toggle-row">
    <input type="checkbox" bind:checked={needsChange} />
    <span>Vou precisar de troco</span>
  </label>

  {#if needsChange}
    <p class="field-label">Troco para quanto?</p>
    <input
      class="change-input fuu-mono"
      type="number"
      inputmode="decimal"
      step="0.01"
      placeholder={money(total)}
      bind:value={changeFor}
    />
    <div class="quick-row">
      {#each QUICK_AMOUNTS as amount (amount)}
        <button type="button" class="quick-btn" onclick={() => (changeFor = amount)}>{money(amount)}</button>
      {/each}
    </div>
    {#if changeOwed !== null}
      <p class="change-owed">Troco a receber: {money(changeOwed)}</p>
      <p class="change-note">O entregador já sai com esse valor separado.</p>
    {/if}
  {/if}

  <p class="warning">
    Sem informar o troco, o entregador pode chegar sem dinheiro trocado. O valor sai impresso em negrito na comanda.
  </p>

  <div class="footer">
    <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={submit}>
      {busy ? 'Confirmando…' : 'Confirmar pedido'}
    </button>
  </div>
</div>

<style>
  .cash-payment {
    padding: 12px 20px 100px;
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
  .total-box {
    text-align: center;
    padding: 16px;
    margin-bottom: 18px;
  }
  .total-box .label {
    margin: 0 0 4px;
    font-size: 12px;
    color: var(--fuu-ink-5);
  }
  .total-box .value {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: var(--fuu-ink-1);
  }
  .toggle-row {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 600;
    color: var(--fuu-ink-1);
    margin-bottom: 14px;
  }
  .toggle-row input {
    width: 18px;
    height: 18px;
  }
  .field-label {
    font-size: 12.5px;
    color: var(--fuu-ink-4);
    margin: 0 0 6px;
  }
  .change-input {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 12px 14px;
    font-size: 18px;
    margin-bottom: 10px;
  }
  .quick-row {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
  }
  .quick-btn {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-pill);
    background: var(--fuu-white);
    padding: 8px 0;
    font-family: var(--fuu-font-mono);
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .change-owed {
    margin: 0;
    font-weight: 700;
    font-size: 14.5px;
    color: var(--fuu-leaf-dark);
  }
  .change-note {
    margin: 2px 0 16px;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
  }
  .warning {
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    background: var(--fuu-line-6);
    border-radius: var(--fuu-radius-card);
    padding: 10px 12px;
    margin: 6px 0 0;
  }
  .footer {
    position: fixed;
    left: 50%;
    bottom: 0;
    transform: translateX(-50%);
    width: 100%;
    max-width: 430px;
    padding: 14px 20px 22px;
    background: linear-gradient(to top, var(--fuu-paper) 60%, transparent);
  }
</style>
