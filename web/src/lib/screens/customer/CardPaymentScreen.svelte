<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { paymentsInTestMode, tokenizeNewCard, tokenizeSavedCard } from '../../services/mercadopago.js';

  // Tela 4.2 — CardForm. "Campos montados pelo SDK com Public Key; o Access
  // Token fica só no PHP." O token sai de lib/services/mercadopago.js
  // (MercadoPago.js com Public Key, ou token de teste em modo fake) -- o
  // número e o CVV nunca vão pro nosso servidor.
  //
  // Com cartão salvo (6.2), ele aparece primeiro: "pagar com ele ainda exige
  // CVV e gera novo token de uso único". Só o CVV é digitado; o servidor
  // recebe o token e qual cartão foi usado (`saved_card_id`).
  let { total, onSubmit, onBack, busy } = $props();

  let saved = $state([]);
  let chosen = $state(null); // id do cartão salvo escolhido, ou null = cartão novo
  let testMode = $state(false);
  let tokenizing = $state(false);

  let number = $state('');
  let name = $state('');
  let expiry = $state('');
  let cvv = $state('');
  let cpf = $state('');
  let installments = $state(1);

  paymentsInTestMode().then((t) => {
    testMode = t;
    // Em teste, o número do mock já vem preenchido pra agilizar.
    if (t && number === '') number = '5031 4332 1540 6351';
  });
  api
    .get('/cards/list.php', { auth: true })
    .then((data) => {
      saved = data.cards;
      chosen = saved.find((c) => c.is_default)?.id ?? saved[0]?.id ?? null;
    })
    .catch(() => {});

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  let installmentOptions = $derived([1, 2, 3].map((n) => ({ n, value: total / n })));

  async function submit() {
    tokenizing = true;
    try {
      if (chosen !== null) {
        if (!/^\d{3,4}$/.test(cvv)) {
          toastr.warning('Digite o CVV do cartão (3 ou 4 dígitos no verso).');
          return;
        }
        const card = saved.find((c) => c.id === chosen);
        const token = await tokenizeSavedCard(card.mp_card_id, cvv);
        onSubmit({ card_token: token, saved_card_id: card.id, installments });
        return;
      }
      const digits = number.replace(/\D/g, '');
      const cpfDigits = cpf.replace(/\D/g, '');
      if (digits.length < 13 || !name.trim() || !/^\d{2}\/\d{2}$/.test(expiry) || cvv.length < 3 || cpfDigits.length !== 11) {
        toastr.warning('Confira os dados do cartão.');
        return;
      }
      const [month, year] = expiry.split('/');
      const token = await tokenizeNewCard({ number: digits, name: name.trim(), month, year, cvv, cpf: cpfDigits });
      onSubmit({ card_token: token, installments, payer_cpf: cpfDigits });
    } catch (e) {
      toastr.error(e.message ?? 'O Mercado Pago não aceitou os dados do cartão.');
    } finally {
      tokenizing = false;
    }
  }
</script>

<div class="card-form">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Cartão de crédito</h1>
  </div>

  {#if testMode}
    <p class="badge-note">modo de teste · nenhuma cobrança real</p>
  {/if}

  {#if saved.length > 0}
    <p class="section">CARTÕES SALVOS</p>
    <div class="saved">
      {#each saved as c (c.id)}
        <button type="button" class="saved-card" class:on={chosen === c.id} onclick={() => { chosen = c.id; cvv = ''; }}>
          <i class={`bi ${chosen === c.id ? 'bi-record-circle' : 'bi-circle'}`}></i>
          <span class="brand">{c.brand}</span>
          <span class="fuu-mono">•••• {c.last4}</span>
          <span class="exp fuu-mono">{String(c.exp_month).padStart(2, '0')}/{String(c.exp_year).slice(-2)}</span>
        </button>
      {/each}
      <button type="button" class="saved-card" class:on={chosen === null} onclick={() => { chosen = null; cvv = ''; }}>
        <i class={`bi ${chosen === null ? 'bi-record-circle' : 'bi-circle'}`}></i>
        <span>Usar outro cartão</span>
      </button>
    </div>
  {/if}

  {#if chosen !== null}
    <label class="field">
      <span>CVV do cartão salvo</span>
      <input type="text" inputmode="numeric" placeholder="000" maxlength="4" bind:value={cvv} />
    </label>
  {:else}
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
  {/if}
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
    <button type="button" class="btn-fuu-primary w-100" disabled={busy || tokenizing} onclick={submit}>
      {busy || tokenizing ? 'Processando…' : `Pagar ${money(total)}`}
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
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-5);
    margin: 4px 0 8px;
  }
  .saved {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-bottom: 14px;
  }
  .saved-card {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 12px;
    background: var(--fuu-white);
    font-size: 14px;
    color: var(--fuu-ink-2);
    text-align: left;
  }
  .saved-card.on {
    border: 2px solid var(--fuu-red);
    background: var(--fuu-red-tint);
  }
  .saved-card .brand {
    font-weight: 700;
    text-transform: capitalize;
  }
  .saved-card .exp {
    margin-left: auto;
    color: var(--fuu-ink-5);
    font-size: 12px;
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
