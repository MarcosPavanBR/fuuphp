<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 2.5 — "Notas e comprovantes". Lista os pedidos que tiveram
  // cobrança e abre o recibo de cada um (orders/receipt.php): loja com CNPJ,
  // itens, taxas, desconto, como foi pago, estornos e gorjeta cobrada à
  // parte. Dá pra imprimir/salvar em PDF pelo navegador.
  //
  // Recibo não é nota fiscal -- quem vende é a loja, e é ela quem emite a
  // NF-e. A tela diz isso no rodapé de cada recibo.
  let { onBack } = $props();

  const METHOD = {
    mp_card: 'Cartão no app',
    pix_auto: 'Pix automático',
    pix_manual: 'Pix (chave da loja)',
    cash: 'Dinheiro na entrega',
    pos_machine: 'Maquininha na entrega',
  };
  const REFUND_STATE = { pending: 'em análise', sent: 'em processamento', done: 'devolvido', failed: 'falhou — o suporte vai te procurar' };
  // Pedido que nunca teve cobrança (Pix que expirou, cartão recusado) não
  // tem recibo.
  const WITHOUT_RECEIPT = new Set(['pending_payment', 'pending_verification', 'rejected']);

  let orders = $state(null);
  let receipt = $state(null);

  async function load() {
    try {
      const data = await api.get('/orders/list.php', { auth: true });
      orders = data.orders.filter((o) => !WITHOUT_RECEIPT.has(o.status));
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar seus pedidos.');
      orders = [];
    }
  }
  load();

  async function open(o) {
    try {
      receipt = await api.get('/orders/receipt.php', { auth: true, query: { id: o.id } });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra abrir o recibo.');
    }
  }

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }
  function when(ts) {
    const d = parsePgTimestamp(ts);
    return d ? d.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '';
  }
  function cnpj(c) {
    const d = String(c ?? '').replace(/\D/g, '');
    return d.length === 14 ? `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}` : c;
  }
</script>

<div class="receipts">
  <div class="header-row no-print">
    <button type="button" class="back" onclick={() => (receipt ? (receipt = null) : onBack())} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">{receipt ? `Recibo #${receipt.order.public_code}` : 'Notas e comprovantes'}</h1>
  </div>

  {#if receipt}
    <div class="paper">
      <p class="store">{receipt.store?.name}</p>
      {#if receipt.store?.cnpj}<p class="muted fuu-mono">CNPJ {cnpj(receipt.store.cnpj)}</p>{/if}
      <p class="muted">Pedido #{receipt.order.public_code} · {when(receipt.order.created_at)}</p>

      <div class="lines">
        {#each receipt.items as item (item.id)}
          <div class="line">
            <span>{item.quantity}× {item.name_snapshot}</span>
            <span class="fuu-mono">{money(item.line_total ?? item.unit_price * item.quantity)}</span>
          </div>
        {/each}
      </div>

      <div class="lines totals">
        <div class="line"><span>Subtotal</span><span class="fuu-mono">{money(receipt.order.subtotal)}</span></div>
        <div class="line"><span>Entrega</span><span class="fuu-mono">{money(receipt.order.delivery_fee)}</span></div>
        {#if Number(receipt.order.surge_fee) > 0}
          <div class="line"><span>Entrega turbinada</span><span class="fuu-mono">{money(receipt.order.surge_fee)}</span></div>
        {/if}
        {#if Number(receipt.order.tip) > 0}
          <div class="line"><span>Gorjeta</span><span class="fuu-mono">{money(receipt.order.tip)}</span></div>
        {/if}
        {#if Number(receipt.order.discount) > 0}
          <div class="line"><span>Descontos e crédito</span><span class="fuu-mono">−{money(receipt.order.discount)}</span></div>
        {/if}
        <div class="line total"><span>Total</span><span class="fuu-mono">{money(receipt.order.total)}</span></div>
      </div>

      <p class="muted">Pago com {METHOD[receipt.order.payment_method] ?? receipt.order.payment_method}</p>

      {#if receipt.review_tip}
        <p class="muted">
          Gorjeta da avaliação: {money(receipt.review_tip.courier_tip)}, cobrada à parte em {when(receipt.review_tip.tip_charged_at)}.
        </p>
      {/if}

      {#each receipt.refunds as r, i (i)}
        <p class="refund">
          <i class="bi bi-arrow-counterclockwise"></i>
          Reembolso de {money(r.amount)} — {REFUND_STATE[r.state] ?? r.state}{r.executed_at ? ` em ${when(r.executed_at)}` : ''}
        </p>
      {/each}

      <p class="fiscal">{receipt.fiscal_notice}</p>
    </div>
    <button type="button" class="btn-fuu-primary w-100 no-print" onclick={() => window.print()}>
      <i class="bi bi-printer"></i> Imprimir ou salvar PDF
    </button>
  {:else if orders === null}
    <p class="muted">Carregando…</p>
  {:else if orders.length === 0}
    <p class="muted">Nenhum pedido pago ainda.</p>
  {:else}
    <div class="list">
      {#each orders as o (o.id)}
        <button type="button" class="row" onclick={() => open(o)}>
          <span>
            <strong>{o.restaurant_name}</strong>
            <small>#{o.public_code} · {when(o.created_at)}</small>
          </span>
          <span class="fuu-mono">{money(o.total)}</span>
        </button>
      {/each}
    </div>
  {/if}
</div>

<style>
  .receipts {
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
  .list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    text-align: left;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 12px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .row small {
    display: block;
    color: var(--fuu-ink-5);
    font-size: 12px;
  }
  .paper {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 18px;
    margin-bottom: 14px;
  }
  .store {
    font-weight: 700;
    font-size: 16px;
    margin: 0;
  }
  .muted {
    color: var(--fuu-ink-5);
    font-size: 12px;
    margin: 2px 0;
  }
  .lines {
    border-top: 1px dashed var(--fuu-line-2);
    margin-top: 12px;
    padding-top: 10px;
  }
  .line {
    display: flex;
    justify-content: space-between;
    font-size: 13.5px;
    padding: 3px 0;
  }
  .line.total {
    font-weight: 700;
    font-size: 15px;
    padding-top: 6px;
  }
  .refund {
    font-size: 12.5px;
    color: var(--fuu-leaf-dark);
    margin: 8px 0 0;
  }
  .fiscal {
    margin: 14px 0 0;
    font-size: 11px;
    color: var(--fuu-ink-5);
    border-top: 1px dashed var(--fuu-line-2);
    padding-top: 10px;
  }
  @media print {
    .no-print {
      display: none !important;
    }
  }
</style>
