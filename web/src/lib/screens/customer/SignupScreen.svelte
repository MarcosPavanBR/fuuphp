<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { currentUser, loadProfile } from '../../state/customerSession.svelte.js';

  // Tela 10.3 — "Três blocos: obrigatório para operar, exigido por lei (CPF
  // na nota) e opcional com consentimento separado. Sem consentimento
  // embutido em aceite de termos."
  //
  // Os blocos não são decoração: cada um tem uma BASE LEGAL diferente, e é
  // por isso que marketing e data de nascimento vão parar em `consents`
  // (revogáveis, um registro por consentimento) enquanto CPF vai direto na
  // conta. Marcar "aceito os termos" não liga marketing junto -- é
  // exatamente o que a tela promete e o que a LGPD exige.
  //
  // O telefone já chegou verificado pelo OTP: aparece travado, com o selo
  // verde, porque trocá-lo exigiria verificar de novo (outro fluxo).
  let { onDone } = $props();

  const TERMS_VERSION = '2026-09-01';

  let user = $derived(currentUser());
  let fullName = $state(currentUser()?.full_name ?? '');
  let email = $state(currentUser()?.email ?? '');
  let cpf = $state('');
  let wantsMarketing = $state(false);
  let wantsBirthday = $state(false);
  let birthDate = $state('');
  let busy = $state(false);

  let cpfDigits = $derived(cpf.replace(/\D/g, ''));
  let cpfMask = $derived.by(() => {
    const d = cpfDigits.slice(0, 11);
    if (d.length <= 3) return d;
    if (d.length <= 6) return `${d.slice(0, 3)}.${d.slice(3)}`;
    if (d.length <= 9) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6)}`;
    return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
  });

  let phoneLabel = $derived.by(() => {
    const d = (user?.phone ?? '').replace(/\D/g, '');
    if (d === '') return null;
    return d.length === 11
      ? `+55 (${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`
      : `+55 (${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  });

  let ready = $derived(
    fullName.trim().length >= 3 &&
      cpfDigits.length === 11 &&
      (!wantsBirthday || birthDate !== '')
  );

  // Progresso honesto: conta o que já está preenchido, não um número fixo.
  let progress = $derived(
    Math.round(
      ((fullName.trim() !== '' ? 1 : 0) +
        (phoneLabel ? 1 : 0) +
        (email.trim() !== '' ? 1 : 0) +
        (cpfDigits.length === 11 ? 1 : 0)) *
        25
    )
  );

  async function submit() {
    if (!ready || busy) return;
    busy = true;
    try {
      await api.post('/profile/update.php', {
        auth: true,
        body: {
          full_name: fullName.trim(),
          email: email.trim() === '' ? null : email.trim(),
          cpf: cpfDigits,
          birth_date: wantsBirthday && birthDate !== '' ? birthDate : null,
        },
      });

      // Termos e aviso de privacidade são a base pra operar a conta;
      // marketing só entra se a pessoa marcou. Três registros distintos em
      // `consents`, cada um com versão e IP -- nunca um só "aceito tudo".
      await api.post('/auth/consent.php', { auth: true, body: { kind: 'terms', version: TERMS_VERSION } });
      await api.post('/auth/consent.php', {
        auth: true,
        body: { kind: 'privacy_policy', version: TERMS_VERSION },
      });
      if (wantsMarketing) {
        await api.post('/auth/consent.php', { auth: true, body: { kind: 'marketing', version: TERMS_VERSION } });
      }

      await loadProfile();
      toastr.success('Conta criada. Bom apetite 🍕');
      onDone();
    } catch (e) {
      const known = e instanceof ApiError ? e.code : null;
      if (known === 'cpf_in_use' || known === 'email_in_use' || known === 'invalid_cpf') {
        toastr.error(e.message);
      } else {
        toastr.error(e.message ?? 'Não deu pra salvar seu cadastro.');
      }
    } finally {
      busy = false;
    }
  }
</script>

<div class="signup">
  <header class="head">
    <div class="title-row">
      <button type="button" class="back" onclick={onDone} aria-label="Depois">
        <i class="bi bi-arrow-left"></i>
      </button>
      <h1 class="fuu-display">Criar sua conta</h1>
    </div>
    <div class="bar"><div class="fill" style={`width:${progress}%`}></div></div>
  </header>

  <div class="body">
    <p class="block-label">OBRIGATÓRIO PARA ENTREGAR</p>

    <p class="label">Nome completo</p>
    <input type="text" bind:value={fullName} placeholder="Seu nome" />

    {#if phoneLabel}
      <p class="label">Telefone <span class="ok">· verificado</span></p>
      <div class="locked fuu-mono">
        {phoneLabel}
        <i class="bi bi-check-circle-fill"></i>
      </div>
    {/if}

    <p class="label">E-mail</p>
    <input type="email" bind:value={email} placeholder="voce@email.com" />

    <p class="block-label">EXIGIDO POR LEI NA NOTA FISCAL</p>
    <p class="label">CPF</p>
    <input
      type="text"
      inputmode="numeric"
      class="fuu-mono"
      placeholder="000.000.000-00"
      value={cpfMask}
      oninput={(e) => (cpf = e.currentTarget.value)}
    />
    <p class="legal">
      Usado para emissão fiscal do pedido e prevenção a fraude. Base legal: obrigação legal (art. 7º,
      II) e legítimo interesse (art. 7º, IX).
    </p>

    <p class="block-label">OPCIONAL — VOCÊ ESCOLHE</p>

    <label class="check">
      <input type="checkbox" bind:checked={wantsMarketing} />
      <span>
        Receber ofertas e cupons por WhatsApp, SMS e e-mail
        <small>(consentimento, revogável a qualquer momento)</small>
      </span>
    </label>

    <label class="check">
      <input type="checkbox" bind:checked={wantsBirthday} />
      <span>Data de nascimento — libera promoções de aniversário</span>
    </label>

    {#if wantsBirthday}
      <input type="date" bind:value={birthDate} max={new Date().toISOString().slice(0, 10)} />
    {/if}

    <div class="fuu-card rights">
      <i class="bi bi-shield-check"></i>
      <p>
        <strong>Seus direitos:</strong> pedir cópia, corrigir ou excluir seus dados em Perfil →
        Privacidade. Guardamos o pedido por 5 anos (fiscal) e comprovantes por 180 dias.
      </p>
    </div>
  </div>

  <footer class="foot">
    <button type="button" class="btn-fuu-primary w-100" disabled={!ready || busy} onclick={submit}>
      {busy ? 'Salvando…' : 'Criar conta'}
    </button>
    <p class="after">Endereço e forma de pagamento são pedidos só no primeiro pedido.</p>
  </footer>
</div>

<style>
  .signup {
    background: var(--fuu-white);
    flex: 1;
    display: flex;
    flex-direction: column;
  }
  .head {
    padding: 16px 22px 10px;
    border-bottom: 1px solid var(--fuu-line-5);
  }
  .title-row {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-1);
    padding: 0;
  }
  h1 {
    font-size: 17px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .bar {
    height: 4px;
    background: var(--fuu-line-5);
    border-radius: 3px;
    margin-top: 12px;
    overflow: hidden;
  }
  .fill {
    height: 4px;
    background: var(--fuu-red);
    transition: width 0.2s;
  }
  .body {
    padding: 16px 22px;
    flex: 1;
  }
  .block-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    color: var(--fuu-ink-5);
    letter-spacing: 0.08em;
    margin: 18px 0 9px;
  }
  .body > .block-label:first-child {
    margin-top: 0;
  }
  .label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--fuu-ink-4);
    margin: 12px 0 5px;
  }
  .label .ok {
    color: var(--fuu-leaf-dark);
  }
  input[type='text'],
  input[type='email'],
  input[type='date'] {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 12px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
    color: var(--fuu-ink-1);
  }
  .locked {
    border: 1px solid var(--fuu-line-4);
    background: var(--fuu-line-6);
    border-radius: var(--fuu-radius-card);
    padding: 12px 14px;
    font-size: 14.5px;
    color: var(--fuu-ink-2);
    display: flex;
    align-items: center;
  }
  .locked i {
    margin-left: auto;
    color: var(--fuu-leaf-dark);
  }
  .legal {
    font-size: 11px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    margin: 7px 0 0;
  }
  .check {
    display: flex;
    gap: 11px;
    align-items: flex-start;
    margin-bottom: 11px;
    font-size: 12px;
    color: var(--fuu-ink-2);
    line-height: 1.5;
  }
  .check input {
    margin-top: 2px;
    width: 17px;
    height: 17px;
    flex: none;
    accent-color: var(--fuu-red);
  }
  .check small {
    color: var(--fuu-ink-4);
  }
  .rights {
    padding: 13px;
    margin-top: 12px;
    display: flex;
    gap: 10px;
  }
  .rights i {
    color: var(--fuu-leaf-dark);
    margin-top: 2px;
  }
  .rights p {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    margin: 0;
  }
  .rights strong {
    color: var(--fuu-ink-1);
  }
  .foot {
    padding: 14px 22px 20px;
    background: var(--fuu-white);
    box-shadow: 0 -6px 18px rgba(0, 0, 0, 0.06);
  }
  .foot .btn-fuu-primary {
    min-height: var(--fuu-tap-customer);
  }
  .after {
    font-size: 10.5px;
    color: var(--fuu-ink-3);
    text-align: center;
    margin: 9px 0 0;
    line-height: 1.5;
  }
</style>
