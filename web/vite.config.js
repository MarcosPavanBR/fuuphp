import { svelte } from '@sveltejs/vite-plugin-svelte'
import { defineConfig } from 'vite'
import { resolve } from 'node:path'

// Quatro entradas, quatro bundles, um por público: o PWA do cliente
// (index.html), o painel da loja (painel.html, Fase 7.3/11) e o app do
// entregador (entregador.html, Fase 8/9). São aparelhos diferentes com
// trabalhos diferentes -- o cliente não baixa a fila de validação de Pix
// junto com o cardápio, o tablet da cozinha não precisa do carrinho, e a
// moto não precisa de nenhum dos dois. O quarto é o painel da plataforma
// (admin.html, Fase 12), que é de quem opera o negócio.
export default defineConfig({
  plugins: [svelte()],
  build: {
    rollupOptions: {
      input: {
        main: resolve(import.meta.dirname, 'index.html'),
        painel: resolve(import.meta.dirname, 'painel.html'),
        entregador: resolve(import.meta.dirname, 'entregador.html'),
        admin: resolve(import.meta.dirname, 'admin.html'),
      },
    },
  },
})
