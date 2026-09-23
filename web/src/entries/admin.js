// Ponto de entrada do painel da plataforma (admin.html) -- time da plataforma: políticas, netting, disputas, relatórios.
// O Vite tem uma entrada por app (vite.config.js); cada uma carrega o CSS
// comum (Bootstrap, ícones, tokens do mock) e monta a raiz do app em
// web/src/apps/ no elemento #admin da página.
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../styles/tokens.css';

import { mount } from 'svelte';
import AdminApp from '../apps/AdminApp.svelte';

const app = mount(AdminApp, { target: document.getElementById('admin') });

export default app;
