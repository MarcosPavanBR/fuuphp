<script>
  import PhoneStatusBar from '../../components/PhoneStatusBar.svelte';
  import { loadServiceStates } from '../../services/cities.js';

  // Tela 1.2 — Seleção de estado. Só os estados onde o FUU já opera (aba
  // Cidades do admin, cities/list.php), com a contagem REAL de lojas
  // aprovadas. O mock trazia os 27 estados e números fixos ("SP 1.284 lojas
  // ativas"): no lançamento isso prometeria o que não existe.
  let { onContinue } = $props();

  let states = $state(null);
  let failed = $state(false);
  let query = $state('');
  let selected = $state(null);

  function load() {
    failed = false;
    loadServiceStates()
      .then((list) => (states = list))
      .catch(() => (failed = true));
  }
  load();

  let filtered = $derived((states ?? []).filter((s) => matches(s, query)));

  function matches(state, q) {
    if (!q) return true;
    const needle = q.toLocaleLowerCase('pt-BR');
    return (
      state.uf.toLocaleLowerCase('pt-BR').includes(needle) ||
      state.name.toLocaleLowerCase('pt-BR').includes(needle) ||
      state.cities.some((c) => c.name.toLocaleLowerCase('pt-BR').includes(needle))
    );
  }

  function storesLabel(n) {
    if (n === 0) return 'em breve';
    return n === 1 ? '1 loja' : `${n.toLocaleString('pt-BR')} lojas`;
  }
</script>

<div class="state-selector">
  <PhoneStatusBar />

  <div class="header">
    <p class="step">PASSO 1 DE 2</p>
    <h1 class="fuu-display">Onde você está?</h1>
    {#if (states?.length ?? 0) > 3}
      <div class="search">
        <i class="bi bi-geo-alt"></i>
        <input type="search" placeholder="Buscar estado ou cidade" bind:value={query} aria-label="Buscar estado ou cidade" />
      </div>
    {/if}
  </div>

  <div class="lists">
    {#if failed}
      <p class="notice">Não deu pra carregar as cidades. Confira a internet e tente de novo.</p>
      <button type="button" class="retry" onclick={load}>Tentar de novo</button>
    {:else if states === null}
      <p class="notice">Carregando as cidades…</p>
    {:else if states.length === 0}
      <p class="notice">O FUU ainda não chegou em nenhuma cidade. Volte em breve!</p>
    {:else}
      <p class="section-label">ONDE O FUU JÁ ENTREGA</p>
      <div class="list-group">
        {#each filtered as state (state.uf)}
          <button
            type="button"
            class="list-group-item"
            class:selected={selected === state.uf}
            onclick={() => (selected = state.uf)}
          >
            <span class="uf">
              {state.uf} — {state.name}
              <small class="cities">{state.cities.map((c) => c.name).join(', ')}</small>
            </span>
            <span class="stores">{storesLabel(state.stores)}</span>
          </button>
        {/each}
      </div>
      {#if filtered.length === 0}
        <p class="notice">Ainda não entregamos aí. Por enquanto, só nas cidades da lista.</p>
      {/if}
    {/if}
  </div>

  <div class="footer">
    <button
      type="button"
      class="btn-fuu-primary w-100"
      disabled={!selected}
      onclick={() => onContinue(selected)}
    >
      Continuar
    </button>
  </div>
</div>

<style>
  .state-selector {
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
  .lists {
    flex: 1;
    overflow-y: auto;
    padding: 4px 20px 100px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.12em;
    color: var(--fuu-ink-5);
    margin: 16px 0 8px;
    font-weight: 500;
  }
  .list-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .list-group-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    text-align: left;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    min-height: var(--fuu-tap-customer);
    font-family: var(--fuu-font-body);
    font-size: 14.5px;
    color: var(--fuu-ink-1);
  }
  .list-group-item.selected {
    border-color: var(--fuu-red);
    background: var(--fuu-red-tint);
  }
  .uf {
    display: grid;
    gap: 2px;
  }
  .cities {
    color: var(--fuu-ink-5);
    font-size: 12px;
  }
  .stores {
    font-family: var(--fuu-font-mono);
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    white-space: nowrap;
  }
  .notice {
    font-size: 14px;
    color: var(--fuu-ink-3);
    margin: 18px 0 10px;
    line-height: 1.5;
  }
  .retry {
    border: 1.5px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: var(--fuu-radius-pill);
    padding: 8px 16px;
    font-family: inherit;
    font-weight: 700;
  }
  .footer {
    position: sticky;
    bottom: 0;
    padding: 14px 20px 22px;
    background: linear-gradient(to top, var(--fuu-paper) 60%, transparent);
  }
</style>
