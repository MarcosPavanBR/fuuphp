<script>
  import { api } from '../api.js';
  import { toastr } from '../toastr.js';
  import { currentUser, logout } from '../session.svelte.js';

  // Tela 2.5 — Perfil. "Entrada para LGPD (exportar/excluir dados) —
  // exigência do banco de comprovantes com retenção de 180 dias."
  // (Bootstrap list-group, api/profile.php, LGPD)
  //
  // Cada linha abre uma tela de verdade: endereços, formas de pagamento e
  // configurações (Fase 6), notas e comprovantes (ReceiptsScreen), ajuda
  // (14.1) e "Editar perfil" (EditProfileScreen). "Privacidade e dados
  // (LGPD)" leva às Configurações, onde moram exportar e excluir.
  let {
    onLoggedOut,
    onOpenOrders,
    onOpenAddresses,
    onOpenPaymentMethods,
    onOpenSettings,
    onOpenHelp,
    onOpenReceipts,
    onEditProfile,
  } = $props();

  let stats = $state(null);
  // Tela 13.4, lado do cliente: a oferta de crédito em carteira aparece
  // aqui, junto do dinheiro dele. "Nunca pode ser imposto" -- então tem que
  // haver um lugar onde ele diz sim ou não, e esse lugar é o perfil.
  let wallet = $state(null);
  let deciding = $state(false);

  async function loadStats() {
    try {
      const data = await api.get('/profile/show.php', { auth: true });
      stats = data.stats;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar o perfil.');
    }
  }
  loadStats();

  async function loadWallet() {
    try {
      wallet = await api.get('/profile/wallet.php', { auth: true });
    } catch {
      // carteira vazia não é erro de tela: o perfil segue sem o cartão
    }
  }
  loadWallet();

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  async function answerOffer(credit, decision) {
    if (deciding) return;
    deciding = true;
    try {
      const res = await api.post('/profile/wallet.php', {
        auth: true,
        body: { credit_id: credit.id, decision },
      });
      toastr.success(res.notice);
      await loadWallet();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra responder a oferta.');
    } finally {
      deciding = false;
    }
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
      <button type="button" class="edit-link" onclick={onEditProfile}>Editar perfil</button>
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

  {#each wallet?.offers ?? [] as offer (offer.id)}
    <div class="offer">
      <p class="title">
        <i class="bi bi-wallet2"></i>
        {money(Number(offer.amount) + Number(offer.bonus))} de crédito no lugar do estorno
      </p>
      <p class="body">
        O pedido {offer.public_code ? `#${offer.public_code}` : ''} tinha {money(offer.amount)} a
        devolver. Se você aceitar crédito na carteira, entram
        {money(offer.bonus)} a mais e o saldo já vale no próximo pedido — mas o estorno no seu
        pagamento continua sendo seu direito.
      </p>
      <div class="actions">
        <button type="button" class="yes" disabled={deciding} onclick={() => answerOffer(offer, 'accept')}>
          Aceitar crédito
        </button>
        <button type="button" class="no" disabled={deciding} onclick={() => answerOffer(offer, 'decline')}>
          Prefiro o estorno
        </button>
      </div>
    </div>
  {/each}

  {#if wallet && wallet.balance > 0}
    <div class="balance">
      <span>Saldo na carteira</span>
      <strong>{money(wallet.balance)}</strong>
    </div>
    <p class="note">Entra sozinho no próximo pedido, abatendo o total.</p>
  {/if}

  <div class="menu">
    <button type="button" onclick={onOpenAddresses}>
      <span>Endereços salvos</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={onOpenPaymentMethods}>
      <span>Formas de pagamento</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={onOpenReceipts}>
      <span>Notas e comprovantes</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={onOpenSettings}>
      <span>Privacidade e dados (LGPD)</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={onOpenHelp}>
      <span>Ajuda</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={onOpenSettings}>
      <span>Configurações</span>
      <i class="bi bi-chevron-right"></i>
    </button>
  </div>

  <button type="button" class="btn-fuu-danger-outline w-100 logout" onclick={doLogout}>
    Sair da conta
  </button>

  <p class="footer">PWA v2.0.0 · HTTP/3 ativo</p>
</div>

<style>
  .edit-link {
    background: none;
    border: none;
    padding: 0;
    margin-top: 2px;
    color: var(--fuu-red);
    font-weight: 600;
    font-size: 12.5px;
  }
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
  .offer {
    border: 1px solid var(--fuu-leaf);
    background: var(--fuu-leaf-tint);
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 12px;
  }
  .offer .title {
    font-size: 14px;
    font-weight: 800;
    color: var(--fuu-leaf-dark);
    margin: 0;
  }
  .offer .body {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    line-height: 1.55;
    margin: 6px 0 0;
  }
  .offer .actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
  }
  .offer .actions button {
    flex: 1;
    border-radius: 10px;
    padding: 12px 6px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
  }
  .offer .yes {
    background: var(--fuu-leaf);
    border: 0;
    color: var(--fuu-white);
    font-weight: 800;
  }
  .offer .no {
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-2);
    color: var(--fuu-ink-1);
  }
  .balance {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 14px;
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .balance strong {
    font-size: 17px;
    color: var(--fuu-ink-1);
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
  .logout {
    margin-bottom: 16px;
  }
  .footer {
    text-align: center;
    font-size: 11px;
    color: var(--fuu-ink-6);
  }
</style>
