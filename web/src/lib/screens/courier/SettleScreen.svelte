<script>
  import { api, ApiError, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { courierToken } from '../../state/courierSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Telas 9.1, 9.2, 9.4 e 9.5 — escolher a baixa, gerar o código (balcão)
  // ou pagar por Pix e mandar o comprovante (loja fechada), e o recibo.
  //
  // 9.4: enquanto espera (código na tela, ou comprovante na fila da loja), a
  // tela consulta a baixa (couriers/settlements.php) e vira o recibo sozinha
  // quando a loja confirma -- com a mesma assinatura que saiu no papel da
  // loja ("Recibo com hash dos dois lados").
  //
  // Pix (9.5): "O saldo só zera quando a loja validar o comprovante." A tela
  // retoma sozinha uma baixa por Pix aberta (couriers/settle_proof.php GET):
  // o entregador pode transferir agora e mandar o comprovante depois.
  //
  // "Guardamos só o hash do código; o valor é imutável depois de gerado."
  // Por isso o código aparece UMA vez, aqui, e a tela avisa: recarregar não
  // traz ele de volta, porque o servidor não tem como mostrar de novo.
  let { me, onDone } = $props();

  let step = $state('choose');
  let intent = $state(null);
  let code = $state(null);
  let restaurantId = $state('');
  let restaurants = $state([]);
  let busy = $state(false);
  let now = $state(Date.now());
  let method = $state('in_person');
  let pix = $state(null); // { id, amount, expires_at, restaurant_name, restaurant_cnpj, pix_copy_paste, proof_state, reject_reason }
  let proofFile = $state(null);
  let receipt = $state(null);
  let balances = $state(null);
  let showFull = $state(false);

  // Espera a confirmação da loja: no balcão (código) e no Pix (comprovante na fila).
  $effect(() => {
    const waitingId = step === 'code' ? intent?.id : step === 'pix' && pix?.proof_state === 'pending' ? pix.id : null;
    if (!waitingId) return;
    const t = setInterval(async () => {
      try {
        const data = await api.get('/couriers/settlements.php', {
          token: courierToken(),
          query: { intent_id: waitingId },
        });
        if (data.receipt) {
          receipt = data.receipt;
          balances = data.balances;
          step = 'receipt';
        } else if (data.intent.state !== 'open') {
          toastr.warning(
            data.intent.state === 'disputed'
              ? 'A loja contou um valor diferente: abriu uma ocorrência e nada foi baixado.'
              : 'Essa baixa expirou. Gere outra.'
          );
          onDone();
        }
      } catch {
        // rede oscilando: tenta de novo na próxima volta
      }
    }, 4000);
    return () => clearInterval(t);
  });

  function weekday(isoDate) {
    const d = new Date(`${isoDate}T12:00:00`);
    return d.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: '2-digit' });
  }

  async function loadPix() {
    try {
      const data = await api.get('/couriers/settle_proof.php', { token: courierToken() });
      pix = data.intent;
      if (pix) step = 'pix';
    } catch {
      // sem rede: fica na escolha
    }
  }
  loadPix();

  function cnpj(c) {
    const d = String(c ?? '').replace(/\D/g, '');
    return d.length === 14 ? `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}` : c;
  }

  async function copyPix() {
    try {
      await navigator.clipboard.writeText(pix.pix_copy_paste);
      toastr.success('Código Pix copiado ✓');
    } catch {
      toastr.warning('Não deu pra copiar. Selecione o código manualmente.');
    }
  }

  async function sendProof() {
    if (!proofFile || busy) return;
    busy = true;
    try {
      const form = new FormData();
      form.append('intent_id', String(pix.id));
      form.append('proof', proofFile);
      // fetch direto: é multipart, e o UUID torna o reenvio seguro.
      const res = await fetch(`${BASE}/couriers/settle_proof.php`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${courierToken()}`, 'X-Idempotency-Key': crypto.randomUUID() },
        body: form,
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message ?? 'Não deu pra enviar o comprovante.');
      toastr.success('Comprovante enviado. A loja confere e seu saldo zera.');
      proofFile = null;
      await loadPix();
    } catch (e) {
      toastr.error(e.message);
    } finally {
      busy = false;
    }
  }

  $effect(() => {
    const t = setInterval(() => (now = Date.now()), 1000);
    return () => clearInterval(t);
  });

  // As lojas onde ele pode baixar são as das corridas dele -- não a lista
  // inteira da praça. O nome vem junto do próprio livro de lançamentos, numa
  // consulta só.
  $effect(() => {
    let alive = true;
    api
      .get('/couriers/earnings.php', { token: courierToken() })
      .then((data) => {
        if (!alive) return;
        const seen = new Map();
        for (const entry of data.entries) {
          if (entry.restaurant_id && !seen.has(entry.restaurant_id)) {
            seen.set(entry.restaurant_id, entry.restaurant_name ?? 'Loja');
          }
        }
        restaurants = [...seen].map(([id, name]) => ({ id, name }));
        if (restaurants.length > 0) restaurantId = restaurants[0].id;
      })
      .catch(() => {});
    return () => (alive = false);
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  let secondsLeft = $derived.by(() => {
    if (!intent?.expires_at) return null;
    const end = parsePgTimestamp(intent.expires_at);
    return end ? Math.max(0, Math.floor((end.getTime() - now) / 1000)) : null;
  });
  let countdown = $derived(
    secondsLeft === null
      ? null
      : `${Math.floor(secondsLeft / 60)}:${String(secondsLeft % 60).padStart(2, '0')}`
  );

  async function generate(method) {
    if (restaurantId === '' || busy) return;
    busy = true;
    try {
      const data = await api.post('/couriers/settle_intent.php', {
        token: courierToken(),
        body: { restaurant_id: restaurantId, method },
      });
      intent = data.intent;
      code = data.code;
      if (method === 'pix') {
        await loadPix();
      } else {
        step = 'code';
      }
    } catch (e) {
      const known = e instanceof ApiError ? e.code : null;
      toastr.error(
        known === 'intent_already_open'
          ? 'Você já tem uma baixa em andamento. Termine ou espere expirar.'
          : known === 'store_has_no_pix_key'
            ? 'Essa loja não tem chave Pix cadastrada — dê baixa no balcão.'
            : (e.message ?? 'Não deu pra gerar a baixa.')
      );
    } finally {
      busy = false;
    }
  }
</script>

<div class="settle">
  <button type="button" class="back" onclick={onDone} aria-label="Voltar">
    <i class="bi bi-arrow-left"></i>
  </button>

  {#if step === 'choose'}
    <h1 class="fuu-display">Baixar a espécie</h1>

    <div class="amount-card">
      <p class="k">VOCÊ TEM EM MÃOS</p>
      <p class="huge fuu-display">{money(me?.balances?.cash ?? 0)}</p>
      <p class="sub">
        Teto de {money(me?.balances?.cash_ceiling ?? 0)} · prazo {me?.balances?.settle_deadline ?? '—'}
      </p>
    </div>

    <p class="k label">ENTREGAR NA LOJA</p>
    {#if restaurants.length === 0}
      <p class="empty">Nenhuma loja das suas corridas ainda.</p>
    {:else}
      <div class="stores">
        {#each restaurants as store (store.id)}
          <button
            type="button"
            class="store"
            class:on={restaurantId === store.id}
            onclick={() => (restaurantId = store.id)}
          >
            <i class={`bi ${restaurantId === store.id ? 'bi-record-circle' : 'bi-circle'}`}></i>
            {store.name}
          </button>
        {/each}
      </div>
    {/if}

    <p class="k label">COMO QUER DAR BAIXA?</p>
    <div class="methods">
      <button type="button" class="method" class:on={method === 'in_person'} onclick={() => (method = 'in_person')}>
        <strong>Entregar na loja</strong>
        <span>Gera um código de 6 dígitos. O atendente conta o dinheiro e digita o código.</span>
        <em>Baixa na hora</em>
      </button>
      <button type="button" class="method" class:on={method === 'pix'} onclick={() => (method = 'pix')}>
        <strong>Pix para a loja</strong>
        <span>Você transfere e envia o comprovante. Entra na fila de validação da loja.</span>
        <em>Até 1 dia útil</em>
      </button>
    </div>

    <button
      type="button"
      class="btn-fuu-primary w-100 big"
      disabled={busy || restaurantId === ''}
      onclick={() => generate(method)}
    >
      {method === 'pix' ? 'Pagar por Pix' : 'Gerar código de baixa'}
    </button>
    <p class="note">
      {method === 'pix'
        ? 'Prazo de baixa: até amanhã, 23:59. Depois disso as corridas em dinheiro ficam bloqueadas.'
        : 'O valor fica travado no código. A loja conta o dinheiro e digita o código no painel dela — os dois lançamentos nascem juntos.'}
    </p>
  {:else if step === 'receipt' && receipt}
    <div class="done-mark"><i class="bi bi-check-circle-fill"></i></div>
    <h1 class="fuu-display center">Baixa confirmada</h1>
    <p class="sub center">
      {receipt.confirmer_first_name ?? 'A loja'} recebeu {money(receipt.amount)} às
      {receipt.confirmed_local?.slice(-5)}.
    </p>
    <div class="receipt-rows">
      <div><span>Saldo em espécie</span><strong class="fuu-mono">{money(balances.cash)}</strong></div>
      <div><span>Ganhos a receber (plataforma)</span><strong class="fuu-mono">{money(balances.payable)}</strong></div>
      <div><span>Próximo repasse</span><strong>{weekday(balances.next_payout)}</strong></div>
    </div>
    <p class="receipt-code fuu-mono">
      Recibo #{receipt.code} · assinatura {receipt.signature_short} · via impressa ficou na loja
    </p>
    {#if showFull}
      <div class="receipt-full">
        <p><strong>{receipt.store_name}</strong> · CNPJ {cnpj(receipt.store_cnpj)}</p>
        <p>{receipt.method === 'pix' ? 'Pix com comprovante' : 'Dinheiro no balcão'} · {receipt.confirmed_local}</p>
        <p class="fuu-mono sig">{receipt.signature}</p>
        <p class="note">Confira: a mesma assinatura está no papel da loja.</p>
      </div>
    {/if}
    <button type="button" class="btn-fuu-primary w-100 big" onclick={onDone}>Voltar a receber corridas</button>
    <button type="button" class="link" onclick={() => (showFull = !showFull)}>{showFull ? 'Fechar recibo' : 'Ver recibo'}</button>
    <p class="note center">Teto liberado · corridas em espécie voltam</p>
  {:else if step === 'pix' && pix}
    <h1 class="fuu-display">Baixa por Pix</h1>
    <p class="k">TRANSFERIR PARA</p>
    <p class="pix-store">{pix.restaurant_name}</p>
    {#if pix.restaurant_cnpj}<p class="sub fuu-mono">CNPJ {cnpj(pix.restaurant_cnpj)}</p>{/if}
    <p class="pix-code fuu-mono">{pix.pix_copy_paste}</p>
    <div class="pix-amount">
      <span>Valor exato</span>
      <strong class="fuu-mono">{money(pix.amount)}</strong>
    </div>
    <button type="button" class="copy" onclick={copyPix}><i class="bi bi-copy"></i> Copiar código Pix</button>

    {#if pix.proof_state === 'pending'}
      <div class="pending">
        <i class="bi bi-hourglass-split"></i>
        Comprovante enviado. O saldo zera quando a loja conferir.
      </div>
    {:else}
      {#if pix.proof_state === 'rejected'}
        <div class="warn">A loja recusou o comprovante: {pix.reject_reason}. Envie outro.</div>
      {/if}
      <p class="k label">COMPROVANTE</p>
      <div class="pick">
        <label class="pick-btn">
          <i class="bi bi-camera"></i> Câmera
          <input type="file" accept="image/*" capture="environment" hidden onchange={(e) => (proofFile = e.currentTarget.files?.[0] ?? null)} />
        </label>
        <label class="pick-btn">
          <i class="bi bi-images"></i> Galeria
          <input type="file" accept="image/jpeg,image/png,image/webp" hidden onchange={(e) => (proofFile = e.currentTarget.files?.[0] ?? null)} />
        </label>
      </div>
      {#if proofFile}<p class="sub">{proofFile.name}</p>{/if}
      <p class="note">
        O saldo só zera quando a loja validar o comprovante. Enviar comprovante falso bloqueia a conta e gera
        ocorrência.
      </p>
      <button type="button" class="btn-fuu-primary w-100 big" disabled={!proofFile || busy} onclick={sendProof}>
        {busy ? 'Enviando…' : 'Enviar comprovante'}
      </button>
    {/if}
    <button type="button" class="link" onclick={onDone}>Voltar ao início</button>
  {:else}
    <h1 class="fuu-display">Mostre este código</h1>
    <p class="sub center">Vale {money(intent.amount)}, por {countdown ?? '—'}.</p>

    <div class="code-box fuu-mono">{code}</div>

    <div class="steps">
      <p><strong>1.</strong> Entregue {money(intent.amount)} em dinheiro no caixa.</p>
      <p><strong>2.</strong> O atendente conta e digita este código no painel.</p>
      <p><strong>3.</strong> Seu saldo zera na hora, e o teto volta a ficar livre.</p>
    </div>

    <div class="warn">
      Guardamos só o hash deste código — ele não pode ser mostrado de novo. Se sumir, gere outro depois
      que este expirar.
    </div>

    <button type="button" class="btn-fuu-primary w-100 big" onclick={onDone}>Voltar ao início</button>
  {/if}
</div>

<style>
  .settle {
    padding: 18px;
  }
  .back {
    background: none;
    border: none;
    font-size: 20px;
    color: var(--fuu-ink-1);
    padding: 0;
  }
  h1 {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 14px 0 16px;
    color: var(--fuu-ink-1);
  }
  .amount-card {
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    padding: 18px;
    text-align: center;
    background: var(--fuu-line-6);
  }
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .label {
    margin: 20px 0 10px;
  }
  .huge {
    font-size: 42px;
    font-weight: 800;
    letter-spacing: -0.03em;
    margin: 4px 0 0;
    color: var(--fuu-red);
  }
  .sub {
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    margin: 8px 0 0;
  }
  .sub.center {
    text-align: center;
    margin-top: 0;
  }
  .stores {
    display: flex;
    flex-direction: column;
    gap: 9px;
  }
  .store {
    display: flex;
    align-items: center;
    gap: 11px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 15px;
    background: var(--fuu-white);
    font-family: var(--fuu-font-body);
    font-size: 15px;
    color: var(--fuu-ink-2);
    text-align: left;
    min-height: var(--fuu-tap-operator);
  }
  .store.on {
    border: 2px solid var(--fuu-red);
    background: var(--fuu-red-tint);
    color: var(--fuu-ink-1);
    font-weight: 600;
  }
  .store i {
    color: var(--fuu-ink-5);
  }
  .store.on i {
    color: var(--fuu-red);
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  .big {
    margin-top: 16px;
    min-height: var(--fuu-tap-operator);
    font-size: 16px;
  }
  .note {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    line-height: 1.55;
    margin-top: 12px;
  }
  .code-box {
    font-size: 46px;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-align: center;
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
    border-radius: 14px;
    padding: 24px 10px;
    margin: 18px 0;
  }
  .steps p {
    font-size: 13.5px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin: 0 0 8px;
  }
  .center {
    text-align: center;
  }
  .done-mark {
    text-align: center;
    font-size: 54px;
    color: var(--fuu-leaf);
    margin-top: 10px;
  }
  .receipt-rows {
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    margin: 18px 0 10px;
  }
  .receipt-rows div {
    display: flex;
    justify-content: space-between;
    padding: 12px 14px;
    font-size: 13.5px;
    border-top: 1px solid var(--fuu-line-4);
  }
  .receipt-rows div:first-child {
    border-top: none;
  }
  .receipt-code {
    font-size: 11px;
    color: var(--fuu-ink-4);
    text-align: center;
  }
  .receipt-full {
    border: 1px dashed var(--fuu-line-2);
    border-radius: 10px;
    padding: 12px;
    font-size: 12.5px;
  }
  .receipt-full p {
    margin: 0 0 6px;
  }
  .sig {
    word-break: break-all;
    font-size: 11px;
  }
  .methods {
    display: flex;
    flex-direction: column;
    gap: 9px;
  }
  .method {
    display: flex;
    flex-direction: column;
    gap: 4px;
    text-align: left;
    border: 1px solid var(--fuu-line-3);
    border-radius: 10px;
    padding: 14px;
    background: var(--fuu-white);
    font-family: var(--fuu-font-body);
    color: var(--fuu-ink-2);
  }
  .method.on {
    border: 2px solid var(--fuu-red);
    background: var(--fuu-red-tint);
  }
  .method strong {
    font-size: 15px;
    color: var(--fuu-ink-1);
  }
  .method span {
    font-size: 12.5px;
    line-height: 1.45;
  }
  .method em {
    font-style: normal;
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
  .pix-store {
    font-size: 18px;
    font-weight: 700;
    margin: 4px 0 0;
    color: var(--fuu-ink-1);
  }
  .pix-code {
    background: var(--fuu-line-6);
    border-radius: 10px;
    padding: 10px 12px;
    font-size: 11px;
    word-break: break-all;
    margin: 14px 0 10px;
    color: var(--fuu-ink-3);
  }
  .pix-amount {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    margin-bottom: 10px;
  }
  .copy,
  .pick-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    border: 1px solid var(--fuu-line-2);
    border-radius: 10px;
    background: var(--fuu-white);
    min-height: var(--fuu-tap-operator);
    font-weight: 600;
    font-size: 14px;
    color: var(--fuu-ink-1);
    cursor: pointer;
  }
  .pick {
    display: flex;
    gap: 9px;
  }
  .pending {
    display: flex;
    gap: 9px;
    align-items: center;
    border-radius: 10px;
    padding: 14px;
    margin-top: 16px;
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
    font-size: 13px;
    font-weight: 600;
  }
  .link {
    display: block;
    margin: 16px auto 0;
    background: none;
    border: none;
    color: var(--fuu-ink-4);
    font-weight: 600;
    font-size: 13px;
  }
  .warn {
    border: 1px solid var(--fuu-wait-text);
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
    border-radius: 10px;
    padding: 13px;
    font-size: 12px;
    line-height: 1.55;
    margin-top: 12px;
  }
</style>
