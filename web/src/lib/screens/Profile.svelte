<script>
  import { api } from '../api.js';
  import { toastr } from '../toastr.js';
  import { currentUser, logout } from '../session.svelte.js';

  // Tela 2.5 — Perfil. "Entrada para LGPD (exportar/excluir dados) —
  // exigência do banco de comprovantes com retenção de 180 dias."
  // (Bootstrap list-group, api/profile.php, LGPD)
  let { onLoggedOut, onOpenOrders } = $props();

  let stats = $state(null);
  let addresses = $state(null);
  let showAddresses = $state(false);

  async function loadStats() {
    try {
      const data = await api.get('/profile/show.php', { auth: true });
      stats = data.stats;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar o perfil.');
    }
  }
  loadStats();

  async function toggleAddresses() {
    showAddresses = !showAddresses;
    if (showAddresses && addresses === null) {
      try {
        const data = await api.get('/addresses/list.php', { auth: true });
        addresses = data.addresses;
      } catch (e) {
        toastr.error(e.message ?? 'Não deu pra carregar os endereços.');
      }
    }
  }

  function notPortedYet(what) {
    toastr.info(`${what} ainda não foi portado.`);
  }

  function doLogout() {
    logout();
    onLoggedOut();
  }

  let user = $derived(currentUser());
  function initials(name) {
    if (!name) return '?';
    return name.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  }
</script>

<div class="profile-screen">
  <div class="header">
    <div class="avatar">{initials(user?.full_name)}</div>
    <div>
      <p class="name">{user?.full_name ?? '—'}</p>
      <p class="contact">{user?.email ?? user?.phone ?? ''}</p>
    </div>
  </div>

  <div class="stats">
    <button type="button" class="stat" onclick={onOpenOrders}>
      <p class="value">{stats?.orders_count ?? '—'}</p>
      <p class="label">PEDIDOS</p>
    </button>
    <div class="stat">
      <p class="value">{stats?.loyalty_points ?? '—'}</p>
      <p class="label">PONTOS</p>
    </div>
    <div class="stat">
      <p class="value">{stats?.coupons_count ?? '—'}</p>
      <p class="label">CUPONS</p>
    </div>
  </div>
  {#if stats && stats.loyalty_points === null}
    <p class="note">Pontos de fidelidade ainda não têm tabela no banco — ver Fase 2.3.</p>
  {/if}

  <div class="menu">
    <button type="button" onclick={toggleAddresses}>
      <span>Endereços salvos</span>
      <i class={`bi ${showAddresses ? 'bi-chevron-up' : 'bi-chevron-down'}`}></i>
    </button>
    {#if showAddresses}
      <div class="address-list">
        {#if addresses === null}
          <p class="note">Carregando…</p>
        {:else if addresses.length === 0}
          <p class="note">Nenhum endereço salvo ainda.</p>
        {:else}
          {#each addresses as a (a.id)}
            <p class="address-item">{a.street}, {a.number ?? 's/n'} — {a.neighborhood ?? a.city}</p>
          {/each}
        {/if}
      </div>
    {/if}

    <button type="button" onclick={() => notPortedYet('Formas de pagamento')}>
      <span>Formas de pagamento</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={() => notPortedYet('Notas e comprovantes')}>
      <span>Notas e comprovantes</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={() => notPortedYet('Exportar/excluir dados (LGPD)')}>
      <span>Privacidade e dados (LGPD)</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={() => notPortedYet('Configurações')}>
      <span>Configurações</span>
      <i class="bi bi-chevron-right"></i>
    </button>
  </div>

  <button type="button" class="btn-fuu-danger-outline w-100 logout" onclick={doLogout}>
    Sair da conta
  </button>

  <p class="footer">PWA v2.0.0 (parcial) · Fase 2 de 15</p>
</div>

<style>
  .profile-screen {
    padding: 12px 20px 24px;
  }
  .header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 20px;
  }
  .avatar {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: var(--fuu-red-tint);
    color: var(--fuu-red-hover);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--fuu-font-display);
    font-weight: 700;
    font-size: 18px;
  }
  .name {
    margin: 0;
    font-weight: 700;
    font-size: 16px;
    color: var(--fuu-ink-1);
  }
  .contact {
    margin: 2px 0 0;
    font-size: 12.5px;
    color: var(--fuu-ink-5);
  }
  .stats {
    display: flex;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 14px 0;
    margin-bottom: 6px;
  }
  .stat {
    flex: 1;
    text-align: center;
    border-right: 1px solid var(--fuu-line-3);
    background: none;
    border-top: none;
    border-bottom: none;
    border-left: none;
    font-family: inherit;
    padding: 0;
  }
  .stat:last-child {
    border-right: none;
  }
  .stat .value {
    margin: 0;
    font-family: var(--fuu-font-mono);
    font-size: 18px;
    font-weight: 600;
    color: var(--fuu-ink-1);
  }
  .stat .label {
    margin: 2px 0 0;
    font-size: 10px;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-5);
  }
  .note {
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    margin: 4px 0 18px;
  }
  .menu {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 20px;
  }
  .menu button {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 0 14px;
    min-height: var(--fuu-tap-customer);
    font-family: var(--fuu-font-body);
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .menu button i {
    color: var(--fuu-ink-5);
  }
  .address-list {
    background: var(--fuu-line-6);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
  }
  .address-item {
    margin: 0 0 6px;
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .logout {
    margin-bottom: 16px;
  }
  .footer {
    text-align: center;
    font-size: 11px;
    color: var(--fuu-ink-6);
  }
</style>
