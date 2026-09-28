<script>
  import BrandMark from '../../components/BrandMark.svelte';
  import { staffLogin } from '../../state/staffSession.svelte.js';
  import { toastr } from '../../utils/toastr.js';
  import { ApiError } from '../../services/api.js';

  // Login da loja: CNPJ + senha, o mesmo partner_login.php que o smoke
  // test já exercita. O device_id vai junto porque partner_accounts faz
  // confiança-no-primeiro-uso (2FA por aparelho, módulo identity): o
  // primeiro aparelho a logar fica gravado, os outros levam
  // device_mismatch até o suporte liberar.
  let { onLoggedIn, onSignup, initialCnpj = '' } = $props();

  // Vindo do cadastro ("Quero vender no FUU"), o CNPJ já chega preenchido.
  let cnpj = $state(initialCnpj);
  let secret = $state('');
  let busy = $state(false);

  function deviceId() {
    try {
      let id = localStorage.getItem('fuu_staff_device');
      if (!id) {
        id = crypto.randomUUID();
        localStorage.setItem('fuu_staff_device', id);
      }
      return id;
    } catch {
      return undefined;
    }
  }

  async function submit() {
    const digits = cnpj.replace(/\D/g, '');
    if (digits.length !== 14 || secret === '') {
      toastr.warning('Informe o CNPJ completo e a senha.');
      return;
    }
    busy = true;
    try {
      await staffLogin({ cnpj: digits, secret, deviceId: deviceId() });
      onLoggedIn();
    } catch (e) {
      const message =
        e instanceof ApiError && e.code === 'device_mismatch'
          ? 'Este login está vinculado a outro aparelho. Peça ao suporte para liberar a troca.'
          : (e.message ?? 'Não deu pra entrar.');
      toastr.error(message);
    } finally {
      busy = false;
    }
  }
</script>

<div class="login-screen">
  <div class="login-card fuu-card">
    <div class="brand">
      <BrandMark size={44} />
      <div>
        <p class="title fuu-display">Painel da loja</p>
        <p class="subtitle">Entre com o CNPJ e a senha da loja</p>
      </div>
    </div>

    <label class="field">
      <span>CNPJ</span>
      <input type="text" inputmode="numeric" placeholder="00.000.000/0000-00" bind:value={cnpj} />
    </label>
    <label class="field">
      <span>Senha</span>
      <input
        type="password"
        placeholder="••••••••"
        bind:value={secret}
        onkeydown={(e) => e.key === 'Enter' && submit()}
      />
    </label>

    <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={submit}>
      {busy ? 'Entrando…' : 'Entrar'}
    </button>

    <p class="note">
      Cada aparelho fica vinculado ao primeiro login (2FA por aparelho). Trocar de tablet exige liberação do suporte.
    </p>

    {#if onSignup}
      <button type="button" class="signup" onclick={onSignup}>
        Ainda não vende no FUU? <strong>Cadastre sua loja</strong>
      </button>
    {/if}
  </div>
</div>

<style>
  .login-screen {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--fuu-paper);
    padding: 24px;
  }
  .login-card {
    width: 100%;
    max-width: 400px;
    padding: 28px 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }
  .brand {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 6px;
  }
  .title {
    margin: 0;
    font-size: 19px;
    color: var(--fuu-ink-1);
  }
  .subtitle {
    margin: 2px 0 0;
    font-size: 12.5px;
    color: var(--fuu-ink-5);
  }
  .field {
    display: block;
  }
  .field span {
    display: block;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin-bottom: 4px;
  }
  input {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 11px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
  }
  .signup {
    background: none;
    border: none;
    border-top: 1px solid var(--fuu-line-4);
    padding: 14px 0 0;
    font-size: 13.5px;
    color: var(--fuu-ink-3);
  }
  .signup strong {
    color: var(--fuu-red);
  }
  .note {
    font-size: 11px;
    color: var(--fuu-ink-5);
    margin: 4px 0 0;
  }
</style>
