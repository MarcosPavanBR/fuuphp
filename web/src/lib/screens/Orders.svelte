<script>
  import { api } from '../api.js';
  import { toastr } from '../toastr.js';

  // Tela 2.4 — Pedidos. "O status 'analisando comprovante' é uma etapa de
  // primeira classe, não um 'em preparo' mentiroso." (orders.status, SSE,
  // order_events)
  //
  // SSE real (tempo real via LISTEN/NOTIFY, Especificação Parte I §5) não
  // está portado ainda -- esta tela busca uma vez ao entrar, sem
  // atualização automática. "Acompanhar"/"Repetir" do mock não têm tela de
  // destino ainda (rastreio ao vivo é dispatch, Fase 8; repetir pedido não
  // foi construído); os dois avisam em vez de fingir.
  let { onOpenOrder, onBack } = $props();

  const LABELS = {
    pending_payment: 'AGUARDANDO PAGAMENTO',
    pending_verification: 'ANALISANDO COMPROVANTE',
    paid: 'PAGO — AGUARDANDO A LOJA',
    preparing: 'EM PREPARO',
    ready: 'PRONTO PARA ENTREGA',
    delivering: 'SAIU PARA ENTREGA',
    delivered: 'ENTREGUE',
    rejected: 'RECUSADO',
    cancelled: 'CANCELADO',
    refunded: 'REEMBOLSADO',
  };
  const OPEN_STATUSES = new Set([
    'pending_payment', 'pending_verification', 'paid', 'preparing', 'ready', 'delivering',
  ]);
  const PAYMENT_LABELS = {
    mp_card: 'Cartão', pix_auto: 'Pix', pix_manual: 'Pix', cash: 'Dinheiro', pos_machine: 'Maquininha',
  };

  let tab = $state('open');
  let orders = $state([]);
  let loading = $state(true);

  async function load() {
    loading = true;
    try {
      const data = await api.get('/orders/list.php', { auth: true });
      orders = data.orders;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar os pedidos.');
    } finally {
      loading = false;
    }
  }
  load();

  let visible = $derived(
    orders.filter((o) => (tab === 'open' ? OPEN_STATUSES.has(o.status) : !OPEN_STATUSES.has(o.status)))
  );

  function statusLabel(o) {
    if (o.status === 'pending_verification' && o.payment_method?.startsWith('pix')) {
      return 'AGUARDANDO APROVAÇÃO DO PIX';
    }
    return LABELS[o.status] ?? o.status.toUpperCase();
  }

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function minutesLeft(deadline) {
    if (!deadline) return null;
    const ms = new Date(deadline.replace(' ', 'T')).getTime() - Date.now();
    return Math.max(0, Math.round(ms / 60000));
  }
</script>

<div class="orders-screen">
  <div class="header-row">
    {#if onBack}
      <button type="button" class="back" onclick={onBack} aria-label="Voltar">
        <i class="bi bi-arrow-left"></i>
      </button>
    {/if}
    <h1 class="fuu-display">Meus pedidos</h1>
  </div>

  <div class="tabs">
    <button type="button" class:active={tab === 'open'} onclick={() => (tab = 'open')}>Em andamento</button>
    <button type="button" class:active={tab === 'history'} onclick={() => (tab = 'history')}>Histórico</button>
  </div>

  {#if loading}
    <p class="empty">Carregando…</p>
  {:else if visible.length === 0}
    <p class="empty">
      {tab === 'open' ? 'Nenhum pedido em andamento.' : 'Ainda sem histórico de pedidos.'}
    </p>
  {:else}
    <div class="order-list">
      {#each visible as o (o.id)}
        <div
          class="order-card fuu-card"
          role="button"
          tabindex="0"
          onclick={() => onOpenOrder(o)}
          onkeydown={(e) => (e.key === 'Enter' || e.key === ' ') && onOpenOrder(o)}
        >
          <span class="status-badge" class:wait={o.status !== 'delivered'} class:done={o.status === 'delivered'}>
            {statusLabel(o)}
          </span>
          <p class="restaurant">{o.restaurant_name} · #{o.public_code}</p>
          <p class="meta">
            {o.items_count} {o.items_count === 1 ? 'item' : 'itens'} · {money(o.total)} · {PAYMENT_LABELS[o.payment_method] ?? o.payment_method}
          </p>
          {#if o.status === 'pending_verification' && minutesLeft(o.verification_deadline) !== null}
            <p class="deadline">A loja tem {minutesLeft(o.verification_deadline)} min para validar</p>
          {/if}
          {#if o.status === 'delivering'}
            <button
              type="button"
              class="action"
              onclick={(e) => { e.stopPropagation(); toastr.info('Rastreio ao vivo é Fase 8 (dispatch) — ainda não portada.'); }}
            >
              Acompanhar
            </button>
          {/if}
          {#if tab === 'history'}
            <button
              type="button"
              class="action"
              onclick={(e) => { e.stopPropagation(); toastr.info('Repetir pedido ainda não foi construído.'); }}
            >
              Repetir
            </button>
          {/if}
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  .orders-screen {
    padding: 8px 20px 16px;
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
    font-size: 22px;
    margin: 0;
  }
  .tabs {
    display: flex;
    gap: 4px;
    background: var(--fuu-line-5);
    border-radius: 999px;
    padding: 4px;
    margin-bottom: 16px;
  }
  .tabs button {
    flex: 1;
    border: none;
    background: none;
    padding: 8px 0;
    border-radius: 999px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 600;
    color: var(--fuu-ink-4);
  }
  .tabs button.active {
    background: var(--fuu-white);
    color: var(--fuu-ink-1);
  }
  .empty {
    color: var(--fuu-ink-5);
    font-size: 13.5px;
  }
  .order-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .order-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    padding: 14px;
    width: 100%;
    text-align: left;
  }
  .status-badge {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.06em;
    border-radius: 999px;
    padding: 3px 10px;
    margin-bottom: 4px;
  }
  .status-badge.wait {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .status-badge.done {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .restaurant {
    margin: 0;
    font-weight: 600;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .meta {
    margin: 0;
    font-size: 12.5px;
    color: var(--fuu-ink-5);
  }
  .deadline {
    margin: 2px 0 0;
    font-size: 12px;
    color: var(--fuu-alert);
  }
  .action {
    margin-top: 6px;
    color: var(--fuu-red);
    font-size: 13px;
    font-weight: 600;
    background: none;
    border: none;
    padding: 0;
    font-family: var(--fuu-font-body);
  }
</style>
