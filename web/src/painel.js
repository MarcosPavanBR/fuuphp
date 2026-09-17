import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import './styles/tokens.css';

import { mount } from 'svelte';
import Panel from './Panel.svelte';

const panel = mount(Panel, {
  target: document.getElementById('painel'),
});

export default panel;
