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
