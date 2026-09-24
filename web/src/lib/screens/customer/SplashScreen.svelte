<script>
  import { fade } from 'svelte/transition';
  import BrandMark from '../../components/BrandMark.svelte';

  // Tela 1.1 — Splash: a marca com transição fade e avanço automático. O
  // cache offline é do service worker (public/sw.js), não desta tela -- por
  // isso o rodapé diz só "abrindo…", sem prometer o que ela não faz.
  let { onContinue } = $props();

  setTimeout(() => onContinue(), 1800);
</script>

<div class="splash" in:fade={{ duration: 300 }}>
  <div class="splash-body">
    <BrandMark size={88} />
    <div class="wordmark fuu-display">
      <span class="fuu">fuu</span><span class="delivery">delivery</span>
    </div>
    <p class="tagline">Pediu, fuu, chegou.</p>
  </div>

  <div class="splash-footer">
    <div class="spinner-fuu" aria-hidden="true"></div>
    <span>abrindo…</span>
  </div>
</div>

<style>
  .splash {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background: var(--fuu-white);
  }
  .splash-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18px;
    text-align: center;
    padding: 0 32px;
  }
  .wordmark {
    font-size: 26px;
  }
  .wordmark .fuu {
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .wordmark .delivery {
    font-weight: 600;
    color: var(--fuu-ink-4);
  }
  .tagline {
    font-family: var(--fuu-font-body);
    color: var(--fuu-ink-4);
    font-size: 14.5px;
    margin: 0;
  }
  .splash-footer {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 22px 0 32px;
    color: var(--fuu-ink-5);
    font-size: 12.5px;
  }
  .spinner-fuu {
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid var(--fuu-line-3);
    border-top-color: var(--fuu-red);
    animation: spin 0.8s linear infinite;
  }
  @keyframes spin {
    to {
      transform: rotate(360deg);
    }
  }
</style>
