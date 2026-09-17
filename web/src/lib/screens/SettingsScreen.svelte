<script>
  import { toastr } from '../toastr.js';

  // Tela 6.3 — Configurações. "Push separado por tipo (status × promoção)
  // para o usuário não desligar tudo e perder o aviso da aprovação." Não
  // há Web Push de verdade neste repositório (outbox+worker é Fase 7.2,
  // ainda não construída) -- os toggles abaixo são preferência local real
  // (localStorage, sobrevive a reload deste aparelho), prontos pro dia que
  // o worker de push existir e precisar checar "esse tipo está ligado?".
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

  function toggle(key) {
    prefs = { ...prefs, [key]: !prefs[key] };
    try {
      localStorage.setItem('fuu_notification_prefs', JSON.stringify(prefs));
    } catch {
      // localStorage pode falhar (aba privada) -- a preferência só não
      // sobrevive a um reload, não é motivo pra travar a tela.
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
  <p class="note">Push de verdade (Fase 7.2) ainda não foi construído — isto guarda a preferência pra quando existir.</p>

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
