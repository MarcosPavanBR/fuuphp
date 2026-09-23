<script>
  import BrandMark from '../../components/BrandMark.svelte';
  import { courierLogin } from '../../state/courierSession.svelte.js';
  import { toastr } from '../../utils/toastr.js';
  import { ApiError } from '../../services/api.js';

  // Tela 10.7, lado do entregador — "entregador usa CPF + código, porque
  // troca de celular com frequência". Sem senha: o código é o segredo, e o
  // servidor guarda só o hash dele (partner_accounts.access_code_hash).
  let { onLoggedIn, onApply } = $props();

  let cpf = $state('');
  let code = $state('');
  let busy = $state(false);

  let digits = $derived(cpf.replace(/\D/g, ''));
  let cpfMask = $derived.by(() => {
    const d = digits.slice(0, 11);
    if (d.length <= 3) return d;
    if (d.length <= 6) return `${d.slice(0, 3)}.${d.slice(3)}`;
    if (d.length <= 9) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6)}`;
    return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
  });

  function deviceId() {
    try {
      let id = localStorage.getItem('fuu_courier_device');
      if (!id) {
        id = crypto.randomUUID();
        localStorage.setItem('fuu_courier_device', id);
      }
      return id;
    } catch {
      return undefined;
    }
  }

  async function submit() {
    if (digits.length !== 11 || code.length < 4 || busy) return;
    busy = true;
    try {
      await courierLogin({ cpf: digits, code, deviceId: deviceId() });
      onLoggedIn();
    } catch (e) {
      toastr.error(
        e instanceof ApiError && e.code === 'device_mismatch'
          ? 'Esse login está preso a outro aparelho. Peça liberação ao suporte.'
          : (e.message ?? 'Não deu pra entrar.')
      );
    } finally {
      busy = false;
    }
  }
</script>

<div class="login">
  <div class="mark"><BrandMark size={58} /></div>
  <h1 class="fuu-display">App do entregador</h1>
  <p class="sub">Entre com o CPF e o código que o suporte te passou.</p>

  <label class="field">
    <span>CPF</span>
    <input
      type="text"
      inputmode="numeric"
      class="fuu-mono"
      placeholder="000.000.000-00"
      value={cpfMask}
      oninput={(e) => (cpf = e.currentTarget.value)}
    />
  </label>

  <label class="field">
    <span>Código de acesso</span>
    <input
      type="password"
      inputmode="numeric"
      class="fuu-mono"
      placeholder="••••••"
      bind:value={code}
      onkeydown={(e) => e.key === 'Enter' && submit()}
    />
  </label>

  <button type="button" class="btn-fuu-primary w-100 enter" disabled={busy} onclick={submit}>
    {busy ? 'Entrando…' : 'Entrar'}
  </button>

  <p class="note">
    Cada aparelho fica vinculado ao primeiro login. Trocou de celular? O suporte libera.
  </p>

  <!-- 15.2 — quem ainda não entrega começa por aqui. O CPF e o código de
       acesso deste formulário só existem depois da candidatura aprovada. -->
  <button type="button" class="apply-link" onclick={onApply}>
    Ainda não sou entregador — quero me candidatar
  </button>
</div>

<style>
  .apply-link {
    display: block;
    width: 100%;
    background: none;
    border: none;
    margin-top: 18px;
    font-family: var(--fuu-font-body);
    font-size: 13.5px;
    font-weight: 700;
    color: var(--fuu-white);
    text-decoration: underline;
    min-height: var(--fuu-tap-operator);
  }
  .login {
    min-height: 100vh;
    background: var(--fuu-white);
    padding: 40px 22px;
    display: flex;
    flex-direction: column;
    max-width: 430px;
    margin: 0 auto;
  }
  .mark {
    width: 58px;
  }
  h1 {
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 16px 0 0;
    color: var(--fuu-ink-1);
  }
  .sub {
    font-size: 13.5px;
    color: var(--fuu-ink-4);
    margin: 6px 0 22px;
  }
  .field {
    display: block;
    margin-bottom: 14px;
  }
  .field span {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-4);
    margin-bottom: 5px;
  }
  input {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 14px;
    font-size: 17px;
    color: var(--fuu-ink-1);
  }
  .enter {
    min-height: var(--fuu-tap-operator);
    font-size: 16px;
  }
  .note {
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    line-height: 1.55;
    margin-top: 16px;
  }
</style>
