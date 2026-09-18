<script>
  import { onMount, onDestroy } from 'svelte';
  import { api, BASE } from '../api.js';
  import { toastr } from '../toastr.js';
  import { currentToken } from '../session.svelte.js';
  import { parsePgTimestamp } from '../datetime.js';
  import ReviewScreen from './ReviewScreen.svelte';
  import CancelDialog from './CancelDialog.svelte';
  import OrderChat from '../components/OrderChat.svelte';

  // Fase 5 — pós-pedido e acompanhamento (5.1 Aprovado, 5.2 Em análise,
  // 5.3 Tracking, 5.4 Rejeitado), uma tela só reagindo ao status ao vivo
  // via SSE (orders/track.php) em vez de 4 arquivos separados -- no mock
  // as quatro já são a mesma ideia ("o aviso chega por SSE"), só o
  // conteúdo do miolo muda com o status. 5.5 (Avaliação) é
  // ReviewScreen.svelte, tela própria, só alcançável daqui quando
  // status = 'delivered'.
  let { orderId, onBack, onDone } = $props();

  let order = $state(null);
  let events = $state([]);
  let restaurant = $state(null);
  let review = $state(null);
  let showReview = $state(false);
  let showCancel = $state(false);
  let showChat = $state(false);
  let now = $state(Date.now());
  let evtSource;
  let clockInterval;

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  const STATUS_LABELS = {
    pending_payment: 'Aguardando pagamento',
    pending_verification: 'Comprovante em análise',
    paid: 'Pagamento confirmado',
    preparing: 'Em preparo na cozinha',
    ready: 'Pronto, aguarda entregador',
    delivering: 'Saiu para entrega',
    delivered: 'Entregue',
    rejected: 'Recusado',
    cancelled: 'Cancelado',
    refunded: 'Reembolsado',
  };
  const CANCELLABLE = new Set([
    'pending_payment',
    'pending_verification',
    'paid',
    'preparing',
    'delivering',
  ]);
  const PAYMENT_LABELS = {
    mp_card: 'Cartão', pix_auto: 'Pix', pix_manual: 'Pix', cash: 'Dinheiro', pos_machine: 'Maquininha',
  };

  function connect() {
    const token = currentToken();
    evtSource = new EventSource(`${BASE}/orders/track.php?id=${orderId}&token=${encodeURIComponent(token ?? '')}`);
    evtSource.addEventListener('order_update', (e) => {
      const data = JSON.parse(e.data);
      order = data.order;
      events = data.events;
    });
    // EventSource reconecta sozinho quando a conexão cai -- é assim que o
    // limite de 25s do backend (documentado em orders/track.php) funciona
    // como long-poll encadeado, sem perder evento nenhum. Não precisa de
    // lógica de retry aqui.
  }

  onMount(() => {
    connect();
    clockInterval = setInterval(() => (now = Date.now()), 1000);
    api
      .get('/orders/show.php', { auth: true, query: { id: orderId } })
      .then((data) => {
        order = data.order;
        events = data.events;
        review = data.review;
        return api.get('/restaurants/show.php', { query: { id: data.order.restaurant_id } });
      })
      .then((data) => (restaurant = data.restaurant))
      .catch(() => {});
  });
  onDestroy(() => {
    evtSource?.close();
    clearInterval(clockInterval);
  });

  // Enquanto o diálogo de cancelamento está aberto, a conexão de tempo real
  // é fechada: o modal cobre a tela inteira (não há o que atualizar atrás
  // dele) e ele precisa de duas chamadas HTTP -- a cotação e a confirmação.
  // Sob `php -S`, que atende uma requisição por vez, manter o SSE aberto
  // fazia a cotação esperar os 25s da janela do stream; medido em 23,5 s com
  // o navegador antes desta linha existir.
  $effect(() => {
    if (showCancel) {
      evtSource?.close();
      evtSource = undefined;
    } else if (order && !evtSource) {
      connect();
    }
  });

  let remainingSeconds = $derived(
    order?.verification_deadline
      ? Math.max(0, Math.floor((parsePgTimestamp(order.verification_deadline).getTime() - now) / 1000))
      : 0
  );
  let remainingLabel = $derived(
    `${String(Math.floor(remainingSeconds / 60)).padStart(2, '0')}:${String(remainingSeconds % 60).padStart(2, '0')}`
  );

  // Sem motor de logística real (Fase 8/9), é uma janela fixa a partir da
  // hora do pedido -- mesma simplificação documentada em PaymentSelector.svelte.
  function etaWindow(createdAt) {
    const base = createdAt ? parsePgTimestamp(createdAt).getTime() : Date.now();
    const fmt = (ms) => new Date(ms).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    return `${fmt(base + 25 * 60000)} – ${fmt(base + 45 * 60000)}`;
  }

  function eventLabel(ev) {
    return STATUS_LABELS[ev.to_status] ?? ev.to_status;
  }
  function eventTime(ev) {
    return parsePgTimestamp(ev.created_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  }

  function talkToStore() {
    showChat = true;
  }
</script>

{#if showReview}
  <ReviewScreen {orderId} orderCode={order?.public_code} restaurantName={restaurant?.name ?? ''} onDone={() => { showReview = false; onDone(); }} onSkip={() => (showReview = false)} />
{:else if order === null}
  <div class="tracking-screen"><p class="loading">Carregando pedido…</p></div>
{:else}
  <div class="tracking-screen">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>

    {#if order.status === 'paid'}
      <div class="hero approved">
        <i class="bi bi-check-circle-fill"></i>
        <h1 class="fuu-display">Pagamento aprovado</h1>
        <p class="hero-text">A cozinha {restaurant ? `do ${restaurant.name} ` : ''}já começou o seu pedido.</p>
      </div>
    {:else if order.status === 'pending_verification'}
      <div class="hero waiting">
        <i class="bi bi-search"></i>
        <h1 class="fuu-display">Analisando seu Pix</h1>
        <p class="hero-text">
          Comprovante recebido. {restaurant ? `A ${restaurant.name} ` : 'A loja '}confirma em até 15 minutos.
        </p>
        <div class="countdown-box">
          <span class="label">TEMPO RESTANTE</span>
          <span class="clock fuu-mono">{remainingLabel}</span>
        </div>
        <p class="hero-note">Se não confirmarem no prazo, o pedido é recusado automaticamente e nada é cobrado.</p>
      </div>
    {:else if order.status === 'rejected' || order.status === 'cancelled'}
      <div class="hero rejected">
        <i class="bi bi-x-circle-fill"></i>
        <h1 class="fuu-display">{order.status === 'rejected' ? 'Pagamento recusado' : 'Pedido cancelado'}</h1>
        <p class="hero-text">
          {order.reject_reason ?? order.cancel_reason ?? 'Não foi possível continuar com esse pedido.'}
        </p>
        <p class="hero-note">Esse pedido não pode ser retomado — monte um novo pra tentar de novo.</p>
      </div>
    {:else if ['preparing', 'ready', 'delivering'].includes(order.status)}
      <div class="hero tracking">
        <div class="map-placeholder">
          <i class="bi bi-map"></i>
          <span>Mapa e localização do entregador — Fase 8 (app do entregador) ainda não foi construída</span>
        </div>
        <h1 class="fuu-display">{STATUS_LABELS[order.status]}</h1>
        <p class="hero-text">Previsão de entrega: {etaWindow(order.created_at)}</p>
      </div>
    {:else if order.status === 'delivered'}
      <div class="hero delivered">
        <i class="bi bi-box-seam"></i>
        <h1 class="fuu-display">Pedido entregue</h1>
        <p class="hero-text">Esperamos que tenha gostado!</p>
        {#if review}
          <p class="hero-note">Você já avaliou esse pedido com {review.rating} {review.rating === 1 ? 'estrela' : 'estrelas'}. Obrigado!</p>
        {:else}
          <button type="button" class="btn-fuu-primary w-100" onclick={() => (showReview = true)}>Avaliar pedido</button>
        {/if}
      </div>
    {:else}
      <div class="hero">
        <h1 class="fuu-display">{STATUS_LABELS[order.status] ?? order.status}</h1>
      </div>
    {/if}

    <div class="summary fuu-card">
      <div class="kv"><span>Pedido</span><span class="fuu-mono">#{order.public_code}</span></div>
      <div class="kv"><span>Pagamento</span><span>{PAYMENT_LABELS[order.payment_method] ?? order.payment_method}</span></div>
      <div class="kv"><span>Total</span><span class="fuu-mono">{money(order.total)}</span></div>
      {#if order.payment_method === 'cash' && order.change_for}
        <div class="kv"><span>Troco para</span><span class="fuu-mono">{money(order.change_for)}</span></div>
      {/if}
    </div>

    <p class="section-label">LINHA DO TEMPO</p>
    <div class="timeline">
      {#each events as ev (ev.id)}
        <div class="timeline-item">
          <span class="dot"></span>
          <div>
            <p class="event-label">{eventLabel(ev)}</p>
            <p class="event-time fuu-mono">{eventTime(ev)}</p>
          </div>
        </div>
      {/each}
    </div>

    <div class="actions">
      <!-- 14.2 — a conversa existe do pedido feito até 2 h depois da
           entrega; o servidor é quem fecha, a tela só abre. -->
      <button type="button" class="btn-fuu-primary w-100" onclick={talkToStore}>
        <i class="bi bi-chat-dots"></i> Falar com a loja
      </button>
      <!-- 13.1 — cancelar só aparece enquanto é possível. 'ready' fica de
           fora porque a comida está na bancada esperando o entregador, e a
           função do banco não permite essa transição. -->
      {#if CANCELLABLE.has(order.status)}
        <button type="button" class="link-btn danger" onclick={() => (showCancel = true)}>
          Cancelar pedido
        </button>
      {/if}
      <button type="button" class="link-btn" onclick={onDone}>Voltar ao início</button>
    </div>
  </div>
{/if}

{#if showChat}
  <OrderChat orderId={order.id} token={currentToken()} onClose={() => (showChat = false)} />
{/if}

{#if showCancel}
  <CancelDialog
    orderId={order.id}
    onClose={() => (showCancel = false)}
    onCancelled={(data) => {
      showCancel = false;
      order = data.order;
    }}
  />
{/if}

<style>
  .tracking-screen {
    padding: 12px 20px 40px;
  }
  .loading {
    text-align: center;
    color: var(--fuu-ink-5);
    padding: 60px 0;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-2);
    padding: 4px;
    margin-bottom: 8px;
  }
  .hero {
    text-align: center;
    padding: 20px 10px 24px;
  }
  .hero i {
    font-size: 44px;
  }
  .hero.approved i {
    color: var(--fuu-leaf);
  }
  .hero.waiting i {
    color: var(--fuu-wait-text);
  }
  .hero.rejected i {
    color: var(--fuu-alert);
  }
  .hero.delivered i {
    color: var(--fuu-leaf);
  }
  .hero h1 {
    font-size: 21px;
    margin: 10px 0 6px;
  }
  .hero-text {
    color: var(--fuu-ink-3);
    font-size: 13.5px;
    margin: 0 0 10px;
  }
  .hero-note {
    color: var(--fuu-ink-5);
    font-size: 11.5px;
    margin: 8px 0 0;
  }
  .hero.delivered .btn-fuu-primary {
    margin-top: 14px;
  }
  .countdown-box {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    background: var(--fuu-wait-bg);
    border-radius: var(--fuu-radius-card);
    padding: 8px 20px;
    margin: 6px 0;
  }
  .countdown-box .label {
    font-family: var(--fuu-font-mono);
    font-size: 9.5px;
    letter-spacing: 0.08em;
    color: var(--fuu-wait-text);
  }
  .countdown-box .clock {
    font-size: 22px;
    font-weight: 700;
    color: var(--fuu-wait-text);
  }
  .map-placeholder {
    height: 140px;
    border-radius: var(--fuu-radius-card);
    background: var(--fuu-line-5);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--fuu-ink-5);
    font-size: 11px;
    padding: 0 24px;
    text-align: center;
    margin-bottom: 12px;
  }
  .map-placeholder i {
    font-size: 32px;
  }
  .summary {
    padding: 12px 16px;
    margin-bottom: 18px;
  }
  .kv {
    display: flex;
    justify-content: space-between;
    font-size: 13.5px;
    color: var(--fuu-ink-3);
    padding: 4px 0;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 10px;
  }
  .timeline {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin-bottom: 20px;
  }
  .timeline-item {
    display: flex;
    gap: 10px;
    align-items: flex-start;
  }
  .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--fuu-leaf);
    margin-top: 5px;
    flex: none;
  }
  .event-label {
    margin: 0;
    font-size: 13.5px;
    color: var(--fuu-ink-1);
  }
  .event-time {
    margin: 2px 0 0;
    font-size: 11px;
    color: var(--fuu-ink-5);
  }
  .actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .link-btn {
    background: none;
    border: none;
    color: var(--fuu-ink-4);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 600;
    padding: 8px 0;
  }
  .link-btn.danger {
    color: var(--fuu-red);
  }
</style>
