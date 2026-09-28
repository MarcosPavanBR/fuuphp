<script>
  import { untrack } from 'svelte';
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import {
    printerSupported,
    printerConnected,
    printerColumns,
    setPrinterColumns,
    connectPrinter,
    reconnectPrinter,
    printEscpos,
    printInBrowser,
  } from '../../services/thermalPrinter.js';

  // A impressora do balcão, no cabeçalho do painel ("Impressora OK" no mock
  // da 7.3; "impressora ok" no KDS 11.1).
  //
  // Com a impressora conectada, a fila imprime sozinha: comanda de pedido pago
  // ("Aprovar dispara advance_order(), que ... imprime a comanda") e recibo de
  // baixa confirmada (9.3, "via impressa ficou na loja"). Cada documento só
  // sai da fila quando o servidor registra que saiu no papel -- tablet
  // desligado não perde comanda, ela espera.
  //
  // Sem impressora (ou navegador sem USB), mostra quantos estão esperando e
  // imprime pelo navegador, num documento só.
  const POLL_MS = 5000;

  let connected = $state(false);
  let columns = $state(printerColumns());
  let pending = $state([]);
  let busy = $state(false);
  let open = $state(false);
  const supported = printerSupported();

  async function ack(job, reprint = false) {
    await api.post('/restaurants/print_queue.php', {
      token: staffToken(),
      body: { kind: job.kind, ref_id: job.ref_id, reprint },
    });
  }

  async function tick() {
    if (busy) return;
    busy = true;
    try {
      const data = await api.get('/restaurants/print_queue.php', { token: staffToken(), query: { columns } });
      pending = data.jobs;
      if (connected) {
        for (const job of data.jobs) {
          await printEscpos(job.escpos_base64);
          await ack(job);
        }
        if (data.jobs.length > 0) pending = [];
      }
    } catch (e) {
      if (connected && e?.name !== 'TypeError') {
        // a impressora sumiu (cabo, papel, energia): volta pro modo sem USB
        connected = false;
        toastr.error('A impressora parou de responder. Confira o cabo e o papel, e conecte de novo.');
      }
    } finally {
      busy = false;
    }
  }

  // untrack: tick() lê e escreve `busy` (e lê `columns`) de forma síncrona.
  // Sem isso o efeito passava a depender deles, rodava de novo a cada fim de
  // requisição e o painel pedia a fila sem parar, uma atrás da outra, em vez
  // de uma vez por POLL_MS.
  $effect(() => {
    reconnectPrinter().then((ok) => (connected = ok && printerConnected()));
    untrack(tick);
    const t = setInterval(tick, POLL_MS);
    return () => clearInterval(t);
  });

  async function connect() {
    try {
      await connectPrinter();
      connected = printerConnected();
      toastr.success('Impressora conectada. As comandas saem sozinhas.');
      tick();
    } catch (e) {
      if (e?.name !== 'NotFoundError') toastr.error(e.message ?? 'Não deu pra conectar a impressora.');
    }
  }

  async function printPendingInBrowser() {
    if (pending.length === 0) return;
    try {
      printInBrowser(
        `${pending.length} documento(s)`,
        pending.map((j) => j.text).join('\n\n' + '='.repeat(columns) + '\n\n')
      );
      for (const job of pending) await ack(job);
      pending = [];
    } catch (e) {
      toastr.error(e.message);
    }
  }

  function changeColumns(n) {
    columns = n;
    setPrinterColumns(n);
  }
</script>

<div class="printer">
  <button type="button" class="status" class:ok={connected} onclick={() => (open = !open)}>
    <i class="bi bi-printer"></i>
    {connected ? 'Impressora OK' : pending.length > 0 ? `${pending.length} pra imprimir` : 'Impressora'}
  </button>
  {#if open}
    <div class="menu">
      {#if !connected}
        {#if supported}
          <button type="button" onclick={connect}>Conectar impressora USB</button>
        {:else}
          <p class="note">Este navegador não fala com impressora USB (use o Chrome). Dá pra imprimir pelo navegador.</p>
        {/if}
        <button type="button" disabled={pending.length === 0} onclick={printPendingInBrowser}>
          Imprimir {pending.length} pelo navegador
        </button>
      {/if}
      <label>
        Bobina
        <select value={columns} onchange={(e) => changeColumns(Number(e.currentTarget.value))}>
          <option value={48}>80 mm (48 colunas)</option>
          <option value={42}>80 mm fonte grande (42)</option>
          <option value={32}>58 mm (32 colunas)</option>
        </select>
      </label>
    </div>
  {/if}
</div>

<style>
  .printer {
    position: relative;
  }
  .status {
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid var(--fuu-line-2);
    border-radius: 999px;
    padding: 6px 12px;
    background: var(--fuu-white);
    font-size: 12.5px;
    font-weight: 600;
    color: var(--fuu-wait-text);
  }
  .status.ok {
    color: var(--fuu-leaf-dark);
    border-color: var(--fuu-leaf);
  }
  .menu {
    position: absolute;
    right: 0;
    top: calc(100% + 6px);
    z-index: 20;
    width: 260px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
  }
  .menu button {
    border: 1px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 10px;
    background: var(--fuu-white);
    font-weight: 600;
    font-size: 13px;
  }
  .menu label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .menu select {
    padding: 8px;
    border-radius: 8px;
    border: 1px solid var(--fuu-line-2);
  }
  .note {
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin: 0;
  }
</style>
