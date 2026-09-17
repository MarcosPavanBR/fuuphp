<script>
  import { onMount, onDestroy } from 'svelte';
  import { toastr } from '../toastr.js';

  // Tela 4.3 — Pix, chave e QR da própria loja. "Dinheiro cai direto na
  // conta do dono (white-label)." "Contador de 15 min vem do
  // verification_deadline do banco, não do relógio do aparelho" — por
  // isso o countdown abaixo é derivado de `deadline` (prop vinda do
  // pedido), não de um timer que o cliente poderia zerar reabrindo a tela.
  let { restaurantName, restaurantCnpj, amount, copyPaste, deadline, onProofStep, onBack } = $props();

  let now = $state(Date.now());
  let interval;
  onMount(() => {
    interval = setInterval(() => (now = Date.now()), 1000);
  });
  onDestroy(() => clearInterval(interval));

  let remainingSeconds = $derived(deadline ? Math.max(0, Math.floor((new Date(deadline).getTime() - now) / 1000)) : 0);
  let remainingLabel = $derived(
    `${String(Math.floor(remainingSeconds / 60)).padStart(2, '0')}:${String(remainingSeconds % 60).padStart(2, '0')}`
  );

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  async function copyCode() {
    try {
      await navigator.clipboard.writeText(copyPaste);
      toastr.success('Código Pix copiado ✓');
    } catch {
      toastr.warning('Não deu pra copiar automaticamente. Selecione o código manualmente.');
    }
  }
</script>

<div class="pix-payment">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Pix</h1>
    <span class="clock fuu-mono">{remainingLabel}</span>
  </div>

  <p class="pay-to-label">Pague para</p>
  <p class="restaurant-name">{restaurantName}</p>
  {#if restaurantCnpj}<p class="cnpj fuu-mono">CNPJ {restaurantCnpj}</p>{/if}

  <div class="qr-box" aria-hidden="true"><i class="bi bi-qr-code"></i><span>QR Code gerado pelo backend (PHP)</span></div>

  <p class="amount fuu-mono">{money(amount)}</p>
  <p class="amount-note">Valor exato — divergência é recusada</p>

  <p class="copy-paste fuu-mono">{copyPaste}</p>
  <button type="button" class="copy-btn" onclick={copyCode}>Copiar código Pix</button>

  <p class="hint">Depois de pagar no seu banco, volte aqui e envie o comprovante.</p>
  <p class="hint strong">O pedido só vai para a cozinha após a loja confirmar.</p>

  <div class="footer">
    <button type="button" class="btn-fuu-primary w-100" onclick={onProofStep}>
      Já paguei, enviar comprovante
    </button>
  </div>
</div>

<style>
  .pix-payment {
    padding: 12px 20px 100px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
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
    flex: 1;
  }
  .clock {
    font-size: 15px;
    color: var(--fuu-wait-text);
  }
  .pay-to-label {
    margin: 0;
    font-size: 12px;
    color: var(--fuu-ink-5);
  }
  .restaurant-name {
    margin: 2px 0 0;
    font-weight: 700;
    font-size: 16px;
    color: var(--fuu-ink-1);
  }
  .cnpj {
    margin: 2px 0 14px;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
  }
  .qr-box {
    height: 180px;
    border-radius: var(--fuu-radius-card);
    background: var(--fuu-line-5);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--fuu-ink-5);
    font-size: 11px;
    margin-bottom: 14px;
  }
  .qr-box i {
    font-size: 48px;
  }
  .amount {
    text-align: center;
    margin: 0;
    font-size: 26px;
    font-weight: 700;
    color: var(--fuu-ink-1);
  }
  .amount-note {
    text-align: center;
    margin: 2px 0 16px;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
  }
  .copy-paste {
    background: var(--fuu-line-6);
    border-radius: var(--fuu-radius-card);
    padding: 10px 12px;
    font-size: 11px;
    color: var(--fuu-ink-3);
    word-break: break-all;
    margin: 0 0 10px;
  }
  .copy-btn {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-pill);
    background: var(--fuu-white);
    min-height: var(--fuu-tap-customer);
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 14px;
    color: var(--fuu-ink-1);
    margin-bottom: 18px;
  }
  .hint {
    text-align: center;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin: 0 0 4px;
  }
  .hint.strong {
    color: var(--fuu-ink-2);
    font-weight: 600;
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
