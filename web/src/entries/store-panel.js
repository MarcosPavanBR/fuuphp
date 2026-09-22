import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../styles/tokens.css';

import { mount } from 'svelte';
import StorePanelApp from '../apps/StorePanelApp.svelte';

const panel = mount(StorePanelApp, {
  target: document.getElementById('painel'),
});

export default panel;
