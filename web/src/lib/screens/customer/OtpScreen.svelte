<script>
  import { toastr } from '../../utils/toastr.js';
  import { ApiError } from '../../services/api.js';
  import { requestOtp, verifyOtp } from '../../state/customerSession.svelte.js';
  import { authChannels } from '../../services/authChannels.js';

  // Tela 10.2 — "Só o hash do código fica no banco. Bloqueio por tentativas
  // no servidor, não no app."
  //
  // As seis caixas são um input só por baixo: um campo por dígito dá
  // dor de cabeça com colar código, autofill de SMS e apagar pra trás. O
  // que se vê são seis caixas desenhadas em cima de um input transparente
  // -- o navegador continua tratando como um campo de 6 dígitos, que é o
  // que o `autocomplete="one-time-code"` precisa pra funcionar.
  let { contact, purpose, onVerified, onChangeContact } = $props();
  // "Receber por WhatsApp" só quando o provedor tem remetente de WhatsApp.
  let whatsappAvailable = $state(false);
  $effect(() => {
    authChannels().then((c) => (whatsappAvailable = c.whatsapp === true));
  });

  const RESEND_SECONDS = 60;

  let code = $state('');
  let busy = $state(false);
  let secondsLeft = $state(RESEND_SECONDS);
  let inputEl = $state(null);

  $effect(() => {
    const t = setInterval(() => {
      if (secondsLeft > 0) secondsLeft -= 1;
    }, 1000);
    return () => clearInterval(t);
  });

  $effect(() => {
    inputEl?.focus();
  });

  let label = $derived(contact.phone ? formatPhone(contact.phone) : contact.email);
  let channelName = $derived(contact.phone ? 'SMS' : 'e-mail');
  let countdown = $derived(`0:${String(secondsLeft).padStart(2, '0')}`);

  function formatPhone(digits) {
    const d = digits.replace(/\D/g, '');
    return d.length === 11
      ? `+55 (${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
      : `+55 (${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  }

  function onInput(e) {
    code = e.currentTarget.value.replace(/\D/g, '').slice(0, 6);
    e.currentTarget.value = code;
    if (code.length === 6) submit();
  }

  async function submit() {
    if (code.length !== 6 || busy) return;
    busy = true;
    try {
      const data = await verifyOtp({ ...contact, code, purpose });
      onVerified(data);
    } catch (e) {
      const known = e instanceof ApiError ? e.code : null;
      if (known === 'otp_locked' || known === 'otp_expired') {
        secondsLeft = 0;
      }
      toastr.error(e.message ?? 'Código inválido.');
      code = '';
      if (inputEl) inputEl.value = '';
    } finally {
      busy = false;
    }
  }

  async function resend(channel) {
    busy = true;
    try {
      await requestOtp({ ...contact, purpose, channel });
      secondsLeft = RESEND_SECONDS;
      toastr.success(channel === 'whatsapp' ? 'Código reenviado por WhatsApp.' : 'Código reenviado.');
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra reenviar.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="otp">
  <button type="button" class="back" onclick={onChangeContact} aria-label="Voltar">
    <i class="bi bi-arrow-left"></i>
  </button>

  <h1 class="fuu-display">Digite o código</h1>
  <p class="sent">
    Enviamos por {channelName} para <strong>{label}</strong>.
    <button type="button" class="link" onclick={onChangeContact}>Trocar</button>
  </p>

  <div class="boxes">
    {#each Array(6) as _, i}
      <div class="box fuu-mono" class:filled={code.length > i} class:active={code.length === i}>
        {code[i] ?? '–'}
      </div>
    {/each}
    <input
      bind:this={inputEl}
      type="text"
      inputmode="numeric"
      autocomplete="one-time-code"
      maxlength="6"
      aria-label="Código de 6 dígitos"
      oninput={onInput}
      onkeydown={(e) => e.key === 'Enter' && submit()}
    />
  </div>

  <p class="timer">
    <i class="bi bi-clock"></i>
    {#if secondsLeft > 0}
      Reenviar em <strong>{countdown}</strong>
    {:else}
      <button type="button" class="link" disabled={busy} onclick={() => resend('sms')}>Reenviar código</button>
    {/if}
  </p>

  <div class="fuu-card note">
    <i class="bi bi-shield-lock"></i>
    <p>
      Código de 6 dígitos, válido por 5 minutos, 5 tentativas. Depois disso é preciso pedir um novo — a
      contagem é do servidor, não deste aparelho.
    </p>
  </div>

  {#if contact.phone && whatsappAvailable}
    <p class="alt">
      Não recebeu?
      <button type="button" class="link" disabled={busy} onclick={() => resend('whatsapp')}>
        Receber por WhatsApp
      </button>
    </p>
  {/if}

  <button type="button" class="btn-fuu-primary w-100 confirm" disabled={code.length !== 6 || busy} onclick={submit}>
    {busy ? 'Confirmando…' : 'Confirmar'}
  </button>
</div>

<style>
  .otp {
    background: var(--fuu-white);
    flex: 1;
    padding: 22px;
    display: flex;
    flex-direction: column;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-1);
    padding: 0;
    align-self: flex-start;
  }
  h1 {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 18px 0 6px;
    color: var(--fuu-ink-1);
  }
  .sent {
    font-size: 13.5px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    margin: 0;
  }
  .link {
    background: none;
    border: none;
    padding: 0;
    font-family: var(--fuu-font-body);
    font-size: inherit;
    font-weight: 700;
    color: var(--fuu-red);
  }
  .boxes {
    position: relative;
    display: flex;
    gap: 8px;
    margin-top: 22px;
  }
  .box {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 15px 0;
    text-align: center;
    font-size: 24px;
    font-weight: 800;
    color: var(--fuu-ink-5);
  }
  .box.filled {
    color: var(--fuu-ink-1);
  }
  .box.active {
    border: 2px solid var(--fuu-red);
  }
  /* O input de verdade fica por cima, invisível: o teclado numérico, o colar
     e o preenchimento automático do SMS continuam funcionando. */
  .boxes input {
    position: absolute;
    inset: 0;
    width: 100%;
    opacity: 0;
    border: none;
    font-size: 24px;
  }
  .timer {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    margin-top: 16px;
  }
  .timer i {
    color: var(--fuu-red);
  }
  .note {
    padding: 14px;
    margin-top: 20px;
    display: flex;
    gap: 10px;
  }
  .note i {
    color: var(--fuu-leaf-dark);
    margin-top: 2px;
  }
  .note p {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    margin: 0;
  }
  .alt {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    line-height: 1.6;
    margin-top: 16px;
  }
  .confirm {
    margin-top: auto;
    min-height: var(--fuu-tap-customer);
  }
</style>
