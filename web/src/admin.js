import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import './styles/tokens.css';

import { mount } from 'svelte';
import Admin from './Admin.svelte';

const app = mount(Admin, { target: document.getElementById('admin') });

export default app;
