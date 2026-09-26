<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { loadProfile } from '../../state/customerSession.svelte.js';

  // Tela 2.5 — "Editar perfil" (o link embaixo do nome, no mock).
  //
  // Os mesmos campos do cadastro 10.3, agora editáveis: nome, e-mail, CPF
  // (na nota) e data de nascimento. O telefone aparece travado: é o que o
  // código por SMS verificou, e trocar exige verificar de novo.
  //
  // CPF: o servidor devolve só mascarado (`cpf_masked`, minimização de
  // dado). O campo começa vazio e só manda CPF se a pessoa digitar um novo.
  let { onBack } = $props();

  let loading = $state(true);
  let saving = $state(false);
  let phone = $state('');
  let cpfMasked = $state(null);
  let fullName = $state('');
  let email = $state('');
  let birthDate = $state('');
  let newCpf = $state('');

  let cpfDigits = $derived(newCpf.replace(/\D/g, '').slice(0, 11));

  async function load() {
    try {
      const data = await api.get('/profile/show.php', { auth: true });
      fullName = data.user.full_name ?? '';
      email = data.user.email ?? '';
      phone = data.user.phone ?? '';
      birthDate = data.user.birth_date ?? '';
      cpfMasked = data.user.cpf_masked;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar seu perfil.');
    } finally {
      loading = false;
    }
  }
  load();

  async function save() {
    if (fullName.trim() === '') {
      toastr.warning('O nome é obrigatório.');
      return;
    }
    if (cpfDigits !== '' && cpfDigits.length !== 11) {
      toastr.warning('O CPF tem 11 dígitos.');
      return;
    }
    saving = true;
    try {
      await api.post('/profile/update.php', {
        auth: true,
        body: {
          full_name: fullName.trim(),
          email: email.trim() === '' ? null : email.trim(),
          birth_date: birthDate === '' ? null : birthDate,
          ...(cpfDigits !== '' ? { cpf: cpfDigits } : {}),
        },
      });
      await loadProfile();
      toastr.success('Perfil atualizado ✓');
      onBack();
    } catch (e) {
      toastr.error(e instanceof ApiError ? e.message : 'Não deu pra salvar.');
    } finally {
      saving = false;
    }
  }
</script>

<div class="edit-profile">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>
    <h1 class="fuu-display">Editar perfil</h1>
  </div>

  {#if loading}
    <p class="hint">Carregando…</p>
  {:else}
    <label class="field">
      <span>Nome completo</span>
      <input maxlength="120" type="text" autocomplete="name" bind:value={fullName} />
    </label>

    <label class="field">
      <span>Celular</span>
      <input type="text" value={phone} disabled />
      <small><i class="bi bi-patch-check-fill"></i> verificado por código — pra trocar, fale com o suporte</small>
    </label>

    <label class="field">
      <span>E-mail</span>
      <input maxlength="254" type="email" autocomplete="email" bind:value={email} placeholder="opcional" />
    </label>

    <label class="field">
      <span>CPF (sai na nota fiscal)</span>
      <input type="text" inputmode="numeric" bind:value={newCpf} placeholder={cpfMasked ?? 'não informado'} />
      {#if cpfMasked}<small>Deixe em branco pra manter o atual.</small>{/if}
    </label>

    <label class="field">
      <span>Data de nascimento</span>
      <input type="date" bind:value={birthDate} />
      <small>Opcional — só pra o cupom de aniversário. Apague pra retirar.</small>
    </label>

    <button type="button" class="btn-fuu-primary w-100 save" disabled={saving} onclick={save}>
      {saving ? 'Salvando…' : 'Salvar'}
    </button>
  {/if}
</div>

<style>
  .edit-profile {
    padding: 12px 20px 40px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
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
  .field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: 14px;
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .field input {
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 11px 12px;
    font-family: var(--fuu-font-body);
    font-size: 15px;
    color: var(--fuu-ink-1);
    background: var(--fuu-white);
  }
  .field input:disabled {
    background: var(--fuu-line-6);
    color: var(--fuu-ink-4);
  }
  .field small {
    font-size: 11px;
    color: var(--fuu-ink-5);
  }
  .field small i {
    color: var(--fuu-leaf);
  }
  .save {
    margin-top: 8px;
  }
  .hint {
    color: var(--fuu-ink-5);
    font-size: 13px;
  }
</style>
