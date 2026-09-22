<script>
  import { BASE } from '../services/api.js';

  // A foto de um item do cardápio, ou o ícone de "sem foto" -- o mesmo
  // quadrado em todo lugar (loja 3.1, item 3.2, busca 2.2, painel 11.1).
  // Quem chama define o tamanho do quadrado; aqui a imagem só o preenche.
  // A URL é estável por conteúdo (restaurants/menu_photo.php), então o
  // navegador e o service worker podem guardar sem medo de foto velha.
  let { photoKey = null, alt = '' } = $props();

  let failed = $state(false);
</script>

{#if photoKey && !failed}
  <img
    class="menu-photo"
    src={`${BASE}/restaurants/menu_photo.php?key=${encodeURIComponent(photoKey)}`}
    {alt}
    loading="lazy"
    onerror={() => (failed = true)}
  />
{:else}
  <i class="bi bi-image" aria-hidden="true"></i>
{/if}

<style>
  .menu-photo {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: inherit;
    display: block;
  }
</style>
