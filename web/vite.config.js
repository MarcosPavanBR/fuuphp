import { svelte } from '@sveltejs/vite-plugin-svelte'
import { defineConfig } from 'vite'
import { resolve } from 'node:path'

// Duas entradas, dois bundles: o PWA do cliente (index.html) e o painel da
// loja (painel.html, Fase 7.3/11). São públicos diferentes, em aparelhos
// diferentes -- o cliente não deve baixar a fila de validação de Pix junto
// com o cardápio, e o tablet da cozinha não precisa do carrinho.
export default defineConfig({
  plugins: [svelte()],
  build: {
    rollupOptions: {
      input: {
        main: resolve(import.meta.dirname, 'index.html'),
        painel: resolve(import.meta.dirname, 'painel.html'),
      },
    },
  },
})
