<script>
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
