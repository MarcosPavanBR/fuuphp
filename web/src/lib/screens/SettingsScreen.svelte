<script>
  import { toastr } from '../toastr.js';
  import { pushState, enablePush, disablePush, savePushPrefs } from '../push.js';

  // Tela 6.3 — Configurações. "Push separado por tipo (status × promoção)
  // para o usuário não desligar tudo e perder o aviso da aprovação."
  //
  // Os três interruptores são as preferências da ASSINATURA de push deste
  // aparelho (push_subscriptions.want_*, migração 024): o worker da tela
  // 7.2 só acorda o aparelho pros tipos ligados aqui. Com o push desligado,
  // a preferência fica guardada no aparelho e vai junto quando ligar.
  let { onBack } = $props();

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

  function notImplemented(what) {
    toastr.info(`${what} ainda não foi implementado nesta passada.`);
  }

  function confirmDeleteAccount() {
    toastr.warning('Exclusão de conta ainda não foi implementada — fale com o suporte por enquanto.');
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
    <button type="button" onclick={() => notImplemented('Cardápios offline')}>
      <span>Cardápios offline</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" onclick={() => notImplemented('Baixar meus dados (LGPD)')}>
      <span>Baixar meus dados (LGPD)</span>
      <i class="bi bi-chevron-right"></i>
    </button>
  </div>

  <p class="section-label">CONTA</p>
  <div class="menu">
    <button type="button" onclick={() => notImplemented('Alterar senha')}>
      <span>Alterar senha</span>
      <i class="bi bi-chevron-right"></i>
    </button>
    <button type="button" class="danger" onclick={confirmDeleteAccount}>
      <span>Excluir conta</span>
      <i class="bi bi-chevron-right"></i>
    </button>
  </div>

  <p class="footer">FUUdelivery PWA 2.0.0 (parcial)<br />HTTP/3 · Cloudflare · Fase 6 de 15</p>
</div>

<style>
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
  .menu button,
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
  .menu button i {
    color: var(--fuu-ink-5);
  }
  .menu button.danger {
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
