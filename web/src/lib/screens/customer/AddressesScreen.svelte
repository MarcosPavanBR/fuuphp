<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';

  // Telas 6.1 (endereços salvos) e 14.3 (novo endereço com mapa).
  //
  // "CEP preenche, pino corrige, ponto de referência salva a entrega. A área
  // de cobertura é validada no servidor e a taxa aparece antes de salvar —
  // não na hora de pagar."
  //
  // A taxa agora aparece de verdade: `addresses/quote.php` devolve distância,
  // cobertura e frete calculados pelo servidor -- o MESMO cálculo que o
  // checkout refaz. Antes desta tela o frete vinha no corpo da requisição de
  // checkout, e mostrar "R$ 6,90" aqui seria inventar um número.
  let { location, onBack } = $props();

  // "Casa / Trabalho / Outro" do mock. `label` é texto livre no banco; as
  // três pastilhas são só os atalhos mais usados, e "Outro" abre o campo.
  const LABELS = ['Casa', 'Trabalho', 'Outro'];

  let addresses = $state(null);
  let editingId = $state(null);
  let creating = $state(false);
  let busy = $state(false);

  let form = $state({
    label: '',
    street: '',
    number: '',
    complement: '',
    reference: '',
    neighborhood: '',
    postalCode: '',
    lat: null,
    lng: null,
  });
  let quote = $state(null);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }
  let quoteBusy = $state(false);
  let locating = $state(false);

  async function load() {
    try {
      const data = await api.get('/addresses/list.php', { auth: true });
      addresses = data.addresses;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar os endereços.');
      addresses = [];
    }
  }
  load();

  function resetForm() {
    form = {
      label: '',
      street: '',
      number: '',
      complement: '',
      reference: '',
      neighborhood: '',
      postalCode: '',
      lat: null,
      lng: null,
    };
    quote = null;
  }

  // Coordenada do endereço: a do aparelho quando a pessoa deixa, senão a da
  // praça escolhida na Fase 1. Não há provedor de mapa na cláusula zero, então
  // não há pino pra arrastar -- o que existe é isto, e a tela diz qual das
  // duas está valendo em vez de fingir precisão de rua.
  let effectiveLat = $derived(form.lat ?? location?.lat ?? location?.city?.lat ?? -22.9056);
  let effectiveLng = $derived(form.lng ?? location?.lng ?? location?.city?.lng ?? -47.0608);

  async function refreshQuote() {
    quoteBusy = true;
    try {
      quote = await api.get('/addresses/quote.php', {
        auth: true,
        query: {
          lat: effectiveLat,
          lng: effectiveLng,
          city_ibge_code: location?.city?.ibge ?? '3509502',
        },
      });
    } catch (e) {
      quote = null;
      toastr.error(e.message ?? 'Não deu pra conferir a área de entrega.');
    } finally {
      quoteBusy = false;
    }
  }

  function useMyPosition() {
    if (!navigator.geolocation) {
      toastr.warning('Esse aparelho não dá a localização.');
      return;
    }
    locating = true;
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        form.lat = pos.coords.latitude;
        form.lng = pos.coords.longitude;
        locating = false;
        toastr.success('Posição usada para calcular a entrega.');
        refreshQuote();
      },
      () => {
        locating = false;
        toastr.warning('Não deu pra usar a localização — a taxa fica pela praça escolhida.');
      }
    );
  }

  function startCreate() {
    resetForm();
    creating = true;
    editingId = null;
    refreshQuote();
  }

  function startEdit(a) {
    form = {
      label: a.label ?? '',
      street: a.street,
      number: a.number ?? '',
      complement: a.complement ?? '',
      reference: a.reference ?? '',
      neighborhood: a.neighborhood ?? '',
      postalCode: a.postal_code,
      lat: a.lat === undefined ? null : Number(a.lat),
      lng: a.lng === undefined ? null : Number(a.lng),
    };
    editingId = a.id;
    creating = false;
  }

  function cancelForm() {
    creating = false;
    editingId = null;
  }

  // ViaCEP é uma API pública e gratuita (sem chave) -- busca real, não
  // simulada. Limite honesto: este ambiente de desenvolvimento bloqueia
  // tráfego de saída pra hosts fora da allowlist do proxy, então o caminho
  // de sucesso desta chamada não pôde ser testado aqui (só o de falha,
  // que cai no formulário manual mesmo). O contrato da API é estável e
  // público -- não é algo inventado.
  let cepLookupBusy = $state(false);
  async function lookupCep() {
    const digits = form.postalCode.replace(/\D/g, '');
    if (digits.length !== 8) return;
    cepLookupBusy = true;
    try {
      const res = await fetch(`https://viacep.com.br/ws/${digits}/json/`);
      const data = await res.json();
      if (!data.erro) {
        form = {
          ...form,
          street: data.logradouro || form.street,
          neighborhood: data.bairro || form.neighborhood,
        };
        toastr.success('Endereço encontrado pelo CEP ✓');
      }
    } catch {
      // Falha de rede ou CEP não encontrado: sem problema, o formulário
      // continua editável manualmente -- é degradação graciosa, não erro.
    } finally {
      cepLookupBusy = false;
    }
  }

  async function submitCreate() {
    if (!form.street.trim() || form.postalCode.replace(/\D/g, '').length !== 8) {
      toastr.warning('Preencha rua e CEP.');
      return;
    }
    busy = true;
    try {
      await api.post('/addresses/create.php', {
        auth: true,
        body: {
          label: form.label.trim() || undefined,
          street: form.street.trim(),
          number: form.number.trim() || undefined,
          complement: form.complement.trim() || undefined,
          reference: form.reference.trim() || undefined,
          neighborhood: form.neighborhood.trim() || undefined,
          city: location?.city?.name ?? 'Campinas',
          city_ibge_code: location?.city?.ibge ?? '3509502',
          state: location?.uf ?? 'SP',
          postal_code: form.postalCode,
          lat: effectiveLat,
          lng: effectiveLng,
          is_default: addresses?.length === 0,
        },
      });
      toastr.success('Endereço salvo ✓');
      creating = false;
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar o endereço.');
    } finally {
      busy = false;
    }
  }

  async function submitEdit() {
    busy = true;
    try {
      await api.post('/addresses/update.php', {
        auth: true,
        body: {
          id: editingId,
          label: form.label.trim() || null,
          street: form.street.trim(),
          number: form.number.trim() || null,
          complement: form.complement.trim() || null,
          reference: form.reference.trim() || null,
          neighborhood: form.neighborhood.trim() || null,
          postal_code: form.postalCode,
        },
      });
      toastr.success('Endereço atualizado ✓');
      editingId = null;
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra atualizar o endereço.');
    } finally {
      busy = false;
    }
  }

  async function makeDefault(a) {
    try {
      await api.post('/addresses/update.php', { auth: true, body: { id: a.id, is_default: true } });
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra trocar o endereço padrão.');
    }
  }

  async function remove(a) {
    try {
      await api.post('/addresses/delete.php', { auth: true, body: { id: a.id } });
      toastr.success('Endereço removido');
      await load();
    } catch (e) {
      const message = e instanceof ApiError && e.code === 'address_in_use'
        ? 'Esse endereço já foi usado num pedido e não pode ser apagado.'
        : (e.message ?? 'Não deu pra apagar o endereço.');
      toastr.error(message);
    }
  }
</script>

<div class="addresses-screen">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>
    <h1 class="fuu-display">Endereços</h1>
  </div>

  {#if addresses === null}
    <p class="empty">Carregando…</p>
  {:else}
    <div class="address-list">
      {#each addresses as a (a.id)}
        {#if editingId === a.id}
          <div class="fuu-card form-card">
            <input type="text" placeholder="Rótulo (Casa, Trabalho...)" bind:value={form.label} />
            <input type="text" placeholder="Rua" bind:value={form.street} />
            <div class="field-row">
              <input type="text" placeholder="Número" bind:value={form.number} />
              <input type="text" placeholder="Complemento" bind:value={form.complement} />
            </div>
            <input type="text" placeholder="Bairro" bind:value={form.neighborhood} />
            <input
              type="text"
              inputmode="numeric"
              placeholder="CEP"
              bind:value={form.postalCode}
              onblur={lookupCep}
            />
            <div class="form-actions">
              <button type="button" class="btn-fuu-primary" disabled={busy} onclick={submitEdit}>
                {busy ? 'Salvando…' : 'Salvar'}
              </button>
              <button type="button" class="link-btn" onclick={cancelForm}>Cancelar</button>
            </div>
          </div>
        {:else}
          <div class="fuu-card address-card">
            <div class="address-top">
              <span class="label">{a.label || 'Endereço'}</span>
              {#if a.is_default}<span class="fuu-badge-confirmed">PADRÃO</span>{/if}
            </div>
            <p class="street">{a.street}{a.number ? `, ${a.number}` : ''}{a.complement ? ` · ${a.complement}` : ''}</p>
            <p class="city">{a.neighborhood ? `${a.neighborhood} · ` : ''}{a.city}/{a.state} · {a.postal_code.replace(/(\d{5})(\d{3})/, '$1-$2')}</p>
            {#if a.reference}<p class="city ref"><i class="bi bi-signpost"></i> {a.reference}</p>{/if}
            <div class="address-actions">
              {#if !a.is_default}
                <button type="button" class="action" onclick={() => makeDefault(a)}>Tornar padrão</button>
              {/if}
              <button type="button" class="action" onclick={() => startEdit(a)}>Editar</button>
              <button type="button" class="action danger" onclick={() => remove(a)}>Remover</button>
            </div>
          </div>
        {/if}
      {/each}

      {#if addresses.length === 0 && !creating}
        <p class="empty">Nenhum endereço salvo ainda.</p>
      {/if}
    </div>

    {#if creating}
      <div class="fuu-card form-card">
        <p class="section-label">NOVO ENDEREÇO</p>
        <input
          type="text"
          inputmode="numeric"
          placeholder="CEP"
          bind:value={form.postalCode}
          onblur={lookupCep}
        />
        <!-- O aviso fica SEMPRE no DOM, só invisível: quando ele aparecia e
             sumia, a busca do CEP disparada no blur empurrava os botões pra
             baixo entre o mousedown e o mouseup, e o primeiro toque em
             "Salvar endereço" se perdia. Achado no teste com navegador. -->
        <p class="hint cep-hint" class:on={cepLookupBusy}>Buscando pelo CEP…</p>
        <input type="text" placeholder="Rua" bind:value={form.street} />
        <div class="field-row">
          <input type="text" placeholder="Número" bind:value={form.number} />
          <input type="text" placeholder="Complemento" bind:value={form.complement} />
        </div>
        <input type="text" placeholder="Bairro" bind:value={form.neighborhood} />
        <!-- 14.3 — "ponto de referência (ajuda o entregador)". Fica junto do
             endereço porque é ele que evita a ligação na hora da entrega. -->
        <input type="text" placeholder="Ponto de referência (ajuda o entregador)" bind:value={form.reference} />

        <div class="labels">
          {#each LABELS as name (name)}
            <button
              type="button"
              class="label-chip"
              class:on={form.label === name}
              onclick={() => (form.label = form.label === name ? '' : name)}
            >
              {#if name === 'Casa'}<i class="bi bi-house-door-fill"></i>{/if}
              {name}
            </button>
          {/each}
        </div>
        {#if form.label === 'Outro'}
          <input type="text" placeholder="Como você chama esse endereço?" bind:value={form.label} />
        {/if}

        <!-- O mock desenha um mapa com pino arrastável. Não há provedor de
             mapas na cláusula zero, então não há mapa nem pino: o que dá pra
             fazer de verdade é usar a posição do aparelho, e a tela diz qual
             coordenada está valendo. -->
        <div class="geo">
          <button type="button" class="geo-btn" disabled={locating} onclick={useMyPosition}>
            <i class="bi bi-crosshair"></i> {locating ? 'Localizando…' : 'Usar minha posição'}
          </button>
          <span class="geo-note">
            {form.lat === null ? 'usando o centro da praça escolhida' : 'usando a posição do aparelho'}
          </span>
        </div>

        {#if quoteBusy}
          <p class="hint">Conferindo a área de entrega…</p>
        {:else if quote}
          <div class="coverage" class:out={!quote.in_area}>
            <i class={`bi ${quote.in_area ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}`}></i>
            <div>
              <p class="coverage-title">
                {quote.in_area
                  ? `Dentro da área de entrega de ${quote.stores_covering} ${quote.stores_covering === 1 ? 'loja' : 'lojas'}`
                  : 'Fora da área de entrega das lojas desta praça'}
              </p>
              <p class="coverage-sub">
                {#if quote.tariff.base > 0 || quote.tariff.per_km > 0}
                  Taxa: {money(quote.tariff.base)}
                  {#if quote.tariff.per_km > 0}+ {money(quote.tariff.per_km)}/km{/if}
                  {#if quote.nearest}· loja mais perto a {String(quote.nearest.distance_km).replace('.', ',')} km{/if}
                {:else}
                  A plataforma ainda não publicou tarifa de entrega — o frete sai zero.
                {/if}
              </p>
            </div>
          </div>
        {/if}

        <div class="form-actions">
          <button type="button" class="btn-fuu-primary" disabled={busy} onclick={submitCreate}>
            {busy ? 'Salvando…' : 'Salvar endereço'}
          </button>
          <button type="button" class="link-btn" onclick={cancelForm}>Cancelar</button>
        </div>
      </div>
    {:else}
      <button type="button" class="add-btn" onclick={startCreate}>
        <i class="bi bi-plus-circle"></i> Adicionar endereço
      </button>
      <p class="hint">Buscamos pelo CEP</p>
    {/if}

    <p class="fee-note">A taxa de entrega é sempre calculada no servidor na hora de fechar o pedido.</p>
  {/if}
</div>

<style>
  .labels {
    display: flex;
    gap: 8px;
    margin: 4px 0 2px;
  }
  .label-chip {
    font-family: var(--fuu-font-body);
    font-size: 12.5px;
    font-weight: 700;
    background: var(--fuu-line-5);
    border: none;
    color: var(--fuu-ink-2);
    padding: 10px 16px;
    border-radius: var(--fuu-radius-pill);
  }
  .label-chip.on {
    background: var(--fuu-red);
    color: var(--fuu-white);
  }
  .geo {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin: 4px 0;
  }
  .geo-btn {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 12.5px;
    font-weight: 700;
    color: var(--fuu-ink-1);
  }
  .geo-btn i {
    color: var(--fuu-red);
  }
  .geo-note {
    font-size: 11px;
    color: var(--fuu-ink-5);
  }
  .coverage {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    background: var(--fuu-leaf-tint);
    border-radius: 10px;
    padding: 12px;
    margin: 4px 0;
  }
  .coverage.out {
    background: var(--fuu-wait-bg);
  }
  .coverage i {
    color: var(--fuu-leaf);
    margin-top: 2px;
  }
  .coverage.out i {
    color: var(--fuu-wait-text);
  }
  .coverage-title {
    font-size: 12.5px;
    font-weight: 800;
    color: var(--fuu-leaf-dark);
    margin: 0;
  }
  .coverage.out .coverage-title {
    color: var(--fuu-wait-text);
  }
  .coverage-sub {
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    margin: 2px 0 0;
    line-height: 1.5;
  }
  .ref {
    color: var(--fuu-ink-4);
  }
  .cep-hint {
    visibility: hidden;
  }
  .cep-hint.on {
    visibility: visible;
  }
  .addresses-screen {
    padding: 12px 20px 40px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
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
  .empty {
    color: var(--fuu-ink-5);
    font-size: 13.5px;
  }
  .address-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 14px;
  }
  .address-card {
    padding: 14px;
  }
  .address-top {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
  }
  .label {
    font-weight: 700;
    font-size: 14.5px;
    color: var(--fuu-ink-1);
  }
  .street {
    margin: 0;
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .city {
    margin: 2px 0 10px;
    font-size: 12px;
    color: var(--fuu-ink-5);
  }
  .address-actions {
    display: flex;
    gap: 14px;
  }
  .action {
    background: none;
    border: none;
    color: var(--fuu-red);
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 12.5px;
    padding: 0;
  }
  .action.danger {
    color: var(--fuu-alert);
  }
  .form-card {
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 14px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 2px;
  }
  .field-row {
    display: flex;
    gap: 8px;
  }
  .field-row input {
    flex: 1;
  }
  input {
    box-sizing: border-box;
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
  }
  .form-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 4px;
  }
  .form-actions .btn-fuu-primary {
    padding: 0 20px;
    min-height: 42px;
  }
  .link-btn {
    background: none;
    border: none;
    color: var(--fuu-ink-4);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 600;
  }
  .add-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px dashed var(--fuu-line-1);
    border-radius: var(--fuu-radius-card);
    background: none;
    min-height: var(--fuu-tap-customer);
    color: var(--fuu-red);
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 14px;
  }
  .hint {
    text-align: center;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    margin: 6px 0 0;
  }
  .fee-note {
    text-align: center;
    font-size: 11px;
    color: var(--fuu-ink-5);
    margin: 20px 0 0;
  }
</style>
