<script>
  import PhoneStatusBar from '../../components/PhoneStatusBar.svelte';
  import { MOST_USED_STATES, ALL_STATES } from '../../data/states.js';

  // Tela 1.2 — Seleção de estado. "27 UFs em list-group do Bootstrap; lista
  // vem do edge cache, então abre offline." (StatePickerScreen.svelte, bi-geo-alt,
  // Cloudflare cache)
  let { onContinue } = $props();

  let query = $state('');
  let selected = $state(null);

  let filteredMostUsed = $derived(
    MOST_USED_STATES.filter((s) => matches(s, query))
  );
  let filteredAll = $derived(ALL_STATES.filter((s) => matches(s, query)));

  function matches(state, q) {
    if (!q) return true;
    const needle = q.toLocaleLowerCase('pt-BR');
    return (
      state.uf.toLocaleLowerCase('pt-BR').includes(needle) ||
      state.name.toLocaleLowerCase('pt-BR').includes(needle)
    );
  }

  function select(state) {
    selected = state.uf;
  }
</script>

<div class="state-selector">
  <PhoneStatusBar />

  <div class="header">
    <p class="step">PASSO 1 DE 2</p>
    <h1 class="fuu-display">Onde você está?</h1>
    <div class="search">
      <i class="bi bi-geo-alt"></i>
      <input
        type="search"
        placeholder="Buscar estado"
        bind:value={query}
        aria-label="Buscar estado"
      />
    </div>
  </div>

  <div class="lists">
    {#if filteredMostUsed.length > 0}
      <p class="section-label">MAIS USADOS</p>
      <div class="list-group">
        {#each filteredMostUsed as state (state.uf)}
          <button
            type="button"
            class="list-group-item"
            class:selected={selected === state.uf}
            onclick={() => select(state)}
          >
            <span class="uf">{state.uf} — {state.name}</span>
            <span class="stores">
              <strong>{state.stores.toLocaleString('pt-BR')}</strong>
              <small>lojas ativas</small>
            </span>
          </button>
        {/each}
      </div>
    {/if}

    {#if filteredAll.length > 0}
      <p class="section-label">TODOS OS 27 ESTADOS</p>
      <div class="list-group">
        {#each filteredAll as state (state.uf)}
          <button
            type="button"
            class="list-group-item"
            class:selected={selected === state.uf}
            onclick={() => select(state)}
          >
            <span class="uf">{state.uf} — {state.name}</span>
          </button>
        {/each}
      </div>
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
  .stores {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    line-height: 1.2;
  }
  .stores strong {
    font-family: var(--fuu-font-mono);
    font-size: 13.5px;
  }
  .stores small {
    color: var(--fuu-ink-5);
    font-size: 11px;
  }
  .footer {
    position: sticky;
    bottom: 0;
    padding: 14px 20px 22px;
    background: linear-gradient(to top, var(--fuu-paper) 60%, transparent);
  }
</style>
