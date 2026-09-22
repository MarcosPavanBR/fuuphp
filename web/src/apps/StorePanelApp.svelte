<script>
  import { api } from '../lib/services/api.js';
  import { toastr } from '../lib/utils/toastr.js';
  import {
    isStaffAuthenticated,
    staffToken,
    staffRestaurantId,
    staffRestaurantName,
    staffLogout,
    loadRestaurant,
  } from '../lib/state/staffSession.svelte.js';
  import StaffLoginScreen from '../lib/screens/panel/StaffLoginScreen.svelte';
  import ProofQueue from '../lib/screens/panel/ProofQueue.svelte';
  import ProofReviewModal from '../lib/screens/panel/ProofReviewModal.svelte';
  import KdsBoard from '../lib/screens/panel/KdsBoard.svelte';
  import RejectDialog from '../lib/screens/panel/RejectDialog.svelte';
  import OverviewScreen from '../lib/screens/panel/OverviewScreen.svelte';
  import CashDeskScreen from '../lib/screens/panel/CashDeskScreen.svelte';
  import PauseScreen from '../lib/screens/panel/PauseScreen.svelte';
  import MenuScreen from '../lib/screens/panel/MenuScreen.svelte';
  import HoursScreen from '../lib/screens/panel/HoursScreen.svelte';
  import PaymentSettingsScreen from '../lib/screens/panel/PaymentSettingsScreen.svelte';
  import ReconciliationScreen from '../lib/screens/panel/ReconciliationScreen.svelte';
  import OrderChat from '../lib/components/OrderChat.svelte';

  // Painel da loja (telas 7.3 e 11.1). Roda numa página própria
  // (painel.html), não dentro do app do cliente: são dois públicos e dois
  // bundles, e ninguém quer baixar o KDS pra pedir uma pizza.
  let logged = $state(isStaffAuthenticated());
  let tab = $state('kds');
  let proofs = $state([]);
  let kds = $state([]);
  let recent = $state([]);
  let stats = $state(null);
  let settlements = $state([]);
  let selectedProof = $state(null);
  let rejecting = $state(null);
  let chatting = $state(null);
  let storeState = $state(null);
  let lastSync = $state(null);
  let online = $state(true);
  let clock = $state(new Date());

  // Atualização por polling, não por SSE, e isso é uma decisão consciente:
  // `php -S` atende uma requisição por vez, então uma conexão SSE aberta no
  // painel travaria todas as outras chamadas DA MESMA TELA (aprovar, avançar
  // pedido, estatísticas). O advance_order() já publica em pg_notify e o
  // endpoint de SSE do cliente existe -- quando o servidor for php-fpm, esta
  // tela troca o setInterval por um EventSource sem mexer no resto.
  const FAST_MS = 5000;
  const SLOW_MS = 30000;

  async function pull({ withStats = false } = {}) {
    if (!staffRestaurantId()) return;
    try {
      const token = staffToken();
      const [proofData, kdsData] = await Promise.all([
        api.get('/restaurants/pending_proofs.php', { token }),
        api.get('/restaurants/orders.php', {
          token,
          query: { id: staffRestaurantId(), scope: 'kds' },
        }),
      ]);
      proofs = proofData.proofs;
      kds = kdsData.orders;

      if (withStats) {
        const [statsData, recentData, settleData, pauseData] = await Promise.all([
          api.get('/restaurants/stats.php', { token }),
          api.get('/restaurants/orders.php', {
            token,
            query: { id: staffRestaurantId(), scope: 'recent' },
          }),
          api.get('/restaurants/settlements.php', { token }),
          api.get('/restaurants/pause_status.php', { token }),
        ]);
        stats = statsData.stats;
        recent = recentData.orders;
        settlements = settleData.settlements;
        // O cabeçalho passa a dizer se a loja está recebendo pedido agora:
        // até aqui dava pra passar o dia inteiro fechada sem ninguém notar.
        storeState = { ...pauseData.store, paused: pauseData.pause !== null };
      }
      online = true;
      lastSync = new Date();
    } catch {
      // Perder um ciclo de polling não é evento: a loja continua vendo o
      // último estado bom e o cabeçalho avisa que parou de atualizar.
      online = false;
    }
  }

  $effect(() => {
    if (!logged) return;
    loadRestaurant();
    pull({ withStats: true });
    const fast = setInterval(() => pull(), FAST_MS);
    const slow = setInterval(() => pull({ withStats: true }), SLOW_MS);
    const tick = setInterval(() => (clock = new Date()), 1000);
    return () => {
      clearInterval(fast);
      clearInterval(slow);
      clearInterval(tick);
    };
  });

  function onReviewed() {
    selectedProof = null;
    pull({ withStats: true });
  }

  function leave() {
    staffLogout();
    logged = false;
    proofs = [];
    kds = [];
    recent = [];
    stats = null;
    toastr.info('Você saiu do painel.');
  }

  let hhmm = $derived(
    `${String(clock.getHours()).padStart(2, '0')}:${String(clock.getMinutes()).padStart(2, '0')}`
  );
  let syncLabel = $derived(
    lastSync
      ? `${String(lastSync.getHours()).padStart(2, '0')}:${String(lastSync.getMinutes()).padStart(2, '0')}:${String(lastSync.getSeconds()).padStart(2, '0')}`
      : '—'
  );
</script>

{#if !logged}
  <StaffLoginScreen onLoggedIn={() => (logged = true)} />
{:else}
  <div class="panel">
    <header class="top">
      <span class="store">
        <i class="bi bi-bag-heart-fill"></i>
        {staffRestaurantName() ?? 'Painel da loja'}
      </span>
      {#if storeState}
        <button
          type="button"
          class="state"
          class:paused={storeState.paused}
          class:closed={!storeState.is_open && !storeState.paused}
          onclick={() => (tab = 'store')}
        >
          {storeState.paused ? 'pausada' : storeState.is_open ? 'recebendo pedidos' : 'fechada'}
        </button>
      {/if}
      {#if proofs.length > 0}
        <span class="badge-alert">{proofs.length} Pix esperando</span>
      {/if}

      <nav class="tabs">
        <button type="button" class:active={tab === 'kds'} onclick={() => (tab = 'kds')}>Cozinha</button>
        <button type="button" class:active={tab === 'overview'} onclick={() => (tab = 'overview')}>
          Visão geral
        </button>
        <button type="button" class:active={tab === 'cash'} onclick={() => (tab = 'cash')}>
          Caixa
          {#if settlements.some((s) => s.state === 'open')}<span class="dot"></span>{/if}
        </button>
        <!-- 11.2 a 11.4: a loja operando a si mesma. Ficam depois da cozinha
             e do caixa de propósito -- são as abas que se abre uma vez por
             dia, não a cada pedido. -->
        <button type="button" class:active={tab === 'store'} onclick={() => (tab = 'store')}>
          Loja
          {#if storeState && (!storeState.is_open || storeState.paused)}<span class="dot"></span>{/if}
        </button>
        <button type="button" class:active={tab === 'menu'} onclick={() => (tab = 'menu')}>Cardápio</button>
        <button type="button" class:active={tab === 'hours'} onclick={() => (tab = 'hours')}>Horário</button>
        <!-- 10.4 e 9.6: dinheiro que não passa pelo caixa -- as formas que
             a loja aceita e a conferência da maquininha. -->
        <button type="button" class:active={tab === 'payments'} onclick={() => (tab = 'payments')}>
          Pagamentos
        </button>
        <button type="button" class:active={tab === 'recon'} onclick={() => (tab = 'recon')}>Conciliação</button>
      </nav>

      <div class="meta">
        <span class="sync" class:off={!online}>
          <i class={`bi ${online ? 'bi-arrow-repeat' : 'bi-wifi-off'}`}></i>
          {online ? `atualizado ${syncLabel}` : 'sem conexão'}
        </span>
        <span class="clock fuu-mono">{hhmm}</span>
        <button type="button" class="leave" onclick={leave} aria-label="Sair">
          <i class="bi bi-box-arrow-right"></i>
        </button>
      </div>
    </header>

    {#if tab === 'kds'}
      <KdsBoard
        orders={kds}
        {proofs}
        onRefresh={() => pull({ withStats: true })}
        onOpenProof={(p) => (selectedProof = p)}
        onReject={(o) => (rejecting = o)}
        onOpenChat={(o) => (chatting = o)}
      />
    {:else if tab === 'cash'}
      <CashDeskScreen {settlements} onDone={() => pull({ withStats: true })} />
    {:else if tab === 'store'}
      <PauseScreen onChanged={() => pull({ withStats: true })} />
    {:else if tab === 'menu'}
      <MenuScreen />
    {:else if tab === 'hours'}
      <HoursScreen onChanged={() => pull({ withStats: true })} />
    {:else if tab === 'payments'}
      <PaymentSettingsScreen />
    {:else if tab === 'recon'}
      <ReconciliationScreen />
    {:else}
      <div class="overview-layout">
        <aside class="side">
          <ProofQueue {proofs} selectedId={selectedProof?.id ?? null} onSelect={(p) => (selectedProof = p)} />
          <p class="col-label">COZINHA (KDS)</p>
          {#each kds as order (order.id)}
            <div class="mini" class:cooking={order.status === 'preparing'}>
              #{order.public_code} · {order.status === 'paid'
                ? 'novo'
                : order.status === 'preparing'
                  ? 'em preparo'
                  : 'pronto · aguarda motoboy'}
            </div>
          {:else}
            <p class="empty">Cozinha vazia.</p>
          {/each}
        </aside>
        <main class="main">
          <OverviewScreen {stats} {recent} {kds} />
        </main>
      </div>
    {/if}
  </div>

  {#if selectedProof}
    <ProofReviewModal proof={selectedProof} onClose={() => (selectedProof = null)} {onReviewed} />
  {/if}

  {#if chatting}
    <OrderChat orderId={chatting.id} token={staffToken()} onClose={() => (chatting = null)} />
  {/if}

  {#if rejecting}
    <RejectDialog
      order={rejecting}
      onClose={() => (rejecting = null)}
      onRejected={() => {
        rejecting = null;
        pull({ withStats: true });
      }}
    />
  {/if}
{/if}

<style>
  .panel {
    min-height: 100vh;
    background: var(--fuu-paper);
  }
  .top {
    background: var(--fuu-white);
    border-bottom: 1px solid var(--fuu-line-3);
    padding: 12px 22px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
  }
  .store {
    font-weight: 800;
    font-size: 15px;
    color: var(--fuu-ink-1);
  }
  .store i {
    color: var(--fuu-red);
  }
  .state {
    font-size: 11px;
    font-weight: 800;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1px solid var(--fuu-leaf);
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
    font-family: var(--fuu-font-body);
  }
  .state.paused {
    border-color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .state.closed {
    border-color: var(--fuu-line-1);
    background: var(--fuu-line-5);
    color: var(--fuu-ink-4);
  }
  .badge-alert {
    background: var(--fuu-red);
    color: var(--fuu-white);
    font-size: 10px;
    font-weight: 800;
    padding: 3px 9px;
    border-radius: 20px;
  }
  .tabs {
    display: flex;
    gap: 6px;
    margin-left: 10px;
  }
  .tabs button {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: var(--fuu-radius-pill);
    padding: 7px 16px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-3);
  }
  .dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--fuu-red);
    margin-left: 5px;
    vertical-align: middle;
  }
  .tabs button.active {
    background: var(--fuu-ink-1);
    border-color: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .meta {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .sync i {
    color: var(--fuu-leaf-dark);
  }
  .sync.off,
  .sync.off i {
    color: var(--fuu-red);
  }
  .clock {
    font-size: 19px;
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .leave {
    background: var(--fuu-line-5);
    border: none;
    border-radius: 10px;
    width: 38px;
    height: 38px;
    color: var(--fuu-ink-3);
    font-size: 17px;
  }
  .overview-layout {
    display: flex;
    align-items: flex-start;
  }
  .side {
    width: 250px;
    flex: none;
    background: var(--fuu-white);
    border-right: 1px solid var(--fuu-line-3);
    padding: 14px;
    min-height: calc(100vh - 63px);
  }
  .main {
    flex: 1;
    padding: 18px;
    min-width: 0;
  }
  .col-label {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 20px 0 10px;
  }
  .mini {
    background: var(--fuu-line-5);
    border-radius: 9px;
    padding: 9px 10px;
    font-size: 11px;
    font-weight: 700;
    color: var(--fuu-ink-3);
    margin-bottom: 7px;
  }
  .mini.cooking {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .empty {
    font-size: 12px;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  @media (max-width: 900px) {
    .overview-layout {
      flex-direction: column;
    }
    .side {
      width: 100%;
      min-height: 0;
      border-right: none;
      border-bottom: 1px solid var(--fuu-line-3);
    }
  }
</style>
