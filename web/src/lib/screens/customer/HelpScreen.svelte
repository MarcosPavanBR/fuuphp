<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 14.1 — Central de ajuda.
  //
  // "Ajuda começa no pedido em andamento, não numa lista de perguntas. Os
  // quatro atalhos cobrem a maior parte dos tickets reais de delivery —
  // cada um abre um fluxo automático antes de chamar gente."
  //
  // O fluxo automático não é texto fixo: vem de support/answer.php, que lê
  // o estado real do pedido. É por isso que "meu pedido está atrasado"
  // responde coisas diferentes pra um pedido em preparo e pra um pedido
  // pronto que ninguém foi buscar.
  let { onBack, onOpenOrder } = $props();

  let data = $state(null);
  let topic = $state(null);
  let message = $state('');
  let busy = $state(false);

  const STATUS_LABELS = {
    pending_payment: 'Aguardando pagamento',
    pending_verification: 'Comprovante em análise',
    paid: 'Pagamento confirmado',
    preparing: 'Em preparo',
    ready: 'Pronto, aguarda entregador',
    delivering: 'Saiu para entrega',
    delivered: 'Entregue',
    rejected: 'Recusado',
    cancelled: 'Cancelado',
    refunded: 'Reembolsado',
  };
  const CATEGORY_LABELS = {
    late: 'pedido atrasado',
    wrong_item: 'item errado ou faltando',
    refund: 'estorno',
    pix_pending: 'Pix não confirmado',
    other: 'outro assunto',
  };
  const TICKET_STATE = {
    open: 'em aberto',
    waiting_customer: 'esperando você',
    resolved: 'resolvido',
  };

  async function load() {
    try {
      data = await api.get('/support/home.php', { auth: true });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra abrir a ajuda.');
    }
  }

  $effect(() => {
    load();
  });

  async function openTopic(code) {
    topic = { code, loading: true };
    message = '';
    try {
      const res = await api.get('/support/answer.php', {
        auth: true,
        query: { topic: code, ...(data?.subject_order ? { order_id: data.subject_order.id } : {}) },
      });
      topic = { code, ...res, loading: false };
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra checar seu pedido.');
      topic = null;
    }
  }

  async function openTicket() {
    if (message.trim() === '') {
      toastr.warning('Conta o que aconteceu — é o que o atendente lê primeiro.');
      return;
    }
    busy = true;
    try {
      const res = await api.post('/support/ticket.php', {
        auth: true,
        body: {
          category: topic.code,
          message: message.trim(),
          ...(topic.order ? { order_id: topic.order.id } : {}),
        },
      });
      toastr.success(
        res.reopened
          ? `Você já tinha o chamado ${res.ticket.code} aberto — sua mensagem entrou nele.`
          : `Chamado ${res.ticket.code} aberto.`
      );
      if (!res.message_delivered) {
        toastr.info('Sem pedido ligado, a mensagem fica no chamado — a conversa é sempre por pedido.');
      }
      topic = null;
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra abrir o chamado.');
    } finally {
      busy = false;
    }
  }

  function slaLabel(minutes) {
    if (minutes >= 1440) return `${Math.round(minutes / 1440)} dia útil`;
    if (minutes >= 60) return `${Math.round(minutes / 60)} h`;
    return `${minutes} min`;
  }

  function ticketDate(value) {
    const d = parsePgTimestamp(value);
    return d ? d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }) : '—';
  }
</script>

<div class="help-screen">
  <header class="top">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>
    <h1>Ajuda</h1>
  </header>

  {#if data === null}
    <p class="loading">Abrindo a ajuda…</p>
  {:else}
    <div class="body">
      {#if data.subject_order}
        <div class="subject" class:live={data.subject_order.in_progress}>
          <p class="eyebrow">
            {data.subject_order.in_progress ? 'PEDIDO EM ANDAMENTO' : 'SEU ÚLTIMO PEDIDO'}
          </p>
          <p class="subject-title">
            #{data.subject_order.public_code} · {data.subject_order.restaurant_name}
          </p>
          <p class="subject-sub">
            {STATUS_LABELS[data.subject_order.status] ?? data.subject_order.status}
          </p>
          <button type="button" class="btn-fuu-primary w-100 talk" onclick={() => onOpenOrder(data.subject_order.id)}>
            <i class="bi bi-chat-dots-fill"></i> Falar sobre este pedido
          </button>
        </div>
      {:else}
        <div class="subject">
          <p class="eyebrow">NENHUM PEDIDO AINDA</p>
          <p class="subject-sub">A ajuda parte do seu pedido — faça um e ela passa a falar dele.</p>
        </div>
      {/if}

      <p class="section-label">RESOLVE NA HORA</p>
      <div class="topics">
        {#each data.topics as option (option.code)}
          <button type="button" class="topic" onclick={() => openTopic(option.code)}>
            <i class={`bi ${option.icon}`}></i>
            <span class="topic-label">{option.label}</span>
            <i class="bi bi-chevron-right chev"></i>
          </button>
        {/each}
      </div>

      {#if data.tickets.length > 0}
        <p class="section-label">SEUS ATENDIMENTOS</p>
        {#each data.tickets as ticket (ticket.id)}
          <div class="ticket">
            <span class="dot" class:resolved={ticket.state === 'resolved'}></span>
            <div class="ticket-text">
              <p class="ticket-title">
                #{ticket.code} · {CATEGORY_LABELS[ticket.category] ?? ticket.category}
              </p>
              <p class="ticket-sub">
                {TICKET_STATE[ticket.state] ?? ticket.state} · aberto em {ticketDate(ticket.created_at)}
                {#if ticket.order_code}· pedido #{ticket.order_code}{/if}
              </p>
            </div>
          </div>
        {/each}
      {/if}

      <!-- O mock mostra "tempo médio de resposta agora: 2 min". Aqui o número
           é medido das conversas reais dos últimos 7 dias; sem conversa no
           período não há média, e a linha some em vez de inventar. -->
      {#if data.avg_reply_seconds !== null}
        <p class="avg">
          Tempo médio de resposta nos últimos 7 dias:
          <strong>
            {data.avg_reply_seconds < 60
              ? 'menos de 1 min'
              : `${Math.round(data.avg_reply_seconds / 60)} min`}
          </strong>
        </p>
      {/if}
    </div>
  {/if}
</div>

{#if topic}
  <div class="help-backdrop" onclick={() => (topic = null)} role="presentation"></div>
  <div class="help-panel fuu-card" role="dialog" aria-label="Ajuda">
    {#if topic.loading}
      <p class="loading">Conferindo seu pedido…</p>
    {:else}
      <p class="answer-title">{topic.answer.title}</p>
      <p class="answer-body">{topic.answer.body}</p>

      {#if topic.answer.go_to_order && topic.order}
        <button
          type="button"
          class="btn-fuu-primary w-100"
          onclick={() => {
            const id = topic.order.id;
            topic = null;
            onOpenOrder(id);
          }}
        >
          Abrir o pedido #{topic.order.public_code}
        </button>
      {/if}

      {#if topic.answer.can_open_ticket}
        <p class="field-label">
          {topic.answer.resolved ? 'AINDA PRECISA DE GENTE?' : 'CONTA O QUE ACONTECEU'}
        </p>
        <textarea rows="3" bind:value={message} placeholder="Descreva em uma frase"></textarea>
        <p class="sla">
          Prazo de resposta pra esse assunto: <strong>{slaLabel(topic.sla_minutes)}</strong>.
        </p>
        <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={openTicket}>
          {busy ? 'Abrindo…' : 'Abrir chamado'}
        </button>
      {/if}
      <button type="button" class="close" onclick={() => (topic = null)}>Fechar</button>
    {/if}
  </div>
{/if}

<style>
  .help-screen {
    display: flex;
    flex-direction: column;
    flex: 1;
    background: var(--fuu-paper);
  }
  .top {
    background: var(--fuu-white);
    border-bottom: 1px solid var(--fuu-line-3);
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .top h1 {
    font-size: 17px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-1);
    padding: 0;
  }
  .body {
    padding: 16px 18px 30px;
  }
  .loading {
    text-align: center;
    color: var(--fuu-ink-5);
    padding: 40px 0;
  }
  .subject {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 15px;
  }
  .subject.live {
    border: 2px solid var(--fuu-red);
  }
  .eyebrow {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-6);
    margin: 0;
  }
  .subject.live .eyebrow {
    color: var(--fuu-red);
  }
  .subject-title {
    font-weight: 800;
    font-size: 14px;
    color: var(--fuu-ink-1);
    margin: 6px 0 2px;
  }
  .subject-sub {
    font-size: 12px;
    color: var(--fuu-ink-2);
    margin: 0;
  }
  .talk {
    margin-top: 12px;
    padding: 12px;
    font-size: 13px;
    min-height: var(--fuu-tap-customer);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-6);
    margin: 18px 0 9px;
  }
  .topics {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    overflow: hidden;
  }
  .topic {
    display: flex;
    align-items: center;
    gap: 13px;
    width: 100%;
    padding: 14px;
    border: none;
    border-bottom: 1px solid var(--fuu-line-5);
    background: var(--fuu-white);
    text-align: left;
    min-height: var(--fuu-tap-customer);
  }
  .topic:last-child {
    border-bottom: none;
  }
  .topic i {
    color: var(--fuu-red);
    font-size: 17px;
  }
  .topic-label {
    flex: 1;
    font-family: var(--fuu-font-body);
    font-size: 13.5px;
    font-weight: 600;
    color: var(--fuu-ink-1);
  }
  .chev {
    font-size: 11px;
    color: var(--fuu-ink-5) !important;
  }
  .ticket {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 13px;
    display: flex;
    align-items: center;
    gap: 11px;
    margin-bottom: 8px;
  }
  .dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--fuu-wait-text);
    flex: 0 0 auto;
  }
  .dot.resolved {
    background: var(--fuu-leaf);
  }
  .ticket-title {
    font-weight: 700;
    font-size: 13px;
    color: var(--fuu-ink-1);
    margin: 0;
  }
  .ticket-sub {
    font-size: 11px;
    color: var(--fuu-ink-2);
    margin: 2px 0 0;
  }
  .avg {
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin-top: 14px;
    text-align: center;
  }
  .help-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(23, 20, 15, 0.28);
    z-index: 70;
  }
  .help-panel {
    position: fixed;
    left: 50%;
    bottom: 0;
    transform: translateX(-50%);
    width: min(430px, 100vw);
    border-radius: var(--fuu-radius-card) var(--fuu-radius-card) 0 0;
    max-height: 88vh;
    overflow-y: auto;
    padding: 20px 18px 24px;
    z-index: 71;
  }
  .answer-title {
    font-size: 16px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    margin: 0 0 6px;
  }
  .answer-body {
    font-size: 13px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin: 0 0 14px;
  }
  .field-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-6);
    margin: 16px 0 8px;
  }
  textarea {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 11px 12px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    color: var(--fuu-ink-1);
    background: var(--fuu-white);
    resize: vertical;
  }
  .sla {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin: 8px 0 12px;
  }
  .close {
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
