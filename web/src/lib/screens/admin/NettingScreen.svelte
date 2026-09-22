<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';

  // Tela 9.7 — "Painel da plataforma: netting semanal e repasse".
  //
  // "Quem paga o motoboy somos nós, e o restaurante nos devolve taxa + frete
  // em um débito único por semana. Como o frete da espécie já foi retido na
  // origem, o valor que circula é pequeno. Livro append-only: saldo é soma
  // de lançamentos, correção é contrapartida — nunca edição."
  //
  // Cada número desta tela vem de `admin/netting.php`, que lê o livro. A
  // tela escolhe a semana e dispara as três ações: gerar o fechamento,
  // mandar o lote e dar baixa.

  let data = $state(null);
  let busy = $state(false);

  function money(v) {
    return v === null || v === undefined
      ? '—'
      : `R$ ${Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }

  function br(iso) {
    if (!iso) return '';
    const [, m, d] = iso.split('-');
    return `${d}/${m}`;
  }

  async function load(start, end) {
    try {
      data = await api.get('/admin/netting.php', {
        token: adminToken(),
        query: start ? { start, end } : undefined,
      });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar o financeiro.');
    }
  }

  $effect(() => {
    load();
  });

  function shiftWeek(days) {
    const s = new Date(`${data.period.start}T12:00:00`);
    s.setDate(s.getDate() + days);
    const e = new Date(s);
    e.setDate(e.getDate() + 6);
    const iso = (d) => d.toISOString().slice(0, 10);
    load(iso(s), iso(e));
  }

  async function act(body, ok) {
    if (busy) return;
    busy = true;
    try {
      const res = await api.post('/admin/netting.php', {
        token: adminToken(),
        body: { ...body, start: data.period.start, end: data.period.end },
      });
      toastr.success(res.notice ?? ok);
      await load(data.period.start, data.period.end);
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra concluir.');
    } finally {
      busy = false;
    }
  }

  // Lojas com valor a cobrar e sem linha em `payouts` ainda: aparecem na
  // tabela (vêm do livro), mas não dá pra dar baixa no que não foi gerado.
  let missingStores = $derived(
    (data?.stores ?? []).filter((s) => !s.payout_id && Number(s.to_charge) > 0).length
  );

  const STATUS_CLASS = (s) =>
    s === 'PAGO' ? 'ok' : s === 'DÉBITO OK' ? 'ok' : s?.startsWith('ATRASO') ? 'bad' : 'wait';
</script>

<div class="netting">
  <section class="main">
    {#if data === null}
      <p class="empty">Carregando…</p>
    {:else}
      <div class="week">
        <button type="button" onclick={() => shiftWeek(-7)} aria-label="Semana anterior"><i class="bi bi-chevron-left"></i></button>
        <span>Semana {br(data.period.start)}–{br(data.period.end)}</span>
        <button type="button" onclick={() => shiftWeek(7)} aria-label="Próxima semana"><i class="bi bi-chevron-right"></i></button>
      </div>

      <div class="kpis">
        <div><p class="l">GMV DA SEMANA</p><p class="n">{money(data.summary.gmv)}</p></div>
        <div><p class="l">NOSSA TAXA</p><p class="n red">{money(data.summary.commission)}</p></div>
        <div><p class="l">FRETE DOS ENTREGADORES</p><p class="n">{money(data.summary.courier_freight)}</p></div>
        <div><p class="l">ESPÉCIE NÃO BAIXADA</p><p class="n warn">{money(data.summary.cash_unsettled)}</p></div>
      </div>

      <p class="k">ACERTO POR RESTAURANTE — UM VALOR ÚNICO (NETTING)</p>
      <div class="table">
        <div class="row head">
          <span>RESTAURANTE</span><span>GMV</span><span>TAXA (JÁ SPLIT)</span><span>A COBRAR</span><span>VENCE</span><span>STATUS</span><span></span>
        </div>
        {#each data.stores as s (s.id)}
          <div class="row">
            <span class="name">{s.name}</span>
            <span>{money(s.gmv)}</span>
            <span>{money(s.commission_split)}</span>
            <span class="strong">{money(s.to_charge)}</span>
            <span>{br(s.due_on)}</span>
            <span><span class="tag {STATUS_CLASS(s.status)}">{s.status}</span></span>
            <span>
              {#if s.payout_id && s.payout_state !== 'paid'}
                <button type="button" class="mini" disabled={busy} onclick={() => act({ action: 'settle', payout_id: s.payout_id }, 'Baixado.')}>
                  Dar baixa
                </button>
              {/if}
            </span>
          </div>
        {:else}
          <p class="empty pad">Nenhuma loja com movimento nesta semana.</p>
        {/each}
      </div>

      <div class="rules">
        <p class="title">Como o dinheiro se organiza (sem transferência de ida e volta)</p>
        {#each data.rules as rule, i (i)}
          <p><strong>{i + 1}</strong> · {rule}</p>
        {/each}
      </div>
    {/if}
  </section>

  <aside class="side">
    {#if data}
      <p class="k">REPASSE AOS ENTREGADORES</p>
      <div class="batch">
        <p class="sub">{data.couriers.count} entregador(es) nesta semana</p>
        <p class="big">{money(data.couriers.net)}</p>
        <p class="sub">
          Bruto {money(data.couriers.gross)} (pago por nós) − espécie não baixada
          {money(data.couriers.withheld)} (retida até a baixa) = {money(data.couriers.net)} a transferir
        </p>
        {#if data.couriers.count > 0}
          <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={() => act({ action: 'send_batch' }, 'Lote enviado.')}>
            Gerar lote de Pix
          </button>
          <p class="hint">O lote marca os repasses como enviados. A transferência é feita no banco — não há Pix em lote integrado neste projeto.</p>
        {/if}
        <!-- Gerar é idempotente (ON CONFLICT DO NOTHING no banco): fica
             sempre à mão, porque loja que teve movimento depois do primeiro
             fechamento precisa entrar sem ninguém apagar nada. -->
        {#if data.couriers.count === 0 || missingStores > 0}
          <button type="button" class="ghost" disabled={busy} onclick={() => act({ action: 'generate' }, 'Gerado.')}>
            {data.couriers.count === 0 ? 'Gerar fechamento da semana' : `Incluir ${missingStores} loja(s) sem acerto`}
          </button>
        {/if}
      </div>

      <p class="k gap">BLOQUEIOS ATIVOS</p>
      <div class="block">
        <strong>{data.blocks.couriers.length} entregador(es)</strong>
        <p>Espécie acima do prazo ou do teto — sem corridas em dinheiro até a baixa.</p>
        {#each data.blocks.couriers as c (c.id)}<p class="who">{c.full_name} · {money(c.cash)}</p>{/each}
      </div>
      <div class="block">
        <strong>{data.blocks.stores.length} loja(s) em atraso</strong>
        <p>Pedidos em espécie e maquininha suspensos até regularizar.</p>
        {#each data.blocks.stores as s (s.id)}<p class="who">{s.name}</p>{/each}
      </div>

      <p class="k gap">TRILHA DE AUDITORIA</p>
      <p class="hint">
        Todo lançamento tem origem (pedido, baixa, repasse, ocorrência), autor e horário. Nada é
        editável: correção é lançamento de contrapartida.
      </p>
      <p class="chips fuu-mono">ledger_entries (append-only)<br />SUM por conta = saldo<br />payouts em lote</p>
    {/if}
  </aside>
</div>

<style>
  .netting {
    display: flex;
    min-height: 70vh;
  }
  .main {
    flex: 1;
    padding: 20px;
    min-width: 0;
  }
  .side {
    width: 330px;
    flex: 0 0 330px;
    background: var(--fuu-white);
    border-left: 1px solid var(--fuu-line-3);
    padding: 20px;
  }
  .empty {
    color: var(--fuu-ink-3);
    font-size: 13px;
  }
  .empty.pad {
    padding: 14px 16px;
    margin: 0;
  }
  .week {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 800;
    margin-bottom: 14px;
  }
  .week button {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 8px;
    width: 32px;
    height: 32px;
  }
  .kpis {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 18px;
  }
  .kpis div {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 14px;
  }
  .kpis .l {
    font-size: 11px;
    color: var(--fuu-ink-3);
    font-weight: 700;
    margin: 0;
  }
  .kpis .n {
    font-size: 21px;
    font-weight: 800;
    margin: 3px 0 0;
  }
  .kpis .n.red {
    color: var(--fuu-red);
  }
  .kpis .n.warn {
    color: var(--fuu-wait-text);
  }
  .k {
    font-size: 11px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.1em;
    margin: 0 0 10px;
  }
  .k.gap {
    margin-top: 22px;
  }
  .table {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    overflow: hidden;
  }
  .row {
    display: grid;
    grid-template-columns: 1.6fr 1fr 1fr 1fr 0.7fr 1fr 0.9fr;
    gap: 8px;
    padding: 12px 16px;
    font-size: 12.5px;
    border-bottom: 1px solid var(--fuu-line-4);
    align-items: center;
  }
  .row.head {
    font-size: 10.5px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.06em;
  }
  .name,
  .strong {
    font-weight: 800;
  }
  .tag {
    font-size: 10px;
    font-weight: 800;
    padding: 4px 8px;
    border-radius: var(--fuu-radius-pill);
  }
  .tag.ok {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .tag.bad {
    background: var(--fuu-red-tint);
    color: var(--fuu-alert);
  }
  .tag.wait {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .mini {
    border: 1px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: 8px;
    padding: 5px 9px;
    font-family: inherit;
    font-size: 11.5px;
    font-weight: 700;
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
    color: var(--fuu-ink-2);
    line-height: 1.75;
    margin: 0;
  }
  .batch {
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 14px;
  }
  .batch .big {
    font-size: 26px;
    font-weight: 800;
    margin: 4px 0;
  }
  .sub,
  .hint {
    font-size: 12px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    margin: 0 0 10px;
  }
  .ghost {
    width: 100%;
    margin-top: 9px;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 11px;
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
  }
  .block {
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 11px 12px;
    margin-bottom: 8px;
    font-size: 12.5px;
  }
  .block p {
    margin: 3px 0 0;
    color: var(--fuu-ink-2);
  }
  .block .who {
    font-weight: 700;
    color: var(--fuu-alert);
  }
  .chips {
    font-size: 10px;
    color: var(--fuu-ink-3);
    margin-top: 14px;
    line-height: 1.6;
  }
  @media (max-width: 1000px) {
    .netting {
      flex-direction: column;
    }
    .side {
      width: auto;
      flex: 1;
      border-left: 0;
      border-top: 1px solid var(--fuu-line-3);
    }
    .kpis {
      grid-template-columns: 1fr 1fr;
    }
    .row {
      grid-template-columns: 1.4fr 1fr 1fr;
    }
    .row > span:nth-child(n + 4):not(:last-child) {
      display: none;
    }
  }
</style>
