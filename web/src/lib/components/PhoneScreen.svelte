<script>
  // A moldura de celular das telas do mock: largura máxima de 430 px,
  // centralizada, fundo papel. Em celular de verdade ocupa a tela toda; no
  // computador, o app aparece do tamanho que foi desenhado.
  let { children } = $props();
</script>

<div class="phone-screen">
  {@render children()}
</div>

<style>
  .phone-screen {
    max-width: 430px;
    min-height: 100vh;
    margin: 0 auto;
    background: var(--fuu-paper);
    display: flex;
    flex-direction: column;
    position: relative;
  }
</style>
