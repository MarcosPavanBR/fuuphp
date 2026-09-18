<script>
  import { api } from '../../api.js';
  import { courierToken } from '../../courierSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Tela 8.7 — "Livro de lançamentos, não um campo de saldo: cada corrida,
  // bônus, gorjeta e repasse é uma linha — é o que permite fechar o caixa sem
  // discussão."
  //
  // Então a tela mostra a lista, não só o total. Cada linha tem conta, valor,
  // origem e pedido: é isso que o entregador confere com a loja quando a
  // conta não bate.
  let data = $state(null);

  $effect(() => {
    let alive = true;
    api
      .get('/couriers/earnings.php', { token: courierToken() })
      .then((d) => {
        if (alive) data = d;
      })
      .catch(() => {});
    return () => (alive = false);
  });

  function money(v) {
    const n = Number(v);
    const sign = n < 0 ? '−' : '';
    return `${sign}R$ ${Math.abs(n).toFixed(2).replace('.', ',')}`;
  }

  function when(raw) {
    const d = parsePgTimestamp(raw);
    if (!d) return '';
    return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  }

  const ACCOUNT_LABEL = {
    courier_cash: 'Espécie em mãos',
    courier_payable: 'A receber',
  };
</script>

<div class="earnings">
  <div class="totals">
    <div class="t">
      <p class="k">A RECEBER</p>
      <p class="v">{money(data?.balances?.payable ?? 0)}</p>
    </div>
    <div class="t">
      <p class="k">EM ESPÉCIE</p>
      <p class="v" class:alert={(data?.balances?.cash ?? 0) > 0}>{money(data?.balances?.cash ?? 0)}</p>
    </div>
  </div>

  <p class="today">
    Hoje: {data?.today?.rides_today ?? 0} corrida{(data?.today?.rides_today ?? 0) === 1 ? '' : 's'} ·
    {money(data?.today?.payable_today ?? 0)} em frete
  </p>

  <p class="section">LANÇAMENTOS</p>
  {#if (data?.entries ?? []).length === 0}
    <p class="empty">Nenhum lançamento ainda. Sua primeira corrida abre o livro.</p>
  {:else}
    <ul class="ledger">
      {#each data.entries as entry (entry.id)}
        <li>
          <div class="line">
            <span class="what">
              {entry.memo ?? ACCOUNT_LABEL[entry.account]}
              {#if entry.public_code}<span class="code fuu-mono">#{entry.public_code}</span>{/if}
            </span>
            <span class="amount" class:neg={Number(entry.amount) < 0}>{money(entry.amount)}</span>
          </div>
          <p class="meta">{ACCOUNT_LABEL[entry.account]} · {when(entry.created_at)}</p>
        </li>
      {/each}
    </ul>
  {/if}

  <p class="note fuu-mono">
    livro append-only · correção é contrapartida, nunca edição
  </p>
</div>

<style>
  .earnings {
    padding: 18px;
  }
  .totals {
    display: flex;
    gap: 10px;
  }
  .t {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: 11px;
    padding: 14px;
    background: var(--fuu-line-6);
  }
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .v {
    font-size: 22px;
    font-weight: 800;
    margin: 3px 0 0;
    color: var(--fuu-ink-1);
  }
  .v.alert {
    color: var(--fuu-red);
  }
  .today {
    font-size: 13px;
    color: var(--fuu-ink-3);
    margin: 12px 0 0;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 20px 0 10px;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  .ledger {
    list-style: none;
    padding: 0;
    margin: 0;
  }
  .ledger li {
    border-bottom: 1px solid var(--fuu-line-5);
    padding: 12px 0;
  }
  .line {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: baseline;
  }
  .what {
    font-size: 14px;
    color: var(--fuu-ink-1);
    font-weight: 600;
  }
  .code {
    font-size: 11px;
    color: var(--fuu-ink-4);
    margin-left: 6px;
  }
  .amount {
    font-size: 15px;
    font-weight: 800;
    color: var(--fuu-leaf-dark);
    white-space: nowrap;
  }
  .amount.neg {
    color: var(--fuu-red);
  }
  .meta {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 3px 0 0;
  }
  .note {
    font-size: 10px;
    color: var(--fuu-ink-5);
    margin-top: 18px;
    line-height: 1.6;
  }
</style>
