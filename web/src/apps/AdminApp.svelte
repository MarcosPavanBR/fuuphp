<script>
  import { api } from '../lib/services/api.js';
  import { toastr } from '../lib/utils/toastr.js';
  import { isAdminAuthenticated, adminToken, adminLogout } from '../lib/state/adminSession.svelte.js';
  import AdminLoginScreen from '../lib/screens/admin/AdminLoginScreen.svelte';
  import StoreQueue from '../lib/screens/admin/StoreQueue.svelte';
  import DisputeQueue from '../lib/screens/admin/DisputeQueue.svelte';
  import ReportsScreen from '../lib/screens/admin/ReportsScreen.svelte';
  import PolicyScreen from '../lib/screens/admin/PolicyScreen.svelte';
  import CampaignsScreen from '../lib/screens/admin/CampaignsScreen.svelte';
  import RefundsScreen from '../lib/screens/admin/RefundsScreen.svelte';
  import IncidentQueue from '../lib/screens/admin/IncidentQueue.svelte';
  import NettingScreen from '../lib/screens/admin/NettingScreen.svelte';

  // Painel da plataforma (Fase 12 + tela 10.5). Quarto bundle, quarto
  // público: quem opera o negócio, não quem usa o app.
  let logged = $state(isAdminAuthenticated());
  let tab = $state('stores');
  let stores = $state(null);
  let disputes = $state(null);

  async function pull() {
    try {
      const [s, d] = await Promise.all([
        api.get('/admin/restaurants.php', { token: adminToken() }),
        api.get('/admin/disputes.php', { token: adminToken() }),
      ]);
      stores = s;
      disputes = d;
    } catch {
      // ciclo perdido não é evento: a tela segue com o último estado bom
    }
  }

  $effect(() => {
    if (!logged) return;
    pull();
    const t = setInterval(pull, 20000);
    return () => clearInterval(t);
  });

  function leave() {
    adminLogout();
    logged = false;
    stores = null;
    disputes = null;
    toastr.info('Você saiu do painel.');
  }

  let pendingStores = $derived(stores?.pending?.length ?? 0);
  let openDisputes = $derived(disputes?.open?.length ?? 0);
</script>

{#if !logged}
  <AdminLoginScreen onLoggedIn={() => (logged = true)} />
{:else}
  <div class="admin">
    <header class="top">
      <span class="brand fuu-display">FUU</span>
      <span class="title">Painel da plataforma</span>

      <nav class="tabs">
        <button type="button" class:on={tab === 'stores'} onclick={() => (tab = 'stores')}>
          Lojas {#if pendingStores > 0}<span class="count">{pendingStores}</span>{/if}
        </button>
        <button type="button" class:on={tab === 'disputes'} onclick={() => (tab = 'disputes')}>
          Ocorrências {#if openDisputes > 0}<span class="count alert">{openDisputes}</span>{/if}
        </button>
        <button type="button" class:on={tab === 'refunds'} onclick={() => (tab = 'refunds')}>
          Reembolsos
        </button>
        <button type="button" class:on={tab === 'netting'} onclick={() => (tab = 'netting')}>Financeiro</button>
        <button type="button" class:on={tab === 'reports'} onclick={() => (tab = 'reports')}>Relatórios</button>
        <button type="button" class:on={tab === 'campaigns'} onclick={() => (tab = 'campaigns')}>
          Campanhas
        </button>
        <button type="button" class:on={tab === 'policy'} onclick={() => (tab = 'policy')}>Políticas</button>
      </nav>

      <button type="button" class="leave" onclick={leave} aria-label="Sair">
        <i class="bi bi-box-arrow-right"></i>
      </button>
    </header>

    <main>
      {#if tab === 'stores'}
        <StoreQueue data={stores} onRefresh={pull} />
      {:else if tab === 'disputes'}
        <DisputeQueue data={disputes} onRefresh={pull} />
      {:else if tab === 'refunds'}
        <!-- 13.3 + 13.4 na mesma mesa: liberar a sacola e decidir o dinheiro
             são a mesma conversa, com o mesmo pedido na frente. -->
        <IncidentQueue onDecided={pull} />
        <RefundsScreen />
      {:else if tab === 'netting'}
        <!-- 9.7: netting semanal, repasse e bloqueios. -->
        <NettingScreen />
      {:else if tab === 'reports'}
        <ReportsScreen />
      {:else if tab === 'campaigns'}
        <CampaignsScreen />
      {:else}
        <PolicyScreen />
      {/if}
    </main>
  </div>
{/if}

<style>
  .admin {
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
  .tabs button.on {
    background: var(--fuu-ink-1);
    border-color: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .count {
    display: inline-block;
    min-width: 18px;
    background: var(--fuu-line-4);
    color: var(--fuu-ink-1);
    border-radius: 999px;
    font-size: 11px;
    padding: 1px 6px;
    margin-left: 5px;
  }
  .count.alert {
    background: var(--fuu-red);
    color: var(--fuu-white);
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
    padding: 20px 22px 40px;
    max-width: 1280px;
  }
</style>
