// Ponto de entrada do app do cliente (index.html) -- a PWA de pedir: telas das Fases 1 a 6, 13 e 14.
// O Vite tem uma entrada por app (vite.config.js); cada uma carrega o CSS
// comum (Bootstrap, ícones, tokens do mock) e monta a raiz do app em
// web/src/apps/ no elemento #app da página.
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../styles/tokens.css';
import 'bootstrap/dist/js/bootstrap.bundle.min.js';

import { mount } from 'svelte';
import CustomerApp from '../apps/CustomerApp.svelte';

const app = mount(CustomerApp, {
  target: document.getElementById('app'),
});

export default app;
