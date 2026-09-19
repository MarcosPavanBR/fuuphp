<script>
  import { api } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { adminToken } from '../../adminSession.svelte.js';

  // Tela 13.4 — "Admin: reembolso por método e quem paga".
  //
  // "A tabela que faltava: cartão estorna na API, Pix precisa de devolução
  // para a chave do pagador, dinheiro não devolve nada e maquininha cancela
  // na adquirente. A coluna 'quem paga' evita a discussão que trava
  // reembolso por dias — e crédito em carteira é oferta, nunca imposição."
  //
  // Nada nesta tela calcula dinheiro: a coluna COMO DEVOLVER, o prazo, quem
  // paga e o valor recalculado pelo ajuste da taxa vêm todos do servidor.
  // Aqui só se escolhe.
  let data = $state(null);
  let openId = $state(null);
  let adjustment = $state('keep');
  let note = $state('');
  let bonus = $state(10);
  let busy = $state(false);

  function money(v) {
    return v === null || v === undefined ? '—' : `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  const METHOD_LABEL = {
    mp_card: 'Cartão · MP',
    pix_auto: 'Pix automático',
    pix_manual: 'Pix manual',
    cash: 'Dinheiro',
    pos_machine: 'Maquininha',
  };

  async function pull() {
    try {
      data = await api.get('/admin/refunds.php', { token: adminToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar a fila.');
    }
  }

  $effect(() => {
    pull();
  });

  let selected = $derived(data?.queue?.find((r) => r.id === openId) ?? null);

  // O ajuste muda o estorno na hora, mas só na TELA: o valor que vale é o
  // que o servidor recalcula quando a decisão é enviada. Aqui é prévia.
  let factor = $derived(data?.fee_adjustments?.[adjustment]?.factor ?? 1);
  let paid = $derived(selected ? Number(selected.amount) + Number(selected.fee) : 0);
  let previewFee = $derived(selected ? Math.round(Number(selected.fee) * factor * 100) / 100 : 0);
  let previewAmount = $derived(Math.round((paid - previewFee) * 100) / 100);

  function open(row) {
    openId = row.id;
    adjustment = 'keep';
    note = '';
    bonus = data?.wallet_bonus_default ?? 10;
  }

  async function decide(action) {
    if (busy || selected === null) return;
    busy = true;
    try {
      const res = await api.post('/admin/refunds.php', {
        token: adminToken(),
        body: {
          refund_id: selected.id,
          action,
          fee_adjustment: adjustment,
          ...(note.trim() === '' ? {} : { note: note.trim() }),
          ...(action === 'wallet_offer' ? { bonus: Number(bonus) } : {}),
        },
      });
      toastr.success(res.notice ?? 'Decidido.');
      openId = null;
      await pull();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra decidir.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="refunds">
  <section class="queue">
    <p class="k">FILA DE REEMBOLSO — CADA MÉTODO DESFAZ DE UM JEITO</p>

    {#if data === null}
      <p class="empty">Carregando…</p>
    {:else if data.queue.length === 0}
      <p class="empty">Nada na fila. Todo reembolso pendente já foi decidido.</p>
    {:else}
      <div class="table">
        <div class="head">
          <span class="c-order">PEDIDO</span>
          <span class="c-method">PAGAMENTO</span>
          <span class="c-amount">VALOR</span>
          <span class="c-how">COMO DEVOLVER</span>
          <span class="c-eta">PRAZO</span>
          <span class="c-payer">QUEM PAGA</span>
        </div>
        {#each data.queue as row (row.id)}
          <button type="button" class="line" class:on={row.id === openId} onclick={() => open(row)}>
            <span class="c-order fuu-mono">#{row.public_code}</span>
            <span class="c-method">{METHOD_LABEL[row.payment_method] ?? row.payment_method}</span>
            <span class="c-amount">{money(row.amount)}</span>
            <span class="c-how">{row.how}</span>
            <span class="c-eta">{row.eta}</span>
            <span class="c-payer">
              <span class="tag" class:us={row.payer === 'platform'} class:split={row.payer === 'shared'}>
                {row.payer_label}
              </span>
            </span>
          </button>
        {/each}
      </div>
    {/if}

    <div class="rules">
      <p class="title">Quem paga a conta, por causa</p>
      <p>· <strong>Loja recusou depois de aceitar</strong> ou errou o pedido → reembolso sai do repasse dela; a plataforma compensa o entregador.</p>
      <p>· <strong>Cliente cancelou antes do preparo</strong> → devolução integral, ninguém perde.</p>
      <p>· <strong>Cliente cancelou depois do preparo</strong> → taxa fica com a loja, resto volta.</p>
      <p>· <strong>Falha nossa</strong> (dispatch sem entregador, bug, fora do ar) → FUUDelivery paga tudo, inclusive a comida produzida.</p>
      <p>· <strong>Fraude confirmada do cliente</strong> → sem reembolso, conta bloqueada, loja ressarcida pela plataforma.</p>
    </div>
  </section>

  <aside class="decide">
    {#if selected === null}
      <p class="k">DECIDIR</p>
      <p class="empty">Escolha uma linha da fila.</p>
    {:else}
      <p class="k">DECIDIR · #{selected.public_code}</p>

      <div class="why">
        <strong>{selected.cause_rule ? 'Causa registrada' : 'Reembolso pendente'}</strong>
        <p>{selected.cancel_reason ?? selected.reject_reason ?? selected.cause_rule}</p>
      </div>

      <dl class="numbers">
        <div><dt>Pago</dt><dd>{money(paid)}</dd></div>
        <div><dt>Taxa de cancelamento</dt><dd>{money(previewFee)}</dd></div>
        <div><dt>A estornar</dt><dd>{money(previewAmount)}</dd></div>
      </dl>

      {#if Number(selected.fee) > 0}
        <p class="k small">AJUSTE DA TAXA</p>
        <div class="adjust">
          {#each Object.entries(data.fee_adjustments) as [code, meta] (code)}
            <button type="button" class:on={adjustment === code} onclick={() => (adjustment = code)}>
              {meta.label}
            </button>
          {/each}
        </div>
      {/if}

      <label class="note">
        <span>Motivo da decisão (fica gravado)</span>
        <textarea bind:value={note} rows="2" placeholder="Ex.: a loja anunciou 25 min e estava com 41."></textarea>
      </label>

      <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={() => decide('refund')}>
        {busy ? 'Enviando…' : `Estornar ${money(previewAmount)}`}
      </button>

      {#if selected.payment_method !== 'cash'}
        <div class="offer">
          <label>
            <span>Bônus do crédito</span>
            <input type="number" min="0" step="1" bind:value={bonus} />
          </label>
          <button type="button" class="ghost" disabled={busy} onclick={() => decide('wallet_offer')}>
            Oferecer crédito + {money(bonus)}
          </button>
        </div>
        {#if selected.wallet_state === 'offered'}
          <p class="waiting">Já há uma oferta esperando resposta do cliente.</p>
        {/if}
        <p class="hint">
          Crédito na carteira é aceito por mais gente e custa menos que o estorno — mas nunca pode
          ser imposto: vira saldo só se a pessoa aceitar, e recusar devolve o estorno pra esta fila.
        </p>
      {/if}

      <p class="chips fuu-mono">
        refunds + ledger_entries<br />payments.status → refunded<br />idempotente por refund_key
      </p>
    {/if}
  </aside>
</div>

<style>
  .refunds {
    display: flex;
    gap: 0;
    align-items: stretch;
    min-height: 70vh;
  }
  .queue {
    flex: 1;
    padding: 20px;
    min-width: 0;
  }
  .k {
    font-size: 11px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.1em;
    margin: 0 0 10px;
  }
  .k.small {
    margin: 18px 0 8px;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-3);
  }
  .table {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    overflow: hidden;
  }
  .head,
  .line {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    text-align: left;
    padding: 12px 16px;
    border: 0;
    background: transparent;
    font-family: inherit;
  }
  .head {
    font-size: 10.5px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.06em;
    border-bottom: 1px solid var(--fuu-line-3);
  }
  .line {
    font-size: 12.5px;
    color: var(--fuu-ink-1);
    border-bottom: 1px solid var(--fuu-line-4);
  }
  .line.on {
    background: var(--fuu-red-tint);
  }
  .c-order {
    width: 92px;
    flex: 0 0 92px;
  }
  .c-method {
    width: 140px;
    flex: 0 0 140px;
    font-weight: 700;
  }
  .c-amount {
    width: 104px;
    flex: 0 0 104px;
    font-weight: 800;
  }
  .c-how {
    flex: 1;
    min-width: 0;
    color: var(--fuu-ink-2);
  }
  .c-eta {
    width: 104px;
    flex: 0 0 104px;
    color: var(--fuu-ink-2);
  }
  .c-payer {
    width: 104px;
    flex: 0 0 104px;
  }
  .tag {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
    font-size: 10px;
    font-weight: 800;
    padding: 4px 8px;
    border-radius: var(--fuu-radius-pill);
  }
  .tag.us {
    background: var(--fuu-red-tint);
    color: var(--fuu-alert);
  }
  .tag.split {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .rules {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 16px;
    margin-top: 14px;
  }
  .rules .title {
    font-size: 14px;
    font-weight: 800;
    margin: 0 0 8px;
  }
  .rules p {
    font-size: 12.5px;
    line-height: 1.8;
    color: var(--fuu-ink-2);
    margin: 0;
  }
  .decide {
    width: 330px;
    flex: 0 0 330px;
    background: var(--fuu-white);
    border-left: 1px solid var(--fuu-line-3);
    padding: 20px;
  }
  .why {
    border: 1px solid var(--fuu-line-3);
    border-radius: 11px;
    padding: 13px;
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    line-height: 1.7;
  }
  .why strong {
    color: var(--fuu-ink-1);
  }
  .why p {
    margin: 4px 0 0;
  }
  .numbers {
    margin: 13px 0 0;
    font-size: 12.5px;
    color: var(--fuu-ink-2);
  }
  .numbers div {
    display: flex;
    justify-content: space-between;
    line-height: 1.9;
  }
  .numbers dt,
  .numbers dd {
    margin: 0;
  }
  .numbers dd {
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .adjust {
    display: flex;
    gap: 7px;
  }
  .adjust button {
    flex: 1;
    text-align: center;
    font-size: 12.5px;
    font-weight: 700;
    background: var(--fuu-line-4);
    border: 0;
    padding: 11px 0;
    border-radius: 8px;
    font-family: inherit;
    color: var(--fuu-ink-2);
  }
  .adjust button.on {
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-ink-1);
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .note {
    display: block;
    margin: 14px 0 12px;
  }
  .note span {
    display: block;
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin-bottom: 5px;
  }
  .note textarea {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 9px;
    font-family: inherit;
    font-size: 13px;
    resize: vertical;
  }
  .offer {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    margin-top: 9px;
  }
  .offer label {
    flex: 0 0 96px;
  }
  .offer span {
    display: block;
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin-bottom: 5px;
  }
  .offer input {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 12px 9px;
    font-family: inherit;
    font-size: 13px;
  }
  .ghost {
    flex: 1;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 13px;
    font-weight: 700;
    font-size: 13px;
    font-family: inherit;
    color: var(--fuu-ink-1);
  }
  .waiting {
    font-size: 11.5px;
    color: var(--fuu-wait-text);
    margin: 8px 0 0;
  }
  .hint {
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 13px;
    margin-top: 14px;
    font-size: 12px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
  }
  .chips {
    font-size: 10px;
    color: var(--fuu-ink-3);
    margin-top: 14px;
    line-height: 1.6;
  }
  @media (max-width: 900px) {
    .refunds {
      flex-direction: column;
    }
    .decide {
      width: auto;
      flex: 1;
      border-left: 0;
      border-top: 1px solid var(--fuu-line-3);
    }
    .head,
    .c-eta,
    .c-how {
      display: none;
    }
    .line {
      flex-wrap: wrap;
    }
  }
</style>
