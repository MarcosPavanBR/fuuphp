<script>
  import { api, ApiError } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { courierToken } from '../../courierSession.svelte.js';

  // Telas 8.3 a 8.6 numa só, mudando conforme o estado da corrida -- pelo
  // mesmo motivo do OrderTracking do cliente: são etapas do mesmo pedido, não
  // quatro telas diferentes.
  //   ready      -> rota até a coleta (8.3) e coleta com troco (8.4)
  //   delivering -> entrega, cobrança (8.5) e prova (8.6)
  let { order, onDone, onProblem } = $props();

  let detail = $state(null);
  let code = $state('');
  let busy = $state(false);
  let arrived = $state(false);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  // Posição real do aparelho quando houver: o evento de chegada e a prova de
  // entrega valem mais com carimbo. Sem permissão, segue sem -- a corrida não
  // pode travar porque o GPS negou.
  //
  // O prazo é nosso, não do navegador: existe aparelho que não chama nenhum
  // dos dois callbacks mesmo com `timeout` (permissão negada sem diálogo,
  // GPS sem provedor), e aí a corrida trava num botão desabilitado pra
  // sempre. Achado testando a tela 13.3 no navegador; vale igual aqui.
  function position() {
    return Promise.race([
      new Promise((resolve) => {
        if (!navigator.geolocation) return resolve({});
        navigator.geolocation.getCurrentPosition(
          (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
          () => resolve({}),
          { timeout: 4000 }
        );
      }),
      new Promise((resolve) => setTimeout(() => resolve({}), 4500)),
    ]);
  }

  async function mark(event) {
    busy = true;
    try {
      const where = await position();
      const data = await api.post('/couriers/pickup.php', {
        token: courierToken(),
        body: { order_id: order.id, event, ...where },
      });
      detail = data;
      arrived = true;
      if (event === 'picked_up') toastr.success('Sacola com você. Boa viagem.');
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra registrar.');
    } finally {
      busy = false;
    }
  }

  async function deliver() {
    if (code.replace(/\D/g, '').length !== 4 || busy) return;
    busy = true;
    try {
      const where = await position();
      const data = await api.post('/couriers/deliver.php', {
        token: courierToken(),
        headers: { 'X-Idempotency-Key': crypto.randomUUID() },
        body: { order_id: order.id, delivery_code: code.replace(/\D/g, ''), ...where },
      });
      toastr.success(
        order.payment_method === 'cash'
          ? `Entrega confirmada. ${money(order.total)} somados ao seu saldo em espécie.`
          : 'Entrega confirmada.'
      );
      onDone(data);
    } catch (e) {
      const known = e instanceof ApiError ? e.code : null;
      toastr.error(
        known === 'wrong_delivery_code'
          ? 'Código não confere. Confira com o cliente.'
          : (e.message ?? 'Não deu pra confirmar a entrega.')
      );
    } finally {
      busy = false;
    }
  }

  let collecting = $derived(order.status === 'ready');
  let changeDue = $derived(detail?.change_due ?? null);
</script>

<div class="ride">
  <header>
    <p class="step">{collecting ? 'ROTA ATÉ A COLETA' : 'ENTREGA'}</p>
    <h1 class="fuu-display">Pedido #{order.public_code}</h1>
    <p class="where">{order.restaurant_name ?? 'Loja'}</p>
  </header>

  {#if collecting}
    <!-- 8.3/8.4: chegar, conferir a comanda e sair com o troco certo. -->
    {#if !arrived}
      <div class="map-placeholder">
        <i class="bi bi-geo-alt"></i>
        <p>Navegação com áudio é integração de mapa — não existe neste projeto (ver README).</p>
      </div>
      <button type="button" class="btn-fuu-primary w-100 big" disabled={busy} onclick={() => mark('arrived_at_store')}>
        {busy ? 'Registrando…' : 'Cheguei na loja'}
      </button>
    {:else}
      <div class="card">
        <p class="k">COMANDA</p>
        <ul>
          {#each detail?.items ?? [] as item}
            <li>{item.quantity}× {item.name_snapshot}</li>
          {/each}
        </ul>
      </div>

      {#if order.payment_method === 'cash'}
        <!-- "O troco é calculado pelo servidor e mostrado em corpo grande —
             é o erro mais comum do delivery em dinheiro." -->
        <div class="change">
          <p class="k">LEVE DE TROCO</p>
          <p class="huge fuu-display">{changeDue === null ? '—' : money(changeDue)}</p>
          <p class="sub">Cliente paga {money(order.total)} e tem {money(order.change_for ?? 0)} na mão.</p>
        </div>
      {/if}

      <button type="button" class="btn-fuu-primary w-100 big" disabled={busy} onclick={() => mark('picked_up')}>
        Peguei o pedido
      </button>
      <p class="waiting-note">
        A loja confirma a saída no painel dela — quando confirmar, esta tela vira a de entrega.
      </p>
    {/if}
  {:else}
    <!-- 8.5/8.6: cobrar (quando for o caso) e comprovar. -->
    {#if order.payment_method === 'cash'}
      <div class="collect">
        <p class="k">RECEBER DO CLIENTE</p>
        <p class="huge fuu-display">{money(order.total)}</p>
        {#if order.change_for}
          <p class="sub">Troco para {money(order.change_for)}</p>
        {/if}
      </div>
    {:else if order.payment_method === 'pos_machine'}
      <div class="collect">
        <p class="k">PASSAR NA MAQUININHA</p>
        <p class="huge fuu-display">{money(order.total)}</p>
        <p class="sub">{order.machine_kind === 'debit' ? 'Débito' : 'Crédito'}</p>
      </div>
    {:else}
      <div class="paid">
        <i class="bi bi-check-circle-fill"></i> Já pago no app — nada a receber.
      </div>
    {/if}

    <div class="card">
      <p class="k">CÓDIGO DO CLIENTE</p>
      <input
        type="text"
        inputmode="numeric"
        maxlength="4"
        class="code-input fuu-mono"
        placeholder="0000"
        bind:value={code}
      />
      <p class="hint">O cliente vê o código no app dele quando o pedido sai pra entrega.</p>
    </div>

    <button
      type="button"
      class="btn-fuu-primary w-100 big confirm"
      disabled={code.replace(/\D/g, '').length !== 4 || busy}
      onclick={deliver}
    >
      {busy ? 'Confirmando…' : 'Confirmar entrega'}
    </button>
    <!-- 13.3: o "Problema" desta tela. Fica ao lado do botão de confirmar, e
         não escondido num menu: quem está na porta com a comida na mão e
         ninguém atendendo precisa achar isso em um toque. -->
    <button type="button" class="problem" onclick={onProblem}>
      <i class="bi bi-exclamation-triangle"></i> Problema na entrega
    </button>
    <p class="waiting-note">
      Sem o código, a saída é a foto na porta — envie a foto pela tela de ocorrência.
    </p>
  {/if}
</div>

<style>
  .ride {
    padding: 18px;
  }
  .step {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  h1 {
    font-size: 23px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 5px 0 0;
    color: var(--fuu-ink-1);
  }
  .where {
    font-size: 14px;
    color: var(--fuu-ink-3);
    margin: 3px 0 16px;
  }
  .map-placeholder {
    border: 1px dashed var(--fuu-line-3);
    border-radius: 12px;
    padding: 28px 18px;
    text-align: center;
    color: var(--fuu-ink-4);
    background: var(--fuu-line-6);
  }
  .map-placeholder i {
    font-size: 30px;
    color: var(--fuu-line-2);
  }
  .map-placeholder p {
    font-size: 12.5px;
    margin: 10px 0 0;
    line-height: 1.5;
  }
  .card {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 11px;
    padding: 16px;
    margin-bottom: 12px;
  }
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .card ul {
    list-style: none;
    padding: 0;
    margin: 10px 0 0;
    font-size: 16px;
    font-weight: 600;
    line-height: 1.7;
    color: var(--fuu-ink-1);
  }
  .change,
  .collect {
    border: 1px solid var(--fuu-line-3);
    border-radius: 11px;
    padding: 18px;
    margin-bottom: 12px;
    text-align: center;
    background: var(--fuu-line-6);
  }
  .huge {
    font-size: 46px;
    font-weight: 800;
    letter-spacing: -0.03em;
    margin: 4px 0 0;
    color: var(--fuu-ink-1);
  }
  .sub {
    font-size: 13px;
    color: var(--fuu-ink-3);
    margin: 6px 0 0;
  }
  .paid {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
    border-radius: 11px;
    padding: 14px;
    margin-bottom: 12px;
    font-size: 14px;
    font-weight: 700;
  }
  .code-input {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 15px;
    margin-top: 10px;
    text-align: center;
    font-size: 30px;
    font-weight: 800;
    letter-spacing: 0.3em;
    color: var(--fuu-ink-1);
  }
  .hint {
    font-size: 11px;
    color: var(--fuu-ink-4);
    margin: 10px 0 0;
    line-height: 1.5;
  }
  .big {
    min-height: var(--fuu-tap-operator);
    font-size: 16px;
  }
  .confirm {
    background: var(--fuu-leaf-dark);
  }
  .waiting-note {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 12px 0 0;
    line-height: 1.5;
    text-align: center;
  }
  .problem {
    width: 100%;
    margin-top: 10px;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-red-tint-2);
    color: var(--fuu-alert);
    border-radius: 11px;
    padding: 14px;
    font-family: inherit;
    font-weight: 800;
    font-size: 14px;
  }
</style>
