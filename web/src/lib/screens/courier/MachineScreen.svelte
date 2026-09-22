<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { courierToken } from '../../state/courierSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 10.6 — "Entregador: devolução da maquininha".
  //
  // "Custódia com dois lados: ele marca 'devolvi', a loja confirma no
  // painel. Atraso alerta em 30 min e bloqueia nova retirada — e os NSUs
  // pendentes travam o fechamento do dia."
  //
  // A mesma tela serve pra RETIRAR (quando há corrida de maquininha e a mão
  // está vazia) e pra DEVOLVER (quando há máquina em mãos). Os NSUs que
  // faltam aparecem aqui porque são a única dívida que existe: "você não
  // deve nada por essas vendas. Só o equipamento e os NSUs."
  let { onBack } = $props();

  let data = $state(null);
  let busy = $state(false);
  let nsuDraft = $state({});
  // Máquina própria (tela 9.6): só aparece quando a política libera.
  let ownForm = $state({ label: '', acquirer: '' });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function hm(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : '';
  }

  async function load() {
    try {
      data = await api.get('/couriers/pos.php', { token: courierToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar a maquininha.');
    }
  }

  $effect(() => {
    load();
    // O prazo corre sozinho na tela: "Prazo de devolução em 1:02" precisa
    // virar "atrasada" sem ninguém recarregar.
    const t = setInterval(load, 60000);
    return () => clearInterval(t);
  });

  async function act(body, ok) {
    if (busy) return;
    busy = true;
    try {
      const res = await api.post('/couriers/pos.php', { token: courierToken(), body });
      toastr.success(res.notice ?? ok);
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra registrar.');
    } finally {
      busy = false;
    }
  }

  function sendNsu(sale) {
    const nsu = String(nsuDraft[sale.id] ?? '').replace(/\D/g, '');
    if (nsu === '') return;
    act({ action: 'sale', order_id: sale.order_id, nsu, amount: Number(sale.amount_app) }, 'NSU informado.');
  }

  let custody = $derived(data?.custody ?? null);
  let overdue = $derived(custody?.overdue === true);
  let missing = $derived((data?.sales ?? []).filter((s) => !s.nsu).length);
</script>

<div class="machine">
  <header>
    <button type="button" class="back" onclick={onBack} aria-label="Voltar"><i class="bi bi-arrow-left"></i></button>
    <h1>Equipamento da loja</h1>
  </header>

  {#if data === null}
    <p class="empty">Carregando…</p>
  {:else if custody === null}
    {#if data.allow_own_pos}
      <!-- 9.6: "Maquininha do próprio entregador: o valor cai na conta dele,
           então vira dívida com a loja e segue o mesmo fluxo da espécie." -->
      <p class="k">SUA MAQUININHA</p>
      {#each data.own_devices as d (d.id)}
        <div class="own"><i class="bi bi-credit-card"></i> <strong>{d.label}</strong> · {d.acquirer}</div>
      {:else}
        <div class="own-form">
          <input placeholder="Apelido (minha-stone)" bind:value={ownForm.label} />
          <input placeholder="Adquirente (sumup, stone…)" bind:value={ownForm.acquirer} />
          <button
            type="button"
            disabled={busy || !ownForm.label.trim() || !ownForm.acquirer.trim()}
            onclick={() => act({ action: 'register_own', ...ownForm }, 'Cadastrada.')}
          >
            Cadastrar minha máquina
          </button>
        </div>
      {/each}
      <p class="hint">
        Venda na sua máquina cai na sua conta: o valor vira dívida com a loja e segue a mesma baixa do
        dinheiro, com prazo de D+1.
      </p>
    {/if}
    {#if data.available_devices.length === 0}
      <p class="empty">
        Você não está com nenhuma maquininha. Ela só aparece aqui pra retirar quando você tem uma
        corrida paga na maquininha da loja.
      </p>
    {:else}
      <p class="k">RETIRAR PRA ESTA CORRIDA</p>
      {#each data.available_devices as d (d.id)}
        <button type="button" class="device" disabled={busy} onclick={() => act({ action: 'take', device_id: d.id }, 'Retirada registrada.')}>
          <i class="bi bi-credit-card-2-front"></i>
          <span><strong>{d.label} · {d.acquirer}</strong><small>{d.restaurant_name} · no balcão</small></span>
        </button>
      {/each}
      <p class="hint">A posse fica registrada no seu nome até a loja confirmar a devolução.</p>
    {/if}
  {:else}
    <div class="card" class:late={overdue}>
      <p class="title"><i class="bi bi-credit-card-2-front"></i> {custody.label} · {custody.acquirer}</p>
      <p class="sub">{custody.restaurant_name} · retirada {hm(custody.taken_at)}</p>
      <p class="sub">A maquininha é do restaurante. Devolva no balcão ao voltar — o prazo é o fim do turno.</p>
      <p class="deadline">
        {overdue ? 'Devolução ' : 'Prazo de devolução em '}<strong>{data.deadline_label}</strong>
      </p>
      {#if overdue}
        <p class="alert">Enquanto ela não voltar, você não retira outra máquina.</p>
      {/if}
    </div>

    <p class="k">VENDAS FEITAS NELA HOJE</p>
    {#each data.sales as sale (sale.id)}
      <div class="sale" class:missing={!sale.nsu}>
        <div class="line">
          <span>
            <strong>#{sale.public_code} · {sale.kind === 'debit' ? 'débito' : 'crédito'}</strong>
            <small>{sale.nsu ? `NSU ${sale.nsu} · ${hm(sale.created_at)}` : 'NSU faltando · informe para fechar o dia'}</small>
          </span>
          <b>{money(sale.amount_app)}</b>
        </div>
        {#if !sale.nsu}
          <div class="nsu">
            <input inputmode="numeric" placeholder="NSU do comprovante" bind:value={nsuDraft[sale.id]} />
            <button type="button" disabled={busy} onclick={() => sendNsu(sale)}>Informar</button>
          </div>
        {/if}
      </div>
    {:else}
      <p class="hint">Nenhuma venda nesta máquina ainda.</p>
    {/each}

    <p class="notice">{data.notice}</p>
    {#if missing > 0}
      <p class="alert">Faltam {missing} NSU(s) — sem eles o dia da loja não fecha.</p>
    {/if}

    {#if custody.returned_at}
      <p class="waiting">Devolução marcada. Falta a loja confirmar no painel dela.</p>
    {:else}
      <button type="button" class="btn-fuu-primary w-100 big" disabled={busy} onclick={() => act({ action: 'return' }, 'Devolução registrada.')}>
        Devolvi a maquininha
      </button>
    {/if}
  {/if}
</div>

<style>
  .machine {
    padding: 18px;
  }
  header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
  }
  .back {
    border: 0;
    background: transparent;
    font-size: 18px;
    padding: 0;
    color: var(--fuu-ink-1);
  }
  h1 {
    font-size: 17px;
    font-weight: 800;
    margin: 0;
  }
  .empty,
  .hint {
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    line-height: 1.55;
  }
  .k {
    font-size: 11px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.1em;
    margin: 16px 0 9px;
  }
  .device {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    text-align: left;
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 11px;
    padding: 14px;
    margin-bottom: 8px;
    font-family: inherit;
    color: var(--fuu-ink-1);
  }
  .device i {
    font-size: 20px;
  }
  .device small,
  .sale small {
    display: block;
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin-top: 2px;
  }
  .own {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 11px;
    padding: 12px 14px;
    font-size: 13.5px;
  }
  .own-form {
    display: flex;
    flex-direction: column;
    gap: 7px;
  }
  .own-form input {
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 10px;
    font-family: inherit;
  }
  .own-form button {
    border: 0;
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
    border-radius: 9px;
    padding: 11px;
    font-family: inherit;
    font-weight: 700;
  }
  .card {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 12px;
    padding: 15px;
  }
  .card.late {
    border-color: var(--fuu-alert);
    background: var(--fuu-red-tint);
  }
  .card .title {
    font-weight: 800;
    font-size: 15px;
    margin: 0;
  }
  .card .sub {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    margin: 5px 0 0;
    line-height: 1.5;
  }
  .deadline {
    font-size: 14px;
    margin: 10px 0 0;
  }
  .sale {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 11px;
    padding: 12px 14px;
    margin-bottom: 8px;
    font-size: 13px;
  }
  .sale.missing {
    border-color: var(--fuu-wait-tint);
    background: var(--fuu-wait-bg);
  }
  .line {
    display: flex;
    justify-content: space-between;
    gap: 10px;
  }
  .nsu {
    display: flex;
    gap: 8px;
    margin-top: 9px;
  }
  .nsu input {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 9px;
    font-family: inherit;
  }
  .nsu button {
    border: 0;
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
    border-radius: 9px;
    padding: 0 14px;
    font-family: inherit;
    font-weight: 700;
  }
  .notice {
    font-size: 12.5px;
    color: var(--fuu-leaf-dark);
    background: var(--fuu-leaf-tint);
    border-radius: 10px;
    padding: 11px 12px;
    line-height: 1.5;
    margin: 12px 0 0;
  }
  .alert {
    font-size: 12.5px;
    color: var(--fuu-alert);
    font-weight: 700;
    margin: 9px 0 0;
  }
  .waiting {
    font-size: 13px;
    color: var(--fuu-wait-text);
    margin-top: 14px;
  }
  .big {
    margin-top: 16px;
    padding: 15px;
    font-weight: 800;
  }
</style>
