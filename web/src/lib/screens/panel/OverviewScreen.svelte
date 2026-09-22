<script>
  // Tela 7.3, área principal: "VISÃO GERAL DE HOJE" + "Pedidos recentes".
  // Os quatro números vêm de restaurants/stats.php num cálculo só no banco;
  // a tabela vem de restaurants/orders.php?scope=recent.
  let { stats, recent, kds } = $props();

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  const STATUS_LABEL = {
    pending_payment: 'AGUARDANDO PAGAMENTO',
    pending_verification: 'EM VALIDAÇÃO',
    paid: 'PAGO',
    preparing: 'EM PREPARO',
    ready: 'PRONTO',
    delivering: 'SAIU PRA ENTREGA',
    delivered: 'ENTREGUE',
    rejected: 'RECUSADO',
    cancelled: 'CANCELADO',
    refunded: 'REEMBOLSADO',
  };

  const STATUS_TONE = {
    pending_payment: 'wait',
    pending_verification: 'wait',
    paid: 'good',
    preparing: 'good',
    delivered: 'good',
    ready: 'neutral',
    delivering: 'neutral',
    rejected: 'bad',
    cancelled: 'bad',
    refunded: 'bad',
  };

  const METHOD_LABEL = {
    card: 'Cartão · MP',
    pix_manual: 'Pix + comprovante',
    pix_auto: 'Pix automático',
    cash: 'Dinheiro',
    pos_machine: 'Maquininha',
  };

  function method(order) {
    const base = METHOD_LABEL[order.payment_method] ?? '—';
    return order.payment_method === 'cash' && order.change_for
      ? `${base} · troco ${money(order.change_for)}`
      : base;
  }

  // O mock mostra "Marcos P." e não o nome inteiro: é uma tela aberta no
  // balcão, à vista de quem passa.
  function shortName(full) {
    if (!full) return '—';
    const parts = full.trim().split(/\s+/);
    return parts.length === 1 ? parts[0] : `${parts[0]} ${parts[1][0]}.`;
  }
</script>

<p class="section-label">VISÃO GERAL DE HOJE</p>
<div class="stat-row">
  <div class="fuu-card stat">
    <span>Faturado</span>
    <strong>{money(stats?.revenue ?? 0)}</strong>
  </div>
  <div class="fuu-card stat">
    <span>Pedidos</span>
    <strong>{stats?.orders_count ?? 0}</strong>
  </div>
  <div class="fuu-card stat">
    <span>Pix validados</span>
    <strong class="good">{stats?.pix_approved ?? 0}</strong>
  </div>
  <div class="fuu-card stat">
    <span>Recusados</span>
    <strong class="bad">{stats?.rejected_count ?? 0}</strong>
  </div>
</div>

<div class="fuu-card table-card">
  <p class="table-title">Pedidos recentes</p>
  <div class="kv head">
    <div class="c-code">PEDIDO</div>
    <div class="c-name">CLIENTE</div>
    <div class="c-pay">PAGAMENTO</div>
    <div class="c-total">TOTAL</div>
    <div class="c-status">STATUS</div>
  </div>
  {#each recent as order (order.id)}
    <div class="kv">
      <div class="c-code fuu-mono">#{order.public_code}</div>
      <div class="c-name">{shortName(order.customer_name)}</div>
      <div class="c-pay">{method(order)}</div>
      <div class="c-total">{money(order.total)}</div>
      <div class="c-status">
        <span class={`pill ${STATUS_TONE[order.status] ?? 'neutral'}`}>
          {STATUS_LABEL[order.status] ?? order.status}
        </span>
      </div>
    </div>
  {:else}
    <p class="empty">Nenhum pedido ainda.</p>
  {/each}
</div>

<p class="kds-note fuu-mono">
  {kds.length} pedido{kds.length === 1 ? '' : 's'} na cozinha agora · KDS lê apenas status IN ('paid','preparing','ready')
</p>

<style>
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  .stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-top: 10px;
  }
  @media (max-width: 760px) {
    .stat-row {
      grid-template-columns: repeat(2, 1fr);
    }
  }
  .stat {
    padding: 14px;
    display: flex;
    flex-direction: column;
  }
  .stat span {
    font-size: 11px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
  .stat strong {
    font-size: 22px;
    font-weight: 800;
    margin-top: 2px;
    color: var(--fuu-ink-1);
  }
  .stat strong.good {
    color: var(--fuu-leaf-dark);
  }
  .stat strong.bad {
    color: var(--fuu-red);
  }
  .table-card {
    margin-top: 14px;
    padding: 16px;
  }
  .table-title {
    font-size: 13px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .kv {
    display: flex;
    align-items: center;
    font-size: 12px;
    padding: 11px 0;
    border-bottom: 1px solid var(--fuu-line-5);
    color: var(--fuu-ink-2);
  }
  .kv.head {
    font-size: 10px;
    font-weight: 800;
    color: var(--fuu-ink-5);
    letter-spacing: 0.06em;
    padding: 12px 0 8px;
    border-bottom: 1px solid var(--fuu-line-3);
  }
  .c-code {
    width: 100px;
  }
  .c-name {
    flex: 1;
    font-weight: 600;
    min-width: 90px;
  }
  .c-pay {
    width: 170px;
  }
  .c-total {
    width: 100px;
    font-weight: 800;
  }
  .c-status {
    width: 160px;
  }
  .pill {
    font-size: 10px;
    font-weight: 800;
    padding: 4px 9px;
    border-radius: 20px;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-3);
  }
  .pill.good {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .pill.wait {
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
  }
  .pill.bad {
    background: var(--fuu-red-tint);
    color: var(--fuu-red);
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
    margin: 14px 0 0;
  }
  .kds-note {
    font-size: 10px;
    color: var(--fuu-ink-5);
    margin: 14px 0 0;
    line-height: 1.6;
  }
</style>
