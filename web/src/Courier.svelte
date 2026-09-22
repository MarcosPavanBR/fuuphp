<script>
  import { api } from './lib/api.js';
  import { toastr } from './lib/toastr.js';
  import {
    isCourierAuthenticated,
    courierToken,
    courierLogout,
  } from './lib/courierSession.svelte.js';
  import CourierLogin from './lib/screens/courier/CourierLogin.svelte';
  import CourierHome from './lib/screens/courier/CourierHome.svelte';
  import OfferList from './lib/screens/courier/OfferList.svelte';
  import RideScreen from './lib/screens/courier/RideScreen.svelte';
  import SettleScreen from './lib/screens/courier/SettleScreen.svelte';
  import EarningsScreen from './lib/screens/courier/EarningsScreen.svelte';
  import ApplyScreen from './lib/screens/courier/ApplyScreen.svelte';
  import IncidentScreen from './lib/screens/courier/IncidentScreen.svelte';
  import MachineScreen from './lib/screens/courier/MachineScreen.svelte';

  // App do entregador (Fase 8 + 9), numa página própria: o terceiro público
  // do projeto, no terceiro bundle. "Aplicativo separado, feito para ser
  // usado com uma mão, no sol, de moto parada."
  let logged = $state(isCourierAuthenticated());
  // 15.2 — quem ainda não é entregador entra por aqui. É uma tela do mesmo
  // app porque é onde a pessoa procura: "quero entregar" não está no app de
  // pedir comida.
  let applying = $state(false);
  let me = $state(null);
  let offers = $state([]);
  let tab = $state('home');
  let settling = $state(false);
  // 13.3 — o "Problema" da tela 8.5. Fica no mesmo nível de `settling`
  // porque é a mesma coisa: uma tela que toma conta do app até terminar.
  let reporting = $state(false);
  // 10.6 — a maquininha da loja em mãos. Mesmo padrão: toma a tela até sair.
  let machine = $state(false);

  // Mesma decisão do painel da loja: polling, não SSE, porque `php -S`
  // atende uma requisição por vez (ver README). 4 s porque corrida é
  // disputada -- esperar mais é perder corrida pro outro.
  const TICK_MS = 4000;

  async function pull() {
    try {
      const data = await api.get('/couriers/me.php', { token: courierToken() });
      me = data;
      // Sem turno aberto não existe vitrine de corridas -- o servidor
      // devolve 409 e a tela não deve tratar isso como falha.
      if (data.shift && !data.current_order) {
        const list = await api.get('/couriers/offers.php', { token: courierToken() });
        offers = list.offers;
      } else {
        offers = [];
      }
    } catch {
      // ciclo perdido não é evento: a tela segue com o último estado bom
    }
  }

  $effect(() => {
    if (!logged) return;
    pull();
    const t = setInterval(pull, TICK_MS);
    return () => clearInterval(t);
  });

  // Fase 15 — "a posição do entregador é escrita a cada 15 s"
  // (especificação, Parte II §7). É ela que decide quem enxerga a corrida em
  // cada rodada do despacho. Só com turno aberto, e fogo-e-esquece: nada
  // na tela espera por isto, então um GPS que não responde não trava botão
  // nenhum -- posição que não veio é só a última rodada a esperar.
  const POSITION_MS = 15000;
  function sendPosition() {
    if (!me?.shift || !navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
      (p) => {
        api
          .post('/couriers/position.php', {
            token: courierToken(),
            body: { lat: p.coords.latitude, lng: p.coords.longitude, heading: p.coords.heading ?? undefined },
          })
          .catch(() => {});
      },
      () => {},
      { timeout: 5000, maximumAge: 10000 }
    );
  }

  $effect(() => {
    if (!logged) return;
    const t = setInterval(sendPosition, POSITION_MS);
    return () => clearInterval(t);
  });

  function leave() {
    courierLogout();
    logged = false;
    me = null;
    offers = [];
    toastr.info('Você saiu.');
  }

  let ride = $derived(me?.current_order ?? null);
</script>

{#if applying}
  <ApplyScreen onBack={() => (applying = false)} />
{:else if !logged}
  <CourierLogin onLoggedIn={() => (logged = true)} onApply={() => (applying = true)} />
{:else}
  <div class="app">
    <header class="top">
      <span class="brand fuu-display">FUU</span>
      <span class="title">Entregador</span>
      <button type="button" class="leave" onclick={leave} aria-label="Sair">
        <i class="bi bi-box-arrow-right"></i>
      </button>
    </header>

    <main>
      {#if machine}
        <MachineScreen onBack={() => { machine = false; pull(); }} />
      {:else if settling}
        <SettleScreen {me} onDone={() => { settling = false; pull(); }} />
      {:else if reporting && ride}
        <IncidentScreen
          order={ride}
          onBack={() => (reporting = false)}
          onDone={() => {
            reporting = false;
            pull();
          }}
        />
      {:else if ride}
        <RideScreen
          order={ride}
          onProblem={() => (reporting = true)}
          onMachine={() => (machine = true)}
          onDone={() => {
            tab = 'home';
            pull();
          }}
        />
      {:else if tab === 'home'}
        <CourierHome {me} onRefresh={pull} onOpenSettle={() => (settling = true)} />
        {#if me?.shift}
          <OfferList {offers} onAccepted={() => pull()} />
        {/if}
      {:else}
        <EarningsScreen />
      {/if}
    </main>

    {#if !ride && !settling && !reporting && !machine}
      <nav class="tabs">
        <button type="button" class:on={tab === 'home'} onclick={() => (tab = 'home')}>
          <i class="bi bi-scooter"></i> Corridas
        </button>
        <button type="button" class:on={tab === 'earnings'} onclick={() => (tab = 'earnings')}>
          <i class="bi bi-journal-text"></i> Ganhos
        </button>
        <button type="button" onclick={() => (machine = true)}>
          <i class="bi bi-credit-card-2-front"></i> Maquininha
        </button>
      </nav>
    {/if}
  </div>
{/if}

<style>
  .app {
    max-width: 430px;
    margin: 0 auto;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: var(--fuu-paper);
  }
  .top {
    background: var(--fuu-white);
    border-bottom: 1px solid var(--fuu-line-3);
    padding: 12px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .brand {
    background: var(--fuu-red);
    color: var(--fuu-white);
    border-radius: 9px;
    padding: 4px 8px;
    font-size: 12px;
    font-weight: 800;
  }
  .title {
    font-weight: 800;
    font-size: 15px;
    color: var(--fuu-ink-1);
  }
  .leave {
    margin-left: auto;
    background: var(--fuu-line-5);
    border: none;
    border-radius: 10px;
    width: 38px;
    height: 38px;
    color: var(--fuu-ink-3);
    font-size: 17px;
  }
  main {
    flex: 1;
    overflow-y: auto;
  }
  .tabs {
    display: flex;
    background: var(--fuu-white);
    border-top: 1px solid var(--fuu-line-3);
    position: sticky;
    bottom: 0;
  }
  .tabs button {
    flex: 1;
    background: none;
    border: none;
    padding: 12px 0 14px;
    font-family: var(--fuu-font-body);
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-4);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    min-height: var(--fuu-tap-operator);
  }
  .tabs button i {
    font-size: 19px;
  }
  .tabs button.on {
    color: var(--fuu-red);
  }
</style>
