<script>
  import { api, ApiError } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { staffToken } from '../../staffSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Tela 11.1 — "Três colunas, cronômetro por pedido, nenhum pedido sem
  // pagamento resolvido." As três colunas são exatamente os três status que
  // o índice orders_kds_idx cobre: paid (NOVOS), preparing (EM PREPARO),
  // ready (PRONTO). Nada além disso chega aqui -- é o que impede a cozinha
  // de gastar insumo antes da aprovação.
  //
  // A fila de Pix fica fixa no rodapé da terceira coluna, como no mock:
  // "trabalho pendente que trava faturamento, não notificação".
  let { orders, proofs, onRefresh, onOpenProof, onReject, onOpenChat } = $props();

  let now = $state(Date.now());
  let busyId = $state(null);

  $effect(() => {
    const t = setInterval(() => (now = Date.now()), 1000);
    return () => clearInterval(t);
  });

  // "34 min" é o alvo de preparo; a barra enche até lá e passa a vermelho
  // depois. É estimativa visual da cozinha, não SLA gravado em lugar nenhum.
  const TARGET_SECONDS = 15 * 60;

  let novos = $derived(orders.filter((o) => o.status === 'paid'));
  let preparando = $derived(orders.filter((o) => o.status === 'preparing'));
  let prontos = $derived(orders.filter((o) => o.status === 'ready'));

  function elapsed(order) {
    const since = parsePgTimestamp(order.status_since ?? order.created_at);
    return since ? Math.max(0, Math.floor((now - since.getTime()) / 1000)) : 0;
  }

  function clock(secs) {
    return `${String(Math.floor(secs / 60)).padStart(2, '0')}:${String(secs % 60).padStart(2, '0')}`;
  }

  function ago(secs) {
    return secs < 60 ? `há ${secs} s` : `há ${Math.floor(secs / 60)} min`;
  }

  function lines(raw) {
    const items = typeof raw === 'string' ? JSON.parse(raw ?? '[]') : (raw ?? []);
    return items.map((item) => {
      const variants = typeof item.variants === 'string' ? JSON.parse(item.variants) : item.variants;
      const detail = [
        ...(variants ?? []).map((v) => v.name ?? v.option_name ?? '').filter(Boolean),
        item.notes,
      ]
        .filter(Boolean)
        .join(' · ');
      return { text: `${item.quantity}× ${item.name}`, detail };
    });
  }

  const METHOD_LABEL = {
    card: 'CARTÃO',
    pix_manual: 'PIX',
    pix_auto: 'PIX',
    cash: 'DINHEIRO',
    pos_machine: 'MAQUININHA',
  };

  function methodLabel(order) {
    return METHOD_LABEL[order.payment_method] ?? 'PAGO NO APP';
  }

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  async function advance(order, to) {
    busyId = order.id;
    try {
      await api.post('/orders/status.php', {
        token: staffToken(),
        body: { order_id: order.id, to },
      });
      onRefresh();
    } catch (e) {
      const message =
        e instanceof ApiError && e.code === 'illegal_transition'
          ? 'Esse pedido já mudou de estado em outro aparelho — atualizando a tela.'
          : (e.message ?? 'Não deu pra mudar o pedido de coluna.');
      toastr.error(message);
      if (e instanceof ApiError && e.code === 'illegal_transition') onRefresh();
    } finally {
      busyId = null;
    }
  }

  // 15.1 — retirada não tem motoboy no meio: entregar a sacola na mão do
  // cliente encerra o pedido. São duas transições porque a máquina de status
  // não tem atalho de 'ready' pra 'delivered' -- e não deveria ter: a sacola
  // sai da bancada e chega ao dono em dois momentos, mesmo que separados por
  // três segundos. As duas ficam na linha do tempo do cliente.
  async function handoffToCustomer(order) {
    busyId = order.id;
    try {
      for (const to of ['delivering', 'delivered']) {
        await api.post('/orders/status.php', {
          token: staffToken(),
          body: { order_id: order.id, to },
        });
      }
      toastr.success(`#${order.public_code} entregue no balcão.`);
      onRefresh();
    } catch (e) {
      const message =
        e instanceof ApiError && e.code === 'illegal_transition'
          ? 'Esse pedido já mudou de estado em outro aparelho — atualizando a tela.'
          : (e.message ?? 'Não deu pra mudar o pedido de coluna.');
      toastr.error(message);
      if (e instanceof ApiError && e.code === 'illegal_transition') onRefresh();
    } finally {
      busyId = null;
    }
  }
</script>

<div class="board">
  <section class="column">
    <header class="col-head new">
      <span>NOVOS</span>
      <span class="count">{novos.length}</span>
    </header>
    {#each novos as order (order.id)}
      <article class="ticket highlight">
        <div class="ticket-head">
          <span class="code fuu-mono">#{order.public_code}</span>
          <span class="when">{ago(elapsed(order))}</span>
          <span class="method">{methodLabel(order)}</span>
          <button type="button" class="chat" onclick={() => onOpenChat(order)} aria-label="Conversa do pedido">
            <i class="bi bi-chat-dots"></i>
          </button>
        </div>
        <ul class="lines">
          {#each lines(order.items) as line}
            <li>
              {line.text}
              {#if line.detail}<span class="detail">{line.detail}</span>{/if}
            </li>
          {/each}
        </ul>
        {#if order.payment_method === 'cash' && order.change_for}
          <p class="change">troco para {money(order.change_for)}</p>
        {/if}
        <div class="ticket-actions">
          <button type="button" class="reject-btn" onclick={() => onReject(order)}>Recusar</button>
          <button
            type="button"
            class="btn-fuu-primary accept"
            disabled={busyId === order.id}
            onclick={() => advance(order, 'preparing')}
          >
            Aceitar
          </button>
        </div>
      </article>
    {:else}
      <p class="empty">Nenhum pedido novo.</p>
    {/each}
  </section>

  <section class="column">
    <header class="col-head cooking">
      <span>EM PREPARO</span>
      <span class="count">{preparando.length}</span>
    </header>
    {#each preparando as order (order.id)}
      {@const secs = elapsed(order)}
      <article class="ticket" class:late={secs > TARGET_SECONDS}>
        <div class="ticket-head">
          <span class="code fuu-mono">#{order.public_code}</span>
          <span class="timer fuu-mono" class:late={secs > TARGET_SECONDS}>{clock(secs)}</span>
          <button type="button" class="chat" onclick={() => onOpenChat(order)} aria-label="Conversa do pedido">
            <i class="bi bi-chat-dots"></i>
          </button>
        </div>
        <ul class="lines">
          {#each lines(order.items) as line}
            <li>
              {line.text}
              {#if line.detail}<span class="detail">{line.detail}</span>{/if}
            </li>
          {/each}
        </ul>
        <div class="bar">
          <div
            class="fill"
            class:late={secs > TARGET_SECONDS}
            style={`width:${Math.min(100, Math.round((secs / TARGET_SECONDS) * 100))}%`}
          ></div>
        </div>
        <div class="ticket-actions">
          <button type="button" class="reject-btn" onclick={() => onReject(order)}>Recusar</button>
          <button
            type="button"
            class="btn-fuu-primary ready-btn"
            disabled={busyId === order.id}
            onclick={() => advance(order, 'ready')}
          >
            Pronto
          </button>
        </div>
      </article>
    {:else}
      <p class="empty">Cozinha vazia.</p>
    {/each}
  </section>

  <section class="column">
    <header class="col-head waiting">
      <span>PRONTO · AGUARDA ENTREGADOR</span>
    </header>
    {#each prontos as order (order.id)}
      <article class="ticket" class:muted={!order.courier_name}>
        <div class="ticket-head">
          <span class="code fuu-mono">#{order.public_code}</span>
          <span class="method plain">{methodLabel(order)}</span>
        </div>
        {#if order.pickup_by_customer}
          <!-- 15.1 — ninguém aceitou a corrida e o cliente veio buscar. A
               cozinha PRECISA ver isso: a sacola fica no balcão esperando
               uma pessoa, não uma moto. -->
          <p class="courier pickup-text"><strong>RETIRADA</strong> — o cliente vem buscar</p>
          <button
            type="button"
            class="handoff"
            disabled={busyId === order.id}
            onclick={() => handoffToCustomer(order)}
          >
            Entregue ao cliente
          </button>
        {:else if order.courier_name}
          <p class="courier"><strong>{order.courier_name}</strong> a caminho</p>
          <button
            type="button"
            class="handoff"
            disabled={busyId === order.id}
            onclick={() => advance(order, 'delivering')}
          >
            Entregue ao motoboy
          </button>
        {:else}
          <p class="courier waiting-text">Aguardando entregador ser designado</p>
        {/if}
      </article>
    {:else}
      <p class="empty">Nada esperando na bancada.</p>
    {/each}

    {#if proofs.length > 0}
      <div class="pix-pin">
        <p class="pix-title">FILA DE VALIDAÇÃO DE PIX · {proofs.length}</p>
        <p class="pix-list">
          {#each proofs.slice(0, 3) as proof (proof.id)}
            {money(proof.total)} · #{proof.public_code}<br />
          {/each}
        </p>
        <button type="button" class="btn-fuu-primary" onclick={() => onOpenProof(proofs[0])}>
          Validar agora
        </button>
      </div>
    {/if}
  </section>
</div>

<style>
  .board {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    align-items: start;
    padding: 16px 22px 22px;
  }
  @media (max-width: 900px) {
    .board {
      grid-template-columns: 1fr;
    }
  }
  .column {
    display: flex;
    flex-direction: column;
    gap: 11px;
    min-height: 60vh;
  }
  .col-head {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.06em;
  }
  .col-head.new {
    color: var(--fuu-wait-text);
  }
  .col-head.cooking {
    color: var(--fuu-leaf-dark);
  }
  .col-head.waiting {
    color: var(--fuu-ink-4);
  }
  .count {
    color: var(--fuu-white);
    font-size: 12px;
    font-weight: 800;
    min-width: 24px;
    height: 24px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .col-head.new .count {
    background: var(--fuu-wait-text);
  }
  .col-head.cooking .count {
    background: var(--fuu-leaf-dark);
  }
  .ticket {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 13px;
    padding: 15px;
  }
  .ticket.highlight {
    background: var(--fuu-wait-tint);
    border: 2px solid var(--fuu-wait-text);
  }
  .ticket.late {
    background: var(--fuu-paper);
    border-color: var(--fuu-wait-text);
  }
  .ticket.muted {
    opacity: 0.7;
  }
  .ticket-head {
    display: flex;
    align-items: baseline;
    gap: 9px;
  }
  .code {
    font-size: 23px;
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .when {
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
  .method {
    margin-left: auto;
    font-size: 12px;
    font-weight: 800;
    background: var(--fuu-wait-text);
    color: var(--fuu-white);
    padding: 4px 9px;
    border-radius: 7px;
  }
  .method.plain {
    background: var(--fuu-line-4);
    color: var(--fuu-ink-2);
  }
  .chat {
    background: none;
    border: none;
    color: var(--fuu-ink-4);
    font-size: 17px;
    padding: 0 0 0 8px;
  }
  .timer {
    margin-left: auto;
    font-size: 16px;
    font-weight: 800;
    color: var(--fuu-leaf-dark);
  }
  .timer.late {
    color: var(--fuu-wait-text);
  }
  .lines {
    list-style: none;
    padding: 0;
    margin: 11px 0 0;
    font-size: 15px;
    font-weight: 600;
    line-height: 1.6;
    color: var(--fuu-ink-1);
  }
  .detail {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--fuu-ink-3);
  }
  .change {
    font-size: 13px;
    color: var(--fuu-ink-3);
    margin: 6px 0 0;
  }
  .bar {
    height: 6px;
    background: var(--fuu-line-4);
    border-radius: 4px;
    margin-top: 11px;
    overflow: hidden;
  }
  .fill {
    height: 6px;
    background: var(--fuu-leaf-dark);
  }
  .fill.late {
    background: var(--fuu-wait-text);
  }
  .ticket-actions {
    display: flex;
    gap: 9px;
    margin-top: 13px;
  }
  .accept,
  .ready-btn {
    flex: 1.6;
    min-height: var(--fuu-tap-operator);
    font-size: 15px;
  }
  /* Recusar é secundário de propósito: a tela 13.2 mostra que recusar tem
     custo, então o botão não compete com o caminho bom. */
  .reject-btn {
    flex: 1;
    min-height: var(--fuu-tap-operator);
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-3);
    border-radius: 10px;
    font-family: var(--fuu-font-body);
    font-weight: 700;
    font-size: 14px;
    color: var(--fuu-ink-3);
  }
  .accept {
    background: var(--fuu-leaf-dark);
  }
  .handoff {
    width: 100%;
    margin-top: 12px;
    min-height: var(--fuu-tap-operator);
    background: var(--fuu-line-4);
    color: var(--fuu-ink-1);
    border: none;
    border-radius: 10px;
    font-family: var(--fuu-font-body);
    font-weight: 700;
    font-size: 14px;
  }
  .courier {
    font-size: 13.5px;
    color: var(--fuu-ink-2);
    margin: 12px 0 0;
  }
  .waiting-text {
    color: var(--fuu-ink-4);
  }
  .pickup-text {
    color: var(--fuu-leaf-dark);
  }
  .pickup-text strong {
    font-family: var(--fuu-font-mono);
    letter-spacing: 0.06em;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  .pix-pin {
    margin-top: auto;
    background: var(--fuu-red-tint);
    border: 1px solid var(--fuu-red-tint-2);
    border-radius: 13px;
    padding: 15px;
  }
  .pix-title {
    font-size: 12px;
    font-weight: 800;
    color: var(--fuu-red);
    letter-spacing: 0.06em;
    margin: 0;
  }
  .pix-list {
    font-size: 13.5px;
    color: var(--fuu-ink-2);
    line-height: 1.55;
    margin: 6px 0 0;
  }
  .pix-pin button {
    width: 100%;
    margin-top: 11px;
    min-height: var(--fuu-tap-operator);
  }
</style>
