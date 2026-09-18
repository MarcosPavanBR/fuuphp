<script>
  import { api, ApiError } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { courierToken } from '../../courierSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Telas 9.1, 9.2 e 9.4 — escolher a baixa, gerar o código e ver o saldo
  // zerado.
  //
  // "Guardamos só o hash do código; o valor é imutável depois de gerado."
  // Por isso o código aparece UMA vez, aqui, e a tela avisa: recarregar não
  // traz ele de volta, porque o servidor não tem como mostrar de novo.
  let { me, onDone } = $props();

  let step = $state('choose');
  let intent = $state(null);
  let code = $state(null);
  let restaurantId = $state('');
  let restaurants = $state([]);
  let busy = $state(false);
  let now = $state(Date.now());

  $effect(() => {
    const t = setInterval(() => (now = Date.now()), 1000);
    return () => clearInterval(t);
  });

  // As lojas onde ele pode baixar são as das corridas dele -- não a lista
  // inteira da praça. O nome vem junto do próprio livro de lançamentos, numa
  // consulta só.
  $effect(() => {
    let alive = true;
    api
      .get('/couriers/earnings.php', { token: courierToken() })
      .then((data) => {
        if (!alive) return;
        const seen = new Map();
        for (const entry of data.entries) {
          if (entry.restaurant_id && !seen.has(entry.restaurant_id)) {
            seen.set(entry.restaurant_id, entry.restaurant_name ?? 'Loja');
          }
        }
        restaurants = [...seen].map(([id, name]) => ({ id, name }));
        if (restaurants.length > 0) restaurantId = restaurants[0].id;
      })
      .catch(() => {});
    return () => (alive = false);
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  let secondsLeft = $derived.by(() => {
    if (!intent?.expires_at) return null;
    const end = parsePgTimestamp(intent.expires_at);
    return end ? Math.max(0, Math.floor((end.getTime() - now) / 1000)) : null;
  });
  let countdown = $derived(
    secondsLeft === null
      ? null
      : `${Math.floor(secondsLeft / 60)}:${String(secondsLeft % 60).padStart(2, '0')}`
  );

  async function generate(method) {
    if (restaurantId === '' || busy) return;
    busy = true;
    try {
      const data = await api.post('/couriers/settle_intent.php', {
        token: courierToken(),
        body: { restaurant_id: restaurantId, method },
      });
      intent = data.intent;
      code = data.code;
      step = 'code';
    } catch (e) {
      const known = e instanceof ApiError ? e.code : null;
      toastr.error(
        known === 'intent_already_open'
          ? 'Você já tem uma baixa em andamento. Termine ou espere expirar.'
          : (e.message ?? 'Não deu pra gerar o código.')
      );
    } finally {
      busy = false;
    }
  }
</script>

<div class="settle">
  <button type="button" class="back" onclick={onDone} aria-label="Voltar">
    <i class="bi bi-arrow-left"></i>
  </button>

  {#if step === 'choose'}
    <h1 class="fuu-display">Baixar a espécie</h1>

    <div class="amount-card">
      <p class="k">VOCÊ TEM EM MÃOS</p>
      <p class="huge fuu-display">{money(me?.balances?.cash ?? 0)}</p>
      <p class="sub">
        Teto de {money(me?.balances?.cash_ceiling ?? 0)} · prazo {me?.balances?.settle_deadline ?? '—'}
      </p>
    </div>

    <p class="k label">ENTREGAR NA LOJA</p>
    {#if restaurants.length === 0}
      <p class="empty">Nenhuma loja das suas corridas ainda.</p>
    {:else}
      <div class="stores">
        {#each restaurants as store (store.id)}
          <button
            type="button"
            class="store"
            class:on={restaurantId === store.id}
            onclick={() => (restaurantId = store.id)}
          >
            <i class={`bi ${restaurantId === store.id ? 'bi-record-circle' : 'bi-circle'}`}></i>
            {store.name}
          </button>
        {/each}
      </div>
    {/if}

    <button
      type="button"
      class="btn-fuu-primary w-100 big"
      disabled={busy || restaurantId === ''}
      onclick={() => generate('in_person')}
    >
      Gerar código de baixa
    </button>
    <p class="note">
      O valor fica travado no código. A loja conta o dinheiro e digita o código no painel dela — os dois
      lançamentos nascem juntos.
    </p>
  {:else}
    <h1 class="fuu-display">Mostre este código</h1>
    <p class="sub center">Vale {money(intent.amount)}, por {countdown ?? '—'}.</p>

    <div class="code-box fuu-mono">{code}</div>

    <div class="steps">
      <p><strong>1.</strong> Entregue {money(intent.amount)} em dinheiro no caixa.</p>
      <p><strong>2.</strong> O atendente conta e digita este código no painel.</p>
      <p><strong>3.</strong> Seu saldo zera na hora, e o teto volta a ficar livre.</p>
    </div>

    <div class="warn">
      Guardamos só o hash deste código — ele não pode ser mostrado de novo. Se sumir, gere outro depois
      que este expirar.
    </div>

    <button type="button" class="btn-fuu-primary w-100 big" onclick={onDone}>Voltar ao início</button>
  {/if}
</div>

<style>
  .settle {
    padding: 18px;
  }
  .back {
    background: none;
    border: none;
    font-size: 20px;
    color: var(--fuu-ink-1);
    padding: 0;
  }
  h1 {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 14px 0 16px;
    color: var(--fuu-ink-1);
  }
  .amount-card {
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 18px;
    text-align: center;
    background: var(--fuu-line-6);
  }
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .label {
    margin: 20px 0 10px;
  }
  .huge {
    font-size: 42px;
    font-weight: 800;
    letter-spacing: -0.03em;
    margin: 4px 0 0;
    color: var(--fuu-red);
  }
  .sub {
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    margin: 8px 0 0;
  }
  .sub.center {
    text-align: center;
    margin-top: 0;
  }
  .stores {
    display: flex;
    flex-direction: column;
    gap: 9px;
  }
  .store {
    display: flex;
    align-items: center;
    gap: 11px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 15px;
    background: var(--fuu-white);
    font-family: var(--fuu-font-body);
    font-size: 15px;
    color: var(--fuu-ink-2);
    text-align: left;
    min-height: var(--fuu-tap-operator);
  }
  .store.on {
    border: 2px solid var(--fuu-red);
    background: var(--fuu-red-tint);
    color: var(--fuu-ink-1);
    font-weight: 600;
  }
  .store i {
    color: var(--fuu-ink-5);
  }
  .store.on i {
    color: var(--fuu-red);
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  .big {
    margin-top: 16px;
    min-height: var(--fuu-tap-operator);
    font-size: 16px;
  }
  .note {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    line-height: 1.55;
    margin-top: 12px;
  }
  .code-box {
    font-size: 46px;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-align: center;
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
    border-radius: 14px;
    padding: 24px 10px;
    margin: 18px 0;
  }
  .steps p {
    font-size: 13.5px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin: 0 0 8px;
  }
  .warn {
    border: 1px solid var(--fuu-wait-text);
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
    border-radius: 10px;
    padding: 13px;
    font-size: 12px;
    line-height: 1.55;
    margin-top: 12px;
  }
</style>
