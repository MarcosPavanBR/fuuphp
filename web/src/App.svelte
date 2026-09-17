<script>
  import Splash from './lib/screens/Splash.svelte';
  import StateSelector from './lib/screens/StateSelector.svelte';
  import CityPicker from './lib/screens/CityPicker.svelte';
  import { toastr } from './lib/toastr.js';

  // Fase 1 — Onboarding & Geolocalização: 1.1 Splash -> 1.2 Seleção de
  // estado -> 1.3 Cidade + bairro. Só essa fase está portada por enquanto;
  // as demais 14 fases das 64 telas ainda não têm componente Svelte.
  let step = $state('splash');
  let uf = $state(null);
  let location = $state(null);

  function goToState() {
    step = 'state';
  }

  function goToCity(selectedUf) {
    uf = selectedUf;
    step = 'city';
  }

  function finishOnboarding(result) {
    location = result;
    step = 'done';
    toastr.success(`${result.neighborhood}, ${result.city.name} — ${result.uf}`, 'Localização definida');
  }
</script>

{#if step === 'splash'}
  <Splash onContinue={goToState} />
{:else if step === 'state'}
  <StateSelector onContinue={goToCity} />
{:else if step === 'city'}
  <CityPicker {uf} onDone={finishOnboarding} />
{:else}
  <div class="done-placeholder">
    <p class="fuu-display">Fase 1 concluída</p>
    <p>
      {location.neighborhood}, {location.city.name} — {location.uf}
      ({location.city.ibge})
    </p>
    <p class="hint">
      A Fase 2 (home, busca, fidelidade) ainda não foi portada para Svelte.
    </p>
  </div>
{/if}

<style>
  .done-placeholder {
    max-width: 430px;
    margin: 0 auto;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-align: center;
    padding: 0 32px;
    color: var(--fuu-ink-2);
  }
  .hint {
    color: var(--fuu-ink-5);
    font-size: 13px;
  }
</style>
