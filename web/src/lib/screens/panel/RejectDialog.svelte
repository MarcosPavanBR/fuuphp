<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';

  // Tela 13.2 — "Recusar tem custo e a tela mostra qual: compensação ao
  // entregador que já saiu, efeito na taxa de recusa e na posição na busca."
  //
  // Os números vêm de orders/cancel_quote.php (a mesma rota da tela 13.1,
  // que muda de causa conforme o papel de quem pergunta): a taxa de recusa é
  // calculada dos pedidos reais desta loja, não é exemplo.
  //
  // O que o mock tem e esta tela não: "sugerir substituição". Depende de um
  // canal pro cliente responder, que é a Fase 14 (suporte/chat) e não existe
  // -- uma caixa de texto que não chega em ninguém seria pior que a ausência.
  let { order, onClose, onRejected } = $props();

  let quote = $state(null);
  let reason = $state(null);
  let busy = $state(false);

  $effect(() => {
    let alive = true;
    api
      .get('/orders/cancel_quote.php', { token: staffToken(), query: { id: order.id } })
      .then((data) => {
        if (alive) quote = data;
      })
      .catch(() => {});
    return () => (alive = false);
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  const METHOD_LABEL = {
    mp_card: 'cartão',
    pix_auto: 'Pix',
    pix_manual: 'Pix',
    cash: 'dinheiro',
    pos_machine: 'maquininha',
  };

  async function confirm() {
    if (reason === null || busy) return;
    busy = true;
    try {
      const label = quote.reasons.find((r) => r.code === reason)?.label ?? reason;
      await api.post('/orders/status.php', {
        token: staffToken(),
        body: { order_id: order.id, to: 'cancelled', reason: label },
      });
      toastr.success('Pedido recusado. O cliente foi avisado do motivo.');
      onRejected();
    } catch (e) {
      const code = e instanceof ApiError ? e.code : null;
      toastr.error(
        code === 'illegal_transition'
          ? 'Esse pedido já mudou de estado em outro aparelho.'
          : (e.message ?? 'Não deu pra recusar.')
      );
      if (code === 'illegal_transition') onRejected();
    } finally {
      busy = false;
    }
  }
</script>

<div class="reject-backdrop" onclick={onClose} role="presentation"></div>
<div class="reject-panel" role="dialog" aria-label="Recusar pedido">
  <header>
    <i class="bi bi-exclamation-triangle-fill"></i>
    <h2 class="fuu-display">Recusar pedido #{order.public_code}</h2>
    <span class="meta">
      {METHOD_LABEL[order.payment_method] ?? 'pagamento'} · {money(order.total)}
    </span>
  </header>

  <div class="body">
    <div class="left">
      <p class="section-label">MOTIVO (O CLIENTE VÊ)</p>
      <div class="reasons">
        {#each quote?.reasons ?? [] as option (option.code)}
          <button
            type="button"
            class="reason"
            class:on={reason === option.code}
            onclick={() => (reason = option.code)}
          >
            <i class={`bi ${reason === option.code ? 'bi-record-circle' : 'bi-circle'}`}></i>
            {option.label}
          </button>
        {/each}
      </div>
    </div>

    <div class="right">
      <p class="section-label">O QUE ACONTECE AO RECUSAR</p>
      {#if quote}
        <ul class="effects">
          <li>
            <i class="bi bi-arrow-return-left ok"></i>
            <span>
              {#if quote.quote.amount > 0}
                <strong>Estorno de {money(quote.quote.amount)}:</strong>
                {quote.quote.how.toLowerCase()} · {quote.quote.eta}. Sai do seu repasse.
              {:else}
                <strong>Nada a estornar:</strong> o pedido é em {METHOD_LABEL[order.payment_method]} e nada foi cobrado.
              {/if}
            </span>
          </li>
          <li>
            <i class="bi bi-scooter warn"></i>
            <span>
              {#if quote.store_impact?.courier_assigned}
                <strong>Entregador já designado:</strong> recebe compensação de deslocamento, paga pela
                plataforma.
              {:else}
                <strong>Sem entregador designado ainda:</strong> ninguém se deslocou à toa.
              {/if}
            </span>
          </li>
          <li>
            <i class="bi bi-graph-down bad"></i>
            <span>
              <strong>Sua taxa de recusa</strong> vai de {quote.store_impact?.reject_rate}% para
              {quote.store_impact?.reject_rate_after}%. Acima de 5% a loja perde posição na busca.
            </span>
          </li>
        </ul>

        {#if reason === 'out_of_stock'}
          <div class="tip">
            Recusar por "item acabou" vira hábito quando o cardápio está desatualizado — marcar o item
            como esgotado evita a recusa e a penalidade.
          </div>
        {/if}
      {:else}
        <p class="loading">Calculando o custo…</p>
      {/if}

      <div class="decision">
        <button type="button" class="back-btn" onclick={onClose}>Voltar</button>
        <button
          type="button"
          class="btn-fuu-primary reject"
          disabled={reason === null || busy || !quote}
          onclick={confirm}
        >
          {busy ? 'Recusando…' : 'Recusar'}
        </button>
      </div>
    </div>
  </div>
</div>

<style>
  .reject-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(23, 20, 15, 0.3);
    z-index: 60;
  }
  .reject-panel {
    position: fixed;
    inset: 6vh 4vw auto;
    max-width: 820px;
    max-height: 88vh;
    margin: 0 auto;
    overflow-y: auto;
    background: var(--fuu-white);
    border-radius: 15px;
    z-index: 61;
  }
  header {
    padding: 18px 22px;
    border-bottom: 1px solid var(--fuu-line-5);
    display: flex;
    align-items: center;
    gap: 12px;
  }
  header i {
    color: var(--fuu-red);
    font-size: 19px;
  }
  h2 {
    font-size: 18px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .meta {
    margin-left: auto;
    font-size: 13px;
    color: var(--fuu-ink-3);
  }
  .body {
    display: grid;
    grid-template-columns: 52% 48%;
  }
  @media (max-width: 760px) {
    .body {
      grid-template-columns: 1fr;
    }
  }
  .left {
    padding: 22px;
    border-right: 1px solid var(--fuu-line-5);
  }
  .right {
    padding: 22px;
    display: flex;
    flex-direction: column;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11.5px;
    font-weight: 700;
    color: var(--fuu-ink-5);
    letter-spacing: 0.08em;
    margin: 0 0 11px;
  }
  .reasons {
    display: flex;
    flex-direction: column;
    gap: 9px;
  }
  .reason {
    display: flex;
    align-items: center;
    gap: 11px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 14px;
    background: var(--fuu-white);
    font-family: var(--fuu-font-body);
    font-size: 14px;
    color: var(--fuu-ink-3);
    text-align: left;
    min-height: var(--fuu-tap-operator);
  }
  .reason.on {
    border: 2px solid var(--fuu-red);
    background: var(--fuu-red-tint);
    color: var(--fuu-ink-1);
    font-weight: 600;
  }
  .reason i {
    color: var(--fuu-ink-5);
  }
  .reason.on i {
    color: var(--fuu-red);
  }
  .effects {
    list-style: none;
    padding: 0;
    margin: 0;
    font-size: 13.5px;
    line-height: 1.6;
    color: var(--fuu-ink-3);
  }
  .effects li {
    display: flex;
    gap: 9px;
    margin-bottom: 7px;
  }
  .effects i {
    margin-top: 3px;
  }
  .effects strong {
    color: var(--fuu-ink-1);
  }
  .ok {
    color: var(--fuu-leaf-dark);
  }
  .warn {
    color: var(--fuu-wait-text);
  }
  .bad {
    color: var(--fuu-red);
  }
  .tip {
    border: 1px solid var(--fuu-wait-text);
    background: var(--fuu-wait-tint);
    border-radius: 11px;
    padding: 14px;
    margin-top: 16px;
    font-size: 12.5px;
    color: var(--fuu-wait-text);
    line-height: 1.6;
  }
  .loading {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  .decision {
    display: flex;
    gap: 11px;
    margin-top: auto;
    padding-top: 20px;
  }
  .back-btn {
    flex: 1;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-3);
    border-radius: 11px;
    padding: 16px;
    font-family: var(--fuu-font-body);
    font-weight: 700;
    font-size: 14px;
    color: var(--fuu-ink-2);
    min-height: var(--fuu-tap-operator);
  }
  .reject {
    flex: 1.2;
    min-height: var(--fuu-tap-operator);
  }
</style>
