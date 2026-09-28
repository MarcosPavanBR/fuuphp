<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';

  // Segundo fator do admin logado (admin/totp.php, auditoria SEG-04). Com ele
  // ligado, entrar pede o SMS E o código do app autenticador: quem clonar o
  // chip não entra só com o SMS. QR Code pediria biblioteca nova, então vai o
  // segredo pra digitar no app ("inserir chave de configuração").
  // onChange: avisa o painel quando o fator liga (em produção é o que libera
  // as outras abas, api/v1/admin/guard.php).
  let { onChange = () => {} } = $props();
  let state = $state(null); // {enabled, pending, required}
  let setup = $state(null); // {secret, uri}
  let code = $state('');
  let busy = $state(false);

  async function load() {
    try {
      state = await api.get('/admin/totp.php', { token: adminToken() });
      onChange(state);
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra ver o segundo fator.');
    }
  }

  $effect(() => {
    load();
  });

  async function act(action) {
    if (busy) return;
    busy = true;
    try {
      const res = await api.post('/admin/totp.php', { token: adminToken(), body: { action, code } });
      if (action === 'start') {
        setup = res;
      } else {
        setup = null;
        toastr.success(res.enabled ? 'Segundo fator ligado. O próximo login pede o código do app.' : 'Segundo fator desligado.');
      }
      code = '';
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu certo.');
    } finally {
      busy = false;
    }
  }

  function grouped(secret) {
    return secret.replace(/(.{4})/g, '$1 ').trim();
  }
</script>

<div class="fuu-card block">
  <p class="section">SEU SEGUNDO FATOR</p>
  {#if !state}
    <p class="note">Carregando…</p>
  {:else if state.enabled}
    <p class="ok"><i class="bi bi-shield-check"></i> Ligado: seu login pede o SMS e o código do app autenticador.</p>
    {#if state.required}
      <p class="note">Aqui ele é obrigatório e não pode ser desligado. Trocou de celular? O passo de recuperação está no manual de operação.</p>
    {:else}
      <div class="row">
        <input inputmode="numeric" maxlength="6" placeholder="código do app" bind:value={code} aria-label="Código do app autenticador" />
        <button type="button" class="ghost" disabled={busy || code.length !== 6} onclick={() => act('disable')}>Desligar</button>
      </div>
    {/if}
  {:else if setup}
    <ol class="steps">
      <li>No app autenticador, escolha <strong>inserir chave de configuração</strong> e digite:</li>
    </ol>
    <p class="secret fuu-mono">{grouped(setup.secret)}</p>
    <p class="note">Nome da conta: FUUdelivery. Tipo: baseado em tempo.</p>
    <ol class="steps" start="2">
      <li>Digite o código de 6 dígitos que o app mostrar:</li>
    </ol>
    <div class="row">
      <input inputmode="numeric" maxlength="6" placeholder="000000" bind:value={code} aria-label="Código do app autenticador" />
      <button type="button" class="btn-fuu-primary" disabled={busy || code.length !== 6} onclick={() => act('confirm')}>Confirmar</button>
    </div>
  {:else}
    <p class="note">
      Hoje seu login é só o código por SMS: quem clonar seu chip entra no painel. Ligue o código de um app
      autenticador (Google Authenticator, Microsoft Authenticator, 2FAS) como segunda chave.
    </p>
    <button type="button" class="btn-fuu-primary" disabled={busy} onclick={() => act('start')}>Ligar segundo fator</button>
  {/if}
</div>

<style>
  .block {
    padding: 16px;
    margin-top: 12px;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 10px;
  }
  .note,
  .steps {
    font-size: 13px;
    color: var(--fuu-ink-3);
    margin: 0 0 10px;
    line-height: 1.5;
  }
  .steps {
    padding-left: 20px;
  }
  .ok {
    font-size: 13.5px;
    color: var(--fuu-leaf);
    font-weight: 600;
    margin: 0 0 10px;
  }
  .secret {
    font-size: 17px;
    letter-spacing: 0.06em;
    background: var(--fuu-line-5);
    border-radius: 10px;
    padding: 10px 12px;
    margin: 0 0 8px;
    word-break: break-all;
    user-select: all;
  }
  .row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  input {
    border: 1px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 9px 12px;
    font-family: var(--fuu-font-mono);
    font-size: 15px;
    width: 140px;
  }
  .ghost {
    border: 1.5px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: 10px;
    padding: 8px 14px;
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
</style>
