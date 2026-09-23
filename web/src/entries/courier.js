// Ponto de entrada do app do entregador (entregador.html) -- celular do entregador: ofertas, rota, caixa, maquininha.
// O Vite tem uma entrada por app (vite.config.js); cada uma carrega o CSS
// comum (Bootstrap, ícones, tokens do mock) e monta a raiz do app em
// web/src/apps/ no elemento #entregador da página.
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../styles/tokens.css';

import { mount } from 'svelte';
import CourierApp from '../apps/CourierApp.svelte';

const app = mount(CourierApp, {
  target: document.getElementById('entregador'),
});

export default app;
