<script>
  import { api } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { staffToken } from '../../staffSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Tela 11.2 — "Pausar loja, motivo e tempo de preparo".
  //
  // "Pausa com volta automática e o custo estimado ao lado — decisão com
  // número, não no escuro." Os números da coluna da direita vêm de
  // pause_status.php e são da PRÓPRIA loja, nesta faixa de horário: uma
  // média genérica diria que pausar às 20h custa o mesmo que às 15h.
  let { onChanged } = $props();

  let data = $state(null);
  let reason = $state('busy_kitchen');
  let busy = $state(null);
  let now = $state(Date.now());

  const MINUTES = [15, 30, 60];
  const SHIFT_LABEL = { lunch: 'Almoço', dinner: 'Jantar' };

  async function load() {
    try {
      data = await api.get('/restaurants/pause_status.php', { token: staffToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra ler o estado da loja.');
    }
  }

  $effect(() => {
    load();
    const t = setInterval(() => (now = Date.now()), 1000);
    return () => clearInterval(t);
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }
  function hhmm(value) {
    if (!value) return '—';
    const d = typeof value === 'string' && value.includes(':') && !value.includes('-')
      ? null
      : parsePgTimestamp(value);
    if (d) return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    return String(value).slice(0, 5);
  }

  async function act(action, minutes) {
    busy = action + (minutes ?? '');
    try {
      await api.post('/restaurants/pause.php', {
        token: staffToken(),
        body: { action, reason, minutes },
      });
      toastr.success(
        action === 'resume'
          ? 'Loja de volta — o horário manda de novo.'
          : action === 'pause'
            ? `Pausada por ${minutes} min. Volta sozinha.`
            : 'Fechada por hoje. Reabre no horário de amanhã.'
      );
      await load();
      onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra mudar o estado da loja.');
    } finally {
      busy = null;
    }
  }

  async function savePrep(patch) {
    busy = 'prep';
    try {
      await api.post('/restaurants/prep_time.php', { token: staffToken(), body: patch });
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar o tempo de preparo.');
    } finally {
      busy = null;
    }
  }

  let paused = $derived(data?.pause != null);
  let pauseEnds = $derived(
    data?.pause?.until ? parsePgTimestamp(data.pause.until).getTime() : null
  );
  let pauseLeft = $derived(
    pauseEnds === null ? null : Math.max(0, Math.round((pauseEnds - now) / 60000))
  );
  let pausedMinutesToday = $derived(Math.round((data?.paused_seconds_today ?? 0) / 60));
  let rankingLimit = $derived(Math.round((data?.ranking_limit_seconds ?? 7200) / 60));
</script>

{#if data === null}
  <p class="loading">Lendo o estado da loja…</p>
{:else}
  <div class="pause-layout">
    <main class="main">
      <h1 class="title">{paused ? 'Loja pausada' : 'Pausar a loja'}</h1>
      <p class="lede">
        Pausa esconde a loja do app e para novos pedidos. Os pedidos já aceitos continuam — nada é
        cancelado por pausar.
      </p>

      {#if paused}
        <div class="resume-card">
          <div class="resume-head">
            <i class="bi bi-pause-circle-fill"></i>
            <div>
              <p class="resume-title">
                {data.pause.kind === 'short' ? 'Pausa curta em andamento' : 'Fechada por hoje'}
              </p>
              <p class="resume-sub">
                {data.reasons.find((r) => r.code === data.pause.reason)?.label ?? data.pause.reason}
                · desde {hhmm(data.pause.started_at)}
                {#if pauseLeft !== null}· volta sozinha em {pauseLeft} min{/if}
              </p>
            </div>
          </div>
          <button type="button" class="btn-fuu-primary w-100" disabled={busy !== null} onclick={() => act('resume')}>
            {busy === 'resume' ? 'Voltando…' : 'Voltar a receber pedidos'}
          </button>
        </div>
      {:else}
        <div class="choices">
          <div class="choice short">
            <div class="icon wait"><i class="bi bi-pause-fill"></i></div>
            <p class="choice-title">Pausa curta</p>
            <p class="choice-note">Volta sozinha no tempo escolhido</p>
            <div class="minutes">
              {#each MINUTES as m (m)}
                <button type="button" class="min" disabled={busy !== null} onclick={() => act('pause', m)}>
                  {busy === `pause${m}` ? '…' : m === 60 ? '1 h' : `${m} min`}
                </button>
              {/each}
            </div>
          </div>
          <div class="choice">
            <div class="icon danger"><i class="bi bi-moon-fill"></i></div>
            <p class="choice-title">Fechar por hoje</p>
            <p class="choice-note">Reabre no horário de amanhã</p>
            <button type="button" class="close-btn" disabled={busy !== null} onclick={() => act('close_today')}>
              {busy === 'close_today' ? 'Fechando…' : 'Fechar agora'}
            </button>
          </div>
        </div>
      {/if}

      <p class="section-label">MOTIVO (O CLIENTE VÊ)</p>
      <div class="reasons">
        {#each data.reasons as option (option.code)}
          <button
            type="button"
            class="reason"
            class:on={reason === option.code}
            disabled={paused}
            onclick={() => (reason = option.code)}
          >
            {option.label}
          </button>
        {/each}
      </div>

      <div class="prep-card">
        <p class="prep-title">Tempo de preparo informado ao cliente</p>
        <div class="prep-row">
          <span class="prep-number fuu-mono">{data.prep.effective}</span>
          <div class="prep-text">
            minutos
            <span class="prep-sub">
              {data.prep.bumped
                ? `${data.prep.base} + 10 pela fila de ${data.prep.queue} pedidos`
                : 'é o que aparece na previsão de entrega'}
            </span>
          </div>
          <div class="steppers">
            <button
              type="button"
              aria-label="Diminuir"
              disabled={busy !== null || data.store.prep_minutes <= 5}
              onclick={() => savePrep({ prep_minutes: data.store.prep_minutes - 5 })}
            >
              <i class="bi bi-dash"></i>
            </button>
            <button
              type="button"
              aria-label="Aumentar"
              disabled={busy !== null || data.store.prep_minutes >= 180}
              onclick={() => savePrep({ prep_minutes: data.store.prep_minutes + 5 })}
            >
              <i class="bi bi-plus"></i>
            </button>
          </div>
        </div>
        <label class="switch-row">
          <input
            type="checkbox"
            checked={data.store.prep_auto_bump}
            disabled={busy !== null}
            onchange={(e) => savePrep({ prep_auto_bump: e.currentTarget.checked })}
          />
          <span class="switch"></span>
          <span class="switch-label">Aumentar sozinho quando a fila passar de 8 pedidos</span>
        </label>
      </div>
    </main>

    <aside class="side">
      <p class="side-label">EFEITO DA PAUSA AGORA</p>
      <div class="effect">
        <div class="kv"><span>Pedidos em andamento</span><strong>{data.effect.orders_in_flight}</strong></div>
        <div class="kv">
          <span>Continuam normalmente</span>
          <strong class="good">{data.effect.orders_in_flight === 0 ? 'nenhum aberto' : 'todos'}</strong>
        </div>
        {#if data.effect.has_history}
          <div class="kv">
            <span>Pedidos/hora perdidos</span><strong class="bad">≈ {data.effect.orders_per_hour}</strong>
          </div>
          <div class="kv">
            <span>Faturamento/hora</span><strong class="bad">≈ {money(data.effect.revenue_per_hour)}</strong>
          </div>
        {:else}
          <!-- Sem pedido nessa faixa de horário nas últimas quatro semanas,
               "≈ 0" seria lido como "pausar não custa nada". -->
          <p class="no-history">
            Ainda não há pedido nesta faixa de horário nas últimas 4 semanas — sem histórico, não dá
            pra estimar o custo da pausa.
          </p>
        {/if}
      </div>

      <p class="side-label">HORÁRIO DE HOJE</p>
      <div class="today">
        {#if data.today.holiday}
          <div class="kv holiday">
            <span>{data.today.holiday.note ?? 'Data especial'}</span>
            <strong>
              {data.today.holiday.closed
                ? 'fechado o dia todo'
                : `${hhmm(data.today.holiday.opens)} – ${hhmm(data.today.holiday.closes)}`}
            </strong>
          </div>
        {/if}
        {#each data.today.shifts as shift (shift.shift)}
          <div class="kv">
            <span>{SHIFT_LABEL[shift.shift] ?? shift.shift}</span>
            <strong class:off={!shift.active}>
              {shift.active ? `${hhmm(shift.opens)} – ${hhmm(shift.closes)}` : 'fechado'}
            </strong>
          </div>
          {#if shift.active}
            <div class="kv"><span>Último pedido</span><strong>{hhmm(shift.last_order)}</strong></div>
          {/if}
        {:else}
          <p class="no-history">Nenhum horário cadastrado — a loja não abre nem fecha sozinha.</p>
        {/each}
        {#if paused}
          <div class="kv paused-line">
            <span>Pausa registrada</span>
            <strong>
              {hhmm(data.pause.started_at)}{data.pause.until ? ` – ${hhmm(data.pause.until)}` : ' – hoje'}
            </strong>
          </div>
        {/if}
      </div>

      <div class="warn">
        <strong>Pausa longa derruba seu ranking</strong> na busca do app. Acima de {rankingLimit} min por
        dia, a loja perde o selo de "Confiável".
        <span class="warn-today" class:over={pausedMinutesToday > rankingLimit}>
          Hoje: {pausedMinutesToday} min pausada.
        </span>
      </div>

      <p class="tech fuu-mono">
        restaurants.is_open + pause_until<br />
        store_pauses (motivo, autor, duração)<br />
        checkout revalida no servidor
      </p>
    </aside>
  </div>
{/if}

<style>
  .pause-layout {
    display: flex;
    align-items: flex-start;
  }
  .main {
    flex: 1;
    padding: 22px;
    min-width: 0;
  }
  .side {
    width: 340px;
    flex: none;
    background: var(--fuu-white);
    border-left: 1px solid var(--fuu-line-3);
    padding: 22px;
    min-height: calc(100vh - 63px);
  }
  .loading {
    padding: 40px 22px;
    color: var(--fuu-ink-5);
  }
  .title {
    font-family: var(--fuu-font-display);
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--fuu-ink-1);
    margin: 0;
  }
  .lede {
    font-size: 14px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin: 6px 0 18px;
    max-width: 46em;
  }
  .choices {
    display: flex;
    gap: 14px;
  }
  .choice {
    flex: 1;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 20px;
    text-align: center;
  }
  .choice.short {
    border: 2px solid var(--fuu-red);
  }
  .icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin: 0 auto;
  }
  .icon.wait {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .icon.danger {
    background: var(--fuu-red-tint);
    color: var(--fuu-alert);
  }
  .choice-title {
    font-size: 17px;
    font-weight: 800;
    margin: 12px 0 0;
    color: var(--fuu-ink-1);
  }
  .choice-note {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    line-height: 1.5;
    margin: 4px 0 0;
  }
  .minutes {
    display: flex;
    gap: 7px;
    justify-content: center;
    margin-top: 13px;
  }
  .min {
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 800;
    background: var(--fuu-line-5);
    border: none;
    color: var(--fuu-ink-1);
    padding: 12px 15px;
    border-radius: 9px;
    min-height: var(--fuu-tap-operator);
  }
  .close-btn {
    width: 100%;
    margin-top: 14px;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-red);
    color: var(--fuu-red);
    border-radius: 10px;
    padding: 15px;
    font-weight: 800;
    font-size: 14px;
    font-family: var(--fuu-font-body);
  }
  .resume-card {
    background: var(--fuu-white);
    border: 2px solid var(--fuu-wait-tint);
    border-radius: var(--fuu-radius-card);
    padding: 20px;
  }
  .resume-head {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 14px;
  }
  .resume-head i {
    font-size: 30px;
    color: var(--fuu-wait-text);
  }
  .resume-title {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .resume-sub {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    margin: 2px 0 0;
  }
  .section-label,
  .side-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-6);
    margin: 22px 0 10px;
  }
  .side-label:first-child {
    margin-top: 0;
  }
  .reasons {
    display: flex;
    gap: 9px;
    flex-wrap: wrap;
  }
  .reason {
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    color: var(--fuu-ink-1);
    padding: 12px 15px;
    border-radius: 9px;
  }
  .reason.on {
    border: 2px solid var(--fuu-ink-1);
  }
  .reason:disabled {
    opacity: 0.55;
  }
  .prep-card {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 18px;
    margin-top: 22px;
    max-width: 46em;
  }
  .prep-title {
    font-size: 15px;
    font-weight: 800;
    margin: 0 0 12px;
    color: var(--fuu-ink-1);
  }
  .prep-row {
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .prep-number {
    font-size: 40px;
    font-weight: 800;
    letter-spacing: -0.03em;
    color: var(--fuu-ink-1);
  }
  .prep-text {
    font-size: 13px;
    color: var(--fuu-ink-2);
    line-height: 1.5;
  }
  .prep-sub {
    display: block;
    color: var(--fuu-ink-6);
  }
  .steppers {
    margin-left: auto;
    display: flex;
    gap: 9px;
  }
  .steppers button {
    width: 56px;
    height: 56px;
    border-radius: 11px;
    background: var(--fuu-line-5);
    border: none;
    font-size: 22px;
    color: var(--fuu-ink-1);
  }
  .switch-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 16px;
    cursor: pointer;
  }
  .switch-row input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
  }
  .switch {
    width: 46px;
    height: 27px;
    border-radius: 15px;
    background: var(--fuu-line-1);
    position: relative;
    flex: 0 0 auto;
    transition: background 0.15s;
  }
  .switch::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 21px;
    height: 21px;
    border-radius: 50%;
    background: var(--fuu-white);
    transition: left 0.15s;
  }
  .switch-row input:checked + .switch {
    background: var(--fuu-leaf);
  }
  .switch-row input:checked + .switch::after {
    left: 22px;
  }
  .switch-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--fuu-ink-1);
  }
  .effect {
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 16px;
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .kv {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    line-height: 1.9;
  }
  .kv strong {
    color: var(--fuu-ink-1);
  }
  .kv .good {
    color: var(--fuu-leaf);
  }
  .kv .bad {
    color: var(--fuu-alert);
  }
  .kv strong.off {
    color: var(--fuu-ink-6);
    font-weight: 600;
  }
  .no-history {
    font-size: 12px;
    color: var(--fuu-ink-5);
    line-height: 1.55;
    margin: 6px 0 0;
  }
  .today {
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .today .holiday strong {
    color: var(--fuu-alert);
  }
  .paused-line {
    color: var(--fuu-alert);
  }
  .paused-line strong {
    color: var(--fuu-alert);
  }
  .warn {
    border: 1px solid var(--fuu-wait-tint);
    background: var(--fuu-wait-bg);
    border-radius: 11px;
    padding: 14px;
    margin-top: 18px;
    font-size: 12.5px;
    color: var(--fuu-wait-text);
    line-height: 1.6;
  }
  .warn-today {
    display: block;
    margin-top: 6px;
    font-weight: 700;
  }
  .warn-today.over {
    color: var(--fuu-alert);
  }
  .tech {
    font-size: 10px;
    color: var(--fuu-ink-6);
    margin-top: 18px;
    line-height: 1.6;
  }
  @media (max-width: 1000px) {
    .pause-layout {
      flex-direction: column;
    }
    .side {
      width: 100%;
      min-height: 0;
      border-left: none;
      border-top: 1px solid var(--fuu-line-3);
    }
  }
</style>
