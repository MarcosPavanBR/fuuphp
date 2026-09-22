import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../styles/tokens.css';

import { mount } from 'svelte';
import CourierApp from '../apps/CourierApp.svelte';

const app = mount(CourierApp, {
  target: document.getElementById('entregador'),
});

export default app;
