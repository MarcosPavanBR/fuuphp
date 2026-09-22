<script>
  import { toastr } from '../toastr.js';
  import { api, BASE, getStoredToken } from '../api.js';
  import { logout } from '../session.svelte.js';
  import { pushState, enablePush, disablePush, savePushPrefs } from '../push.js';

  // Tela 6.3 — Configurações. "Push separado por tipo (status × promoção)
  // para o usuário não desligar tudo e perder o aviso da aprovação."
  //
  // Os três interruptores são as preferências da ASSINATURA de push deste
  // aparelho (push_subscriptions.want_*, migração 024): o worker da tela
  // 7.2 só acorda o aparelho pros tipos ligados aqui. Com o push desligado,
  // a preferência fica guardada no aparelho e vai junto quando ligar.
  //
  // APARÊNCIA E DADOS / CONTA (LGPD, migração 026):
  //   - "Cardápios offline" lista o que o service worker guardou de verdade
  //     (cache `*-data`, tela 7.1) e deixa apagar;
  //   - "Baixar meus dados" baixa profile/export.php;
  //   - "Alterar senha": conta de cliente entra por código no celular (10.1),
  //     não tem senha -- a tela diz isso em vez de fingir um formulário;
  //   - "Excluir conta" pergunta ao servidor o que impede (pedido em
  //     andamento, reembolso vivo, saldo na carteira) antes de confirmar.
  // `onDeleted`: a conta deixou de existir -- quem chamou volta pro início.
  let { onBack, onDeleted = () => location.reload() } = $props();

  const DEFAULTS = { orderStatus: true, paymentApproval: true, promotions: false };

  function loadPrefs() {
    try {
      const raw = localStorage.getItem('fuu_notification_prefs');
      return raw ? { ...DEFAULTS, ...JSON.parse(raw) } : { ...DEFAULTS };
    } catch {
      return { ...DEFAULTS };
    }
  }

  let prefs = $state(loadPrefs());

  // Nome da preferência no front → nome no servidor.
  const serverPrefs = () => ({
    status: prefs.orderStatus,
    payment: prefs.paymentApproval,
    promotion: prefs.promotions,
  });

  let push = $state('checking');
  let pushBusy = $state(false);
  pushState()
    .then((v) => (push = v))
    .catch(() => (push = 'unsupported'));

  async function toggle(key) {
    prefs = { ...prefs, [key]: !prefs[key] };
    try {
      localStorage.setItem('fuu_notification_prefs', JSON.stringify(prefs));
    } catch {
      // localStorage pode falhar (aba privada) -- a preferência só não
      // sobrevive a um reload, não é motivo pra travar a tela.
    }
    if (push === 'on') {
      savePushPrefs(serverPrefs()).catch(() => toastr.error('Não deu pra salvar a preferência no servidor.'));
    }
  }

  async function togglePush() {
    pushBusy = true;
    try {
      if (push === 'on') {
        await disablePush();
        push = 'off';
        toastr.info('Notificações desligadas neste aparelho.');
      } else {
        await enablePush(serverPrefs());
        push = 'on';
        toastr.success('Notificações ligadas neste aparelho.');
      }
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra mudar as notificações.');
      push = await pushState().catch(() => 'unsupported');
    } finally {
      pushBusy = false;
    }
  }

  // ── Cardápios offline ────────────────────────────────────────────────
  let offlineOpen = $state(false);
  let offlineMenus = $state(null); // [{ id, name, urls }]
  let swVersion = $state(null);

  // O nome do cache de shell é `<versão>-shell` (web/public/sw.js): lê a
  // versão do que está instalado, não de uma constante que pode mentir.
  if (typeof caches !== 'undefined') {
    caches
      .keys()
      .then((keys) => (swVersion = keys.find((k) => k.endsWith('-shell'))?.replace(/-shell$/, '') ?? null))
      .catch(() => {});
  }

  async function loadOfflineMenus() {
    offlineOpen = !offlineOpen;
    if (!offlineOpen) return;
    offlineMenus = null;
    try {
      const names = (await caches.keys()).filter((k) => k.endsWith('-data'));
      const byId = new Map();
      for (const name of names) {
        const cache = await caches.open(name);
        for (const req of await cache.keys()) {
          const url = new URL(req.url);
          if (!/\/restaurants\/(menu|show)\.php$/.test(url.pathname)) continue;
          const id = url.searchParams.get('id') ?? url.searchParams.get('restaurant_id');
          if (!id) continue;
          const entry = byId.get(id) ?? { id, name: null, hasMenu: false, entries: [] };
          entry.entries.push({ cache: name, req });
          if (url.pathname.endsWith('menu.php')) entry.hasMenu = true;
          if (url.pathname.endsWith('show.php') && !entry.name) {
            const res = await cache.match(req);
            entry.name = (await res?.json().catch(() => null))?.restaurant?.name ?? null;
          }
          byId.set(id, entry);
        }
      }
      offlineMenus = [...byId.values()].filter((e) => e.hasMenu);
    } catch {
      offlineMenus = [];
    }
  }

  async function forgetMenu(entry) {
    for (const { cache, req } of entry.entries) {
      await (await caches.open(cache)).delete(req);
    }
    offlineMenus = offlineMenus.filter((e) => e.id !== entry.id);
    toastr.info(`Cardápio de ${entry.name ?? 'loja'} apagado deste aparelho.`);
  }

  // ── Baixar meus dados (LGPD) ─────────────────────────────────────────
  let exporting = $state(false);

  async function exportData() {
    exporting = true;
    try {
      // fetch direto (não api.get): a resposta é um arquivo, não JSON pra tela.
      const res = await fetch(`${BASE}/profile/export.php`, {
        headers: { Authorization: `Bearer ${getStoredToken() ?? ''}` },
      });
      if (!res.ok) throw new Error();
      const blob = await res.blob();
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = `fuudelivery-meus-dados-${new Date().toISOString().slice(0, 10)}.json`;
      link.click();
      setTimeout(() => URL.revokeObjectURL(link.href), 1000);
      toastr.success('Arquivo com seus dados baixado ✓');
    } catch {
      toastr.error('Não deu pra gerar o arquivo agora.');
    } finally {
      exporting = false;
    }
  }

  // ── Alterar senha ────────────────────────────────────────────────────
  let passwordOpen = $state(false);

  // ── Excluir conta ────────────────────────────────────────────────────
  let deleteState = $state(null); // null | { blockers, wallet_balance }
  let deleteConfirm = $state('');
  let forfeit = $state(false);
  let deleting = $state(false);

  async function openDelete() {
    if (deleteState) {
      deleteState = null;
      return;
    }
    try {
      deleteState = await api.get('/profile/delete_account.php', { auth: true });
      deleteConfirm = '';
      forfeit = false;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra verificar sua conta agora.');
    }
  }

  async function deleteAccount() {
    deleting = true;
    try {
      await api.post('/profile/delete_account.php', {
        auth: true,
        body: { confirm: deleteConfirm.trim(), forfeit_wallet: forfeit },
      });
      logout();
      toastr.success('Sua conta foi excluída.');
      onDeleted();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra excluir a conta.');
    } finally {
      deleting = false;
    }
  }

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }
</script>

<div class="settings-screen">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>
    <h1 class="fuu-display">Configurações</h1>
  </div>

  <p class="section-label">NOTIFICAÇÕES</p>
  <div class="menu">
    <label class="toggle-row">
      <span>Status do pedido</span>
      <input type="checkbox" checked={prefs.orderStatus} onchange={() => toggle('orderStatus')} />
    </label>
    <label class="toggle-row">
      <span>Aprovação de pagamento</span>
      <input type="checkbox" checked={prefs.paymentApproval} onchange={() => toggle('paymentApproval')} />
    </label>
    <label class="toggle-row">
      <span>Promoções e cupons</span>
      <input type="checkbox" checked={prefs.promotions} onchange={() => toggle('promotions')} />
    </label>
  </div>
  {#if push === 'unsupported'}
    <p class="note">Este navegador não recebe notificações push (ou a página não está em https).</p>
  {:else if push === 'denied'}
    <p class="note">As notificações estão bloqueadas nas permissões do navegador para este site.</p>
  {:else if push !== 'checking'}
    <button type="button" class="push-btn" disabled={pushBusy} onclick={togglePush}>
      <i class="bi {push === 'on' ? 'bi-bell-slash' : 'bi-bell'}"></i>
      {push === 'on' ? 'Desligar notificações neste aparelho' : 'Ligar notificações neste aparelho'}
    </button>
    <p class="note">
      {push === 'on'
        ? 'Os três tipos acima valem para este aparelho — o celular pode querer promoção e o computador não.'
        : 'Ligue para receber a aprovação do Pix, a saída para entrega e o aviso de prazo do comprovante.'}
    </p>
  {/if}

  <p class="section-label">APARÊNCIA E DADOS</p>
  <div class="menu">
    <div class="static-row">
      <span>Tema</span>
      <span class="value">Automático</span>
    </div>
    <button type="button" onclick={loadOfflineMenus} aria-expanded={offlineOpen}>
      <span>Cardápios offline</span>
      <i class="bi {offlineOpen ? 'bi-chevron-down' : 'bi-chevron-right'}"></i>
    </button>
    {#if offlineOpen}
      <div class="panel">
        {#if offlineMenus === null}
          <p class="note">Procurando…</p>
        {:else if offlineMenus.length === 0}
          <p class="note">Nenhum cardápio guardado ainda. Todo cardápio que você abre com internet fica disponível sem rede.</p>
        {:else}
          <p class="note">Disponíveis sem internet neste aparelho:</p>
          {#each offlineMenus as entry (entry.id)}
            <div class="offline-row">
              <span>{entry.name ?? 'Loja'}</span>
              <button type="button" class="link-btn" onclick={() => forgetMenu(entry)}>Apagar</button>
            </div>
          {/each}
        {/if}
      </div>
    {/if}
    <button type="button" onclick={exportData} disabled={exporting}>
      <span>{exporting ? 'Gerando arquivo…' : 'Baixar meus dados (LGPD)'}</span>
      <i class="bi bi-download"></i>
    </button>
  </div>

  <p class="section-label">CONTA</p>
  <div class="menu">
    <button type="button" onclick={() => (passwordOpen = !passwordOpen)} aria-expanded={passwordOpen}>
      <span>Alterar senha</span>
      <i class="bi {passwordOpen ? 'bi-chevron-down' : 'bi-chevron-right'}"></i>
    </button>
    {#if passwordOpen}
      <div class="panel">
        <p class="note">
          Sua conta não tem senha: você entra com um código enviado ao seu celular a cada acesso. Não há senha pra
          vazar nem pra esquecer. Pra entrar com outro número, fale com o suporte na Central de ajuda.
        </p>
      </div>
    {/if}
    <button type="button" class="danger" onclick={openDelete} aria-expanded={deleteState !== null}>
      <span>Excluir conta</span>
      <i class="bi {deleteState ? 'bi-chevron-down' : 'bi-chevron-right'}"></i>
    </button>
    {#if deleteState}
      <div class="panel">
        {#if deleteState.blockers.length > 0}
          {#each deleteState.blockers as b (b.code)}
            <p class="note warn"><i class="bi bi-exclamation-triangle"></i> {b.message}</p>
          {/each}
        {:else}
          <p class="note">
            Seus dados pessoais (nome, CPF, telefone, e-mail, endereços, cartões) são apagados. Pedidos e pagamentos
            ficam guardados sem identificar você, porque a lei fiscal obriga. Não dá pra desfazer.
          </p>
          {#if deleteState.wallet_balance > 0}
            <label class="forfeit">
              <input type="checkbox" bind:checked={forfeit} />
              Entendo que perco {money(deleteState.wallet_balance)} de crédito na carteira.
            </label>
          {/if}
          <input class="confirm-input" placeholder="Digite EXCLUIR" bind:value={deleteConfirm} autocomplete="off" />
          <button
            type="button"
            class="delete-btn"
            disabled={deleting || deleteConfirm.trim() !== 'EXCLUIR' || (deleteState.wallet_balance > 0 && !forfeit)}
            onclick={deleteAccount}
          >
            {deleting ? 'Excluindo…' : 'Excluir minha conta'}
          </button>
        {/if}
      </div>
    {/if}
  </div>

  <p class="footer">FUUdelivery PWA 2.0.0<br />HTTP/3 · Cloudflare{swVersion ? ` · sw ${swVersion}` : ''}</p>
</div>

<style>
  .panel {
    padding: 4px 14px 12px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
  }
  .offline-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
    padding: 6px 0;
  }
  .link-btn {
    background: none;
    border: none;
    color: var(--fuu-red);
    font-weight: 600;
    font-size: 13px;
    padding: 4px;
  }
  .note.warn {
    color: var(--fuu-wait-text);
  }
  .forfeit {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    font-size: 13px;
    margin: 8px 0;
  }
  .confirm-input {
    width: 100%;
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 10px 12px;
    font-family: var(--fuu-font-mono);
    margin: 6px 0 10px;
  }
  .delete-btn {
    width: 100%;
    border: none;
    border-radius: 12px;
    padding: 12px;
    background: var(--fuu-red);
    color: var(--fuu-white);
    font-weight: 700;
  }
  .delete-btn:disabled {
    opacity: 0.45;
  }
  .push-btn {
    width: 100%;
    margin-top: 10px;
    border: 1.5px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: 12px;
    padding: 12px;
    font-family: inherit;
    font-weight: 700;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .settings-screen {
    padding: 12px 20px 40px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-2);
    padding: 4px;
  }
  h1 {
    font-size: 20px;
    margin: 0;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 20px 0 8px;
  }
  .section-label:first-of-type {
    margin-top: 4px;
  }
  .menu {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .menu > button,
  .toggle-row,
  .static-row {
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
  .menu > button i {
    color: var(--fuu-ink-5);
  }
  .menu > button.danger {
    color: var(--fuu-alert);
  }
  .toggle-row input {
    width: 20px;
    height: 20px;
  }
  .static-row .value {
    color: var(--fuu-ink-5);
    font-size: 13px;
  }
  .note {
    font-size: 11px;
    color: var(--fuu-ink-5);
    margin: 6px 0 0;
  }
  .footer {
    text-align: center;
    font-size: 11px;
    color: var(--fuu-ink-6);
    margin-top: 30px;
    line-height: 1.6;
  }
</style>
