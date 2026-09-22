<script>
  import { api, ApiError } from '../api.js';
  import { toastr } from '../toastr.js';
  import { cartState, clearCartState } from '../cart.svelte.js';
  import QuickAddress from '../components/QuickAddress.svelte';
  import PaymentSelector from './PaymentSelector.svelte';
  import PixAutoPayment from './PixAutoPayment.svelte';
  import CardForm from './CardForm.svelte';
  import PixPayment from './PixPayment.svelte';
  import ProofUploader from './ProofUploader.svelte';
  import CashPayment from './CashPayment.svelte';
  import MachinePayment from './MachinePayment.svelte';
  import ScheduleScreen from './ScheduleScreen.svelte';

  // Fase 4 completa, orquestrada: endereço (mínimo, ver QuickAddress) ->
  // 4.1 seleção -> 4.2/4.3/4.5/4.6 conforme o método -> 4.4 (só Pix manual)
  // -> resultado. orders/checkout.php só é chamado quando TODOS os campos
  // que o método precisa já foram coletados (change_for, machine_kind),
  // porque o CHECK do banco (cash_change_valid / machine_needs_kind) exige
  // isso na mesma linha que grava payment_method -- checkout.php não pode
  // ser chamado "incompleto" e completado depois.
  //
  // Igual a RestaurantPage/CartDrawer: cada tela carrega o que precisa. O
  // carrinho vem do mesmo módulo reativo que CartDrawer já usa (não é
  // prop-drilling vindo do App.svelte); a loja é carregada aqui mesmo.
  let { restaurantId, location, couponCode = null, onBack, onOrderReady } = $props();

  let restaurant = $state(null);
  // O que a loja aceita agora (restaurants/show.php, já com a trava de
  // "somente online" de repasse atrasado). null = ainda não sabe: o seletor
  // mostra tudo e o checkout decide.
  let acceptedMethods = $state(null);
  // `restaurantId` é prop fixa pro tempo de vida deste componente -- App.svelte
  // recria o PaymentFlow a cada troca de loja (restaurantId volta a null
  // entre uma visita e outra), então ler o valor inicial aqui é
  // intencional, não um bug de reatividade (mesmo padrão de ItemModal.svelte).
  api
    .get('/restaurants/show.php', { query: { id: restaurantId } })
    .then((data) => {
      restaurant = data.restaurant;
      acceptedMethods = data.payment_methods ?? null;
    })
    .catch(() => {});

  let cart = $derived(cartState());

  let step = $state('address');
  let addressId = $state(null);
  let addressLabel = $state('');
  let method = $state(null);
  let order = $state(null);
  let payment = $state(null);
  let pixCopyPaste = $state('');
  let pixQrBase64 = $state(null);
  let busy = $state(false);
  // 14.4 — a faixa escolhida, ou null pra "assim que ficar pronto".
  let slot = $state(null);

  let total = $derived(Number(cart?.order?.total ?? 0));

  function onAddressReady(id, label) {
    addressId = id;
    addressLabel = label ?? 'endereço selecionado';
    // 14.4 entra ANTES do método de pagamento: quando receber muda o que a
    // tela seguinte promete ("cobramos na entrega" só vale pra dinheiro e
    // maquininha), então a pergunta vem primeiro.
    step = 'when';
  }

  async function onMethodContinue(chosen) {
    method = chosen;
    if (chosen === 'pix_manual' || chosen === 'pix_auto') {
      // Pix precisa do QR já pronto quando a tela aparece -- diferente dos
      // outros métodos, que só chamam checkout+pay quando o usuário confirma.
      step = 'pix_loading';
      try {
        await checkoutAndPay({});
        step = chosen;
      } catch {
        step = 'select';
      }
      return;
    }
    step = chosen;
  }

  // orders/checkout.php só pode ser chamado UMA vez por carrinho (ele
  // transiciona cart -> pending_payment; não existe "cart" pra achar numa
  // segunda chamada). payments/pay.php, ao contrário, é seguro de repetir
  // (idempotência à parte) enquanto o pedido continuar pending_payment --
  // por isso só chama checkout.php quando `order` ainda não existe, e todo
  // retry (CVV errado, etc.) chama só pay.php de novo em cima do mesmo
  // `order.id`.
  //
  // Recusa de cartão (HTTP 402) chega como ApiError, mas É uma resposta
  // normal do domínio (o pedido foi decidido, só que negativamente) — o
  // corpo do erro já traz order+payment. Rejeitado é estado terminal
  // (advance_order não tem transição saindo de 'rejected'): não dá pra
  // "tentar de novo" no mesmo pedido, então isso não é reaproveitável como
  // os outros erros são.
  async function checkoutAndPay(extra) {
    busy = true;
    try {
      if (order === null) {
        const checkoutData = await api.post('/orders/checkout.php', {
          auth: true,
          body: {
            restaurant_id: restaurantId,
            address_id: addressId,
            payment_method: method,
            // O desconto já está no carrinho; o código vai junto pra o
            // checkout gravar o resgate e consumir o orçamento da campanha.
            ...(couponCode ? { coupon_code: couponCode } : {}),
            ...(slot ? { slot: { start: slot.start, end: slot.end } } : {}),
            ...extra,
          },
        });
        order = checkoutData.order;
      } else if (order.payment_method !== method) {
        // Voltou e escolheu outro método depois do checkout (ex.: Pix
        // manual numa loja sem chave): troca o método do MESMO pedido --
        // itens, cupom, crédito e vaga agendada continuam valendo.
        const changed = await api.post('/payments/change_method.php', {
          auth: true,
          body: {
            order_id: order.id,
            payment_method: method,
            ...(extra.change_for !== undefined ? { change_for: extra.change_for } : {}),
            ...(extra.machine_kind ? { machine_kind: extra.machine_kind } : {}),
          },
        });
        order = changed.order;
        pixCopyPaste = '';
        pixQrBase64 = null;
      }

      const payData = await api.post('/payments/pay.php', {
        auth: true,
        headers: { 'X-Idempotency-Key': crypto.randomUUID() },
        body: { order_id: order.id, ...(extra.card_token ? extra : {}) },
      });
      order = payData.order;
      payment = payData.payment;
      pixCopyPaste = payData.pix_copy_paste ?? pixCopyPaste;
      pixQrBase64 = payData.pix_qr_base64 ?? pixQrBase64;
      return payData;
    } catch (e) {
      if (e instanceof ApiError && e.body?.order?.status && e.body.order.status !== 'pending_payment') {
        order = e.body.order;
        payment = e.body.payment ?? payment;
        return e.body;
      }
      const message = e instanceof ApiError ? e.message : 'Não deu pra processar o pagamento.';
      toastr.error(message);
      throw e;
    } finally {
      busy = false;
    }
  }

  async function onCardSubmit(fields) {
    try {
      await checkoutAndPay(fields);
      showResult();
    } catch {
      // erro genuinamente recuperável (ex.: checkout ainda não aconteceu e
      // a loja fechou, ou o card_token ficou mal formado) -- fica na tela
      // pra corrigir e tentar de novo, já avisado por toastr.
    }
  }

  async function onCashSubmit(fields) {
    try {
      await checkoutAndPay(fields);
      showResult();
    } catch {
      // fica na tela pra corrigir e tentar de novo
    }
  }

  async function onMachineSubmit(fields) {
    try {
      await checkoutAndPay(fields);
      showResult();
    } catch {
      // fica na tela pra corrigir e tentar de novo
    }
  }

  function onProofUploaded(data) {
    order = data.order;
    showResult();
  }

  // Entrega pro OrderTracking.svelte (Fase 5.1/5.2/5.3/5.4, uma tela só
  // reagindo ao status ao vivo por SSE) em vez de mostrar um SweetAlert e
  // voltar pro início -- o pedido rejeitado também abre lá (o hero de 5.4
  // é a mesma tela, só que outro status), não precisa de tratamento
  // especial aqui.
  function showResult() {
    clearCartState();
    onOrderReady(order.id);
  }

  // Voltar pra escolha de método depois do checkout não perde o pedido:
  // `checkoutAndPay` percebe que o método mudou e chama
  // payments/change_method.php antes de pagar. O servidor recusa (409) se
  // o pagamento já andou -- comprovante enviado, cartão ou Pix automático
  // já no Mercado Pago --, e aí o toastr explica.
  function backFromMethod() {
    step = 'select';
  }
</script>

{#if step === 'when'}
  <ScheduleScreen
    {restaurantId}
    prepMinutes={restaurant?.prep_minutes ?? null}
    onContinue={(chosenSlot) => {
      slot = chosenSlot;
      step = 'select';
    }}
  />
{:else if step === 'address'}
  <div class="flow-shell">
    <button type="button" class="back-link" onclick={onBack}><i class="bi bi-arrow-left"></i> Voltar ao carrinho</button>
    <QuickAddress {location} onReady={onAddressReady} />
  </div>
{:else if step === 'select'}
  <PaymentSelector {total} {addressLabel} {acceptedMethods} onContinue={onMethodContinue} {onBack} />
{:else if step === 'mp_card'}
  <CardForm {total} onSubmit={onCardSubmit} onBack={backFromMethod} {busy} />
{:else if step === 'pix_loading'}
  <div class="flow-shell"><p class="loading">Gerando QR do Pix…</p></div>
{:else if step === 'pix_manual'}
  <PixPayment
    restaurantName={restaurant?.name ?? ''}
    restaurantCnpj={restaurant?.cnpj ?? ''}
    amount={order?.total ?? total}
    copyPaste={pixCopyPaste}
    deadline={order?.verification_deadline}
    onProofStep={() => (step = 'proof')}
    onBack={backFromMethod}
  />
{:else if step === 'pix_auto'}
  <PixAutoPayment
    orderId={order?.id}
    restaurantName={restaurant?.name ?? ''}
    amount={order?.total ?? total}
    copyPaste={pixCopyPaste}
    qrBase64={pixQrBase64}
    deadline={order?.verification_deadline}
    onPaid={(paidOrder) => {
      order = paidOrder;
      showResult();
    }}
    onBack={backFromMethod}
  />
{:else if step === 'proof'}
  <ProofUploader orderId={order?.id} orderCode={order?.public_code} onUploaded={onProofUploaded} onBack={() => (step = 'pix_manual')} />
{:else if step === 'cash'}
  <CashPayment {total} onSubmit={onCashSubmit} onBack={backFromMethod} {busy} />
{:else if step === 'pos_machine'}
  <MachinePayment {total} onSubmit={onMachineSubmit} onBack={backFromMethod} {busy} />
{/if}

<style>
  .flow-shell {
    padding: 40px 20px;
  }
  .back-link {
    display: flex;
    align-items: center;
    gap: 6px;
    background: none;
    border: none;
    color: var(--fuu-ink-3);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    padding: 4px;
    margin: 0 auto;
    max-width: 360px;
  }
  .loading {
    text-align: center;
    color: var(--fuu-ink-5);
    font-size: 13.5px;
  }
</style>
