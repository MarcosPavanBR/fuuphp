<script>
  import { toastr } from '../toastr.js';

  // Tela 4.6 — Maquininha na entrega. "Bandeira obrigatória
  // (machine_needs_type no banco) para o entregador não sair sem a
  // máquina certa." No esquema deste projeto a coluna é machine_kind
  // (débito/crédito) com a mesma regra: CHECK machine_needs_kind exige o
  // valor sempre que payment_method = 'pos_machine'.
  let { total, onSubmit, onBack, busy } = $props();

  let kind = $state(null);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function submit() {
    if (!kind) {
      toastr.warning('Escolha débito ou crédito.');
      return;
    }
    onSubmit({ machine_kind: kind });
  }
</script>

<div class="machine-payment">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Maquininha na porta</h1>
  </div>

  <p class="hint">O entregador leva a maquininha. Escolha como vai pagar para a loja levar a bandeira certa.</p>

  <div class="kind-row">
    <button type="button" class="kind-btn" class:selected={kind === 'debit'} onclick={() => (kind = 'debit')}>
      Débito
    </button>
    <button type="button" class="kind-btn" class:selected={kind === 'credit'} onclick={() => (kind = 'credit')}>
      Crédito
    </button>
  </div>

  <p class="section-label">BANDEIRAS ACEITAS NESTA LOJA</p>
  <div class="brands">
    <span class="brand-chip">Visa</span>
    <span class="brand-chip">Mastercard</span>
    <span class="brand-chip">Elo</span>
    <span class="brand-chip">Pix na maquininha</span>
  </div>

  <div class="total-row fuu-card">
    <span>A pagar na entrega</span>
    <span class="fuu-mono">{money(total)}</span>
  </div>

  <p class="warning">Tenha um documento em mãos: a operadora pode pedir na hora da transação.</p>

  <div class="footer">
    <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={submit}>
      {busy ? 'Confirmando…' : 'Confirmar pedido'}
    </button>
  </div>
</div>

<style>
  .machine-payment {
    padding: 12px 20px 100px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-2);
    padding: 4px;
  }
  h1 {
    font-size: 18px;
    margin: 0;
  }
  .hint {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 0 0 16px;
  }
  .kind-row {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
  }
  .kind-btn {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    background: var(--fuu-white);
    min-height: 56px;
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 15px;
    color: var(--fuu-ink-2);
  }
  .kind-btn.selected {
    border-color: var(--fuu-red);
    background: var(--fuu-red-tint);
    color: var(--fuu-red-hover);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 8px;
  }
  .brands {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 18px;
  }
  .brand-chip {
    border: 1px solid var(--fuu-line-3);
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 12px;
    color: var(--fuu-ink-3);
  }
  .total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 16px;
    font-size: 14px;
    font-weight: 600;
    color: var(--fuu-ink-1);
    margin-bottom: 16px;
  }
  .warning {
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    background: var(--fuu-line-6);
    border-radius: var(--fuu-radius-card);
    padding: 10px 12px;
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
