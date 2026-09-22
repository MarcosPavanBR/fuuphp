<script>
  import { api, BASE } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { adminToken } from '../../adminSession.svelte.js';

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
  .note {
    font-size: 10px;
    color: var(--fuu-ink-5);
    margin: 12px 0 0;
    line-height: 1.6;
  }
</style>
