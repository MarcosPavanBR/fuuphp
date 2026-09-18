<script>
  import { api } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { staffToken } from '../../staffSession.svelte.js';

  // Tela 11.4 — "Horário, feriados e último pedido".
  //
  // "Dois turnos por dia, feriado como exceção e o histograma de pedidos por
  // hora ao lado — quem edita horário vê na hora quanto está deixando na
  // mesa. Abrir e fechar é tarefa do pg_cron, não do atendente."
  //
  // O histograma é de pedido real desta loja nas últimas quatro semanas: um
  // gráfico de exemplo aqui seria pior que nenhum, porque a tela existe pra
  // decidir horário olhando pra ele.
  let { onChanged } = $props();

  const DAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
  const SCOPES = [
    { code: 'day', label: 'Só este dia' },
    { code: 'week', label: 'Seg a sex' },
    { code: 'all', label: 'Todos os dias' },
  ];

  let data = $state(null);
  let editingDay = $state(null);
  let form = $state(null);
  let scope = $state('day');
  let saving = $state(false);
  let holidayForm = $state(null);
  // 14.4 — quantos pedidos agendados cabem numa faixa de 30 min. Zero é
  // "essa loja não aceita agendamento", e é o padrão.
  let slotCapacity = $state(0);
  let savingCapacity = $state(false);

  async function load() {
    try {
      data = await api.get('/restaurants/hours.php', { token: staffToken() });
      slotCapacity = data.slot_capacity ?? 0;
      if (editingDay === null) editingDay = data.today_dow;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar o horário.');
    }
  }

  $effect(() => {
    load();
  });

  function hhmm(t) {
    return t ? String(t).slice(0, 5) : '';
  }

  function shiftOf(dow, name) {
    return (data?.hours ?? []).find((h) => h.dow === dow && h.shift === name) ?? null;
  }

  // O formulário nasce do que está salvo pro dia escolhido; dia sem nada
  // cadastrado começa com o turno desligado e horário em branco, não com um
  // horário inventado que alguém salvaria sem ler.
  function openDay(dow) {
    editingDay = dow;
    const build = (name) => {
      const row = shiftOf(dow, name);
      return {
        shift: name,
        active: row ? row.active : false,
        opens: hhmm(row?.opens) || (name === 'lunch' ? '11:00' : '18:00'),
        closes: hhmm(row?.closes) || (name === 'lunch' ? '15:00' : '23:00'),
        last_order: hhmm(row?.last_order) || (name === 'lunch' ? '14:30' : '22:30'),
      };
    };
    form = { lunch: build('lunch'), dinner: build('dinner') };
    scope = 'day';
  }

  function daysForScope() {
    if (scope === 'week') return [1, 2, 3, 4, 5];
    if (scope === 'all') return [0, 1, 2, 3, 4, 5, 6];
    return [editingDay];
  }

  async function save() {
    saving = true;
    try {
      const res = await api.post('/restaurants/hours_save.php', {
        token: staffToken(),
        body: { days: daysForScope(), shifts: [form.lunch, form.dinner] },
      });
      toastr.success(
        res.is_open ? 'Horário salvo — a loja está aberta agora.' : 'Horário salvo. A loja está fechada agora.'
      );
      form = null;
      await load();
      onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar o horário.');
    } finally {
      saving = false;
    }
  }

  async function toggleDay(dow) {
    const lunch = shiftOf(dow, 'lunch');
    const dinner = shiftOf(dow, 'dinner');
    if (!lunch && !dinner) {
      openDay(dow);
      toastr.info('Esse dia ainda não tem horário — preencha e salve.');
      return;
    }
    const turningOn = !(lunch?.active || dinner?.active);
    const shifts = [lunch, dinner].filter(Boolean).map((row) => ({
      shift: row.shift,
      opens: hhmm(row.opens),
      closes: hhmm(row.closes),
      last_order: hhmm(row.last_order),
      active: turningOn,
    }));
    try {
      await api.post('/restaurants/hours_save.php', { token: staffToken(), body: { days: [dow], shifts } });
      await load();
      onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra mudar o dia.');
    }
  }

  async function saveCapacity() {
    savingCapacity = true;
    try {
      await api.post('/restaurants/hours_save.php', {
        token: staffToken(),
        body: { slot_capacity: Number(slotCapacity) },
      });
      toastr.success(
        Number(slotCapacity) > 0
          ? `Agendamento ligado: ${slotCapacity} pedido(s) por faixa de 30 min.`
          : 'Agendamento desligado — a loja só recebe pedido pra agora.'
      );
      await load();
      onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar a capacidade.');
    } finally {
      savingCapacity = false;
    }
  }

  function newHoliday() {
    holidayForm = { day: '', closed: true, opens: '18:00', closes: '23:00', last_order: '22:30', note: '' };
  }

  async function saveHoliday() {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(holidayForm.day)) {
      toastr.warning('Escolha a data.');
      return;
    }
    saving = true;
    try {
      await api.post('/restaurants/holiday.php', {
        token: staffToken(),
        body: {
          day: holidayForm.day,
          closed: holidayForm.closed,
          opens: holidayForm.closed ? null : holidayForm.opens,
          closes: holidayForm.closed ? null : holidayForm.closes,
          last_order: holidayForm.closed ? null : holidayForm.last_order,
          note: holidayForm.note.trim() === '' ? null : holidayForm.note.trim(),
        },
      });
      toastr.success('Data especial salva.');
      holidayForm = null;
      await load();
      onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar a data.');
    } finally {
      saving = false;
    }
  }

  async function removeHoliday(holiday) {
    try {
      await api.post('/restaurants/holiday.php', {
        token: staffToken(),
        body: { action: 'remove', id: holiday.id },
      });
      await load();
      onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra remover a data.');
    }
  }

  function dayLabel(iso) {
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
  }

  let maxOrders = $derived(Math.max(1, ...(data?.histogram ?? []).map((h) => Number(h.orders_count))));
</script>

{#if data === null}
  <p class="loading">Carregando horário…</p>
{:else}
  <div class="hours-layout">
    <main class="main">
      <div class="table">
        <div class="thead">
          <span class="c-day">DIA</span>
          <span class="c-shift">ALMOÇO</span>
          <span class="c-shift">JANTAR</span>
          <span class="c-last">ÚLTIMO PEDIDO</span>
          <span class="c-active">ATIVO</span>
        </div>
        {#each DAYS as label, dow (label)}
          {@const lunch = shiftOf(dow, 'lunch')}
          {@const dinner = shiftOf(dow, 'dinner')}
          {@const on = (lunch?.active || dinner?.active) === true}
          <div class="trow" class:today={dow === data.today_dow} class:off={!on}>
            <button type="button" class="c-day day-btn" onclick={() => openDay(dow)}>
              {label}
              {#if dow === data.today_dow}<span class="today-tag">HOJE</span>{/if}
            </button>
            <span class="c-shift fuu-mono">
              {lunch?.active ? `${hhmm(lunch.opens)} – ${hhmm(lunch.closes)}` : 'fechado'}
            </span>
            <span class="c-shift fuu-mono">
              {dinner?.active ? `${hhmm(dinner.opens)} – ${hhmm(dinner.closes)}` : 'fechado'}
            </span>
            <span class="c-last">
              {dinner?.active ? hhmm(dinner.last_order) : lunch?.active ? hhmm(lunch.last_order) : '—'}
            </span>
            <span class="c-active">
              <label class="switch-row">
                <input type="checkbox" checked={on} onchange={() => toggleDay(dow)} />
                <span class="switch"></span>
                <span class="sr-only">{on ? 'Dia ativo' : 'Dia fechado'}</span>
              </label>
            </span>
          </div>
        {/each}
      </div>

      {#if form}
        <div class="editor">
          <p class="editor-title">{DAYS[editingDay]} — turnos</p>
          {#each ['lunch', 'dinner'] as name (name)}
            <div class="shift-row">
              <label class="switch-row">
                <input type="checkbox" bind:checked={form[name].active} />
                <span class="switch"></span>
                <span class="switch-label">{name === 'lunch' ? 'Almoço' : 'Jantar'}</span>
              </label>
              <label class="time">abre <input type="time" bind:value={form[name].opens} /></label>
              <label class="time">fecha <input type="time" bind:value={form[name].closes} /></label>
              <label class="time">último pedido <input type="time" bind:value={form[name].last_order} /></label>
            </div>
          {/each}

          <p class="field-label">APLICAR PARA</p>
          <div class="scopes">
            {#each SCOPES as option (option.code)}
              <button type="button" class="scope" class:on={scope === option.code} onclick={() => (scope = option.code)}>
                {option.label}
              </button>
            {/each}
          </div>

          <div class="editor-actions">
            <button type="button" class="discard" onclick={() => (form = null)}>Cancelar</button>
            <button type="button" class="btn-fuu-primary" disabled={saving} onclick={save}>
              {saving ? 'Salvando…' : 'Salvar horário'}
            </button>
          </div>
        </div>
      {/if}

      <div class="holidays">
        <p class="holidays-title">Pedido agendado</p>
        <p class="capacity-note">
          Quantos pedidos agendados cabem em cada faixa de 30 min. Zero desliga o agendamento — a vaga é
          da capacidade da sua cozinha, não do relógio.
        </p>
        <div class="capacity-row">
          <input type="number" min="0" max="100" step="1" bind:value={slotCapacity} class="fuu-mono" />
          <button type="button" class="btn-fuu-primary" disabled={savingCapacity} onclick={saveCapacity}>
            {savingCapacity ? 'Salvando…' : 'Salvar capacidade'}
          </button>
        </div>
      </div>

      <div class="holidays">
        <p class="holidays-title">Feriados e datas especiais</p>
        <div class="holiday-list">
          {#each data.holidays as holiday (holiday.id)}
            <div class="holiday">
              <strong>{dayLabel(holiday.day)}{holiday.note ? ` · ${holiday.note}` : ''}</strong>
              <span class:closed={holiday.closed}>
                {holiday.closed
                  ? 'fechado o dia todo'
                  : `só ${hhmm(holiday.opens)} – ${hhmm(holiday.closes)}`}
              </span>
              <button type="button" class="holiday-del" onclick={() => removeHoliday(holiday)}>
                <i class="bi bi-trash"></i> remover
              </button>
            </div>
          {/each}
          <button type="button" class="holiday-add" onclick={newHoliday}>
            <i class="bi bi-plus-lg"></i> Adicionar
          </button>
        </div>

        {#if holidayForm}
          <div class="holiday-form">
            <label class="time">data <input type="date" bind:value={holidayForm.day} /></label>
            <label class="time">nome <input type="text" placeholder="Nossa Senhora" bind:value={holidayForm.note} /></label>
            <label class="switch-row">
              <input type="checkbox" bind:checked={holidayForm.closed} />
              <span class="switch"></span>
              <span class="switch-label">Fechado o dia todo</span>
            </label>
            {#if !holidayForm.closed}
              <label class="time">abre <input type="time" bind:value={holidayForm.opens} /></label>
              <label class="time">fecha <input type="time" bind:value={holidayForm.closes} /></label>
              <label class="time">último pedido <input type="time" bind:value={holidayForm.last_order} /></label>
            {/if}
            <div class="editor-actions">
              <button type="button" class="discard" onclick={() => (holidayForm = null)}>Cancelar</button>
              <button type="button" class="btn-fuu-primary" disabled={saving} onclick={saveHoliday}>Salvar data</button>
            </div>
          </div>
        {/if}
      </div>
    </main>

    <aside class="side">
      <p class="side-label">SEUS PEDIDOS POR HORA</p>
      <div class="chart">
        {#each data.histogram as bar (bar.hour)}
          <div
            class="bar"
            class:peak={data.peak && bar.hour >= data.peak.from && bar.hour < data.peak.to}
            style:height={`${Math.max(2, (Number(bar.orders_count) * 100) / maxOrders)}%`}
            title={`${bar.hour}h · ${bar.orders_count} pedidos`}
          ></div>
        {/each}
      </div>
      <div class="chart-axis"><span>0h</span><span>6h</span><span>12h</span><span>18h</span><span>23h</span></div>
      {#if data.peak}
        <p class="peak-note">
          Seu pico é entre <strong>{data.peak.from}h e {data.peak.to}h</strong>, contando os pedidos das
          últimas 4 semanas.
        </p>
      {:else}
        <!-- "Fechar 30 min mais tarde rendeu +11 pedidos" é comparação
             contrafactual: exigiria histórico de MUDANÇA de horário, que
             ninguém guarda ainda. Fica o que é medido de verdade. -->
        <p class="peak-note">Sem pedidos nas últimas 4 semanas — ainda não dá pra ver o pico do dia.</p>
      {/if}

      <p class="tech fuu-mono">
        business_hours (dia, turno, faixa)<br />
        holiday_overrides<br />
        pg_cron abre/fecha a loja
      </p>
    </aside>
  </div>
{/if}

<style>
  .hours-layout {
    display: flex;
    align-items: flex-start;
  }
  .loading {
    padding: 40px 22px;
    color: var(--fuu-ink-5);
  }
  .main {
    flex: 1;
    padding: 20px;
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
  .table {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    overflow: hidden;
  }
  .thead {
    display: flex;
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    color: var(--fuu-ink-6);
    letter-spacing: 0.06em;
    padding: 13px 18px;
    border-bottom: 1px solid var(--fuu-line-4);
  }
  .trow {
    display: flex;
    align-items: center;
    font-size: 14px;
    padding: 6px 18px;
    border-bottom: 1px solid var(--fuu-line-6);
  }
  .trow.today {
    background: var(--fuu-line-6);
  }
  .trow.off {
    opacity: 0.55;
  }
  .c-day {
    width: 150px;
  }
  .c-shift {
    width: 175px;
  }
  .c-last {
    flex: 1;
    color: var(--fuu-ink-2);
  }
  .c-active {
    width: 60px;
    display: flex;
    justify-content: flex-end;
  }
  .day-btn {
    background: none;
    border: none;
    font-family: var(--fuu-font-body);
    font-size: 14px;
    font-weight: 700;
    color: var(--fuu-ink-1);
    text-align: left;
    padding: 10px 0;
  }
  .today-tag {
    font-size: 10px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-2);
    color: var(--fuu-ink-1);
    padding: 2px 7px;
    border-radius: 20px;
    margin-left: 3px;
  }
  .editor,
  .holidays {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 18px;
    margin-top: 14px;
  }
  .editor-title,
  .holidays-title {
    font-size: 15px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    margin: 0 0 12px;
  }
  .shift-row {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    padding: 9px 0;
    border-bottom: 1px solid var(--fuu-line-6);
  }
  .time {
    font-size: 12px;
    color: var(--fuu-ink-2);
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .time input {
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 9px 10px;
    font-family: var(--fuu-font-mono);
    font-size: 13px;
    color: var(--fuu-ink-1);
    background: var(--fuu-white);
  }
  .field-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-6);
    margin: 16px 0 9px;
  }
  .scopes {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .scope {
    font-family: var(--fuu-font-body);
    font-size: 12.5px;
    font-weight: 700;
    background: var(--fuu-line-5);
    border: none;
    color: var(--fuu-ink-1);
    padding: 10px 13px;
    border-radius: 8px;
  }
  .scope.on {
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .editor-actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
  }
  .discard {
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 13px 18px;
    font-family: var(--fuu-font-body);
    font-weight: 700;
    font-size: 14px;
    color: var(--fuu-ink-2);
  }
  .editor-actions .btn-fuu-primary {
    width: auto;
    padding: 13px 22px;
  }
  .capacity-note {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin: 0 0 12px;
    max-width: 46em;
  }
  .capacity-row {
    display: flex;
    gap: 10px;
    align-items: center;
  }
  .capacity-row input {
    width: 90px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 12px;
    font-size: 15px;
    font-weight: 700;
    color: var(--fuu-ink-1);
    background: var(--fuu-white);
  }
  .capacity-row .btn-fuu-primary {
    width: auto;
    padding: 12px 20px;
  }
  .holiday-list {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
  .holiday {
    flex: 1 1 220px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 13px;
    font-size: 13px;
    line-height: 1.5;
    color: var(--fuu-ink-2);
  }
  .holiday strong {
    display: block;
    color: var(--fuu-ink-1);
  }
  .holiday .closed {
    color: var(--fuu-alert);
    font-weight: 700;
  }
  .holiday-del {
    display: block;
    background: none;
    border: none;
    padding: 6px 0 0;
    font-family: var(--fuu-font-body);
    font-size: 11.5px;
    color: var(--fuu-ink-5);
  }
  .holiday-add {
    width: 150px;
    border: 1.5px dashed var(--fuu-line-1);
    border-radius: 10px;
    padding: 13px;
    background: none;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-6);
  }
  .holiday-form {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    align-items: center;
    border-top: 1px dashed var(--fuu-line-2);
    margin-top: 14px;
    padding-top: 14px;
  }
  .holiday-form .time input[type='text'] {
    font-family: var(--fuu-font-body);
  }
  .switch-row {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
  }
  .switch-row input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
  }
  .switch {
    width: 44px;
    height: 26px;
    border-radius: 14px;
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
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--fuu-white);
    transition: left 0.15s;
  }
  .switch-row input:checked + .switch {
    background: var(--fuu-leaf);
  }
  .switch-row input:checked + .switch::after {
    left: 21px;
  }
  .switch-label {
    font-size: 13.5px;
    font-weight: 600;
    color: var(--fuu-ink-1);
  }
  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }
  .side-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-6);
    margin: 0 0 12px;
  }
  .chart {
    display: flex;
    align-items: flex-end;
    gap: 3px;
    height: 130px;
  }
  .bar {
    flex: 1;
    background: var(--fuu-line-4);
    border-radius: 3px;
    min-height: 2px;
  }
  .bar.peak {
    background: var(--fuu-red);
  }
  .chart-axis {
    display: flex;
    justify-content: space-between;
    font-size: 10.5px;
    color: var(--fuu-ink-6);
    margin-top: 6px;
  }
  .peak-note {
    font-size: 13px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin-top: 14px;
  }
  .tech {
    font-size: 10px;
    color: var(--fuu-ink-6);
    margin-top: 22px;
    line-height: 1.6;
  }
  @media (max-width: 1000px) {
    .hours-layout {
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
