// Ponto de entrada do painel da loja (painel.html) -- tablet do balcão: KDS, validação de Pix, operação da loja.
// O Vite tem uma entrada por app (vite.config.js); cada uma carrega o CSS
// comum (Bootstrap, ícones, tokens do mock) e monta a raiz do app em
// web/src/apps/ no elemento #painel da página.
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../styles/tokens.css';

import { mount } from 'svelte';
import StorePanelApp from '../apps/StorePanelApp.svelte';

const panel = mount(StorePanelApp, {
  target: document.getElementById('painel'),
});

export default panel;
