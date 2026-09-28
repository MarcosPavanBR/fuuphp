<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';

  // Tela 2.3 — Fidelidade. "progress do Bootstrap; saldo calculado no
  // banco (soma dos lançamentos), nunca no cliente." (LoyaltyDashboard,
  // api/loyalty.php → profile/loyalty.php, migração 029)
  //
  // Tudo que aparece vem do servidor: saldo, a próxima meta, o que já dá pra
  // trocar e o histórico. Trocar gera um cupom pessoal, que a tela mostra com
  // o código e entra no carrinho como qualquer cupom (só o dono consegue usar).
  let data = $state(null);
  let busy = $state(false);

  async function load() {
    try {
      data = await api.get('/profile/loyalty.php', { auth: true });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar seus pontos.');
    }
  }
  load();

  const fmt = (n) => Number(n).toLocaleString('pt-BR');

  // "Faltam 260 pontos para o cupom de R$ 20" / "... para a entrega grátis".
  function goalLabel(goal) {
    return goal.kind === 'free_delivery' ? 'a entrega grátis' : `o cupom de R$ ${fmt(goal.value)}`;
  }

  let goalCost = $derived(data?.goal ? Number(data.goal.cost) : 0);
  let progressPct = $derived(goalCost > 0 ? Math.min(100, Math.round((data.balance / goalCost) * 100)) : 0);
  let missing = $derived(Math.max(0, goalCost - (data?.balance ?? 0)));
  // "Resgatar agora": a melhor troca que o saldo já paga (a de maior custo).
  let bestNow = $derived(data?.rewards.filter((r) => r.affordable).at(-1) ?? null);

  async function redeem(reward) {
    if (!reward.affordable) {
      toastr.info(`Faltam ${fmt(reward.cost - data.balance)} pontos pra essa troca.`);
      return;
    }
    busy = true;
    try {
      const res = await api.post('/profile/loyalty.php', { auth: true, body: { reward_id: reward.id } });
      data = res.summary;
      toastr.success(`Cupom ${res.coupon.code} criado — use no carrinho.`);
    } catch (e) {
      toastr.error(e instanceof ApiError ? e.message : 'Não deu pra trocar os pontos.');
    } finally {
      busy = false;
    }
  }

  async function copy(code) {
    try {
      await navigator.clipboard.writeText(code);
      toastr.success('Código copiado ✓');
    } catch {
      toastr.info(code);
    }
  }
</script>

<div class="loyalty-screen">
  <h1 class="fuu-display">Seus pontos</h1>
  {#if data === null}
    <p class="hint">Carregando…</p>
  {:else}
    <p class="points">
      <strong>{fmt(data.balance)}</strong>
      {#if data.goal}<span>de {fmt(data.goal.cost)}</span>{/if}
    </p>
    <div class="progress-track" role="progressbar" aria-valuenow={progressPct} aria-valuemin="0" aria-valuemax="100">
      <div class="progress-fill" style={`width:${progressPct}%`}></div>
    </div>
    {#if data.goal && missing > 0}
      <p class="hint">Faltam {fmt(missing)} pontos para {goalLabel(data.goal)}</p>
    {:else}
      <p class="hint">Você já pode trocar qualquer cupom da lista.</p>
    {/if}

    <button type="button" class="btn-fuu-primary w-100" disabled={busy || !bestNow} onclick={() => redeem(bestNow)}>
      {bestNow ? `Resgatar agora · ${bestNow.label}` : 'Resgatar agora'}
    </button>
    <p class="rule">Você ganha {fmt(data.rate)} ponto por real em itens, quando o pedido é entregue.</p>

    <div class="rewards">
      {#each data.rewards as r (r.id)}
        <button type="button" class="reward fuu-card" class:locked={!r.affordable} disabled={busy} onclick={() => redeem(r)}>
          <div>
            <p class="title">{r.label}</p>
            <p class="cost">{fmt(r.cost)} pontos</p>
          </div>
          <span class="trade">{r.affordable ? 'Trocar' : `faltam ${fmt(r.cost - data.balance)}`}</span>
        </button>
      {/each}
    </div>

    {#if data.coupons.length > 0}
      <p class="section-label">SEUS CUPONS DE PONTOS</p>
      <div class="history">
        {#each data.coupons as c (c.code)}
          <button type="button" class="coupon" onclick={() => copy(c.code)}>
            <span class="fuu-mono">{c.code}</span>
            <span>{c.kind === 'free_delivery' ? 'Entrega grátis' : `R$ ${fmt(c.value)}`} · copiar</span>
          </button>
        {/each}
      </div>
    {/if}

    <p class="section-label">HISTÓRICO</p>
    {#if data.history.length === 0}
      <p class="hint">Seus pontos aparecem aqui quando o primeiro pedido for entregue.</p>
    {:else}
      <div class="history">
        {#each data.history as h, i (i)}
          <div class="history-row">
            <span>{h.memo}</span>
            <span class={h.points >= 0 ? 'positive' : 'negative'}>
              {h.points >= 0 ? '+' : '−'}{fmt(Math.abs(h.points))}
            </span>
          </div>
        {/each}
      </div>
    {/if}
  {/if}
</div>

<style>
  .loyalty-screen {
    padding: 12px 20px 24px;
  }
  .rule {
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    margin: 8px 0 0;
    text-align: center;
  }
  .reward.locked .trade {
    color: var(--fuu-ink-5);
    font-weight: 500;
  }
  .coupon {
    display: flex;
    justify-content: space-between;
    border: 1px dashed var(--fuu-red);
    border-radius: 10px;
    padding: 10px 12px;
    background: var(--fuu-red-tint);
    font-size: 13px;
    color: var(--fuu-ink-1);
  }
  h1 {
    font-size: 20px;
    margin: 0 0 6px;
  }
  .points {
    margin: 0 0 10px;
    color: var(--fuu-ink-2);
  }
  .points strong {
    font-family: var(--fuu-font-mono);
    font-size: 22px;
    color: var(--fuu-ink-1);
  }
  .progress-track {
    height: 8px;
    border-radius: 999px;
    background: var(--fuu-line-4);
    overflow: hidden;
    margin-bottom: 8px;
  }
  .progress-fill {
    height: 100%;
    background: var(--fuu-red);
  }
  .hint {
    color: var(--fuu-ink-5);
    font-size: 12.5px;
    margin: 0 0 16px;
  }
  .rewards {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 18px 0;
  }
  .reward {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px;
    width: 100%;
    text-align: left;
  }
  .title {
    margin: 0;
    font-weight: 600;
    color: var(--fuu-ink-1);
    font-size: 14px;
  }
  .cost {
    margin: 2px 0 0;
    color: var(--fuu-ink-5);
    font-size: 12px;
  }
  .trade {
    color: var(--fuu-red);
    font-weight: 600;
    font-size: 13px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 8px 0 10px;
  }
  .history {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .history-row {
    display: flex;
    justify-content: space-between;
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .positive {
    color: var(--fuu-leaf);
    font-family: var(--fuu-font-mono);
  }
  .negative {
    color: var(--fuu-ink-4);
    font-family: var(--fuu-font-mono);
  }
</style>
