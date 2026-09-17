<script>
  import swal from 'sweetalert';
  import { api, ApiError } from '../api.js';
  import { toastr } from '../toastr.js';
  import { cartState, clearCartState } from '../cart.svelte.js';
  import QuickAddress from '../components/QuickAddress.svelte';
  import PaymentSelector from './PaymentSelector.svelte';
  import CardForm from './CardForm.svelte';
  import PixPayment from './PixPayment.svelte';
  import ProofUploader from './ProofUploader.svelte';
  import CashPayment from './CashPayment.svelte';
  import MachinePayment from './MachinePayment.svelte';

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
  let { restaurantId, location, onBack, onDone } = $props();

  let restaurant = $state(null);
  // `restaurantId` é prop fixa pro tempo de vida deste componente -- App.svelte
  // recria o PaymentFlow a cada troca de loja (restaurantId volta a null
  // entre uma visita e outra), então ler o valor inicial aqui é
  // intencional, não um bug de reatividade (mesmo padrão de ItemModal.svelte).
  api
    .get('/restaurants/show.php', { query: { id: restaurantId } })
    .then((data) => (restaurant = data.restaurant))
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

  let total = $derived(Number(cart?.order?.total ?? 0));

  function onAddressReady(id, label) {
    addressId = id;
    addressLabel = label ?? 'endereço selecionado';
    step = 'select';
  }

  async function onMethodContinue(chosen) {
    method = chosen;
    if (chosen === 'pix_manual') {
      // Pix precisa do QR já pronto quando a tela aparece -- diferente dos
      // outros métodos, que só chamam checkout+pay quando o usuário confirma.
      step = 'pix_loading';
      try {
        await checkoutAndPay({});
        step = 'pix_manual';
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
          body: { restaurant_id: restaurantId, address_id: addressId, payment_method: method, ...extra },
        });
        order = checkoutData.order;
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
      const data = await checkoutAndPay(fields);
      await showResult(data.order.status, data);
    } catch {
      // erro genuinamente recuperável (ex.: checkout ainda não aconteceu e
      // a loja fechou, ou o card_token ficou mal formado) -- fica na tela
      // pra corrigir e tentar de novo, já avisado por toastr.
    }
  }

  async function onCashSubmit(fields) {
    try {
      const data = await checkoutAndPay(fields);
      await showResult(data.order.status, data);
    } catch {
      // fica na tela pra corrigir e tentar de novo
    }
  }

  async function onMachineSubmit(fields) {
    try {
      const data = await checkoutAndPay(fields);
      await showResult(data.order.status, data);
    } catch {
      // fica na tela pra corrigir e tentar de novo
    }
  }

  function onProofUploaded(data) {
    order = data.order;
    showResult(data.order.status, data);
  }

  // Fase 5 (pós-pedido) ainda não tem tela própria -- o feedback aqui é um
  // SweetAlert com o essencial de 5.1/5.2/5.4, não a tela de tracking
  // completa (mapa, timeline, avaliação).
  async function showResult(status, data) {
    // Limpar o carrinho DEPOIS do SweetAlert fechar, não antes: o estado
    // reativo do carrinho (cart.svelte.js) é o mesmo módulo que a tela por
    // baixo do modal ainda lê (ex.: CashPayment mostra "Total do pedido") --
    // zerar antes faria esse total virar R$0,00 por trás do modal.
    if (status === 'paid') {
      await swal({
        title: 'Pagamento aprovado',
        text: `Pedido #${order.public_code} confirmado. A cozinha já foi avisada.`,
        icon: 'success',
        button: 'Voltar ao início',
      });
    } else if (status === 'pending_verification') {
      await swal({
        title: 'Comprovante em análise',
        text: `A loja confirma em até 15 minutos. Pedido #${order.public_code}.`,
        icon: undefined,
        button: 'Voltar ao início',
      });
    } else if (status === 'rejected') {
      // O pedido rejeitado é terminal (advance_order não deixa voltar pra
      // pending_payment) e o carrinho que ele consumiu já não existe mais
      // -- não dá pra "tentar de novo" dentro deste mesmo fluxo. Volta pro
      // início; um novo item adicionado cria um carrinho novo sozinho
      // (find_or_create_cart, o mesmo caminho da Fase 3).
      await swal({
        title: 'Pagamento recusado',
        text:
          (data?.payment?.status_detail ?? order.reject_reason ?? 'Não foi possível aprovar esse pagamento.') +
          '. Monte o pedido de novo pra tentar com outro método.',
        icon: 'error',
        button: 'Voltar ao início',
      });
    } else {
      await swal({ title: 'Pedido em andamento', text: `Status atual: ${status}.`, button: 'Voltar ao início' });
    }
    clearCartState();
    onDone();
  }

  // Simplificação assumida: se o checkout já aconteceu (order !== null,
  // ex.: pix_manual sem chave Pix cadastrada) e o cliente volta e escolhe
  // OUTRO método aqui, o pedido já existe com o payment_method antigo
  // gravado -- trocar de método de verdade, nesse caso, exigiria um
  // endpoint pra abandonar o pending_payment e abrir carrinho novo, que
  // não existe ainda. Não é o caminho comum (a maioria das voltas acontece
  // antes de qualquer checkout, quando `order` ainda é null e o retry já
  // funciona certo).
  function backFromMethod() {
    step = 'select';
  }
</script>

{#if step === 'address'}
  <div class="flow-shell">
    <button type="button" class="back-link" onclick={onBack}><i class="bi bi-arrow-left"></i> Voltar ao carrinho</button>
    <QuickAddress {location} onReady={onAddressReady} />
  </div>
{:else if step === 'select'}
  <PaymentSelector {total} {addressLabel} onContinue={onMethodContinue} {onBack} />
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
