// A impressora térmica ESC/POS ligada no tablet do balcão (cláusula zero).
//
// O servidor gera os bytes (restaurants/print_queue.php, lib/printing/); este
// módulo só os entrega à impressora que está fisicamente no aparelho, por:
//   WebUSB     -- Chrome no Android e no computador; a impressora aparece como
//                 interface USB de classe 7 (impressora) com um endpoint OUT;
//   Web Serial -- impressoras que se apresentam como porta serial/COM.
// O navegador exige um toque da pessoa pra dar permissão na primeira vez
// ("Conectar impressora"); depois, `reconnect()` reabre sozinho a que já foi
// autorizada, sem perguntar de novo.
//
// Sem nenhuma das duas APIs (Safari, Firefox), o painel imprime pelo
// navegador: o mesmo documento em texto, na largura da bobina.
//
// A largura (48 colunas = 80 mm, 32 = 58 mm) é da bobina deste aparelho, e
// fica guardada nele.

const COLUMNS_KEY = 'fuu_printer_columns';
const PRINTER_CLASS = 7;

let usbDevice = null;
let usbEndpoint = null;
let serialPort = null;

export function printerSupported() {
  return typeof navigator !== 'undefined' && (!!navigator.usb || !!navigator.serial);
}

export function printerConnected() {
  return usbDevice !== null || serialPort !== null;
}

export function printerColumns() {
  try {
    const v = Number(localStorage.getItem(COLUMNS_KEY));
    return [32, 42, 48].includes(v) ? v : 48;
  } catch {
    return 48;
  }
}

export function setPrinterColumns(n) {
  try {
    localStorage.setItem(COLUMNS_KEY, String(n));
  } catch {
    // sem armazenamento: vale só até recarregar
  }
}

async function openUsb(device) {
  await device.open();
  if (device.configuration === null) await device.selectConfiguration(1);
  // Preferência: a interface de classe "impressora"; senão, qualquer uma com
  // endpoint de saída (muitas térmicas nacionais se declaram como "vendor").
  const interfaces = device.configuration.interfaces;
  const pick =
    interfaces.find((i) => i.alternate.interfaceClass === PRINTER_CLASS) ??
    interfaces.find((i) => i.alternate.endpoints.some((e) => e.direction === 'out'));
  if (!pick) throw new Error('Esse aparelho USB não parece uma impressora.');
  await device.claimInterface(pick.interfaceNumber);
  usbEndpoint = pick.alternate.endpoints.find((e) => e.direction === 'out').endpointNumber;
  usbDevice = device;
}

/** Pede ao navegador a impressora (precisa de um toque da pessoa). */
export async function connectPrinter() {
  if (navigator.usb) {
    const device = await navigator.usb.requestDevice({ filters: [{ classCode: PRINTER_CLASS }, {}] });
    await openUsb(device);
    return;
  }
  if (navigator.serial) {
    const port = await navigator.serial.requestPort();
    await port.open({ baudRate: 9600 });
    serialPort = port;
    return;
  }
  throw new Error('Este navegador não fala com impressora USB. Use a impressão pelo navegador.');
}

/** Reabre sozinha a impressora já autorizada neste aparelho (ao abrir o painel). */
export async function reconnectPrinter() {
  try {
    if (navigator.usb) {
      const [device] = await navigator.usb.getDevices();
      if (device) {
        await openUsb(device);
        return true;
      }
    }
    if (navigator.serial) {
      const [port] = await navigator.serial.getPorts();
      if (port) {
        await port.open({ baudRate: 9600 });
        serialPort = port;
        return true;
      }
    }
  } catch {
    // desconectada ou em uso por outra aba: fica como "Conectar impressora"
  }
  return false;
}

function base64ToBytes(b64) {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

/** Manda os bytes ESC/POS (base64, como vêm da API) pra impressora. */
export async function printEscpos(base64) {
  const bytes = base64ToBytes(base64);
  if (usbDevice) {
    await usbDevice.transferOut(usbEndpoint, bytes);
    return;
  }
  if (serialPort) {
    const writer = serialPort.writable.getWriter();
    try {
      await writer.write(bytes);
    } finally {
      writer.releaseLock();
    }
    return;
  }
  throw new Error('Nenhuma impressora conectada.');
}

/**
 * Impressão pelo navegador (sem USB): o texto do documento, em fonte
 * monoespaçada na largura da bobina, numa janela própria.
 */
export function printInBrowser(title, text) {
  const win = window.open('', '_blank', 'width=420,height=640');
  if (!win) throw new Error('O navegador bloqueou a janela de impressão.');
  const escaped = text.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]);
  win.document.write(
    `<!doctype html><title>${title}</title><style>@page{margin:4mm}body{margin:0}` +
      `pre{font:13px/1.35 monospace;white-space:pre;margin:0}</style><pre>${escaped}</pre>`
  );
  win.document.close();
  win.focus();
  win.print();
  win.close();
}
