<script>
  // O símbolo da marca (docs/BRAND.md): um U que é tigela e sorriso, com o
  // vapor da comida quente subindo -- o "fuu". Mesmo desenho de
  // web/public/brand/fuu-simbolo.svg, inline pra não custar uma requisição.
  let { size = 44, label = 'FUUdelivery' } = $props();
</script>

<svg
  class="brand-mark"
  viewBox="0 0 512 512"
  width={size}
  height={size}
  role="img"
  aria-label={label}
>
  <rect width="512" height="512" rx="120" fill="var(--fuu-red)" />
  <g fill="none" stroke="var(--fuu-white)" stroke-linecap="round">
    <path d="M138 272 v14 a118 118 0 0 0 236 0 v-14" stroke-width="64" />
    <path d="M216 208 C197 190 197 170 216 152 C235 134 235 114 216 96" stroke-width="34" />
    <path d="M296 208 C277 190 277 170 296 152 C315 134 315 114 296 96" stroke-width="34" />
  </g>
</svg>

<style>
  .brand-mark {
    display: block;
    flex: none;
  }
</style>
