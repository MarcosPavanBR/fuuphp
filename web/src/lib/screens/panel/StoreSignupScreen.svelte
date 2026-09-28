<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { loadServiceStates } from '../../services/cities.js';
  import { STORE_CATEGORIES } from '../../data/categories.js';

  // "Quero vender no FUU" — cadastro da loja pela própria loja
  // (restaurants/signup.php, migração 033).
  //
  // A loja nasce em análise: fora da vitrine até a plataforma aprovar. Mas a
  // conta do balcão já funciona -- quem se cadastra entra no painel e prepara
  // cardápio, horário e pagamentos enquanto espera. As cidades são as praças
  // onde o FUU opera (a mesma lista do app do cliente); fora delas, a tela
  // manda falar com a gente em vez de aceitar um cadastro que não vai vender.
  let { onDone, onBack } = $props();

  // As cidades atendidas (aba Cidades do admin, cities/list.php).
  let states = $state([]);
  loadServiceStates()
    .then((list) => {
      states = list;
      if (!form.uf) form.uf = list[0]?.uf ?? '';
    })
    .catch(() => toastr.error('Não deu pra carregar as cidades. Recarregue a página.'));

  let form = $state({
    name: '',
    cnpj: '',
    category: '',
    uf: '',
    city: '',
    address: '',
    contact_name: '',
    contact_phone: '',
    email: '',
    password: '',
    pix_key: '',
    accept_terms: false,
  });
  let location = $state(null);
  let locating = $state(false);
  let busy = $state(false);
  let errors = $state({});

  let cities = $derived(states.find((s) => s.uf === form.uf)?.cities ?? []);

  function cnpjMask(v) {
    const d = v.replace(/\D/g, '').slice(0, 14);
    return d
      .replace(/^(\d{2})(\d)/, '$1.$2')
      .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
      .replace(/\.(\d{3})(\d)/, '.$1/$2')
      .replace(/(\d{4})(\d)/, '$1-$2');
  }

  function locate() {
    if (!navigator.geolocation) {
      toastr.warning('Este aparelho não informa a localização. A plataforma ajusta na aprovação.');
      return;
    }
    locating = true;
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        location = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        locating = false;
        toastr.success('Localização da loja marcada.');
      },
      () => {
        locating = false;
        toastr.warning('Não deu pra pegar a localização. Dá pra seguir sem; a plataforma ajusta na aprovação.');
      },
      { enableHighAccuracy: true, timeout: 10000 }
    );
  }

  async function submit() {
    errors = {};
    busy = true;
    try {
      const res = await api.post('/restaurants/signup.php', {
        body: {
          name: form.name.trim(),
          cnpj: form.cnpj.replace(/\D/g, ''),
          category: form.category,
          city_ibge_code: form.city,
          address: form.address.trim(),
          contact_name: form.contact_name.trim(),
          contact_phone: form.contact_phone.replace(/\D/g, ''),
          email: form.email.trim(),
          password: form.password,
          pix_key: form.pix_key.trim() || undefined,
          lat: location?.lat,
          lng: location?.lng,
          accept_terms: form.accept_terms,
        },
      });
      toastr.success('Cadastro recebido! Entre com o CNPJ e a senha pra preparar a loja.');
      onDone(res.login_code);
    } catch (e) {
      if (e instanceof ApiError && e.fields) errors = e.fields;
      toastr.error(e.message ?? 'Não deu pra enviar o cadastro.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="signup-screen">
  <div class="card fuu-card">
    <button type="button" class="back" onclick={onBack}><i class="bi bi-arrow-left"></i> Já tenho cadastro</button>
    <h1 class="fuu-display">Quero vender no FUU</h1>
    <p class="lead">
      Cadastre a loja em 3 minutos. A equipe do FUU confere e aprova; enquanto isso, você já entra no painel e monta
      o cardápio.
    </p>

    <p class="section">A LOJA</p>
    <label class="field" class:bad={errors.name}>
      <span>Nome da loja</span>
      <input type="text" maxlength="80" placeholder="Cantina da Nonna" bind:value={form.name} />
      {#if errors.name}<small>{errors.name}</small>{/if}
    </label>
    <label class="field" class:bad={errors.cnpj}>
      <span>CNPJ</span>
      <input
        type="text"
        inputmode="numeric"
        placeholder="00.000.000/0000-00"
        value={cnpjMask(form.cnpj)}
        oninput={(e) => (form.cnpj = e.currentTarget.value)}
      />
      {#if errors.cnpj}<small>{errors.cnpj}</small>{/if}
    </label>
    <div class="field" class:bad={errors.category}>
      <span>Categoria</span>
      <div class="pills">
        {#each STORE_CATEGORIES as c (c)}
          <button type="button" class="pill" class:on={form.category === c} onclick={() => (form.category = c)}>{c}</button>
        {/each}
      </div>
      {#if errors.category}<small>{errors.category}</small>{/if}
    </div>
    <div class="two">
      <label class="field">
        <span>Estado</span>
        <select bind:value={form.uf} onchange={() => (form.city = '')}>
          {#each states as s (s.uf)}
            <option value={s.uf}>{s.name}</option>
          {/each}
        </select>
      </label>
      <label class="field" class:bad={errors.city_ibge_code}>
        <span>Cidade</span>
        <select bind:value={form.city}>
          <option value="" disabled>Escolha</option>
          {#each cities as c (c.ibge)}
            <option value={c.ibge}>{c.name}</option>
          {/each}
        </select>
        {#if errors.city_ibge_code}<small>{errors.city_ibge_code}</small>{/if}
      </label>
    </div>
    <p class="hint">Sua cidade não está na lista? O FUU ainda não chegou aí — fale com a gente.</p>
    <label class="field" class:bad={errors.address}>
      <span>Endereço</span>
      <input maxlength="300" type="text" placeholder="Rua, número, bairro" bind:value={form.address} />
      {#if errors.address}<small>{errors.address}</small>{/if}
    </label>
    <button type="button" class="locate" class:done={location} disabled={locating} onclick={locate}>
      <i class={`bi ${location ? 'bi-geo-alt-fill' : 'bi-crosshair'}`}></i>
      {locating ? 'Localizando…' : location ? 'Localização marcada' : 'Estou na loja: marcar localização'}
    </button>
    <p class="hint">A localização calcula distância e frete. Marque estando na loja.</p>

    <p class="section">QUEM RESPONDE PELA LOJA</p>
    <label class="field" class:bad={errors.contact_name}>
      <span>Nome</span>
      <input maxlength="120" type="text" bind:value={form.contact_name} />
      {#if errors.contact_name}<small>{errors.contact_name}</small>{/if}
    </label>
    <div class="two">
      <label class="field" class:bad={errors.contact_phone}>
        <span>Celular com DDD</span>
        <input type="tel" inputmode="numeric" placeholder="(19) 99128-4407" bind:value={form.contact_phone} />
        {#if errors.contact_phone}<small>{errors.contact_phone}</small>{/if}
      </label>
      <label class="field" class:bad={errors.email}>
        <span>E-mail</span>
        <input maxlength="254" type="email" bind:value={form.email} />
        {#if errors.email}<small>{errors.email}</small>{/if}
      </label>
    </div>

    <p class="section">ACESSO E RECEBIMENTO</p>
    <label class="field" class:bad={errors.password}>
      <span>Senha do painel (mínimo 8)</span>
      <input type="password" autocomplete="new-password" bind:value={form.password} />
      {#if errors.password}<small>{errors.password}</small>{/if}
    </label>
    <label class="field" class:bad={errors.pix_key}>
      <span>Chave Pix da loja (opcional agora)</span>
      <input maxlength="140" type="text" placeholder="CNPJ, e-mail, telefone ou chave aleatória" bind:value={form.pix_key} />
      {#if errors.pix_key}<small>{errors.pix_key}</small>{/if}
    </label>
    <p class="hint">É pra onde vai o Pix que o cliente paga direto pra loja. Chave CNPJ tem que ser o desta loja.</p>

    <label class="terms" class:bad={errors.accept_terms}>
      <input type="checkbox" bind:checked={form.accept_terms} />
      <span>
        Li e aceito os <a href="/termos.html#parceiros" target="_blank" rel="noopener">termos de parceiro</a> e o
        <a href="/privacidade.html" target="_blank" rel="noopener">aviso de privacidade</a>. Comissão de 8% por pedido
        na política inicial.
      </span>
    </label>

    <button type="button" class="btn-fuu-primary w-100 submit" disabled={busy} onclick={submit}>
      {busy ? 'Enviando…' : 'Enviar cadastro'}
    </button>
  </div>
</div>

<style>
  .signup-screen {
    min-height: 100vh;
    background: var(--fuu-paper);
    padding: 24px 16px;
    display: flex;
    justify-content: center;
  }
  .card {
    width: 100%;
    max-width: 520px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-self: flex-start;
  }
  .back {
    align-self: flex-start;
    background: none;
    border: none;
    padding: 0;
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
  h1 {
    font-size: 24px;
    margin: 4px 0 0;
    color: var(--fuu-ink-1);
  }
  .lead {
    font-size: 14px;
    color: var(--fuu-ink-3);
    margin: 0 0 4px;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-6);
    margin: 10px 0 0;
  }
  .field {
    display: block;
    min-width: 0;
  }
  .field > span {
    display: block;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin-bottom: 4px;
  }
  .field small {
    display: block;
    color: var(--fuu-alert);
    font-size: 12px;
    margin-top: 3px;
  }
  .field.bad input,
  .field.bad select {
    border-color: var(--fuu-alert);
  }
  input[type='text'],
  input[type='tel'],
  input[type='email'],
  input[type='password'],
  select {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 11px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
    background: var(--fuu-white);
  }
  .two {
    display: flex;
    gap: 10px;
  }
  .two .field {
    flex: 1;
  }
  @media (max-width: 460px) {
    .two {
      flex-direction: column;
    }
  }
  .pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .pill {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: var(--fuu-radius-pill);
    padding: 7px 12px;
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-3);
  }
  .pill.on {
    background: var(--fuu-ink-1);
    border-color: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .hint {
    font-size: 12px;
    color: var(--fuu-ink-5);
    margin: -4px 0 0;
  }
  .locate {
    border: 1px dashed var(--fuu-line-1);
    background: var(--fuu-white);
    border-radius: var(--fuu-radius-card);
    padding: 10px;
    font-weight: 700;
    color: var(--fuu-ink-2);
  }
  .locate.done {
    border-style: solid;
    border-color: var(--fuu-leaf);
    color: var(--fuu-leaf-dark);
    background: var(--fuu-leaf-tint);
  }
  .terms {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    font-size: 13px;
    color: var(--fuu-ink-3);
    margin-top: 6px;
  }
  .terms.bad {
    color: var(--fuu-alert);
  }
  .terms input {
    margin-top: 3px;
    width: 18px;
    height: 18px;
    flex: none;
  }
  .terms a {
    color: var(--fuu-red);
    font-weight: 700;
  }
  .submit {
    min-height: var(--fuu-tap-operator);
    margin-top: 6px;
  }
</style>
