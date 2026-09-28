<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { lookupCep } from '../../utils/viacep.js';

  // Tela 14.3 — novo endereço: "CEP preenche, pino corrige, ponto de
  // referência salva a entrega. A área de cobertura é validada no servidor e
  // a taxa aparece antes de salvar — não na hora de pagar." Separado de
  // AddressesScreen.svelte na auditoria ARQ-02.
  //
  // A taxa é a de `addresses/quote.php` -- o MESMO cálculo que o checkout
  // refaz, não um número inventado aqui.
  //
  // location: a praça escolhida na Fase 1 (cidade, UF, coordenada).
  // isFirst: é o primeiro endereço -- nasce como padrão.
  // onSaved / onCancel: a lista recarrega ou fecha o formulário.
  let { location, isFirst = false, onSaved, onCancel } = $props();

  // "Casa / Trabalho / Outro" do mock. `label` é texto livre no banco; as
  // três pastilhas são só os atalhos mais usados, e "Outro" abre o campo.
  const LABELS = ['Casa', 'Trabalho', 'Outro'];

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
  let quoteBusy = $state(false);
  let locating = $state(false);
  let cepLookupBusy = $state(false);
  let busy = $state(false);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  // Coordenada do endereço: a do aparelho quando a pessoa deixa, senão a da
  // praça escolhida na Fase 1. Não há provedor de mapa na cláusula zero, então
  // não há pino pra arrastar -- o que existe é isto, e a tela diz qual das
  // duas está valendo em vez de fingir precisão de rua.
  let effectiveLat = $derived(form.lat ?? location?.city?.lat ?? location?.lat ?? null);
  let effectiveLng = $derived(form.lng ?? location?.city?.lng ?? location?.lng ?? null);

  async function refreshQuote() {
    quoteBusy = true;
    try {
      quote = await api.get('/addresses/quote.php', {
        auth: true,
        query: {
          lat: effectiveLat,
          lng: effectiveLng,
          city_ibge_code: location?.city?.ibge ?? '',
        },
      });
    } catch (e) {
      quote = null;
      toastr.error(e.message ?? 'Não deu pra conferir a área de entrega.');
    } finally {
      quoteBusy = false;
    }
  }
  refreshQuote();

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
      },
      // Sem timeout, um GPS que não responde deixava o botão "localizando"
      // girando pra sempre (o erro nunca vinha).
      { timeout: 10000, maximumAge: 60000 }
    );
  }

  async function fillFromCep() {
    cepLookupBusy = true;
    const found = await lookupCep(form.postalCode);
    cepLookupBusy = false;
    if (found) {
      form = { ...form, street: found.street || form.street, neighborhood: found.neighborhood || form.neighborhood };
      toastr.success('Endereço encontrado pelo CEP ✓');
    }
  }

  async function submit() {
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
          city: location?.city?.name ?? '',
          city_ibge_code: location?.city?.ibge ?? '',
          state: location?.uf ?? '',
          postal_code: form.postalCode,
          lat: effectiveLat,
          lng: effectiveLng,
          is_default: isFirst,
        },
      });
      toastr.success('Endereço salvo ✓');
      await onSaved();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar o endereço.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="fuu-card form-card">
  <p class="section-label">NOVO ENDEREÇO</p>
  <input
    type="text"
    inputmode="numeric"
    placeholder="CEP"
    bind:value={form.postalCode}
    onblur={fillFromCep}
  />
  <!-- O aviso fica SEMPRE no DOM, só invisível: quando ele aparecia e
       sumia, a busca do CEP disparada no blur empurrava os botões pra
       baixo entre o mousedown e o mouseup, e o primeiro toque em
       "Salvar endereço" se perdia. Achado no teste com navegador. -->
  <p class="hint cep-hint" class:on={cepLookupBusy}>Buscando pelo CEP…</p>
  <input type="text" maxlength="200" placeholder="Rua" bind:value={form.street} />
  <div class="field-row">
    <input type="text" maxlength="20" placeholder="Número" bind:value={form.number} />
    <input type="text" maxlength="100" placeholder="Complemento" bind:value={form.complement} />
  </div>
  <input type="text" maxlength="100" placeholder="Bairro" bind:value={form.neighborhood} />
  <!-- 14.3 — "ponto de referência (ajuda o entregador)". Fica junto do
       endereço porque é ele que evita a ligação na hora da entrega. -->
  <input type="text" maxlength="200" placeholder="Ponto de referência (ajuda o entregador)" bind:value={form.reference} />

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
    <input type="text" maxlength="40" placeholder="Como você chama esse endereço?" bind:value={form.label} />
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
    <button type="button" class="btn-fuu-primary" disabled={busy} onclick={submit}>
      {busy ? 'Salvando…' : 'Salvar endereço'}
    </button>
    <button type="button" class="link-btn" onclick={onCancel}>Cancelar</button>
  </div>
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
  .hint {
    text-align: center;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
    margin: 6px 0 0;
  }
</style>
