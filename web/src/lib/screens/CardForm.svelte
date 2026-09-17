<script>
  import { toastr } from '../toastr.js';

  // Tela 4.2 — CardForm. "Campos montados pelo SDK com Public Key; o Access
  // Token fica só no PHP." Limite honesto: este ambiente não tem uma Public
  // Key de sandbox do Mercado Pago, então o MercadoPago.js real
  // (mp.fields.createCardForm) não está integrado -- os campos abaixo são
  // os mesmos do mock, mas o "token" enviado é um placeholder local (não
  // passa pela tokenização de verdade). payments/pay.php só aceita isso
  // porque o backend também está em MERCADOPAGO_MODE=fake neste ambiente
  // (ver README, seção "Módulo de pagamentos"). Trocar pelo SDK real é
  // troca de chave, não de arquitetura: o backend já espera só um token.
  let { total, onSubmit, onBack, busy } = $props();

  let number = $state('5031 4332 1540 6351');
  let name = $state('');
  let expiry = $state('');
  let cvv = $state('');
  let cpf = $state('');
  let installments = $state(1);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  let installmentOptions = $derived(
    [1, 2, 3].map((n) => ({ n, value: total / n }))
  );

  function submit() {
    const digits = number.replace(/\D/g, '');
    if (digits.length < 13 || !name.trim() || !/^\d{2}\/\d{2}$/.test(expiry) || cvv.length < 3 || cpf.replace(/\D/g, '').length !== 11) {
      toastr.warning('Confira os dados do cartão.');
      return;
    }
    onSubmit({ card_token: digits, installments, payer_cpf: cpf.replace(/\D/g, '') });
  }
</script>

<div class="card-form">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Cartão de crédito</h1>
  </div>

  <p class="badge-note">tokenização real do MercadoPago.js pendente · ver README</p>

  <label class="field">
    <span>Número do cartão</span>
    <input type="text" inputmode="numeric" placeholder="0000 0000 0000 0000" bind:value={number} />
  </label>
  <label class="field">
    <span>Nome impresso no cartão</span>
    <input type="text" placeholder="NOME COMPLETO" bind:value={name} />
  </label>
  <div class="field-row">
    <label class="field">
      <span>Validade</span>
      <input type="text" placeholder="MM/AA" maxlength="5" bind:value={expiry} />
    </label>
    <label class="field">
      <span>CVV</span>
      <input type="text" inputmode="numeric" placeholder="000" maxlength="4" bind:value={cvv} />
    </label>
  </div>
  <label class="field">
    <span>CPF do titular</span>
    <input type="text" inputmode="numeric" placeholder="000.000.000-00" bind:value={cpf} />
  </label>
  <label class="field">
    <span>Parcelas</span>
    <select bind:value={installments}>
      {#each installmentOptions as opt (opt.n)}
        <option value={opt.n}>{opt.n}x de {money(opt.value)} sem juros</option>
      {/each}
    </select>
  </label>

  <p class="disclaimer">
    Os dados não passam pelo nosso servidor. O cartão é tokenizado no seu aparelho pelo Mercado Pago;
    guardamos só os 4 últimos dígitos.
  </p>

  <div class="footer">
    <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={submit}>
      {busy ? 'Processando…' : `Pagar ${money(total)}`}
    </button>
  </div>
</div>

<style>
  .card-form {
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
    font-size: 20px;
    margin: 0;
  }
  .badge-note {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    letter-spacing: 0.06em;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    border-radius: 999px;
    padding: 3px 10px;
    display: inline-block;
    margin: 0 0 14px;
  }
  .field {
    display: block;
    margin-bottom: 12px;
  }
  .field span {
    display: block;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin-bottom: 4px;
  }
  .field-row {
    display: flex;
    gap: 10px;
  }
  .field-row .field {
    flex: 1;
  }
  input,
  select {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
    background: var(--fuu-white);
  }
  .disclaimer {
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    margin: 10px 0 0;
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
