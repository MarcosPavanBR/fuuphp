<script>
  import { api, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';

  // Tela 15.2 — "Onboarding do entregador".
  //
  // "Documento por documento com verificação automática visível... A regra
  // 'chave Pix tem que ser sua' é antifraude, e dizer isso na tela evita 90%
  // das tentativas."
  //
  // Esta tela vive no app do entregador, mas roda com o token do CLIENTE: a
  // pessoa entra pelo mesmo OTP do app de pedido (Fase 10) e só vira
  // entregador quando a candidatura é aprovada -- é a aprovação que cria o
  // login de CPF + código de acesso.
  //
  // Entrar aqui é pelo OTP do cliente, não pelo CPF + código de acesso: o
  // código de acesso é o que a APROVAÇÃO cria. Por isso o login mora nesta
  // tela, em vez de no CourierLogin.
  let { onBack } = $props();

  let token = $state(null);
  let phone = $state('');
  let fullName = $state('');
  let otpSent = $state(false);
  let otpCode = $state('');
  let devCode = $state(null);
  let authBusy = $state(false);

  async function requestCode() {
    if (phone.replace(/\D/g, '').length < 10) {
      toastr.warning('Digite seu telefone com DDD.');
      return;
    }
    authBusy = true;
    try {
      const res = await api.post('/auth/otp_request.php', {
        body: {
          purpose: 'signup',
          phone: phone.replace(/\D/g, ''),
          ...(fullName.trim() !== '' ? { full_name: fullName.trim() } : {}),
        },
      });
      otpSent = true;
      // Em desenvolvimento o código volta na resposta (mesmo comportamento
      // do app do cliente); em produção ele só chega por SMS.
      devCode = res.dev_code ?? null;
      toastr.success('Código enviado por SMS.');
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra mandar o código.');
    } finally {
      authBusy = false;
    }
  }

  async function verifyCode() {
    authBusy = true;
    try {
      const res = await api.post('/auth/otp_verify.php', {
        body: { purpose: 'signup', phone: phone.replace(/\D/g, ''), code: otpCode.trim() },
      });
      token = res.access_token;
      form.phone = phone.replace(/\D/g, '');
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Código inválido.');
    } finally {
      authBusy = false;
    }
  }

  let data = $state(null);
  let busy = $state(false);
  let uploading = $state(null);

  const VEHICLES = [
    { code: 'moto', label: 'Moto' },
    { code: 'bike', label: 'Bike' },
    { code: 'car', label: 'Carro' },
    { code: 'foot', label: 'A pé' },
  ];
  const DOC_ICON = {
    cnh: 'bi-person-vcard',
    selfie: 'bi-person-bounding-box',
    crlv: 'bi-file-earmark-text',
    address_proof: 'bi-house-door',
  };
  const STATE_LABEL = {
    draft: 'rascunho',
    review: 'em análise',
    approved: 'aprovada',
    rejected: 'recusada',
    needs_fix: 'precisa de correção',
  };

  let form = $state({ full_name: '', cpf: '', phone: '', vehicle: 'moto', plate: '', pix_key: '' });
  let acceptContract = $state(false);

  async function load() {
    try {
      data = await api.get('/couriers/application.php', { token });
      if (data.application) {
        form = {
          full_name: data.application.full_name,
          cpf: data.application.cpf,
          phone: data.application.phone,
          vehicle: data.application.vehicle,
          plate: data.application.plate ?? '',
          pix_key: data.application.pix_key,
        };
      }
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra abrir a candidatura.');
    }
  }

  $effect(() => {
    if (token) load();
  });

  async function save() {
    busy = true;
    try {
      const res = await api.post('/couriers/apply.php', { token, body: { ...form } });
      toastr.success(res.pix_ownership_checked ? 'Dados salvos. Conferimos: a chave Pix é sua.' : 'Dados salvos.');
      if (!res.pix_ownership_checked) toastr.info(res.pix_note);
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar a candidatura.');
    } finally {
      busy = false;
    }
  }

  async function upload(kind, file) {
    if (!file) return;
    uploading = kind;
    try {
      const fd = new FormData();
      fd.append('kind', kind);
      fd.append('document', file);
      const res = await fetch(`${BASE}/couriers/apply_document.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: fd,
      });
      const json = await res.json();
      if (!res.ok) throw new Error(json.message ?? 'Não deu pra enviar o documento.');
      toastr.success('Documento enviado.');
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra enviar o documento.');
    } finally {
      uploading = null;
    }
  }

  async function submit() {
    if (!acceptContract) {
      toastr.warning('Pra enviar é preciso aceitar o contrato.');
      return;
    }
    busy = true;
    try {
      const res = await api.post('/couriers/submit_application.php', {
        token,
        body: { accept_contract: true },
      });
      toastr.success(res.notice);
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra enviar a candidatura.');
    } finally {
      busy = false;
    }
  }

  let docByKind = $derived(
    Object.fromEntries((data?.documents ?? []).map((d) => [d.kind, d]))
  );
</script>

<div class="apply-screen">
  <header class="top">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>
    <h1>Quero entregar</h1>
  </header>

  {#if token === null}
    <div class="body">
      <p class="intro">
        Pra se candidatar, entre com seu telefone — é a mesma conta do app de pedidos. O login de
        entregador (CPF e código de acesso) só nasce quando a candidatura é aprovada.
      </p>
      <div class="card">
        {#if !otpSent}
          <label class="field">
            <span>Seu nome</span>
            <input type="text" bind:value={fullName} />
          </label>
          <label class="field">
            <span>Telefone com DDD</span>
            <input type="tel" inputmode="numeric" class="fuu-mono" bind:value={phone} />
          </label>
          <button type="button" class="btn-fuu-primary w-100" disabled={authBusy} onclick={requestCode}>
            {authBusy ? 'Enviando…' : 'Receber código'}
          </button>
        {:else}
          <label class="field">
            <span>Código do SMS</span>
            <input type="text" inputmode="numeric" class="fuu-mono" bind:value={otpCode} />
          </label>
          {#if devCode}
            <p class="dev-code">Ambiente de desenvolvimento: o código é {devCode}.</p>
          {/if}
          <button type="button" class="btn-fuu-primary w-100" disabled={authBusy} onclick={verifyCode}>
            {authBusy ? 'Entrando…' : 'Entrar'}
          </button>
        {/if}
      </div>
    </div>
  {:else if data === null}
    <p class="loading">Abrindo sua candidatura…</p>
  {:else}
    <div class="body">
      {#if data.application}
        <div class={`state ${data.application.state}`}>
          Candidatura {STATE_LABEL[data.application.state] ?? data.application.state}
          {#if data.application.state === 'review'}· análise em até 48 h{/if}
        </div>
      {/if}

      <p class="section-label">SEUS DADOS</p>
      <div class="card">
        <label class="field">
          <span>Nome completo</span>
          <input type="text" bind:value={form.full_name} />
        </label>
        <div class="field-row">
          <label class="field">
            <span>CPF</span>
            <input type="text" inputmode="numeric" class="fuu-mono" bind:value={form.cpf} />
          </label>
          <label class="field">
            <span>Telefone</span>
            <input type="text" inputmode="numeric" class="fuu-mono" bind:value={form.phone} />
          </label>
        </div>

        <p class="field-label">Como você entrega</p>
        <div class="pills">
          {#each VEHICLES as option (option.code)}
            <button
              type="button"
              class="pill"
              class:on={form.vehicle === option.code}
              onclick={() => (form.vehicle = option.code)}
            >
              {option.label}
            </button>
          {/each}
        </div>
        {#if form.vehicle === 'moto' || form.vehicle === 'car'}
          <label class="field">
            <span>Placa</span>
            <input type="text" class="fuu-mono" placeholder="QQP1B34" bind:value={form.plate} />
          </label>
        {/if}
      </div>

      <p class="section-label">ONDE VOCÊ RECEBE</p>
      <div class="card">
        <label class="field">
          <span>Chave Pix (precisa ser sua)</span>
          <input type="text" class="fuu-mono" bind:value={form.pix_key} />
        </label>
        <p class="pix-note">
          <i class="bi bi-shield-check"></i>
          Chave que é CPF ou telefone a gente confere com os seus dados na hora. Chave no nome de outra
          pessoa é recusada por prevenção a fraude. Pagamos toda terça.
        </p>
        <button type="button" class="btn-fuu-primary w-100" disabled={busy} onclick={save}>
          {busy ? 'Salvando…' : 'Salvar dados'}
        </button>
      </div>

      {#if data.application}
        <p class="section-label">DOCUMENTOS</p>
        <div class="card docs">
          {#each data.required as doc (doc.kind)}
            {@const sent = docByKind[doc.kind]}
            <label class="doc">
              <i class={`bi ${sent ? 'bi-check-circle-fill' : DOC_ICON[doc.kind]}`} class:ok={!!sent}></i>
              <span class="doc-text">
                <span class="doc-title">{doc.label}</span>
                <span class="doc-note" class:pending={!sent}>
                  {sent ? (sent.state === 'pending' ? 'enviado · aguardando conferência' : sent.state) : doc.note}
                </span>
              </span>
              <span class="doc-action">{uploading === doc.kind ? '…' : sent ? 'trocar' : 'enviar'}</span>
              <input
                type="file"
                accept="image/*,application/pdf"
                onchange={(e) => upload(doc.kind, e.currentTarget.files?.[0])}
              />
            </label>
          {/each}
        </div>
        <p class="face-note">{data.face_match_note}</p>

        <div class="card contract">
          <i class="bi bi-file-earmark-text"></i>
          <div>
            <p>{data.contract_note}</p>
            <label class="accept">
              <input type="checkbox" bind:checked={acceptContract} disabled={data.application.state === 'review'} />
              Li e aceito o contrato de prestação de serviço e a política de dados.
            </label>
          </div>
        </div>

        {#if data.missing.length > 0}
          <p class="missing">
            Falta enviar: {data.missing.map((k) => data.required.find((r) => r.kind === k)?.label ?? k).join(', ')}.
          </p>
        {/if}

        <button
          type="button"
          class="btn-fuu-primary w-100 submit"
          disabled={busy || !data.can_submit}
          onclick={submit}
        >
          {data.application.state === 'review' ? 'Em análise' : 'Enviar para análise'}
        </button>
      {/if}
    </div>
  {/if}
</div>

<style>
  .apply-screen {
    display: flex;
    flex-direction: column;
    flex: 1;
    background: var(--fuu-paper);
  }
  .top {
    background: var(--fuu-white);
    border-bottom: 1px solid var(--fuu-line-3);
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .top h1 {
    font-size: 17px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-1);
    padding: 0;
  }
  .body {
    padding: 16px 18px 30px;
  }
  .loading {
    text-align: center;
    color: var(--fuu-ink-5);
    padding: 40px 0;
  }
  .intro {
    font-size: 13px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin: 0 0 14px;
  }
  .dev-code {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 0 0 10px;
  }
  .state {
    border-radius: 10px;
    padding: 12px;
    font-size: 12.5px;
    font-weight: 700;
    margin-bottom: 14px;
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .state.approved {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .state.rejected {
    background: var(--fuu-red-tint);
    color: var(--fuu-alert);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-6);
    margin: 18px 0 9px;
  }
  .card {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 15px;
  }
  .field {
    display: block;
    margin-bottom: 12px;
  }
  .field > span,
  .field-label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--fuu-ink-2);
    margin-bottom: 5px;
  }
  .field input {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 12px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
    color: var(--fuu-ink-1);
    background: var(--fuu-white);
    min-height: var(--fuu-tap-operator);
  }
  .field-row {
    display: flex;
    gap: 10px;
  }
  .field-row .field {
    flex: 1;
  }
  .pills {
    display: flex;
    gap: 7px;
    margin-bottom: 12px;
    flex-wrap: wrap;
  }
  .pill {
    flex: 1;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    background: var(--fuu-line-5);
    border: none;
    color: var(--fuu-ink-1);
    padding: 12px 0;
    border-radius: 8px;
    min-height: var(--fuu-tap-operator);
  }
  .pill.on {
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .pix-note {
    display: flex;
    gap: 8px;
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    line-height: 1.55;
    margin: 0 0 12px;
  }
  .pix-note i {
    color: var(--fuu-leaf);
  }
  .docs {
    padding: 0;
  }
  .doc {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    border-bottom: 1px solid var(--fuu-line-5);
    min-height: var(--fuu-tap-operator);
  }
  .doc:last-child {
    border-bottom: none;
  }
  .doc input[type='file'] {
    display: none;
  }
  .doc i {
    font-size: 18px;
    color: var(--fuu-ink-5);
  }
  .doc i.ok {
    color: var(--fuu-leaf);
  }
  .doc-text {
    flex: 1;
    min-width: 0;
  }
  .doc-title {
    display: block;
    font-weight: 700;
    font-size: 13.5px;
    color: var(--fuu-ink-1);
  }
  .doc-note {
    display: block;
    font-size: 11px;
    color: var(--fuu-ink-2);
  }
  .doc-note.pending {
    color: var(--fuu-red);
    font-weight: 600;
  }
  .doc-action {
    font-size: 11.5px;
    font-weight: 800;
    color: var(--fuu-red);
  }
  .face-note {
    font-size: 11px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
    margin: 8px 0 0;
  }
  .contract {
    display: flex;
    gap: 10px;
    margin-top: 14px;
  }
  .contract i {
    color: var(--fuu-ink-6);
    margin-top: 2px;
  }
  .contract p {
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    line-height: 1.55;
    margin: 0 0 8px;
  }
  .accept {
    display: flex;
    gap: 8px;
    align-items: flex-start;
    font-size: 12px;
    color: var(--fuu-ink-1);
    line-height: 1.5;
  }
  .missing {
    font-size: 12px;
    color: var(--fuu-alert);
    margin: 12px 0 0;
  }
  .submit {
    margin-top: 14px;
    min-height: var(--fuu-tap-operator);
  }
</style>
