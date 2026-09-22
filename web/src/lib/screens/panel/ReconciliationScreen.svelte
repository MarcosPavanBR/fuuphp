<script>
  import { api } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { staffToken } from '../../staffSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Tela 9.6 — "Painel da loja: conciliação da maquininha física".
  //
  // "Não há entrega de dinheiro, há conferência. NSU informado no app ×
  // extrato da adquirente; divergência trava o fechamento e abre
  // ocorrência."
  //
  // A tela não decide nada sobre dinheiro: casar NSU, calcular diferença e
  // abrir ocorrência é tudo do servidor (`restaurants/reconciliation.php`).
  // Aqui se escolhe o dia, se importa o CSV e se lê o resultado.

  function yesterday() {
    const d = new Date(Date.now() - 86400000);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  }

  let day = $state(yesterday());
  let data = $state(null);
  let acquirer = $state('');
  let importing = $state(false);
  let lastImport = $state(null);

  function money(v) {
    return v === null || v === undefined ? '—' : `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function when(raw) {
    const d = parsePgTimestamp(raw);
    return d
      ? `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
      : '';
  }

  async function load() {
    try {
      data = await api.get('/restaurants/reconciliation.php', { token: staffToken(), query: { day } });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar a conciliação.');
    }
  }

  $effect(() => {
    // Relê quando o dia muda: `day` é lido aqui dentro de propósito.
    day;
    load();
  });

  async function upload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (acquirer.trim() === '') {
      toastr.warning('Diga de qual adquirente é o extrato antes de enviar.');
      return;
    }
    importing = true;
    try {
      const form = new FormData();
      form.append('acquirer', acquirer.trim());
      form.append('day', day);
      form.append('statement', file);
      const res = await api.post('/restaurants/reconciliation.php', { token: staffToken(), form });
      data = res;
      lastImport = res;
      toastr.success(
        `${res.matched} de ${res.statement.rows_total} linhas casaram` +
          (res.divergent.length ? ` · ${res.divergent.length} divergência(s) viraram ocorrência` : '')
      );
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra importar o extrato.');
    } finally {
      importing = false;
    }
  }

  const SITUATION_CLASS = { CONCILIADO: 'ok', 'DIVERGÊNCIA': 'bad', PENDENTE: 'wait' };
</script>

<div class="recon">
  <section class="main">
    <div class="head">
      <p class="k">MAQUININHA FÍSICA — CONFERÊNCIA</p>
      <input type="date" bind:value={day} aria-label="Dia da conferência" />
    </div>
    <p class="lead">
      Quando o cliente paga na porta, o dinheiro entra direto na adquirente do restaurante — o
      entregador não fica devendo nada. A baixa é por conciliação: o entregador informa NSU e valor
      no app, e o sistema cruza com o extrato importado da adquirente.
    </p>

    {#if data === null}
      <p class="empty">Carregando…</p>
    {:else if data.rows.length === 0}
      <p class="empty">Nenhuma venda na maquininha neste dia.</p>
    {:else}
      <div class="table">
        <div class="row head-row">
          <span>PEDIDO</span><span>NSU INFORMADO</span><span>VALOR APP</span><span>EXTRATO</span>
          <span>BANDEIRA</span><span>SITUAÇÃO</span>
        </div>
        {#each data.rows as r (r.id)}
          <div class="row" class:alert={r.situation !== 'CONCILIADO'}>
            <span class="fuu-mono">#{r.public_code ?? '—'}</span>
            <span class="fuu-mono">{r.nsu ?? 'não informado'}</span>
            <span>{money(r.amount_app)}</span>
            <span>{r.amount_statement === null ? 'sem par' : money(r.amount_statement)}</span>
            <span>{r.brand ?? '—'}{r.kind ? ` ${r.kind === 'debit' ? 'déb.' : 'créd.'}` : ''}</span>
            <span>
              <span class="tag {SITUATION_CLASS[r.situation]}">
                {r.situation}{r.difference && r.difference !== 0 ? ` ${r.difference < 0 ? '−' : '+'} ${money(Math.abs(r.difference))}` : ''}
              </span>
            </span>
          </div>
        {/each}
      </div>
    {/if}

    {#if data}
      <div class="totals">
        <div><p class="n">{data.rows.length}</p><p class="l">TRANSAÇÕES DO DIA</p></div>
        <div><p class="n ok">{data.reconciled}</p><p class="l">CONCILIADAS</p></div>
        <div><p class="n bad">{data.to_resolve}</p><p class="l">A RESOLVER</p></div>
        <div><p class="n">{money(data.total)}</p><p class="l">TOTAL MAQUININHA</p></div>
      </div>
      <p class="closing" class:closed={data.day_closed}>
        {data.day_closed
          ? 'Dia fechado: tudo conferido.'
          : 'Dia aberto: divergência de valor ou NSU faltando trava o fechamento.'}
      </p>
    {/if}

    <div class="rules">
      <p class="title">Regras da maquininha</p>
      <p>· <strong>Maquininha da loja levada pelo entregador:</strong> dinheiro é da loja desde a transação. Ele só informa NSU e valor — não há dívida.</p>
      <p>· <strong>Maquininha do próprio entregador:</strong> o valor cai na conta dele, então vira dívida com a loja e segue o mesmo fluxo da espécie (código ou Pix), com prazo de D+1.</p>
      <p>· <strong>Divergência de valor ou NSU faltando</strong> trava o fechamento do dia e vira ocorrência para a plataforma.</p>
    </div>
  </section>

  <aside class="side">
    <p class="k">IMPORTAR EXTRATO</p>
    <label class="field">
      <span>Adquirente</span>
      <input placeholder="stone, cielo, getnet…" bind:value={acquirer} />
    </label>
    <label class="drop" class:busy={importing}>
      <input type="file" accept=".csv,text/csv" onchange={upload} hidden />
      <i class="bi bi-file-earmark-arrow-up"></i>
      <span>{importing ? 'Importando…' : 'Escolha o CSV da adquirente'}</span>
    </label>
    <p class="hint">
      Colunas: <code>nsu, valor, data_hora</code> (e opcionalmente <code>bandeira, tipo</code>).
      A conexão direta com a API da adquirente não existe neste projeto — o CSV é o caminho.
    </p>
    {#if data?.last_statement}
      <p class="hint">
        Último extrato: {when(data.last_statement.imported_at)} · {data.last_statement.rows_total}
        transações · {data.last_statement.rows_matched} casaram.
      </p>
    {/if}
    {#if lastImport?.orphan?.length}
      <p class="warn">
        {lastImport.orphan.length} linha(s) do extrato sem venda informada no app — confira com o
        entregador.
      </p>
    {/if}

    <p class="k gap">OCORRÊNCIAS ABERTAS</p>
    {#each data?.open_issues ?? [] as issue (issue.id)}
      <div class="issue">
        <strong>#{issue.public_code} · {money(issue.amount)} de diferença</strong>
        <p>Aguardando decisão da plataforma.</p>
      </div>
    {:else}
      <p class="hint">Nenhuma.</p>
    {/each}

    <p class="chips fuu-mono">card_transactions<br />UNIQUE (acquirer, nsu)<br />match por nsu, fallback valor+hora</p>
  </aside>
</div>

<style>
  .recon {
    display: flex;
    min-height: 70vh;
  }
  .main {
    flex: 1;
    padding: 20px;
    min-width: 0;
  }
  .side {
    width: 320px;
    flex: 0 0 320px;
    background: var(--fuu-white);
    border-left: 1px solid var(--fuu-line-3);
    padding: 20px;
  }
  .head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
  }
  .head input {
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 6px 9px;
    font-family: inherit;
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
  .lead {
    font-size: 13px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin: 0 0 14px;
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
  .row {
    display: grid;
    grid-template-columns: 1fr 1.2fr 1fr 1fr 1fr 1.4fr;
    gap: 8px;
    padding: 11px 16px;
    font-size: 12.5px;
    border-bottom: 1px solid var(--fuu-line-4);
    align-items: center;
  }
  .head-row {
    font-size: 10.5px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.06em;
  }
  .row.alert {
    background: var(--fuu-red-tint);
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
    background: var(--fuu-red-tint-2);
    color: var(--fuu-alert);
  }
  .tag.wait {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .totals {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-top: 14px;
  }
  .totals div {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 12px;
  }
  .totals .n {
    font-size: 20px;
    font-weight: 800;
    margin: 0;
  }
  .totals .n.ok {
    color: var(--fuu-leaf-dark);
  }
  .totals .n.bad {
    color: var(--fuu-alert);
  }
  .totals .l {
    font-size: 10.5px;
    color: var(--fuu-ink-3);
    font-weight: 700;
    margin: 2px 0 0;
  }
  .closing {
    font-size: 12.5px;
    color: var(--fuu-alert);
    font-weight: 700;
    margin: 10px 0 0;
  }
  .closing.closed {
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
    color: var(--fuu-ink-2);
    line-height: 1.7;
    margin: 0;
  }
  .field span {
    display: block;
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin-bottom: 4px;
  }
  .field input {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 9px;
    font-family: inherit;
    font-size: 13px;
  }
  .drop {
    margin-top: 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    border: 1.5px dashed var(--fuu-line-2);
    border-radius: 11px;
    padding: 20px 12px;
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    cursor: pointer;
    text-align: center;
  }
  .drop i {
    font-size: 22px;
  }
  .drop.busy {
    opacity: 0.6;
  }
  .hint {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    margin: 9px 0 0;
  }
  .warn {
    font-size: 12px;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    border-radius: 9px;
    padding: 9px;
    margin: 10px 0 0;
  }
  .issue {
    border: 1px solid var(--fuu-red-tint-2);
    background: var(--fuu-red-tint);
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 8px;
    font-size: 12.5px;
  }
  .issue p {
    margin: 3px 0 0;
    color: var(--fuu-ink-2);
  }
  .chips {
    font-size: 10px;
    color: var(--fuu-ink-3);
    margin-top: 16px;
    line-height: 1.6;
  }
  @media (max-width: 900px) {
    .recon {
      flex-direction: column;
    }
    .side {
      width: auto;
      flex: 1;
      border-left: 0;
      border-top: 1px solid var(--fuu-line-3);
    }
    .row {
      grid-template-columns: 1fr 1fr 1fr;
    }
  }
</style>
