// toastr, sem jQuery.
//
// A cláusula zero pede "toastr" para avisos leves e proíbe jQuery em código
// novo ("Proibido em código novo; a remover até a semana 8") -- mas o
// pacote npm `toastr` exige jQuery em runtime ("jQuery is required", no
// próprio package.json dele). As duas regras da mesma cláusula, seguidas ao
// pé da letra, se contradizem para código escrito do zero.
//
// Resolvido do mesmo jeito que o JWT em lib/core/jwt.php: a MESMA interface que
// o resto do app vai chamar (toastr.success/error/warning/info, com as
// mesmas opções mais comuns), reimplementada em ~60 linhas de DOM puro. Se
// algum dia isso não bastar, é proposta de mudança de stack -- não decisão
// de quem estiver escrevendo o componente.

const CONTAINER_ID = 'fuu-toastr-container';
const DEFAULT_TIMEOUT = 4000;

function ensureContainer() {
  let el = document.getElementById(CONTAINER_ID);
  if (!el) {
    el = document.createElement('div');
    el.id = CONTAINER_ID;
    el.setAttribute('aria-live', 'polite');
    Object.assign(el.style, {
      position: 'fixed',
      top: '16px',
      right: '16px',
      zIndex: '2000',
      display: 'flex',
      flexDirection: 'column',
      gap: '8px',
      maxWidth: '320px',
    });
    document.body.appendChild(el);
  }
  return el;
}

const KIND_STYLES = {
  success: { bg: '#10633a', fg: '#fff' },
  error: { bg: '#b3231a', fg: '#fff' },
  warning: { bg: '#fff6e5', fg: '#8a5a00' },
  info: { bg: '#2f2f2f', fg: '#fff' },
};

function show(kind, message, title, options = {}) {
  const container = ensureContainer();
  const { bg, fg } = KIND_STYLES[kind] ?? KIND_STYLES.info;

  const toast = document.createElement('div');
  Object.assign(toast.style, {
    background: bg,
    color: fg,
    borderRadius: '11px',
    padding: '12px 14px',
    fontFamily: "'Familjen Grotesk', system-ui, sans-serif",
    fontSize: '13.5px',
    lineHeight: '1.4',
    boxShadow: '0 2px 10px rgba(0,0,0,.12)',
    opacity: '0',
    transform: 'translateY(-6px)',
    transition: 'opacity .15s ease, transform .15s ease',
  });

  if (title) {
    const titleEl = document.createElement('div');
    titleEl.style.fontWeight = '700';
    titleEl.style.marginBottom = '2px';
    titleEl.textContent = title;
    toast.appendChild(titleEl);
  }
  const bodyEl = document.createElement('div');
  bodyEl.textContent = message;
  toast.appendChild(bodyEl);

  container.appendChild(toast);
  requestAnimationFrame(() => {
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';
  });

  const timeout = options.timeOut ?? DEFAULT_TIMEOUT;
  if (timeout > 0) {
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-6px)';
      setTimeout(() => toast.remove(), 150);
    }, timeout);
  }
}

export const toastr = {
  success: (message, title, options) => show('success', message, title, options),
  error: (message, title, options) => show('error', message, title, options),
  warning: (message, title, options) => show('warning', message, title, options),
  info: (message, title, options) => show('info', message, title, options),
};
