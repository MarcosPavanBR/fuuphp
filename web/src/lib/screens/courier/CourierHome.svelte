<script>
  import { api, ApiError } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { courierToken } from '../../courierSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Tela 8.1 — "Turno e saldo em espécie". Alvos grandes, números enormes:
  // é uma tela pra ser usada com uma mão, no sol, de moto parada.
  //
  // O saldo em espécie aparece sempre que existe, em vermelho, porque é
  // passivo do entregador com a loja -- não é ganho dele.
  let { me, onRefresh, onOpenSettle } = $props();

  let busy = $state(false);
  let now = $state(Date.now());

  $effect(() => {
    const t = setInterval(() => (now = Date.now()), 1000);
    return () => clearInterval(t);
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  let online = $derived(me?.shift != null);
  let shiftLabel = $derived.by(() => {
    if (!me?.shift) return null;
    const started = parsePgTimestamp(me.shift.started_at);
    if (!started) return null;
    const mins = Math.max(0, Math.floor((now - started.getTime()) / 60000));
    return `${Math.floor(mins / 60)}h${String(mins % 60).padStart(2, '0')}`;
  });

  async function toggleShift() {
    busy = true;
    try {
      await api.post('/couriers/shift.php', {
        token: courierToken(),
        body: { action: online ? 'end' : 'start' },
      });
      toastr.success(online ? 'Turno encerrado.' : 'Turno aberto — as corridas começam a aparecer.');
      onRefresh();
    } catch (e) {
      const code = e instanceof ApiError ? e.code : null;
      toastr.error(
        code === 'ride_in_progress'
          ? 'Termine a corrida em andamento antes de encerrar o turno.'
          : (e.message ?? 'Não deu pra mudar o turno.')
      );
    } finally {
      busy = false;
    }
  }
</script>

<div class="home">
  <header class="who">
    <div class="avatar">{(me?.courier?.name ?? '?').slice(0, 1)}</div>
    <div class="ident">
      <p class="name">{me?.courier?.name ?? 'Entregador'}</p>
      <p class="meta">
        {me?.courier?.rating ? `nota ${Number(me.courier.rating).toFixed(2).replace('.', ',')}` : 'sem nota ainda'}
      </p>
    </div>
  </header>

  <div class="power-card">
    <p class="label">VOCÊ ESTÁ</p>
    <p class="state fuu-display" class:on={online}>{online ? 'ONLINE' : 'OFFLINE'}</p>
    <button
      type="button"
      class="power"
      class:on={online}
      disabled={busy}
      onclick={toggleShift}
      aria-label={online ? 'Encerrar turno' : 'Abrir turno'}
    >
      <i class="bi bi-power"></i>
    </button>
    <p class="hint">
      {online ? `Em turno há ${shiftLabel}. Toque para encerrar.` : 'Toque para receber corridas'}
    </p>
  </div>

  <div class="stats">
    <div class="stat">
      <p class="k">A RECEBER</p>
      <p class="v">{money(me?.balances?.payable ?? 0)}</p>
    </div>
    <div class="stat">
      <p class="k">EM ESPÉCIE</p>
      <p class="v" class:alert={(me?.balances?.cash ?? 0) > 0}>{money(me?.balances?.cash ?? 0)}</p>
    </div>
    <div class="stat">
      <p class="k">ONLINE</p>
      <p class="v">{shiftLabel ?? '—'}</p>
    </div>
  </div>

  {#if (me?.balances?.cash ?? 0) > 0}
    <!-- 9.1 — "Teto de espécie e prazo visíveis antes de qualquer ação: são
         as duas travas que impedem furo de caixa." -->
    <div class="cash-card">
      <i class="bi bi-cash-stack"></i>
      <div>
        <p>
          <strong>{money(me.balances.cash)} em espécie</strong> em sua posse. Repasse na loja pra liberar
          o teto.
        </p>
        <p class="ceiling">
          Teto: {money(me.balances.cash_ceiling)} · ainda cabem {money(me.balances.cash_headroom)}
        </p>
      </div>
    </div>
    <button type="button" class="btn-fuu-primary w-100 settle" onclick={onOpenSettle}>
      Baixar a espécie na loja
    </button>
  {/if}

  {#if me?.courier?.cash_blocked}
    <div class="blocked">
      <i class="bi bi-exclamation-octagon-fill"></i>
      Caixa bloqueado: sem corrida em dinheiro até você baixar a espécie.
    </div>
  {/if}
</div>

<style>
  .home {
    padding: 18px;
  }
  .who {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .avatar {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: var(--fuu-line-4);
    color: var(--fuu-ink-2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 19px;
    flex: none;
  }
  .name {
    font-weight: 800;
    font-size: 15px;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .meta {
    font-size: 11px;
    color: var(--fuu-ink-4);
    margin: 2px 0 0;
  }
  .power-card {
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 18px;
    margin-top: 18px;
    text-align: center;
    background: var(--fuu-line-6);
  }
  .label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    color: var(--fuu-ink-4);
    letter-spacing: 0.1em;
    margin: 0;
  }
  .state {
    font-size: 30px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 4px 0 14px;
    color: var(--fuu-ink-3);
  }
  .state.on {
    color: var(--fuu-leaf-dark);
  }
  .power {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    border: none;
    background: var(--fuu-leaf-dark);
    color: var(--fuu-white);
    font-size: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
  }
  .power.on {
    background: var(--fuu-red);
  }
  .hint {
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin: 12px 0 0;
  }
  .stats {
    display: flex;
    gap: 10px;
    margin-top: 16px;
  }
  .stat {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: 11px;
    padding: 13px;
    background: var(--fuu-line-6);
  }
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    color: var(--fuu-ink-4);
    font-weight: 700;
    margin: 0;
  }
  .v {
    font-size: 19px;
    font-weight: 800;
    margin: 2px 0 0;
    color: var(--fuu-ink-1);
  }
  .v.alert {
    color: var(--fuu-red);
  }
  .cash-card {
    border: 1px solid var(--fuu-red-tint-2);
    background: var(--fuu-red-tint);
    border-radius: 11px;
    padding: 13px;
    margin-top: 12px;
    display: flex;
    gap: 10px;
  }
  .cash-card i {
    color: var(--fuu-red);
    margin-top: 2px;
  }
  .cash-card p {
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    line-height: 1.5;
    margin: 0;
  }
  .cash-card .ceiling {
    color: var(--fuu-ink-4);
    margin-top: 4px;
  }
  .settle {
    margin-top: 12px;
    min-height: var(--fuu-tap-operator);
  }
  .blocked {
    display: flex;
    align-items: center;
    gap: 9px;
    background: var(--fuu-red);
    color: var(--fuu-white);
    border-radius: 11px;
    padding: 13px;
    margin-top: 12px;
    font-size: 12.5px;
    font-weight: 700;
  }
</style>
