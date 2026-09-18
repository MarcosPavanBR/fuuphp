<script>
  import { adminRequestCode, adminVerify } from '../../adminSession.svelte.js';
  import { toastr } from '../../toastr.js';

  // Admin entra pelo OTP, igual ao cliente: a conta é de uma pessoa. O que
  // separa é o papel, e quem confere isso a cada chamada é o servidor.
  let { onLoggedIn } = $props();

  let step = $state('phone');
  let phone = $state('');
  let code = $state('');
  let busy = $state(false);

  let digits = $derived(phone.replace(/\D/g, ''));

  async function askCode() {
    if (digits.length < 10 || busy) return;
    busy = true;
    try {
      await adminRequestCode(digits);
      step = 'code';
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra enviar o código.');
    } finally {
      busy = false;
    }
  }

  async function confirm() {
    if (code.length !== 6 || busy) return;
    busy = true;
    try {
      await adminVerify({ phone: digits, code });
      onLoggedIn();
    } catch (e) {
      toastr.error(e.message ?? 'Código inválido.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="login">
  <div class="card fuu-card">
    <span class="tag fuu-mono">PLATAFORMA</span>
    <h1 class="fuu-display">Painel FUUdelivery</h1>

    {#if step === 'phone'}
      <p class="sub">Entre com o telefone da sua conta de administrador.</p>
      <input
        type="tel"
        inputmode="numeric"
        class="fuu-mono"
        placeholder="11999998888"
        bind:value={phone}
        onkeydown={(e) => e.key === 'Enter' && askCode()}
      />
      <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={askCode}>
        {busy ? 'Enviando…' : 'Receber código'}
      </button>
    {:else}
      <p class="sub">Código de 6 dígitos enviado por SMS.</p>
      <input
        type="text"
        inputmode="numeric"
        maxlength="6"
        class="code fuu-mono"
        placeholder="000000"
        bind:value={code}
        onkeydown={(e) => e.key === 'Enter' && confirm()}
      />
      <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={confirm}>
        {busy ? 'Entrando…' : 'Entrar'}
      </button>
      <button type="button" class="link" onclick={() => (step = 'phone')}>Trocar telefone</button>
    {/if}
  </div>
</div>

<style>
  .login {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--fuu-ink-1);
    padding: 24px;
  }
  .card {
    width: 100%;
    max-width: 390px;
    padding: 28px 24px;
  }
  .tag {
    font-size: 10px;
    letter-spacing: 0.12em;
    color: var(--fuu-red);
    font-weight: 700;
  }
  h1 {
    font-size: 22px;
    font-weight: 800;
    margin: 6px 0 0;
    color: var(--fuu-ink-1);
  }
  .sub {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 6px 0 16px;
  }
  input {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 13px 14px;
    font-size: 16px;
    margin-bottom: 14px;
    color: var(--fuu-ink-1);
  }
  input.code {
    text-align: center;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: 0.25em;
  }
  .btn-fuu-primary {
    min-height: var(--fuu-tap-operator);
  }
  .link {
    display: block;
    width: 100%;
    background: none;
    border: none;
    margin-top: 12px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
</style>
