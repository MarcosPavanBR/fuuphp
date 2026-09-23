<script>
  import { api, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';

  // Tela 12.3 — "Os números que mudam decisão".
  //
  // A escolha dos números é o conteúdo da tela: quanto do GMV depende de
  // alguém conferindo comprovante, custo de entrega por pedido, perda por
  // fraude como % do GMV, e o que há pra cobrar. Onde o backend não sustenta
  // o número, aparece "—" com a razão, não um zero que parece dado.
  let days = $state(30);
  let data = $state(null);

  $effect(() => {
    let alive = true;
    api
      .get('/admin/reports.php', { token: adminToken(), query: { days } })
      .then((d) => {
        if (alive) data = d;
      })
      .catch(() => {});
    return () => (alive = false);
  });

  function money(v) {
    return v === null || v === undefined ? '—' : `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function pct(v) {
    return v === null || v === undefined ? '—' : `${Number(v).toFixed(1).replace('.', ',')}%`;
  }

  // Variação contra o período anterior do mesmo tamanho (null sem base).
  function change(now, before) {
    if (now === null || now === undefined || !before) return null;
    return ((now - before) * 100) / before;
  }
  function changeLabel(v) {
    if (v === null) return 'sem período anterior pra comparar';
    const sign = v > 0 ? '+' : '';
    return `${sign}${v.toFixed(1).replace('.', ',')}% contra os ${days} dias anteriores`;
  }

  const WEEKDAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

  let hours = $derived(data?.by_hour ?? []);
  let hourMax = $derived(Math.max(1, ...hours.map((h) => h.orders)));
  let peakHour = $derived(
    hours.reduce((best, h, i) => (h.orders > (hours[best]?.orders ?? 0) ? i : best), 0)
  );
  let weekdays = $derived(data?.by_weekday ?? []);
  let weekdayMax = $derived(Math.max(1, ...weekdays.map((d) => d.orders)));
  let ticketChange = $derived(change(data?.totals?.average_ticket, data?.previous?.average_ticket));

  const METHOD = {
    mp_card: 'Cartão',
    pix_auto: 'Pix automático',
    pix_manual: 'Pix + comprovante',
    cash: 'Dinheiro',
    pos_machine: 'Maquininha',
  };
  const NEEDS_HUMAN = new Set(['pix_manual', 'cash', 'pos_machine']);

  // O download passa por fetch com o token no cabeçalho (nunca na URL) e
  // vira um link temporário -- o mesmo cuidado das imagens privadas.
  let exporting = $state(null);
  async function exportCsv(kind) {
    exporting = kind;
    try {
      const to = new Date();
      const from = new Date(Date.now() - days * 86400000);
      const iso = (d) => d.toISOString().slice(0, 10);
      const res = await fetch(`${BASE}/admin/export.php?kind=${kind}&from=${iso(from)}&to=${iso(to)}`, {
        headers: { Authorization: `Bearer ${adminToken()}` },
      });
      if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message ?? 'Falha ao exportar.');
      const url = URL.createObjectURL(await res.blob());
      const a = document.createElement('a');
      a.href = url;
      a.download = `fuu-${kind}-${iso(from)}-a-${iso(to)}.csv`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 5000);
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra exportar.');
    } finally {
      exporting = null;
    }
  }
</script>

<div class="head">
  <p class="section">PERÍODO</p>
  <div class="range">
    {#each [7, 30, 90] as option}
      <button type="button" class:on={days === option} onclick={() => (days = option)}>{option} dias</button>
    {/each}
  </div>
</div>

<div class="cards">
  <div class="fuu-card kpi">
    <p class="k">GMV</p>
    <p class="v">{money(data?.totals?.gmv)}</p>
    <p class="s">{data?.totals?.orders ?? 0} pedidos</p>
  </div>
  <div class="fuu-card kpi">
    <p class="k">TICKET MÉDIO</p>
    <p class="v">{money(data?.totals?.average_ticket)}</p>
    <p class="s">{changeLabel(ticketChange)}</p>
  </div>
  <div class="fuu-card kpi">
    <p class="k">DEPENDE DE CONFERÊNCIA</p>
    <p class="v">{pct(data?.manual_share)}</p>
    <p class="s">do GMV em Pix manual, dinheiro e maquininha</p>
  </div>
  <div class="fuu-card kpi">
    <p class="k">ENTREGA POR PEDIDO</p>
    <p class="v">{money(data?.totals?.delivery_cost_per_order)}</p>
    <p class="s">total de {money(data?.totals?.delivery_cost)} no período</p>
  </div>
  <div class="fuu-card kpi">
    <p class="k">PERDA POR FRAUDE</p>
    <p class="v bad">{money(data?.fraud?.loss)}</p>
    <p class="s">{data?.fraud?.share_of_gmv === null ? '—' : `${data?.fraud?.share_of_gmv}% do GMV`}</p>
  </div>
</div>

<!-- Quando o cliente pede: onde pôr entregador e quando vale campanha. -->
<div class="fuu-card block">
  <p class="section">PEDIDOS POR HORA DO DIA</p>
  {#if (data?.totals?.orders ?? 0) === 0}
    <p class="empty">Nenhum pedido pago no período.</p>
  {:else}
    <p class="lead">
      Pico às <strong>{peakHour}h</strong>: {hours[peakHour].orders}
      {hours[peakHour].orders === 1 ? 'pedido' : 'pedidos'} no período (horário de Brasília).
    </p>
    <div class="hours" role="list" aria-label="Pedidos por hora do dia">
      {#each hours as h, i (i)}
        <div
          class="hour"
          class:peak={i === peakHour}
          role="listitem"
          tabindex="0"
          aria-label={`${i}h: ${h.orders} pedidos, ${money(h.gmv)}`}
          title={`${i}h às ${i + 1}h · ${h.orders} pedidos · ${money(h.gmv)}`}
        >
          <span class="bar" style={`height: ${Math.max(h.orders > 0 ? 4 : 0, (h.orders * 100) / hourMax)}%`}></span>
        </div>
      {/each}
    </div>
    <div class="axis" aria-hidden="true">
      {#each [0, 6, 12, 18, 23] as t (t)}<span style={`left: ${((t + 0.5) * 100) / 24}%`}>{t}h</span>{/each}
    </div>
  {/if}
</div>

<div class="split">
  <div class="fuu-card block">
    <p class="section">PEDIDOS POR DIA DA SEMANA</p>
    {#if weekdays.length === 0 || (data?.totals?.orders ?? 0) === 0}
      <p class="empty">Nenhum pedido pago no período.</p>
    {:else}
      {#each weekdays as d, i (i)}
        <div class="dow">
          <span class="name">{WEEKDAYS[i]}</span>
          <span class="track"><span class="fill" style={`width: ${(d.orders * 100) / weekdayMax}%`}></span></span>
          <span class="fuu-mono num">{d.orders}</span>
        </div>
      {/each}
    {/if}
  </div>

  <div class="fuu-card block">
    <p class="section">CLIENTES</p>
    <div class="kv">
      <span>Compraram no período</span>
      <span class="fuu-mono">{data?.customers?.buyers ?? 0}</span>
    </div>
    <div class="kv">
      <span>Voltaram (2 pedidos ou mais)</span>
      <span class="fuu-mono">{data?.customers?.returning ?? 0} · {pct(data?.customers?.returning_share)}</span>
    </div>
    <div class="kv">
      <span>Primeira compra no FUU</span>
      <span class="fuu-mono">{data?.customers?.first_time ?? 0}</span>
    </div>
    <p class="note">
      Cliente que volta é o que sustenta o delivery: se a fatia dos que voltam cai, vale cupom de segunda compra
      (aba Campanhas) e olhar as avaliações das lojas.
    </p>
  </div>
</div>

<div class="fuu-card block">
  <p class="section">LOJAS QUE MAIS VENDEM</p>
  {#if (data?.top_stores ?? []).length === 0}
    <p class="empty">Nenhum pedido pago no período.</p>
  {:else}
    <div class="table-wrap">
      <table class="top">
        <thead><tr><th>Loja</th><th>Pedidos</th><th>Vendas</th><th>Ticket médio</th></tr></thead>
        <tbody>
          {#each data.top_stores as st (st.id)}
            <tr>
              <td>{st.name}</td>
              <td class="fuu-mono">{st.orders}</td>
              <td class="fuu-mono">{money(st.gmv)}</td>
              <td class="fuu-mono">{money(st.average_ticket)}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>

<div class="split">
  <div class="fuu-card block">
    <p class="section">MIX DE PAGAMENTO</p>
    {#if (data?.payment_mix ?? []).length === 0}
      <p class="empty">Nenhum pedido pago no período.</p>
    {:else}
      {#each data.payment_mix as row (row.payment_method)}
        <div class="kv">
          <span>
            {METHOD[row.payment_method] ?? row.payment_method}
            {#if NEEDS_HUMAN.has(row.payment_method)}<i class="bi bi-person-check" title="depende de conferência"></i>{/if}
          </span>
          <span class="fuu-mono">{money(row.amount)} · {row.orders}</span>
        </div>
      {/each}
    {/if}
  </div>

  <div class="fuu-card block">
    <p class="section">SALDOS NO LIVRO</p>
    <div class="kv">
      <span>Conta das lojas (store_receivable)</span>
      <span class="fuu-mono">{money(data?.balances?.store_receivable)}</span>
    </div>
    <div class="kv">
      <span>Conta de espécie dos entregadores</span>
      <span class="fuu-mono">{money(data?.balances?.courier_cash_out)}</span>
    </div>
    <div class="kv">
      <span>Comissão calculada no período</span>
      <span class="fuu-mono">{money(data?.totals?.commission)}</span>
    </div>
    <!-- O saldo é o livro inteiro: cada pedido entregue lança a parte da
         loja (lib/ledger/order_ledger.php), a baixa de espécie paga essa parte, e o
         que sobra é comissão + frete. Positivo, a loja nos deve; negativo,
         devemos a ela (repasse). O acerto semana a semana é a aba
         Financeiro (9.7). -->
    <p class="note">
      Positivo: as lojas nos devem (comissão + frete de pedidos que receberam direto). Negativo:
      devemos repasse a elas (pedidos pagos no app). O acerto semanal fica na aba Financeiro.
    </p>
  </div>
</div>

<div class="fuu-card block">
  <p class="section">ESTORNOS POR CAUSA</p>
  {#if (data?.refunds_by_cause ?? []).length === 0}
    <p class="empty">Nenhum estorno no período.</p>
  {:else}
    {#each data.refunds_by_cause as row (row.cause)}
      <div class="kv">
        <span>{row.cause}</span>
        <span class="fuu-mono">{money(row.amount)} · {row.n}</span>
      </div>
    {/each}
  {/if}
</div>

<!-- 12.3: "fechar com o livro contábil". Três arquivos, as três perguntas do
     contador: o livro, as vendas e os acertos. -->
<div class="fuu-card block">
  <p class="section">EXPORTAR PARA A CONTABILIDADE (CSV)</p>
  <p class="note">
    Período: últimos {days} dias. Separador <code>;</code> e vírgula decimal — abre direto no Excel
    em português.
  </p>
  <div class="exports">
    {#each [['ledger', 'Livro (lançamentos)'], ['orders', 'Pedidos'], ['payouts', 'Acertos semanais']] as [kind, label] (kind)}
      <button type="button" disabled={exporting === kind} onclick={() => exportCsv(kind)}>
        <i class="bi bi-download"></i> {exporting === kind ? 'Gerando…' : label}
      </button>
    {/each}
  </div>
</div>

<style>
  .exports {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 10px;
  }
  .exports button {
    border: 1.5px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: 10px;
    padding: 9px 14px;
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
  }
  .head {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 14px;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 10px;
  }
  .head .section {
    margin: 0;
  }
  .range {
    display: flex;
    gap: 6px;
  }
  .range button {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: var(--fuu-radius-pill);
    padding: 6px 14px;
    font-family: var(--fuu-font-body);
    font-size: 12.5px;
    font-weight: 700;
    color: var(--fuu-ink-3);
  }
  .range button.on {
    background: var(--fuu-ink-1);
    border-color: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
  }
  .kpi {
    padding: 16px;
  }
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .v {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 4px 0 0;
    color: var(--fuu-ink-1);
  }
  .v.bad {
    color: var(--fuu-red);
  }
  .s {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 4px 0 0;
    line-height: 1.45;
  }
  .split {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 12px;
    margin-top: 12px;
  }
  .block {
    padding: 16px;
    margin-top: 12px;
  }
  .split .block {
    margin-top: 0;
  }
  .kv {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 13.5px;
    padding: 9px 0;
    border-bottom: 1px solid var(--fuu-line-5);
    color: var(--fuu-ink-2);
  }
  .kv i {
    color: var(--fuu-wait-text);
    margin-left: 5px;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  .lead {
    font-size: 13.5px;
    color: var(--fuu-ink-2);
    margin: 0 0 12px;
  }
  /* Barras de uma série só, em tinta: o vermelho é da ação, não de dado. */
  .hours {
    display: grid;
    grid-template-columns: repeat(24, 1fr);
    gap: 2px;
    height: 120px;
    align-items: end;
    border-bottom: 1px solid var(--fuu-line-3);
  }
  .hour {
    height: 100%;
    display: flex;
    align-items: flex-end;
    outline-offset: 2px;
  }
  .hour:focus-visible {
    outline: 2px solid var(--fuu-ink-1);
  }
  .bar {
    width: 100%;
    background: var(--fuu-ink-5);
    border-radius: 4px 4px 0 0;
  }
  .hour.peak .bar {
    background: var(--fuu-ink-1);
  }
  .hour:hover .bar {
    background: var(--fuu-ink-2);
  }
  .axis {
    position: relative;
    height: 18px;
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    color: var(--fuu-ink-4);
  }
  .axis span {
    position: absolute;
    top: 4px;
    transform: translateX(-50%);
  }
  .dow {
    display: grid;
    grid-template-columns: 70px 1fr 40px;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    padding: 5px 0;
    color: var(--fuu-ink-2);
  }
  .track {
    height: 10px;
    background: var(--fuu-line-5);
    border-radius: 4px;
    overflow: hidden;
  }
  .fill {
    display: block;
    height: 100%;
    background: var(--fuu-ink-3);
    border-radius: 0 4px 4px 0;
  }
  .num {
    text-align: right;
  }
  .table-wrap {
    overflow-x: auto;
  }
  table.top {
    width: 100%;
    border-collapse: collapse;
    font-size: 13.5px;
  }
  table.top th {
    text-align: left;
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.06em;
    color: var(--fuu-ink-4);
    font-weight: 700;
    padding: 6px 8px 8px 0;
    border-bottom: 1px solid var(--fuu-line-3);
  }
  table.top td {
    padding: 9px 8px 9px 0;
    border-bottom: 1px solid var(--fuu-line-5);
    color: var(--fuu-ink-2);
    white-space: nowrap;
  }
  .note {
    font-size: 10px;
    color: var(--fuu-ink-5);
    margin: 12px 0 0;
    line-height: 1.6;
  }
</style>
