<script>
  import { api, ApiError } from '../api.js';
  import { toastr } from '../toastr.js';

  // Tela 13.1 — "Taxa e valor do estorno na mesma tela, antes de confirmar —
  // nada de descobrir depois."
  //
  // Os números não são calculados aqui: vêm de orders/cancel_quote.php, a
  // mesma conta que o servidor refaz na hora de confirmar. Se a tela
  // calculasse por conta própria, o cliente veria um valor e receberia
  // outro -- que é exatamente o que a tela existe pra evitar.
  //
  // A classe não se chama .modal: o Bootstrap reserva esse nome com
  // display:none (ver "Módulo de carrinho" no README).
  let { orderId, onClose, onCancelled } = $props();

  let quote = $state(null);
  let reason = $state(null);
  let busy = $state(false);
  let loadError = $state(null);

  $effect(() => {
    let alive = true;
    api
      .get('/orders/cancel_quote.php', { auth: true, query: { id: orderId } })
      .then((data) => {
        if (!alive) return;
        quote = data;
        // 15.1 — quando quem falhou fomos nós, o motivo já está escrito e não
        // há o que escolher: a tela não vai pedir explicação a quem esperou
        // quinze minutos por um entregador que não apareceu.
        if (data.no_courier) reason = data.reasons[0].code;
      })
      .catch((e) => {
        if (alive) loadError = e.message ?? 'Não deu pra calcular o cancelamento.';
      });
    return () => (alive = false);
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  async function confirm() {
    if (reason === null || busy) return;
    busy = true;
    try {
      const label = quote.reasons.find((r) => r.code === reason)?.label ?? reason;
      const data = await api.post('/orders/status.php', {
        auth: true,
        body: { order_id: orderId, to: 'cancelled', reason: label },
      });
      toastr.success(
        data.refund
          ? `Pedido cancelado. ${money(data.refund.amount)} de volta ${data.refund_plan.eta}.`
          : 'Pedido cancelado.'
      );
      onCancelled(data);
    } catch (e) {
      const code = e instanceof ApiError ? e.code : null;
      toastr.error(
        code === 'illegal_transition'
          ? 'Esse pedido já passou do ponto em que dava pra cancelar.'
          : (e.message ?? 'Não deu pra cancelar.')
      );
      if (code === 'illegal_transition') onClose();
    } finally {
      busy = false;
    }
  }
</script>

<div class="cancel-backdrop" onclick={onClose} role="presentation"></div>
<div class="cancel-panel fuu-card" role="dialog" aria-label="Cancelar o pedido">
  <div class="icon"><i class="bi bi-x-circle-fill"></i></div>
  <h2 class="fuu-display">Cancelar o pedido?</h2>

  {#if loadError}
    <p class="load-error">{loadError}</p>
    <button type="button" class="keep" onclick={onClose}>Voltar</button>
  {:else if !quote}
    <p class="loading">Calculando…</p>
  {:else}
    <p class="subject">
      #{quote.order.public_code} · {quote.order.restaurant_name} · {money(quote.order.total)}
    </p>

    {#if !quote.can_cancel}
      <div class="warn">
        Esse pedido não pode mais ser cancelado por aqui. Fale com o suporte se algo deu errado.
      </div>
      <button type="button" class="keep" onclick={onClose}>Voltar</button>
    {:else}
      {#if quote.no_courier}
        <div class="ok-note">
          <strong>A falha é nossa.</strong> Nenhum entregador aceitou a corrida, então a devolução é
          integral, sem taxa — inclusive a comida, que a loja recebe por nossa conta.
        </div>
      {:else if quote.quote.fee > 0}
        <div class="warn">
          <strong>A cozinha já começou.</strong> Cancelar agora cobra uma taxa de
          {money(quote.quote.fee)} — o restaurante já gastou os insumos.
        </div>
      {:else}
        <div class="ok-note">
          <strong>Ainda dá tempo.</strong> A loja não começou a preparar, então o cancelamento é sem taxa.
        </div>
      {/if}

      {#if !quote.no_courier}
        <p class="section-label">POR QUE ESTÁ CANCELANDO?</p>
        <div class="reasons">
          {#each quote.reasons as option (option.code)}
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
      {/if}

      <div class="money">
        <div class="kv">
          <span>Pago em {quote.order.payment_method === 'cash' ? 'dinheiro' : 'app'}</span>
          <strong>{money(quote.quote.paid_amount)}</strong>
        </div>
        {#if quote.quote.fee > 0}
          <div class="kv">
            <span>Taxa de cancelamento</span>
            <strong class="minus">− {money(quote.quote.fee)}</strong>
          </div>
        {/if}
        <div class="kv total">
          <span>{quote.quote.amount > 0 ? 'Estorno' : 'A devolver'}</span>
          <span>{money(quote.quote.amount)}</span>
        </div>
      </div>
      <p class="how">{quote.quote.how} · {quote.quote.eta}.</p>

      <button
        type="button"
        class="btn-fuu-primary w-100 confirm"
        disabled={reason === null || busy}
        onclick={confirm}
      >
        {busy ? 'Cancelando…' : 'Confirmar cancelamento'}
      </button>
      <button type="button" class="keep" onclick={onClose}>Manter o pedido</button>
    {/if}
  {/if}
</div>

<style>
  .cancel-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(23, 20, 15, 0.28);
    z-index: 70;
  }
  .cancel-panel {
    position: fixed;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    width: min(370px, calc(100vw - 32px));
    max-height: 90vh;
    overflow-y: auto;
    padding: 22px 20px;
    z-index: 71;
  }
  .icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
    font-size: 30px;
  }
  h2 {
    font-size: 19px;
    font-weight: 800;
    text-align: center;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .subject {
    font-size: 13px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    text-align: center;
    margin: 7px 0 0;
  }
  .loading,
  .load-error {
    text-align: center;
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 16px 0 0;
  }
  .load-error {
    color: var(--fuu-alert);
  }
  .warn,
  .ok-note {
    border-radius: 10px;
    padding: 13px;
    margin-top: 16px;
    font-size: 12.5px;
    line-height: 1.6;
  }
  .warn {
    border: 1px solid var(--fuu-wait-text);
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
  }
  .ok-note {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    color: var(--fuu-ink-5);
    letter-spacing: 0.08em;
    margin: 16px 0 9px;
  }
  .reasons {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .reason {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 12px;
    background: var(--fuu-white);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    color: var(--fuu-ink-3);
    text-align: left;
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
  .money {
    border-top: 1px dashed var(--fuu-line-3);
    margin-top: 16px;
    padding-top: 12px;
    font-size: 13px;
    color: var(--fuu-ink-3);
  }
  .kv {
    display: flex;
    justify-content: space-between;
    line-height: 1.9;
  }
  .kv strong {
    color: var(--fuu-ink-1);
  }
  .kv .minus {
    color: var(--fuu-red);
  }
  .kv.total {
    font-size: 15px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    border-top: 1px solid var(--fuu-line-4);
    margin-top: 6px;
    padding-top: 7px;
  }
  .how {
    font-size: 11px;
    color: var(--fuu-ink-3);
    margin: 6px 0 0;
    line-height: 1.5;
  }
  .confirm {
    margin-top: 16px;
    min-height: var(--fuu-tap-customer);
  }
  .keep {
    display: block;
    width: 100%;
    background: none;
    border: none;
    text-align: center;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-3);
    margin-top: 13px;
  }
</style>
