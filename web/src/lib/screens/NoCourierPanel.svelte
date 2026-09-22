<script>
  import { api, ApiError } from '../api.js';
  import { toastr } from '../toastr.js';

  // Tela 15.1 — "Sem entregador disponível".
  //
  // "O momento que mais gera ticket e ninguém desenha: em vez de 'aguarde',
  // três saídas concretas e a promessa escrita de cancelamento automático
  // com devolução integral."
  //
  // Nenhum número aqui é calculado no navegador: prazo, tempo de espera,
  // valor de volta e quais saídas existem vêm de orders/dispatch_status.php,
  // porque é o servidor que vai cumprir cada uma delas. O relógio é a única
  // coisa que corre local, entre uma consulta e outra.
  let { dispatch, onAction, onCancel } = $props();

  let busy = $state(null);
  let tick = $state(Date.now());

  // O contador precisa andar entre as consultas (a cada 8 s), senão o "há 6
  // min" fica congelado enquanto a pessoa olha pra ele. O ponto de partida é
  // reancorado a cada resposta do servidor -- quem manda no tempo é ele; o
  // relógio local só preenche o intervalo.
  let anchor = $state({ base: 0, at: Date.now() });
  $effect(() => {
    anchor = { base: dispatch.waiting_seconds ?? 0, at: Date.now() };
  });
  $effect(() => {
    const id = setInterval(() => (tick = Date.now()), 1000);
    return () => clearInterval(id);
  });

  let waiting = $derived(anchor.base + Math.max(0, Math.floor((tick - anchor.at) / 1000)));
  let timeout = $derived(dispatch.timeout_seconds ?? 900);
  let progress = $derived(Math.min(100, Math.round((waiting * 100) / timeout)));

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  // "Procurando há 6 min" -- abaixo de um minuto, minuto arredondado mentiria
  // ("há 0 min"), então os primeiros 60 s aparecem em segundos.
  let waitingLabel = $derived(
    waiting < 60 ? `${waiting} s` : `${Math.floor(waiting / 60)} min`
  );
  let autoCancelLabel = $derived(`${Math.max(1, Math.round(timeout / 60))} min`);

  async function act(action) {
    if (busy) return;
    busy = action;
    try {
      const data = await api.post('/orders/dispatch_action.php', {
        auth: true,
        body: { order_id: dispatch.order.id, action },
      });
      if (action === 'boost') {
        toastr.success(`${money(data.boosted)} a mais no frete. Os entregadores já estão vendo.`);
      } else {
        toastr.success(
          data.refunded_amount > 0
            ? `Retirada confirmada. ${money(data.refunded_amount)} da entrega voltam pra você.`
            : 'Retirada confirmada — a entrega saiu da conta.'
        );
      }
      onAction(data);
    } catch (e) {
      const code = e instanceof ApiError ? e.code : null;
      toastr.error(
        code === 'courier_assigned'
          ? 'Um entregador acabou de aceitar — a corrida já tem dono.'
          : (e.message ?? 'Não deu pra fazer isso agora.')
      );
      if (code === 'courier_assigned') onAction(null);
    } finally {
      busy = null;
    }
  }
</script>

<div class="dispatch-alert">
  <div class="alert-head">
    <i class="bi bi-scooter"></i>
    <div>
      <p class="eyebrow">PROCURANDO ENTREGADOR</p>
      <p class="headline">
        {dispatch.harder_than_usual ? 'Está mais difícil que o normal' : 'Chamando quem está por perto'}
      </p>
    </div>
  </div>
  <!-- O mock explica o motivo ("Chuva na região e muitos pedidos ao mesmo
       tempo"). Não há de onde tirar isso: não existe clima nem densidade de
       pedidos na especificação, e inventar uma desculpa é pior que não dar
       nenhuma. O que é verdade e a pessoa quer saber é há quanto tempo. -->
  <p class="alert-text">
    Ninguém aceitou a corrida ainda. Procurando há <strong>{waitingLabel}</strong>{#if dispatch.search}
      , agora num raio de <strong>{Number(dispatch.search.radius_km) >= 50 ? 'toda a cidade' : `${Number(dispatch.search.radius_km).toLocaleString('pt-BR')} km`}</strong>{#if Number(dispatch.search.surge) > 0}
        · com bônus pro entregador por nossa conta{/if}{/if}.
  </p>
  <div class="bar"><div class="fill" style:width={`${progress}%`}></div></div>
</div>

<p class="section-label">O QUE VOCÊ PODE FAZER</p>

<div class="option boost" class:off={!dispatch.options.boost.available}>
  <div class="option-head">
    <i class="bi bi-plus-circle-fill"></i>
    <div class="option-text">
      <p class="option-title">Adicionar {money(dispatch.options.boost.amount)} ao frete</p>
      <!-- O mock promete "costuma achar entregador em 2 min". Sem histórico
           de despacho pra medir, a promessa honesta é a mecânica: o dinheiro
           aparece na oferta que o entregador vê. -->
      <p class="option-note">
        {dispatch.options.boost.reason ?? 'O valor a mais aparece na hora para quem está com o app aberto'}
      </p>
    </div>
  </div>
  {#if dispatch.options.boost.available}
    <button type="button" class="btn-fuu-primary w-100 boost-btn" disabled={busy !== null} onclick={() => act('boost')}>
      {busy === 'boost' ? 'Turbinando…' : 'Turbinar o frete'}
    </button>
  {/if}
</div>

{#if dispatch.options.pickup.available}
  <button type="button" class="option tappable" disabled={busy !== null} onclick={() => act('pickup')}>
    <i class="bi bi-bag-check pickup-icon"></i>
    <div class="option-text">
      <p class="option-title">Retirar na loja</p>
      <p class="option-note">
        {#if dispatch.options.pickup.refund > 0}
          Devolvemos os {money(dispatch.options.pickup.refund)} da entrega
        {:else}
          A entrega sai da conta — você não paga frete nenhum
        {/if}
        <!-- A distância só entra quando a loja tem coordenada cadastrada:
             "1,2 km de você" é o que decide se a pessoa sai de casa. -->
        {#if dispatch.options.pickup.distance_km}
          · {String(dispatch.options.pickup.distance_km).replace('.', ',')} km de você
        {/if}
      </p>
    </div>
    <i class="bi bi-chevron-right chev"></i>
  </button>
{/if}

<div class="option">
  <i class="bi bi-clock wait-icon"></i>
  <div class="option-text">
    <p class="option-title">Continuar esperando</p>
    <!-- "Avisamos assim que alguém aceitar" seria push (Fase 7.2), que não
         existe. Esta tela se atualiza sozinha enquanto está aberta -- é o que
         dá pra prometer hoje. -->
    <p class="option-note">Esta tela avisa sozinha assim que alguém aceitar</p>
  </div>
</div>

<div class="cancel-line">
  <button type="button" class="cancel-link" onclick={onCancel}>Cancelar e receber tudo de volta</button>
</div>

<p class="promise">
  Passados {autoCancelLabel} sem entregador, cancelamos sozinhos e devolvemos o valor integral —
  inclusive a comida, que a loja recebe por nossa conta.
</p>

<style>
  .dispatch-alert {
    border: 2px solid var(--fuu-wait-tint);
    background: var(--fuu-wait-bg);
    border-radius: var(--fuu-radius-card);
    padding: 16px;
  }
  .alert-head {
    display: flex;
    align-items: center;
    gap: 11px;
  }
  .alert-head i {
    font-size: 22px;
    color: var(--fuu-wait-text);
  }
  .eyebrow {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--fuu-wait-text);
    margin: 0;
  }
  .headline {
    font-size: 14.5px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    margin: 2px 0 0;
  }
  .alert-text {
    font-size: 12.5px;
    line-height: 1.6;
    color: var(--fuu-wait-text);
    margin: 10px 0 0;
  }
  .bar {
    height: 7px;
    background: var(--fuu-wait-tint);
    border-radius: 5px;
    margin-top: 12px;
    overflow: hidden;
  }
  .fill {
    height: 7px;
    background: var(--fuu-wait-text);
    transition: width 0.9s linear;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-5);
    margin: 18px 0 9px;
  }
  .option {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    box-shadow: var(--fuu-shadow-card);
    padding: 15px;
    margin-bottom: 10px;
    text-align: left;
  }
  .option.boost {
    display: block;
    border: 2px solid var(--fuu-red);
  }
  .option.boost.off {
    border: 1px solid var(--fuu-line-3);
    opacity: 0.72;
  }
  .option-head {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .option i {
    font-size: 20px;
    flex: none;
  }
  .option.boost i {
    color: var(--fuu-red);
  }
  .option.boost.off i {
    color: var(--fuu-ink-5);
  }
  .pickup-icon {
    color: var(--fuu-leaf-dark);
  }
  .wait-icon {
    color: var(--fuu-ink-5);
  }
  .option-text {
    flex: 1;
    min-width: 0;
  }
  .option-title {
    font-size: 14px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    margin: 0;
  }
  .option-note {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    line-height: 1.5;
    margin: 2px 0 0;
  }
  .chev {
    color: var(--fuu-ink-5);
    font-size: 15px;
  }
  .tappable {
    min-height: var(--fuu-tap-customer);
  }
  .boost-btn {
    margin-top: 12px;
    padding: 12px;
    font-size: 13.5px;
    min-height: var(--fuu-tap-customer);
  }
  .cancel-line {
    text-align: center;
    margin-top: 16px;
  }
  .cancel-link {
    background: none;
    border: none;
    border-bottom: 1px solid var(--fuu-red-tint-2);
    padding: 0 0 2px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-alert);
  }
  .promise {
    font-size: 11px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    margin: 12px 0 0;
    text-align: center;
  }
</style>
