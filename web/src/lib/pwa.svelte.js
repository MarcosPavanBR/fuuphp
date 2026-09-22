// Tela 7.1 — instalação e modo offline.
//
// Três coisas, todas reais: registrar o service worker, guardar o evento de
// instalação que o navegador dispara (`beforeinstallprompt`) pra poder
// oferecer na hora certa, e saber se está online.
//
// O prompt não é inventado: `beforeinstallprompt` só dispara quando o
// navegador já decidiu que o app é instalável (manifest válido, SW ativo,
// https). Por isso o banner só aparece quando o evento chegou -- um botão
// "Instalar" que não instala seria pior que nenhum.
import { BASE } from './api.js';

let online = $state(typeof navigator === 'undefined' ? true : navigator.onLine);
let installPrompt = $state(null);
let dismissed = $state(readDismissed());

function readDismissed() {
  try {
    return localStorage.getItem('fuu_install_dismissed') === '1';
  } catch {
    return false;
  }
}

export function isOnline() {
  return online;
}

export function canInstall() {
  return installPrompt !== null && !dismissed;
}

export function dismissInstall() {
  dismissed = true;
  try {
    localStorage.setItem('fuu_install_dismissed', '1');
  } catch {
    // storage bloqueado: o banner volta no próximo acesso, e tudo bem
  }
}

export async function promptInstall() {
  if (!installPrompt) return false;
  installPrompt.prompt();
  const { outcome } = await installPrompt.userChoice;
  installPrompt = null;
  if (outcome !== 'accepted') dismissInstall();
  return outcome === 'accepted';
}

export function startPwa() {
  if (typeof window === 'undefined') return;

  window.addEventListener('online', () => (online = true));
  window.addEventListener('offline', () => (online = false));

  window.addEventListener('beforeinstallprompt', (e) => {
    // Sem preventDefault o Chrome mostra o próprio banner, e a tela 7.1 quer
    // o convite no lugar dela, com o texto dela.
    e.preventDefault();
    installPrompt = e;
  });

  window.addEventListener('appinstalled', () => {
    installPrompt = null;
  });

  // Em dev o Vite serve o app de /src; o SW mora em /sw.js (public/) nos
  // dois modos, então o registro é o mesmo.
  if ('serviceWorker' in navigator) {
    // O endereço da API vai na URL do SW: em produção é o mesmo domínio
    // (/api/v1), em dev é outra porta (VITE_API_BASE) -- e o SW precisa dele
    // pra buscar o texto do push (tela 7.2).
    navigator.serviceWorker.register(`/sw.js?api=${encodeURIComponent(BASE)}`).catch(() => {
      // navegador sem suporte ou origem insegura: o app funciona igual,
      // só não offline
    });
  }
}
