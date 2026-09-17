<script>
  import { requestOtp, verifyOtp } from '../session.svelte.js';
  import { toastr } from '../toastr.js';
  import { ApiError } from '../api.js';

  // Login mínimo, funcional de verdade (chama /auth/otp_request e
  // /otp_verify de verdade) -- não é a tela desenhada da Fase 10, que
  // ainda não foi portada. Existe só pra destravar as abas da Fase 2 que
  // precisam de usuário autenticado (pedidos, perfil, fidelidade).
  let { onSuccess } = $props();

  let step = $state('phone');
  let phone = $state('');
  let fullName = $state('');
  let code = $state('');
  let busy = $state(false);
  let needsName = $state(false);

  async function submitPhone() {
    busy = true;
    try {
      await requestOtp({ phone, fullName: needsName ? fullName : undefined, purpose: needsName ? 'signup' : 'login' });
      step = 'code';
    } catch (e) {
      if (e instanceof ApiError && e.code === 'user_not_found') {
        needsName = true;
        toastr.info('Não achamos essa conta. Preencha seu nome para criar uma.');
      } else {
        toastr.error(e.message ?? 'Não deu pra pedir o código.');
      }
    } finally {
      busy = false;
    }
  }

  async function submitCode() {
    busy = true;
    try {
      await verifyOtp({ phone, code, purpose: needsName ? 'signup' : 'login' });
      onSuccess();
    } catch (e) {
      toastr.error(e.message ?? 'Código inválido.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="quick-login fuu-card">
  <p class="badge-note">login provisório · Fase 10 ainda não portada</p>
  {#if step === 'phone'}
    <h2 class="fuu-display">Entrar</h2>
    <p class="hint">Seu telefone com DDD.</p>
    <input type="tel" placeholder="11999998888" bind:value={phone} />
    {#if needsName}
      <input type="text" placeholder="Nome completo" bind:value={fullName} />
    {/if}
    <button type="button" class="btn-fuu-primary w-100" disabled={busy || !phone} onclick={submitPhone}>
      {busy ? 'Enviando…' : 'Continuar'}
    </button>
  {:else}
    <h2 class="fuu-display">Digite o código</h2>
    <p class="hint">Enviamos um código de 6 dígitos por SMS.</p>
    <input type="text" inputmode="numeric" maxlength="6" placeholder="000000" bind:value={code} />
    <button type="button" class="btn-fuu-primary w-100" disabled={busy || code.length !== 6} onclick={submitCode}>
      {busy ? 'Confirmando…' : 'Confirmar'}
    </button>
  {/if}
</div>

<style>
  .quick-login {
    max-width: 340px;
    margin: 40px auto;
    padding: 24px 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .badge-note {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    letter-spacing: 0.08em;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    border-radius: 999px;
    padding: 3px 10px;
    align-self: flex-start;
    margin: 0 0 6px;
  }
  h2 {
    margin: 0;
    font-size: 20px;
  }
  .hint {
    color: var(--fuu-ink-4);
    font-size: 13px;
    margin: 0 0 4px;
  }
  input {
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
  }
</style>
