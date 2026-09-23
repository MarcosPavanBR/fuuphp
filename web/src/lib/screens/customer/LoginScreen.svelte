<script>
  import { toastr } from '../../utils/toastr.js';
  import { ApiError } from '../../services/api.js';
  import { requestOtp } from '../../state/customerSession.svelte.js';
  import { authChannels } from '../../services/authChannels.js';

  // Tela 10.1 — "Sem senha no primeiro acesso: código de uso único. Login
  // social é atalho, não obrigatório. A base legal aparece na própria tela."
  //
  // O mesmo formulário serve pra entrar e pra criar conta: quem não tem
  // conta recebe `user_not_found` do servidor e o campo de nome aparece --
  // é o próprio backend dizendo qual dos dois caminhos é o certo, em vez de
  // a tela adivinhar e obrigar todo mundo a escolher "entrar ou cadastrar"
  // antes de digitar qualquer coisa.
  let { onCodeSent, onPartnerLogin } = $props();

  let mode = $state('phone');
  let phone = $state('');
  let email = $state('');
  let fullName = $state('');
  let needsName = $state(false);
  let busy = $state(false);
  // A aba "E-mail" só aparece se o provedor do servidor manda código por
  // e-mail (em produção com a Twilio, não manda).
  let emailAvailable = $state(false);

  $effect(() => {
    authChannels().then((c) => (emailAvailable = c.email === true));
  });

  let digits = $derived(phone.replace(/\D/g, ''));
  // (19) 99128-4407 — máscara só na exibição; o que vai pro servidor é
  // sempre `digits`, que é o formato que users.phone guarda.
  let phoneMask = $derived.by(() => {
    const d = digits.slice(0, 11);
    if (d.length <= 2) return d;
    if (d.length <= 6) return `(${d.slice(0, 2)}) ${d.slice(2)}`;
    if (d.length <= 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
  });

  let contactReady = $derived(mode === 'phone' ? digits.length >= 10 : /.+@.+\..+/.test(email));
  let canSubmit = $derived(contactReady && (!needsName || fullName.trim().length >= 3));

  function switchMode(next) {
    mode = next;
    needsName = false;
  }

  async function submit() {
    if (!canSubmit || busy) return;
    busy = true;
    const contact = mode === 'phone' ? { phone: digits } : { email: email.trim() };
    try {
      const data = await requestOtp({
        ...contact,
        purpose: needsName ? 'signup' : 'login',
        fullName: needsName ? fullName.trim() : undefined,
      });
      onCodeSent({ ...contact, purpose: needsName ? 'signup' : 'login', isNew: needsName, expiresIn: data.expires_in });
    } catch (e) {
      if (e instanceof ApiError && e.code === 'user_not_found') {
        needsName = true;
        toastr.info('Primeira vez por aqui? Diga seu nome que a gente cria a conta.');
      } else {
        toastr.error(e.message ?? 'Não deu pra enviar o código.');
      }
    } finally {
      busy = false;
    }
  }
</script>

<div class="login">
  <header class="hero">
    <div class="mark fuu-display">FUU</div>
    <h1 class="fuu-display">Entrar no FUUDelivery</h1>
    <p class="sub">
      {emailAvailable ? 'Telefone ou e-mail.' : 'Seu celular.'} Enviamos um código de 6 dígitos — você não precisa
      criar senha.
    </p>
  </header>

  <div class="form">
    {#if emailAvailable}
      <div class="switch" role="tablist">
        <button type="button" role="tab" class:on={mode === 'phone'} onclick={() => switchMode('phone')}>
          <i class="bi bi-phone"></i> Telefone
        </button>
        <button type="button" role="tab" class:on={mode === 'email'} onclick={() => switchMode('email')}>
          <i class="bi bi-envelope"></i> E-mail
        </button>
      </div>
    {/if}

    {#if mode === 'phone'}
      <p class="label">Número de celular</p>
      <div class="phone-row">
        <span class="ddi">🇧🇷 +55</span>
        <input
          type="tel"
          inputmode="numeric"
          class="fuu-mono"
          placeholder="(19) 99128-4407"
          value={phoneMask}
          oninput={(e) => (phone = e.currentTarget.value)}
          onkeydown={(e) => e.key === 'Enter' && submit()}
        />
      </div>
    {:else}
      <p class="label">Seu e-mail</p>
      <input
        type="email"
        placeholder="voce@email.com"
        bind:value={email}
        onkeydown={(e) => e.key === 'Enter' && submit()}
      />
    {/if}

    {#if needsName}
      <p class="label">Como você se chama</p>
      <input
        type="text"
        placeholder="Nome completo"
        bind:value={fullName}
        onkeydown={(e) => e.key === 'Enter' && submit()}
      />
    {/if}

    <button type="button" class="btn-fuu-primary w-100 send" disabled={!canSubmit || busy} onclick={submit}>
      {busy ? 'Enviando…' : 'Receber código'}
      <i class="bi bi-arrow-right"></i>
    </button>

    <div class="or"><span></span><small>ou</small><span></span></div>

    <!-- O mock oferece Google e Apple como atalho. Não existe OAuth neste
         backend (nem tabela de identidade federada), então os botões ficam
         desabilitados e dizendo isso -- mesma escolha já feita com o BitPay
         na tela 4.1, em vez de botão que não faz nada. -->
    <button type="button" class="social" disabled>
      <i class="bi bi-google"></i> Continuar com Google
      <span class="soon">em breve</span>
    </button>
    <button type="button" class="social" disabled>
      <i class="bi bi-apple"></i> Continuar com Apple
      <span class="soon">em breve</span>
    </button>

    <p class="legal">
      Ao continuar você aceita os <a href="/termos.html" target="_blank" rel="noopener">Termos de uso</a> e o
      <a href="/privacidade.html" target="_blank" rel="noopener">Aviso de privacidade</a>. Tratamos seus dados para executar o contrato de
      entrega (art. 7º, V, LGPD).
    </p>

    <button type="button" class="partner" onclick={onPartnerLogin}>
      Entrar como restaurante ou entregador
    </button>
  </div>
</div>

<style>
  .login {
    background: var(--fuu-white);
    flex: 1;
  }
  .hero {
    padding: 44px 22px 28px;
  }
  .mark {
    width: 58px;
    height: 58px;
    border-radius: 17px;
    background: var(--fuu-red);
    color: var(--fuu-white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 21px;
    font-weight: 800;
    letter-spacing: -0.04em;
  }
  h1 {
    font-size: 27px;
    font-weight: 800;
    letter-spacing: -0.03em;
    margin: 16px 0 0;
    color: var(--fuu-ink-1);
  }
  .sub {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 5px 0 0;
    line-height: 1.5;
  }
  .form {
    padding: 20px 22px 28px;
  }
  .switch {
    display: flex;
    background: var(--fuu-line-5);
    border-radius: 10px;
    padding: 4px;
    margin-bottom: 16px;
  }
  .switch button {
    flex: 1;
    border: none;
    background: transparent;
    border-radius: 8px;
    padding: 10px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
  .switch button.on {
    background: var(--fuu-white);
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--fuu-ink-4);
    margin: 0 0 6px;
  }
  .phone-row {
    display: flex;
    gap: 8px;
  }
  .ddi {
    width: 86px;
    flex: none;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-weight: 700;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  input {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 13px 14px;
    font-family: var(--fuu-font-body);
    font-size: 15px;
    color: var(--fuu-ink-1);
  }
  input + .label {
    margin-top: 12px;
  }
  .send {
    margin-top: 16px;
    min-height: var(--fuu-tap-customer);
  }
  .or {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 20px 0;
  }
  .or span {
    flex: 1;
    height: 1px;
    background: var(--fuu-line-4);
  }
  .or small {
    font-size: 11px;
    color: var(--fuu-ink-5);
    font-weight: 700;
  }
  .social {
    width: 100%;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 14px;
    font-family: var(--fuu-font-body);
    font-weight: 700;
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    margin-bottom: 9px;
    color: var(--fuu-ink-2);
  }
  .social:disabled {
    color: var(--fuu-ink-5);
  }
  .soon {
    font-family: var(--fuu-font-mono);
    font-size: 9.5px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    background: var(--fuu-line-5);
    border-radius: 999px;
    padding: 2px 8px;
  }
  .legal {
    font-size: 11px;
    color: var(--fuu-ink-3);
    line-height: 1.6;
    margin: 18px 0 0;
    text-align: center;
  }
  .legal a {
    color: var(--fuu-ink-2);
  }
  .partner {
    display: block;
    width: 100%;
    background: none;
    border: none;
    text-align: center;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-red);
    margin-top: 16px;
  }
</style>
