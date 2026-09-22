<script>
  import { api, ApiError } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { courierToken } from '../../courierSession.svelte.js';

  // Tela 8.2 — "Tudo que decide o aceite em uma tela: ganho, distância, forma
  // de pagamento e troco."
  //
  // O timer de 15 s é o tempo de DECISÃO, não a validade da oferta: o
  // servidor segura a corrida por 5 min (lib/dispatch/dispatch.php explica por quê).
  // Quando os 15 s acabam a oferta some DESTA tela e vai pro fim da fila --
  // ela continua existindo pros outros, o que é o comportamento certo:
  // ninguém perde corrida porque este entregador ficou olhando.
  let { offers, onAccepted } = $props();

  const DECIDE_SECONDS = 15;

  let busyId = $state(null);
  let shownAt = $state(new Map());
  let now = $state(Date.now());

  $effect(() => {
    const t = setInterval(() => (now = Date.now()), 250);
    return () => clearInterval(t);
  });

  // Marca quando cada oferta apareceu na tela, pra contar os 15 s a partir
  // dali -- e não a partir de quando o servidor criou a corrida.
  $effect(() => {
    const next = new Map(shownAt);
    let changed = false;
    for (const offer of offers) {
      if (!next.has(offer.offer_id)) {
        next.set(offer.offer_id, Date.now());
        changed = true;
      }
    }
    if (changed) shownAt = next;
  });

  function secondsLeft(offer) {
    const since = shownAt.get(offer.offer_id);
    if (!since) return DECIDE_SECONDS;
    return Math.max(0, DECIDE_SECONDS - Math.floor((now - since) / 1000));
  }

  let visible = $derived(offers.filter((o) => secondsLeft(o) > 0));

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  const METHOD = {
    mp_card: 'Cartão · já pago',
    pix_auto: 'Pix · já pago',
    pix_manual: 'Pix · já pago',
    cash: 'DINHEIRO',
    pos_machine: 'MAQUININHA',
  };

  async function accept(offer) {
    busyId = offer.offer_id;
    try {
      const data = await api.post('/couriers/accept_offer.php', {
        token: courierToken(),
        body: { offer_id: offer.offer_id },
      });
      toastr.success('Corrida sua. Bora!');
      onAccepted(data);
    } catch (e) {
      const code = e instanceof ApiError ? e.code : null;
      toastr.error(
        code === 'offer_taken'
          ? 'Outro entregador pegou essa primeiro.'
          : code === 'cash_blocked'
            ? 'Seu caixa está bloqueado: baixe a espécie pra pegar corrida em dinheiro.'
            : (e.message ?? 'Não deu pra aceitar.')
      );
      if (code === 'offer_taken') onAccepted(null);
    } finally {
      busyId = null;
    }
  }
</script>

<div class="offers">
  {#if visible.length === 0}
    <div class="waiting">
      <i class="bi bi-hourglass-split"></i>
      <p>Nenhuma corrida agora. Fique por perto que a gente chama.</p>
    </div>
  {:else}
    {#each visible as offer (offer.offer_id)}
      {@const left = secondsLeft(offer)}
      <article class="offer">
        <div class="timer-bar">
          <div class="fill" style={`width:${(left / DECIDE_SECONDS) * 100}%`}></div>
        </div>

        <div class="earn">
          <p class="k">VOCÊ GANHA</p>
          <p class="v fuu-display">{money(Number(offer.fee) + Number(offer.bonus))}</p>
          {#if Number(offer.bonus) > 0}
            <!-- Fase 15: o bônus aparece separado -- é turbo do cliente e/ou
                 surge das rodadas, e quem aceita quer saber que ele existe. -->
            <p class="bonus">inclui {money(offer.bonus)} de bônus</p>
          {/if}
        </div>

        <div class="grid">
          <div>
            <p class="k">DISTÂNCIA</p>
            <p class="s">{offer.distance_km ? `${offer.distance_km} km` : '—'}</p>
          </div>
          <div>
            <p class="k">PAGAMENTO</p>
            <p class="s" class:cash={offer.payment_method === 'cash'}>
              {METHOD[offer.payment_method] ?? offer.payment_method}
            </p>
          </div>
        </div>

        <p class="from">{offer.restaurant_name}</p>
        <p class="to">
          {offer.street ?? 'Endereço do cliente'}{offer.number ? `, ${offer.number}` : ''}
          {#if offer.neighborhood}· {offer.neighborhood}{/if}
        </p>

        {#if offer.payment_method === 'cash'}
          <div class="cash-note">
            Receber {money(offer.total)} em dinheiro
            {#if offer.change_for}· troco para {money(offer.change_for)}{/if}
          </div>
        {/if}

        <button
          type="button"
          class="btn-fuu-primary w-100 take"
          disabled={busyId === offer.offer_id}
          onclick={() => accept(offer)}
        >
          {busyId === offer.offer_id ? 'Pegando…' : `Aceitar · ${left}s`}
        </button>
      </article>
    {/each}
  {/if}
</div>

<style>
  .bonus {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--fuu-leaf-dark);
    margin: 2px 0 0;
  }
  .offers {
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  .waiting {
    text-align: center;
    color: var(--fuu-ink-4);
    padding: 50px 20px;
  }
  .waiting i {
    font-size: 34px;
    color: var(--fuu-line-3);
  }
  .waiting p {
    font-size: 14px;
    margin: 12px 0 0;
  }
  .offer {
    border: 2px solid var(--fuu-ink-1);
    border-radius: 14px;
    padding: 16px;
    background: var(--fuu-white);
  }
  .timer-bar {
    height: 6px;
    background: var(--fuu-line-4);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 14px;
  }
  .timer-bar .fill {
    height: 6px;
    background: var(--fuu-red);
    transition: width 0.25s linear;
  }
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .earn .v {
    font-size: 40px;
    font-weight: 800;
    letter-spacing: -0.03em;
    margin: 2px 0 0;
    color: var(--fuu-leaf-dark);
  }
  .grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 14px;
  }
  .s {
    font-size: 16px;
    font-weight: 700;
    margin: 2px 0 0;
    color: var(--fuu-ink-1);
  }
  .s.cash {
    color: var(--fuu-red);
  }
  .from {
    font-size: 15px;
    font-weight: 700;
    margin: 14px 0 0;
    color: var(--fuu-ink-1);
  }
  .to {
    font-size: 13.5px;
    color: var(--fuu-ink-3);
    margin: 3px 0 0;
    line-height: 1.5;
  }
  .cash-note {
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
    border-radius: 9px;
    padding: 11px;
    margin-top: 12px;
    font-size: 13px;
    font-weight: 700;
  }
  .take {
    margin-top: 14px;
    min-height: var(--fuu-tap-operator);
    font-size: 16px;
  }
</style>
