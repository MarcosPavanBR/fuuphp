<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';

  // Aba Cidades: onde o FUU opera (migração 036, admin/cities.php).
  // Ligar uma cidade é o que faz ela aparecer no onboarding do cliente (telas
  // 1.2 e 1.3) e no cadastro de loja. Desligar esconde do app sem apagar nada.
  //
  // O código IBGE (7 dígitos) é o mesmo que as lojas e os endereços gravam;
  // lat/lng é o centro da cidade, pra Home ordenar lojas por distância antes
  // de o cliente ter endereço.
  const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR',
    'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
  // O formulário não carrega `active`: ligar/desligar é só pelo botão da
  // lista, pra salvar uma edição nunca desfazer um liga/desliga.
  const EMPTY = { ibge_code: '', name: '', uf: 'SP', lat: '', lng: '', neighborhoods: '', timezone: '' };

  let cities = $state(null);
  // Fusos aceitos (migração 038): o relógio das lojas da cidade. Vazio no
  // formulário = automático pela UF (MS, MT, AM, RO, RR e AC não são UTC−3).
  let timezones = $state({});
  let form = $state({ ...EMPTY });
  let editing = $state(false);
  let errors = $state({});
  let saving = $state(false);

  async function load() {
    try {
      const res = await api.get('/admin/cities.php', { token: adminToken() });
      cities = res.cities;
      timezones = res.timezones ?? {};
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar as cidades.');
    }
  }

  $effect(() => {
    load();
  });

  function edit(city) {
    form = {
      ibge_code: city.ibge,
      name: city.name,
      uf: city.uf,
      lat: String(city.lat),
      lng: String(city.lng),
      neighborhoods: city.neighborhoods.join(', '),
      timezone: city.timezone ?? '',
    };
    editing = true;
    errors = {};
  }

  function reset() {
    form = { ...EMPTY };
    editing = false;
    errors = {};
  }

  // Campo vazio vira null (a API recusa), nunca 0: Number('') é 0, e 0 cai
  // dentro da faixa do Brasil pra latitude -- a cidade iria pro Equador.
  function coord(v) {
    const t = String(v ?? '').trim().replace(',', '.');
    return t === '' ? null : Number(t);
  }

  async function save(event, overrides = null) {
    event?.preventDefault();
    const data = { ...form, ...(overrides ?? {}) };
    saving = true;
    errors = {};
    try {
      await api.post('/admin/cities.php', {
        token: adminToken(),
        body: {
          ...data,
          lat: coord(data.lat),
          lng: coord(data.lng),
          neighborhoods: String(data.neighborhoods).split(','),
        },
      });
      toastr.success(
        !overrides
          ? `${data.name} salva.`
          : data.active
            ? `${data.name} está no app.`
            : `${data.name} saiu do app (nada foi apagado).`
      );
      if (!overrides) reset();
      await load();
    } catch (e) {
      errors = e.fields ?? {};
      toastr.error(e.message ?? 'Não deu pra salvar a cidade.');
    } finally {
      saving = false;
    }
  }

  function toggle(city) {
    save(null, {
      ibge_code: city.ibge,
      name: city.name,
      uf: city.uf,
      lat: city.lat,
      lng: city.lng,
      neighborhoods: city.neighborhoods.join(','),
      timezone: city.timezone ?? '',
      active: !city.active,
    });
  }
</script>

<section class="cities">
  <div class="fuu-card block">
    <p class="section">{editing ? 'EDITAR CIDADE' : 'LIGAR UMA CIDADE'}</p>
    <form class="grid" onsubmit={save}>
      <label class:bad={errors.ibge_code}>
        <span>Código IBGE</span>
        <input inputmode="numeric" maxlength="7" placeholder="3509502" bind:value={form.ibge_code} disabled={editing} />
        {#if errors.ibge_code}<small>{errors.ibge_code}</small>{/if}
      </label>
      <label class="wide" class:bad={errors.name}>
        <span>Cidade</span>
        <input maxlength="100" placeholder="Campinas" bind:value={form.name} />
        {#if errors.name}<small>{errors.name}</small>{/if}
      </label>
      <label class:bad={errors.uf}>
        <span>UF</span>
        <select bind:value={form.uf}>
          {#each UFS as uf (uf)}<option value={uf}>{uf}</option>{/each}
        </select>
      </label>
      <label class:bad={errors.lat}>
        <span>Latitude do centro</span>
        <input inputmode="decimal" placeholder="-22.9056" bind:value={form.lat} />
        {#if errors.lat}<small>{errors.lat}</small>{/if}
      </label>
      <label class:bad={errors.lng}>
        <span>Longitude do centro</span>
        <input inputmode="decimal" placeholder="-47.0608" bind:value={form.lng} />
        {#if errors.lng}<small>{errors.lng}</small>{/if}
      </label>
      <label class="wide" class:bad={errors.timezone}>
        <span>Fuso (o relógio das lojas da cidade)</span>
        <select bind:value={form.timezone}>
          <option value="">Automático pela UF</option>
          {#each Object.entries(timezones) as [tz, label] (tz)}<option value={tz}>{label}</option>{/each}
        </select>
        {#if errors.timezone}<small>{errors.timezone}</small>{/if}
      </label>
      <label class="full" class:bad={errors.neighborhoods}>
        <span>Bairros (separados por vírgula)</span>
        <input placeholder="Centro, Cambuí, Taquaral" bind:value={form.neighborhoods} />
        {#if errors.neighborhoods}<small>{errors.neighborhoods}</small>{/if}
      </label>
      <div class="actions full">
        <button type="submit" class="btn-fuu-primary" disabled={saving}>
          {saving ? 'Salvando…' : editing ? 'Salvar cidade' : 'Ligar cidade'}
        </button>
        {#if editing}<button type="button" class="ghost" onclick={reset}>Cancelar</button>{/if}
      </div>
    </form>
    <p class="note">
      O código IBGE aparece buscando "código IBGE" + nome da cidade (site do IBGE). Latitude e longitude: no Google
      Maps, clique com o botão direito no centro da cidade e copie os dois números.
    </p>
  </div>

  <div class="fuu-card block">
    <p class="section">CIDADES ATENDIDAS</p>
    {#if cities === null}
      <p class="empty">Carregando…</p>
    {:else if cities.length === 0}
      <p class="empty">
        Nenhuma cidade ligada ainda. Enquanto não houver, o app não mostra cidade nenhuma e nenhuma loja consegue se
        cadastrar.
      </p>
    {:else}
      {#each cities as city (city.ibge)}
        <div class="city-row" class:off={!city.active}>
          <div class="info">
            <strong>{city.name} — {city.uf}</strong>
            <span class="fuu-mono">IBGE {city.ibge} · {city.stores} {city.stores === 1 ? 'loja aprovada' : 'lojas aprovadas'}</span>
            <span>{timezones[city.timezone] ?? city.timezone}</span>
            <span class="hoods">{city.neighborhoods.join(' · ')}</span>
          </div>
          <span class="state">{city.active ? 'No app' : 'Desligada'}</span>
          <button type="button" class="ghost" onclick={() => edit(city)}>Editar</button>
          <button type="button" class="ghost" disabled={saving} onclick={() => toggle(city)}>
            {city.active ? 'Desligar' : 'Ligar'}
          </button>
        </div>
      {/each}
    {/if}
  </div>
</section>

<style>
  .cities {
    display: grid;
    gap: 12px;
  }
  .block {
    padding: 16px;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 12px;
  }
  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px 12px;
  }
  .grid .wide {
    grid-column: span 2;
  }
  .grid .full {
    grid-column: 1 / -1;
  }
  label {
    display: grid;
    gap: 4px;
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    min-width: 0;
  }
  input,
  select {
    border: 1px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 9px 12px;
    font-family: inherit;
    font-size: 14px;
    background: var(--fuu-white);
    width: 100%;
    min-width: 0;
  }
  label.bad input {
    border-color: var(--fuu-alert);
  }
  label small {
    color: var(--fuu-alert);
    font-size: 11.5px;
  }
  .actions {
    display: flex;
    gap: 8px;
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
  .note {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 12px 0 0;
    line-height: 1.55;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .city-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid var(--fuu-line-5);
    flex-wrap: wrap;
  }
  .city-row.off .info {
    opacity: 0.55;
  }
  .info {
    flex: 1;
    min-width: 200px;
    display: grid;
    gap: 2px;
  }
  .info span {
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .state {
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-3);
  }
  .city-row:not(.off) .state {
    color: var(--fuu-leaf);
  }
  @media (max-width: 520px) {
    .grid .wide {
      grid-column: 1 / -1;
    }
  }
</style>
