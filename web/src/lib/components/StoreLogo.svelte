<script>
  import { BASE } from '../services/api.js';

  // O logo da loja (restaurants/logo.php), ou as iniciais do nome quando a
  // loja ainda não subiu logo -- o mesmo quadrado em todo lugar (Home, busca,
  // topo da loja, painel). A URL é estável por conteúdo, então o navegador e o
  // service worker guardam sem medo de logo velho.
  let { logoKey = null, name = '', size = 54 } = $props();

  let failed = $state(false);

  function initials(text) {
    return (
      text
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase() || '?'
    );
  }
</script>

<div class="store-logo" style={`width:${size}px;height:${size}px;font-size:${Math.round(size * 0.3)}px`}>
  {#if logoKey && !failed}
    <img
      src={`${BASE}/restaurants/logo.php?key=${encodeURIComponent(logoKey)}`}
      alt={`Logo de ${name}`}
      loading="lazy"
      onerror={() => (failed = true)}
    />
  {:else}
    <span aria-hidden="true">{initials(name)}</span>
  {/if}
</div>

<style>
  .store-logo {
    flex: none;
    border-radius: 12px;
    background: var(--fuu-red-tint);
    color: var(--fuu-red-hover);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--fuu-font-display);
    font-weight: 800;
    overflow: hidden;
    border: 1px solid var(--fuu-line-3);
  }
  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
</style>
