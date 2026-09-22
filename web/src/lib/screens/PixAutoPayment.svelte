<script>
  import { onMount, onDestroy } from 'svelte';
  import { api } from '../api.js';
  import { toastr } from '../toastr.js';

  // Pix automático (online) — o QR do Mercado Pago, com confirmação sozinha.
  //
  // O mock não desenha uma tela própria pra ele: aparece na 10.5/11 como
  // forma que a loja liga ("Cobrança com confirmação automática · nada de
  // conferir comprovante", RECOMENDADO) e no mix de pagamento da 12.3. Esta
  // tela é a 4.3 (PixPayment.svelte) sem o que não se aplica: sem
  // comprovante e sem "a loja confirma". Mesmo cabeçalho, mesmo relógio
  // vindo do `verification_deadline` do banco, mesmo "copiar código".
  //
  // Quem confirma é o webhook do Mercado Pago (payments/webhook_mercadopago.php
  // avança o pedido pra 'paid'). A tela só observa: consulta o pedido a cada
  // POLL_MS e sai sozinha quando ele deixa 'pending_payment'.
  let { orderId, restaurantName, amount, copyPaste, qrBase64, deadline, onPaid, onBack } = $props();

  const POLL_MS = 4000;

  let now = $state(Date.now());
  let clock;
  let poll;

  // O QR em modo fake é texto em base64, não imagem: só vira <img> quando
  // é um PNG de verdade (assinatura "iVBOR" = \x89PNG em base64).
  let qrIsImage = $derived(typeof qrBase64 === 'string' && qrBase64.startsWith('iVBOR'));

  let remainingSeconds = $derived(deadline ? Math.max(0, Math.floor((new Date(deadline).getTime() - now) / 1000)) : 0);
  let remainingLabel = $derived(
    `${String(Math.floor(remainingSeconds / 60)).padStart(2, '0')}:${String(remainingSeconds % 60).padStart(2, '0')}`
  );

  async function checkPaid() {
    try {
      const data = await api.get('/orders/show.php', { auth: true, query: { id: orderId } });
      if (data.order?.status && data.order.status !== 'pending_payment') {
        clearInterval(poll);
        onPaid(data.order);
      }
    } catch {
      // rede oscilando: a próxima volta tenta de novo
    }
  }

  onMount(() => {
    clock = setInterval(() => (now = Date.now()), 1000);
    poll = setInterval(checkPaid, POLL_MS);
  });
  onDestroy(() => {
    clearInterval(clock);
    clearInterval(poll);
  });

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

<div class="pix-auto">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Pix automático</h1>
    <span class="clock fuu-mono">{remainingLabel}</span>
  </div>

  <p class="pay-to-label">Pague para</p>
  <p class="restaurant-name">{restaurantName}</p>
  <p class="via">via Mercado Pago</p>

  {#if qrIsImage}
    <img class="qr-img" src={`data:image/png;base64,${qrBase64}`} alt="QR Code do Pix" />
  {:else}
    <div class="qr-box" aria-hidden="true"><i class="bi bi-qr-code"></i><span>QR Code do Mercado Pago</span></div>
  {/if}

  <p class="amount fuu-mono">{money(amount)}</p>

  <p class="copy-paste fuu-mono">{copyPaste}</p>
  <button type="button" class="copy-btn" onclick={copyCode}>Copiar código Pix</button>

  <div class="waiting" role="status">
    <span class="dot" aria-hidden="true"></span>
    Esperando o pagamento — a confirmação é automática.
  </div>
  <p class="hint">Não precisa enviar comprovante. Assim que o banco confirmar, o pedido vai direto pra cozinha.</p>
</div>

<style>
  .pix-auto {
    padding: 12px 20px 40px;
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
  .via {
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
  .qr-img {
    display: block;
    width: 180px;
    height: 180px;
    margin: 0 auto 14px;
    image-rendering: pixelated;
  }
  .amount {
    text-align: center;
    margin: 0 0 16px;
    font-size: 26px;
    font-weight: 700;
    color: var(--fuu-ink-1);
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
  .waiting {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--fuu-ink-2);
    margin-bottom: 6px;
  }
  .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--fuu-wait-text);
    animation: pulse 1.2s ease-in-out infinite;
  }
  @keyframes pulse {
    50% {
      opacity: 0.25;
    }
  }
  .hint {
    text-align: center;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin: 0;
  }
</style>
