import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import './styles/tokens.css';

import { mount } from 'svelte';
import Courier from './Courier.svelte';

const app = mount(Courier, {
  target: document.getElementById('entregador'),
});

export default app;
