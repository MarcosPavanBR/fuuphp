<script>
  import { api } from '../api.js';
  import { toastr } from '../toastr.js';
  import { parsePgTimestamp } from '../datetime.js';

  // Tela 14.4 — "Quando você quer receber?"
  //
  // "Faixa com vaga limitada pela capacidade real da cozinha, não pelo
  // relógio. Cobrança só no início do preparo — agendar sem cobrar evita
  // estorno em massa se a loja não abrir."
  //
  // As faixas vêm do horário declarado pela loja (11.4) e a vaga vem de
  // `delivery_slots`, onde o CHECK (taken <= capacity) é quem garante o
  // "3 vagas" quando duas pessoas apertam ao mesmo tempo.
  let { restaurantId, prepMinutes = null, onContinue } = $props();

  let data = $state(null);
  let day = $state(null);
  let chosen = $state(null);

  $effect(() => {
    api
      .get('/orders/slots.php', { auth: true, query: { restaurant_id: restaurantId } })
      .then((res) => {
        data = res;
        day = res.days.find((d) => d.has_slots)?.day ?? res.days[0]?.day ?? null;
      })
      .catch((e) => {
        toastr.error(e.message ?? 'Não deu pra ver os horários de entrega.');
        // Sem as faixas, a tela não trava o pedido: "assim que ficar pronto"
        // continua valendo, que é o caminho normal.
        data = { scheduling_enabled: false, days: [], slots: {} };
      });
  });

  function hhmm(iso) {
    const d = parsePgTimestamp(iso);
    return d ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '--:--';
  }

  // "Chega entre 16:50 e 17:10": preparo informado pela loja + a janela de
  // viagem que o acompanhamento também usa.
  let asap = $derived.by(() => {
    const prep = prepMinutes ?? data?.prep_minutes ?? 30;
    const now = Date.now();
    const fmt = (ms) => new Date(ms).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    return `${fmt(now + prep * 60000)} e ${fmt(now + (prep + 20) * 60000)}`;
  });

  let slots = $derived(day && data ? (data.slots[day] ?? []) : []);
</script>

<div class="schedule-screen">
  <header class="top">
    <h1>Quando você quer receber?</h1>
  </header>

  {#if data === null}
    <p class="loading">Vendo os horários da loja…</p>
  {:else}
    <div class="body">
      <button type="button" class="choice" class:on={chosen === null} onclick={() => (chosen = null)}>
        <i class="bi bi-lightning-charge-fill now"></i>
        <span class="choice-text">
          <span class="choice-title">Assim que ficar pronto</span>
          <span class="choice-note">Chega entre {asap}</span>
        </span>
        {#if chosen === null}<i class="bi bi-check-circle-fill check"></i>{/if}
      </button>

      {#if data.scheduling_enabled}
        <button
          type="button"
          class="choice"
          class:on={chosen !== null}
          onclick={() => (chosen = chosen ?? slots.find((s) => s.free > 0) ?? null)}
        >
          <i class="bi bi-calendar-check later"></i>
          <span class="choice-text">
            <span class="choice-title">Agendar</span>
            <span class="choice-note">Escolha dia e faixa de horário</span>
          </span>
          {#if chosen !== null}<i class="bi bi-check-circle-fill check"></i>{/if}
        </button>

        <p class="section-label">DIA</p>
        <div class="days">
          {#each data.days as option (option.day)}
            <button
              type="button"
              class="day"
              class:on={day === option.day}
              class:empty={!option.has_slots}
              onclick={() => {
                day = option.day;
                chosen = null;
              }}
            >
              <span class="day-label">{option.label}</span>
              <span class="day-number">{option.number}</span>
            </button>
          {/each}
        </div>

        <p class="section-label">FAIXA DE ENTREGA</p>
        <div class="slots">
          {#each slots as slot (slot.start)}
            <button
              type="button"
              class="slot"
              class:on={chosen?.start === slot.start}
              class:full={slot.free === 0}
              disabled={slot.free === 0}
              onclick={() => (chosen = slot)}
            >
              <i class={`bi ${chosen?.start === slot.start ? 'bi-record-circle' : 'bi-circle'}`}></i>
              <span class="slot-time">{hhmm(slot.start)} – {hhmm(slot.end)}</span>
              <span class="slot-free" class:none={slot.free === 0} class:few={slot.free > 0 && slot.free <= 3}>
                {slot.free === 0 ? 'esgotado' : `${slot.free} ${slot.free === 1 ? 'vaga' : 'vagas'}`}
              </span>
            </button>
          {:else}
            <p class="empty-note">
              A loja não tem faixa livre nesse dia — escolha outro, ou receba assim que ficar pronto.
            </p>
          {/each}
        </div>

        <div class="note">
          <i class="bi bi-credit-card"></i>
          <p>
            Você pode cancelar sem taxa até {Math.round((data.free_cancel_minutes ?? 60) / 60)} h antes da
            faixa — enquanto a cozinha não começar, cancelar é de graça.
            <!-- O mock promete "cobramos quando a loja começa a preparar".
                 Isso é literalmente verdade em dinheiro e maquininha, que só
                 pagam na entrega. Nos métodos pré-pagos a cobrança acontece
                 agora, e a tela diz isso em vez de prometer o contrário. -->
            <strong>Dinheiro e maquininha só pagam na entrega; cartão e Pix cobram agora.</strong>
          </p>
        </div>
      {:else}
        <p class="empty-note">
          Essa loja ainda não aceita pedido agendado — ela é quem declara quantos pedidos cabem em cada
          faixa, na tela de horário.
        </p>
      {/if}
    </div>

    <div class="footer">
      <button type="button" class="btn-fuu-primary w-100" onclick={() => onContinue(chosen)}>
        {chosen
          ? `Agendar para ${hhmm(chosen.start)} – ${hhmm(chosen.end)}`
          : 'Receber assim que ficar pronto'}
      </button>
    </div>
  {/if}
</div>

<style>
  .schedule-screen {
    display: flex;
    flex-direction: column;
    flex: 1;
    background: var(--fuu-paper);
  }
  .top {
    background: var(--fuu-white);
    border-bottom: 1px solid var(--fuu-line-3);
    padding: 14px 18px;
  }
  .top h1 {
    font-size: 17px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .body {
    padding: 16px 18px 20px;
    flex: 1;
  }
  .loading {
    text-align: center;
    color: var(--fuu-ink-5);
    padding: 40px 0;
  }
  .choice {
    display: flex;
    align-items: center;
    gap: 13px;
    width: 100%;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 15px;
    margin-bottom: 10px;
    text-align: left;
    min-height: var(--fuu-tap-customer);
  }
  .choice.on {
    border: 2px solid var(--fuu-red);
  }
  .choice i {
    font-size: 20px;
  }
  .now {
    color: var(--fuu-red);
  }
  .later {
    color: var(--fuu-ink-6);
  }
  .check {
    color: var(--fuu-red);
    margin-left: auto;
  }
  .choice-text {
    flex: 1;
    min-width: 0;
  }
  .choice-title {
    display: block;
    font-weight: 800;
    font-size: 14.5px;
    color: var(--fuu-ink-1);
  }
  .choice-note {
    display: block;
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    margin-top: 2px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-6);
    margin: 18px 0 9px;
  }
  .days {
    display: flex;
    gap: 8px;
  }
  .day {
    flex: 1;
    text-align: center;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 11px;
    padding: 12px 0;
  }
  .day.on {
    background: var(--fuu-ink-1);
    border-color: var(--fuu-ink-1);
  }
  .day.empty {
    opacity: 0.5;
  }
  .day-label {
    display: block;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--fuu-ink-6);
  }
  .day.on .day-label {
    color: var(--fuu-white);
    opacity: 0.8;
  }
  .day-number {
    display: block;
    font-size: 16px;
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .day.on .day-number {
    color: var(--fuu-white);
  }
  .slots {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .slot {
    display: flex;
    align-items: center;
    gap: 11px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 13px;
    font-size: 13.5px;
    text-align: left;
    min-height: var(--fuu-tap-customer);
  }
  .slot.on {
    background: var(--fuu-red-tint);
    border: 2px solid var(--fuu-red);
  }
  .slot.full {
    opacity: 0.5;
  }
  .slot i {
    color: var(--fuu-line-1);
  }
  .slot.on i {
    color: var(--fuu-red);
  }
  .slot-time {
    flex: 1;
    font-weight: 600;
    color: var(--fuu-ink-1);
  }
  .slot.on .slot-time {
    font-weight: 700;
  }
  .slot-free {
    font-size: 11.5px;
    color: var(--fuu-ink-2);
  }
  .slot-free.few {
    color: var(--fuu-leaf);
    font-weight: 700;
  }
  .slot-free.none {
    color: var(--fuu-ink-5);
  }
  .empty-note {
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    line-height: 1.6;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 14px;
    margin: 0;
  }
  .note {
    display: flex;
    gap: 10px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 13px;
    margin-top: 14px;
  }
  .note i {
    color: var(--fuu-wait-text);
    margin-top: 2px;
  }
  .note p {
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    line-height: 1.55;
    margin: 0;
  }
  .note strong {
    color: var(--fuu-ink-1);
    display: block;
    margin-top: 4px;
  }
  .footer {
    padding: 14px 18px 20px;
    background: var(--fuu-white);
    box-shadow: 0 -6px 18px rgba(0, 0, 0, 0.06);
  }
</style>
