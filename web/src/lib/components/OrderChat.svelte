<script>
  import { api } from '../services/api.js';
  import { toastr } from '../utils/toastr.js';
  import { parsePgTimestamp } from '../utils/datetime.js';

  // Tela 14.2 — "Chat do pedido (três pontas)".
  //
  // O mesmo componente serve o cliente e a loja: quem está falando vem do
  // servidor (`me`), porque é o vínculo com o pedido que decide isso, não a
  // tela. Por isso ele recebe um `token` em vez de assumir a sessão do
  // cliente -- o painel da loja passa o token dela.
  //
  // "Eventos do sistema entram na mesma linha do tempo": mensagens e
  // transições de status são intercaladas por horário antes de desenhar.
  let { orderId, token, onClose } = $props();

  let data = $state(null);
  let text = $state('');
  let busy = $state(false);

  async function load() {
    try {
      data = await api.get('/orders/messages.php', { token, query: { id: orderId } });
    } catch {
      // ciclo perdido não é evento: a conversa segue com o que já tem
    }
  }

  $effect(() => {
    load();
    const t = setInterval(load, 5000);
    return () => clearInterval(t);
  });

  const STATUS_TEXT = {
    pending_payment: 'Pedido enviado',
    pending_verification: 'Comprovante em análise',
    paid: 'Pagamento confirmado',
    preparing: 'A cozinha começou',
    ready: 'Pedido pronto',
    delivering: 'Saiu para entrega',
    delivered: 'Entregue',
    cancelled: 'Pedido cancelado',
    rejected: 'Pedido recusado',
  };

  const ROLE_LABEL = {
    customer: 'CLIENTE',
    store: 'LOJA',
    courier: 'ENTREGADOR',
    support: 'SUPORTE',
    system: 'SISTEMA',
  };

  // Uma lista só, ordenada por horário -- é isso que faz "Saiu para entrega"
  // aparecer entre duas falas, como aconteceu de verdade.
  let timeline = $derived.by(() => {
    if (!data) return [];
    const items = [
      ...data.messages.map((m) => ({ kind: 'msg', at: m.created_at, ...m })),
      ...data.events
        .filter((e) => STATUS_TEXT[e.to_status])
        .map((e) => ({ kind: 'event', at: e.created_at, text: STATUS_TEXT[e.to_status] })),
    ];
    return items.sort(
      (a, b) => (parsePgTimestamp(a.at)?.getTime() ?? 0) - (parsePgTimestamp(b.at)?.getTime() ?? 0)
    );
  });

  function clock(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : '';
  }

  async function send(body) {
    const value = (body ?? text).trim();
    if (value === '' || busy) return;
    busy = true;
    try {
      await api.post('/orders/messages.php', { token, body: { order_id: orderId, body: value } });
      text = '';
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra enviar.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="chat-backdrop" onclick={onClose} role="presentation"></div>
<div class="chat-panel" role="dialog" aria-label="Conversa do pedido">
  <header>
    <button type="button" class="back" onclick={onClose} aria-label="Fechar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <div>
      <p class="title">Conversa do pedido</p>
      <p class="who">
        <i class="bi bi-circle-fill"></i>
        {data?.me === 'store' ? 'cliente e entregador na conversa' : 'loja e entregador na conversa'}
      </p>
    </div>
  </header>

  <div class="body">
    {#each timeline as item (item.kind + item.id + item.at)}
      {#if item.kind === 'event'}
        <div class="event"><i class="bi bi-box-seam"></i> {item.text} às {clock(item.at)}</div>
      {:else if item.sender_role === data?.me}
        <div class="mine">
          <div class="bubble">{item.body}</div>
          <p class="stamp">{clock(item.at)}{item.read_at ? ' · lida' : ''}</p>
        </div>
      {:else}
        <div class="theirs">
          <p class="from">{ROLE_LABEL[item.sender_role] ?? item.sender_role}</p>
          <div class="bubble">{item.body}</div>
          <p class="stamp">{clock(item.at)}</p>
        </div>
      {/if}
    {:else}
      <p class="empty">Nenhuma mensagem ainda. Fale com a loja se precisar de algo.</p>
    {/each}
  </div>

  {#if data?.closed}
    <p class="closed">
      Esta conversa fechou 2 h depois da entrega. O histórico continua aqui — se ainda tem problema,
      abra um chamado no suporte.
    </p>
  {:else}
    {#if (data?.quick_replies ?? []).length > 0}
      <div class="quick">
        {#each data.quick_replies as reply}
          <button type="button" disabled={busy} onclick={() => send(reply)}>{reply}</button>
        {/each}
      </div>
    {/if}
    <div class="composer">
      <input
        type="text"
        maxlength="1000"
        placeholder="Escreva para a loja e o entregador…"
        bind:value={text}
        onkeydown={(e) => e.key === 'Enter' && send()}
      />
      <button type="button" class="send" disabled={busy || text.trim() === ''} onclick={() => send()} aria-label="Enviar">
        <i class="bi bi-send-fill"></i>
      </button>
    </div>
  {/if}
</div>

<style>
  .chat-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(23, 20, 15, 0.35);
    z-index: 80;
  }
  .chat-panel {
    position: fixed;
    inset: 0;
    max-width: 430px;
    margin: 0 auto;
    background: var(--fuu-paper);
    z-index: 81;
    display: flex;
    flex-direction: column;
  }
  header {
    background: var(--fuu-white);
    border-bottom: 1px solid var(--fuu-line-3);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 11px;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-1);
    padding: 0;
  }
  .title {
    font-size: 14px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .who {
    font-size: 11px;
    color: var(--fuu-leaf-dark);
    font-weight: 700;
    margin: 2px 0 0;
  }
  .who i {
    font-size: 7px;
    vertical-align: middle;
  }
  .body {
    flex: 1;
    overflow-y: auto;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .empty {
    text-align: center;
    color: var(--fuu-ink-5);
    font-size: 13px;
    margin-top: 40px;
  }
  .event {
    align-self: center;
    background: var(--fuu-wait-tint);
    border: 1px solid var(--fuu-wait-text);
    border-radius: 9px;
    padding: 8px 12px;
    font-size: 11.5px;
    color: var(--fuu-wait-text);
    font-weight: 600;
  }
  .mine {
    align-self: flex-end;
    max-width: 78%;
  }
  .theirs {
    align-self: flex-start;
    max-width: 78%;
  }
  .from {
    font-size: 10px;
    font-weight: 800;
    color: var(--fuu-ink-5);
    letter-spacing: 0.06em;
    margin: 0 0 3px;
  }
  .bubble {
    font-size: 13px;
    line-height: 1.5;
    padding: 11px 13px;
  }
  .theirs .bubble {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 13px 13px 13px 4px;
    color: var(--fuu-ink-1);
  }
  .mine .bubble {
    background: var(--fuu-red);
    color: var(--fuu-white);
    border-radius: 13px 13px 4px 13px;
  }
  .stamp {
    font-size: 10px;
    color: var(--fuu-ink-5);
    margin: 3px 0 0;
  }
  .mine .stamp {
    text-align: right;
  }
  .quick {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    justify-content: flex-end;
    padding: 0 16px 8px;
  }
  .quick button {
    font-size: 12px;
    font-weight: 700;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    padding: 9px 13px;
    border-radius: 20px;
    font-family: var(--fuu-font-body);
    color: var(--fuu-ink-2);
  }
  .composer {
    background: var(--fuu-white);
    border-top: 1px solid var(--fuu-line-3);
    padding: 11px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .composer input {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 11px 14px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
  }
  .send {
    width: 42px;
    height: 42px;
    flex: none;
    border-radius: 50%;
    border: none;
    background: var(--fuu-red);
    color: var(--fuu-white);
    font-size: 17px;
  }
  .send:disabled {
    background: var(--fuu-line-3);
  }
  .closed {
    background: var(--fuu-white);
    border-top: 1px solid var(--fuu-line-3);
    padding: 14px 16px;
    font-size: 12px;
    color: var(--fuu-ink-4);
    line-height: 1.55;
    margin: 0;
  }
</style>
