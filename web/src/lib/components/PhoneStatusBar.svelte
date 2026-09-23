<script>
  // A barra de status "9:41" que o mock desenha no topo das telas. É
  // decoração do mock (o celular real já tem a sua); aparece só nas telas
  // que a desenham, como o splash e o onboarding.
  let { dark = false } = $props();
</script>

<div class="status-bar" class:dark>
  <span class="time">9:41</span>
  <span class="icons">
    <i class="bi bi-reception-4"></i>
    <i class="bi bi-wifi"></i>
    <i class="bi bi-battery-full"></i>
  </span>
</div>

<style>
  .status-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 20px 4px;
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .status-bar.dark {
    color: var(--fuu-white);
  }
  .icons {
    display: flex;
    gap: 6px;
    font-size: 13px;
  }
</style>
