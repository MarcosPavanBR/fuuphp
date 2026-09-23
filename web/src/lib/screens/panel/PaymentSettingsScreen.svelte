<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';
  import PixKeyPanel from './PixKeyPanel.svelte';

  // Tela 10.4 — "Painel da loja: configurar formas de pagamento".
  //
  // "Cada opção mostra a consequência operacional, não só um interruptor."
  // Por isso o texto ao lado de cada forma vem do servidor
  // (`restaurants/payment_settings.php`): é regra de operação, e regra não
  // mora escrita no front.
  //
  // O bloco das maquininhas é a outra metade da tela: "Inclui o controle de
  // posse das maquininhas, que são da loja." É daqui que a loja confirma a
  // devolução -- a segunda ponta da custódia da tela 10.6.

  let data = $state(null);
  let chosen = $state([]);
  let limits = $state({ min_order: '', max_cash: '', max_card_machine: '', max_change: '' });
  let saving = $state(false);
  let newDevice = $state({ label: '', acquirer: '' });
  let busyDevice = $state(null);

  function money(v) {
    return v === null || v === undefined || v === '' ? '—' : `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function hm(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : '';
  }

  function fill(res) {
    data = res;
    const s = res.settings;
    chosen = s ? String(s.methods).replace(/[{}]/g, '').split(',').filter(Boolean) : [];
    limits = {
      min_order: s?.min_order ?? '',
      max_cash: s?.max_cash ?? '',
      max_card_machine: s?.max_card_machine ?? '',
      max_change: s?.max_change ?? '',
    };
  }

  async function load() {
    try {
      fill(await api.get('/restaurants/payment_settings.php', { token: staffToken() }));
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar as formas de pagamento.');
    }
  }

  $effect(() => {
    load();
  });

  function toggle(code) {
    chosen = chosen.includes(code) ? chosen.filter((c) => c !== code) : [...chosen, code];
  }

  async function save() {
    if (saving) return;
    saving = true;
    try {
      const body = { methods: chosen };
      for (const [k, v] of Object.entries(limits)) {
        if (v !== '' && v !== null) body[k] = Number(v);
      }
      fill(await api.post('/restaurants/payment_settings.php', { token: staffToken(), body }));
      toastr.success('Formas de pagamento salvas.');
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar.');
    } finally {
      saving = false;
    }
  }

  async function device(body, okMessage) {
    busyDevice = body.custody_id ?? body.device_id ?? 'new';
    try {
      await api.post('/restaurants/pos_devices.php', { token: staffToken(), body });
      toastr.success(okMessage);
      if (body.action === 'register') newDevice = { label: '', acquirer: '' };
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra atualizar a maquininha.');
    } finally {
      busyDevice = null;
    }
  }

  let selectable = $derived(data?.selectable ?? []);
</script>

<div class="payset">
  <section class="main">
    <PixKeyPanel />

    <p class="k">FORMAS DE PAGAMENTO ACEITAS</p>

    {#if data === null}
      <p class="empty">Carregando…</p>
    {:else}
      {#if data.online_only}
        <div class="lock">
          <i class="bi bi-lock-fill"></i>
          Sua loja está limitada a pagamento online até regularizar o débito semanal. Dinheiro e
          maquininha voltam sozinhos quando o acerto for baixado.
        </div>
      {/if}

      <div class="methods">
        {#each Object.entries(data.methods) as [code, meta] (code)}
          {@const allowed = selectable.includes(code)}
          <button
            type="button"
            class="method"
            class:on={chosen.includes(code)}
            class:off={!allowed}
            disabled={!allowed}
            onclick={() => toggle(code)}
          >
            <span class="switch" aria-hidden="true"><span></span></span>
            <span class="body">
              <span class="label">{meta.label}</span>
              <span class="note">{meta.note}</span>
            </span>
            {#if meta.recommended}<span class="tag">RECOMENDADO</span>{/if}
          </button>
        {/each}
      </div>

      <div class="rules">
        <p class="title">Regras da maquininha (a máquina é do estabelecimento)</p>
        <p>· O entregador retira a máquina registrando a posse no app dele e devolve depois da venda — no retorno ou até o fim do turno.</p>
        <p>· Enquanto a máquina não voltar, ele fica marcado como "com equipamento" e você vê isso no painel.</p>
        <p>· O dinheiro cai direto na sua adquirente; ele informa NSU e valor, e a conciliação bate com o extrato.</p>
      </div>
    {/if}
  </section>

  <aside class="side">
    <p class="k">LIMITES DESTA LOJA</p>
    <div class="limits">
      <label><span>Pedido mínimo</span><input type="number" min="0" step="0.01" bind:value={limits.min_order} /></label>
      <label><span>Máx. em dinheiro</span><input type="number" min="0" step="0.01" bind:value={limits.max_cash} /></label>
      <label><span>Máx. na maquininha</span><input type="number" min="0" step="0.01" bind:value={limits.max_card_machine} /></label>
      <label><span>Troco máximo</span><input type="number" min="0" step="0.01" bind:value={limits.max_change} /></label>
    </div>
    {#if data?.cash_ceiling}
      <p class="hint">O teto de dinheiro da plataforma é {money(data.cash_ceiling)}.</p>
    {/if}
    <button type="button" class="btn-fuu-primary w-100" disabled={saving || chosen.length === 0} onclick={save}>
      {saving ? 'Salvando…' : 'Salvar formas e limites'}
    </button>

    <p class="k gap">MAQUININHAS CADASTRADAS</p>
    {#each data?.devices ?? [] as d (d.id)}
      <div class="device" class:late={d.overdue} class:inactive={!d.active}>
        <p class="name"><strong>{d.label}</strong> · {d.acquirer}</p>
        {#if d.out_with_courier}
          <p class="where">
            Com {d.holder_name ?? 'entregador'} desde {hm(d.taken_at)}
            {#if d.returned_at}· marcou devolvida{:else if d.overdue}· <b>devolução atrasada</b>{:else}· devolução pendente{/if}
          </p>
          <button
            type="button"
            class="ghost"
            disabled={busyDevice === d.custody_id}
            onclick={() => device({ action: 'confirm_return', custody_id: d.custody_id }, 'Devolução confirmada.')}
          >
            Confirmar devolução no balcão
          </button>
        {:else}
          <p class="where">{d.active ? 'No balcão · disponível' : 'Desativada'}</p>
          {#if d.active}
            <button
              type="button"
              class="link"
              disabled={busyDevice === d.id}
              onclick={() => device({ action: 'deactivate', device_id: d.id }, 'Maquininha desativada.')}
            >
              Desativar
            </button>
          {/if}
        {/if}
      </div>
    {/each}

    <div class="add">
      <input placeholder="Apelido (POS-03)" bind:value={newDevice.label} />
      <input placeholder="Adquirente (stone, cielo…)" bind:value={newDevice.acquirer} />
      <button
        type="button"
        class="ghost"
        disabled={busyDevice === 'new' || !newDevice.label.trim() || !newDevice.acquirer.trim()}
        onclick={() => device({ action: 'register', ...newDevice }, 'Maquininha cadastrada.')}
      >
        Cadastrar maquininha
      </button>
    </div>

    <p class="chips fuu-mono">restaurant_payment_settings<br />pos_devices + custódia<br />validação server-side</p>
  </aside>
</div>

<style>
  .payset {
    display: flex;
    gap: 0;
    min-height: 70vh;
  }
  .main {
    flex: 1;
    padding: 20px;
    min-width: 0;
  }
  .side {
    width: 320px;
    flex: 0 0 320px;
    background: var(--fuu-white);
    border-left: 1px solid var(--fuu-line-3);
    padding: 20px;
  }
  .k {
    font-size: 11px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.1em;
    margin: 0 0 10px;
  }
  .k.gap {
    margin-top: 22px;
  }
  .empty {
    color: var(--fuu-ink-3);
    font-size: 13px;
  }
  .lock {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
    border-radius: 11px;
    padding: 12px 14px;
    font-size: 13px;
    margin-bottom: 12px;
    display: flex;
    gap: 8px;
  }
  .methods {
    display: flex;
    flex-direction: column;
    gap: 9px;
  }
  .method {
    display: flex;
    align-items: center;
    gap: 13px;
    text-align: left;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 14px 16px;
    font-family: inherit;
    color: var(--fuu-ink-1);
  }
  .method.off {
    opacity: 0.5;
  }
  .switch {
    width: 38px;
    height: 22px;
    border-radius: 11px;
    background: var(--fuu-line-2);
    position: relative;
    flex: 0 0 38px;
  }
  .switch span {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--fuu-white);
    transition: left 0.15s;
  }
  .method.on .switch {
    background: var(--fuu-leaf);
  }
  .method.on .switch span {
    left: 19px;
  }
  .body {
    flex: 1;
  }
  .label {
    display: block;
    font-weight: 800;
    font-size: 14px;
  }
  .note {
    display: block;
    font-size: 12px;
    color: var(--fuu-ink-3);
    margin-top: 2px;
  }
  .tag {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
    font-size: 10px;
    font-weight: 800;
    padding: 4px 8px;
    border-radius: var(--fuu-radius-pill);
  }
  .rules {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 16px;
    margin-top: 14px;
  }
  .rules .title {
    font-size: 14px;
    font-weight: 800;
    margin: 0 0 8px;
  }
  .rules p {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    line-height: 1.7;
    margin: 0;
  }
  .limits {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px;
  }
  .limits span,
  .add input::placeholder {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
  }
  .limits span {
    display: block;
    margin-bottom: 4px;
  }
  .limits input,
  .add input {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 9px;
    font-family: inherit;
    font-size: 13px;
  }
  .hint {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin: 8px 0 12px;
  }
  .device {
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 11px 12px;
    margin-bottom: 8px;
    font-size: 12.5px;
  }
  .device.late {
    border-color: var(--fuu-alert);
    background: var(--fuu-red-tint);
  }
  .device.inactive {
    opacity: 0.6;
  }
  .device .name,
  .device .where {
    margin: 0;
  }
  .device .where {
    color: var(--fuu-ink-3);
    margin-top: 2px;
  }
  .ghost {
    width: 100%;
    margin-top: 8px;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 9px;
    padding: 9px;
    font-family: inherit;
    font-weight: 700;
    font-size: 12.5px;
    color: var(--fuu-ink-1);
  }
  .link {
    border: 0;
    background: transparent;
    padding: 0;
    margin-top: 6px;
    font-size: 12px;
    color: var(--fuu-ink-3);
    text-decoration: underline;
    font-family: inherit;
  }
  .add {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-top: 10px;
  }
  .chips {
    font-size: 10px;
    color: var(--fuu-ink-3);
    margin-top: 16px;
    line-height: 1.6;
  }
  @media (max-width: 900px) {
    .payset {
      flex-direction: column;
    }
    .side {
      width: auto;
      flex: 1;
      border-left: 0;
      border-top: 1px solid var(--fuu-line-3);
    }
  }
</style>
