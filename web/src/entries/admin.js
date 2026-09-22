import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../styles/tokens.css';

import { mount } from 'svelte';
import AdminApp from '../apps/AdminApp.svelte';

const app = mount(AdminApp, { target: document.getElementById('admin') });

export default app;
