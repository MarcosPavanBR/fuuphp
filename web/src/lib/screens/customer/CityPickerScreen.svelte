<script>
  import swal from 'sweetalert';
  import PhoneStatusBar from '../../components/PhoneStatusBar.svelte';
  import { loadServiceStates } from '../../services/cities.js';
  import { toastr } from '../../utils/toastr.js';

  // Tela 1.3 — Cidade + bairro. "O modal é SweetAlert — pergunta antes do
  // prompt nativo do navegador, como manda a regra 'SweetAlert = modais'."
  // (CityPickerScreen.svelte, SweetAlert, Geolocation API)
  let { uf, onDone } = $props();

  // As cidades atendidas do estado escolhido (a mesma busca da tela 1.2).
  let cities = $state([]);
  loadServiceStates()
    .then((states) => (cities = states.find((s) => s.uf === uf)?.cities ?? []))
    .catch(() => toastr.error('Não deu pra carregar as cidades. Volte e tente de novo.'));
  let query = $state('');
  let selectedCity = $state(null);
  let selectedNeighborhood = $state(null);

  let filteredCities = $derived(
    query
      ? cities.filter((c) => c.name.toLocaleLowerCase('pt-BR').includes(query.toLocaleLowerCase('pt-BR')))
      : cities
  );

  // Posição do aparelho, se o cliente deixar: ordena as lojas pela
  // distância real em vez do centro da cidade.
  let coords = $state(null);
  // Cada escolha de cidade tem um número: resposta de GPS atrasada, de uma
  // escolha anterior, não grava posição na escolha de agora.
  let pick = 0;

  async function pickCity(city) {
    const current = ++pick;
    selectedCity = city;
    selectedNeighborhood = null;
    coords = null;

    // "pergunta antes do prompt nativo do navegador": o SweetAlert decide
    // se vale a pena nem chamar a Geolocation API, pra não queimar o
    // consentimento do navegador com uma recusa.
    const useLocation = await swal({
      title: 'Usar sua localização?',
      text: 'Mostramos primeiro as lojas mais perto de você.',
      icon: undefined,
      buttons: {
        manual: { text: 'Agora não', value: false, className: 'swal-btn-manual' },
        locate: { text: 'Ativar localização', value: true, className: 'swal-btn-locate' },
      },
    });

    if (useLocation && current === pick) {
      requestGeolocation(current);
    }
  }

  function requestGeolocation(current) {
    if (!navigator.geolocation) {
      toastr.warning('Esse aparelho não suporta localização automática.');
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        if (current !== pick) return;
        // Descobrir o NOME do bairro a partir de lat/lng pediria um serviço
        // de geocodificação (fora da stack); o bairro o cliente escolhe. A
        // posição fica e vale pra distância das lojas.
        coords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        toastr.success('Localização marcada. Agora escolha o seu bairro.');
      },
      () => {
        if (current !== pick) return;
        toastr.warning('Não deu pra usar a localização. Sem problema: escolha o bairro na lista.');
      }
    );
  }
</script>

<div class="city-picker">
  <PhoneStatusBar />

  <div class="header">
    <p class="step">PASSO 2 DE 2</p>
    <h1 class="fuu-display">Cidade e bairro</h1>
    <div class="search">
      <i class="bi bi-search"></i>
      <input
        type="search"
        placeholder={`Buscar cidade em ${uf}`}
        bind:value={query}
        aria-label={`Buscar cidade em ${uf}`}
      />
    </div>
  </div>

  <div class="body">
    <div class="list-group">
      {#each filteredCities as city (city.ibge)}
        <button
          type="button"
          class="list-group-item"
          class:selected={selectedCity?.ibge === city.ibge}
          onclick={() => pickCity(city)}
        >
          {city.name}
        </button>
      {/each}
    </div>

    {#if selectedCity}
      <p class="section-label">BAIRRO</p>
      <div class="chips">
        {#each selectedCity.neighborhoods as n (n)}
          <button
            type="button"
            class="chip"
            class:selected={selectedNeighborhood === n}
            onclick={() => (selectedNeighborhood = n)}
          >
            {n}
          </button>
        {/each}
      </div>
    {/if}
  </div>

  <div class="footer">
    <button
      type="button"
      class="btn-fuu-primary w-100"
      disabled={!selectedCity || !selectedNeighborhood}
      onclick={() => onDone({ uf, city: selectedCity, neighborhood: selectedNeighborhood, coords })}
    >
      Confirmar
    </button>
  </div>
</div>

<style>
  .city-picker {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background: var(--fuu-paper);
  }
  .header {
    padding: 4px 20px 12px;
  }
  .step {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    letter-spacing: 0.14em;
    color: var(--fuu-red);
    margin: 0 0 6px;
    font-weight: 500;
  }
  h1 {
    font-size: 24px;
    font-weight: 600;
    margin: 0 0 14px;
    color: var(--fuu-ink-1);
  }
  .search {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
  }
  .search i {
    color: var(--fuu-ink-5);
  }
  .search input {
    border: none;
    outline: none;
    flex: 1;
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
    background: transparent;
  }
  .body {
    flex: 1;
    overflow-y: auto;
    padding: 4px 20px 100px;
  }
  .list-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .list-group-item {
    text-align: left;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 0 14px;
    min-height: var(--fuu-tap-customer);
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
    color: var(--fuu-ink-1);
  }
  .list-group-item.selected {
    border-color: var(--fuu-red);
    background: var(--fuu-red-tint);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.12em;
    color: var(--fuu-ink-5);
    margin: 18px 0 8px;
    font-weight: 500;
  }
  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .chip {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 999px;
    padding: 8px 16px;
    font-family: var(--fuu-font-body);
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .chip.selected {
    background: var(--fuu-red);
    border-color: var(--fuu-red);
    color: var(--fuu-white);
  }
  .footer {
    position: sticky;
    bottom: 0;
    padding: 14px 20px 22px;
    background: linear-gradient(to top, var(--fuu-paper) 60%, transparent);
  }
</style>
