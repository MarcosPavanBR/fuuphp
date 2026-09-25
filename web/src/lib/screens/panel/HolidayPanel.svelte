<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';

  // Feriados e datas especiais da tela 11.4 (holiday_overrides): fechado o
  // dia todo ou um horário só daquele dia. Separado de HoursScreen.svelte na
  // auditoria ARQ-02.
  //
  // holidays: as datas cadastradas (vêm de restaurants/hours.php).
  // onChanged: salvou ou removeu -- a tela recarrega.
  let { holidays = [], onChanged } = $props();

  let form = $state(null);
  let saving = $state(false);

  function hhmm(t) {
    return t ? String(t).slice(0, 5) : '';
  }

  function dayLabel(iso) {
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
  }

  function newForm() {
    form = { day: '', closed: true, opens: '18:00', closes: '23:00', last_order: '22:30', note: '' };
  }

  async function save() {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.day)) {
      toastr.warning('Escolha a data.');
      return;
    }
    saving = true;
    try {
      await api.post('/restaurants/holiday.php', {
        token: staffToken(),
        body: {
          day: form.day,
          closed: form.closed,
          opens: form.closed ? null : form.opens,
          closes: form.closed ? null : form.closes,
          last_order: form.closed ? null : form.last_order,
          note: form.note.trim() === '' ? null : form.note.trim(),
        },
      });
      toastr.success('Data especial salva.');
      form = null;
      await onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar a data.');
    } finally {
      saving = false;
    }
  }

  async function remove(holiday) {
    try {
      await api.post('/restaurants/holiday.php', {
        token: staffToken(),
        body: { action: 'remove', id: holiday.id },
      });
      await onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra remover a data.');
    }
  }
</script>

<div class="holidays">
  <p class="holidays-title">Feriados e datas especiais</p>
  <div class="holiday-list">
    {#each holidays as holiday (holiday.id)}
      <div class="holiday">
        <strong>{dayLabel(holiday.day)}{holiday.note ? ` · ${holiday.note}` : ''}</strong>
        <span class:closed={holiday.closed}>
          {holiday.closed
            ? 'fechado o dia todo'
            : `só ${hhmm(holiday.opens)} – ${hhmm(holiday.closes)}`}
        </span>
        <button type="button" class="holiday-del" onclick={() => remove(holiday)}>
          <i class="bi bi-trash"></i> remover
        </button>
      </div>
    {/each}
    <button type="button" class="holiday-add" onclick={newForm}>
      <i class="bi bi-plus-lg"></i> Adicionar
    </button>
  </div>

  {#if form}
    <div class="holiday-form">
      <label class="time">data <input type="date" bind:value={form.day} /></label>
      <label class="time">nome <input type="text" maxlength="120" placeholder="Nossa Senhora" bind:value={form.note} /></label>
      <label class="switch-row">
        <input type="checkbox" bind:checked={form.closed} />
        <span class="switch"></span>
        <span class="switch-label">Fechado o dia todo</span>
      </label>
      {#if !form.closed}
        <label class="time">abre <input type="time" bind:value={form.opens} /></label>
        <label class="time">fecha <input type="time" bind:value={form.closes} /></label>
        <label class="time">último pedido <input type="time" bind:value={form.last_order} /></label>
      {/if}
      <div class="editor-actions">
        <button type="button" class="discard" onclick={() => (form = null)}>Cancelar</button>
        <button type="button" class="btn-fuu-primary" disabled={saving} onclick={save}>Salvar data</button>
      </div>
    </div>
  {/if}
</div>

<style>
  .holidays {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 18px;
    margin-top: 14px;
  }
  .holidays-title {
    font-size: 15px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    margin: 0 0 12px;
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
</style>
