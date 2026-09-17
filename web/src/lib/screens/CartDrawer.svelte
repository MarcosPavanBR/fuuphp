<script>
  import { cartState, loadCart, removeFromCart, updateCartQuantity } from '../cart.svelte.js';
  import { toastr } from '../toastr.js';

  // Tela 3.3 — Carrinho. "Total nunca é somado no cliente: vem da coluna
  // gerada, o que impede divergência com a cobrança." (CartDrawer.svelte,
  // toastr, orders.status='cart')
  let { restaurantId, onBack, onCheckout } = $props();

  let cart = $derived(cartState());
  let coupon = $state('');
  let busyItemId = $state(null);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function variantSummary(item) {
    const snapshot = typeof item.variants_snapshot === 'string' ? JSON.parse(item.variants_snapshot) : item.variants_snapshot;
    const parts = (snapshot ?? []).map((v) => v.name);
    if (item.notes) parts.push(item.notes);
    return parts.join(' · ');
  }

  async function changeQty(item, delta) {
    const next = item.quantity + delta;
    busyItemId = item.id;
    try {
      if (next < 1) {
        await removeFromCart(item.id);
      } else {
        await updateCartQuantity(item.id, next);
      }
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra atualizar o carrinho.');
    } finally {
      busyItemId = null;
    }
  }

  function applyCoupon() {
    toastr.info('Cupom ainda não foi implementado — coupons existe no esquema (migração 008), mas sem endpoint de resgate.');
  }

  function goToPayment() {
    toastr.info('Fase 4 (pagamento) ainda não foi portada.');
  }
</script>

<div class="cart-drawer">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Carrinho</h1>
  </div>

  {#if !cart.order || cart.items.length === 0}
    <p class="empty">Seu carrinho está vazio.</p>
  {:else}
    <div class="item-list">
      {#each cart.items as item (item.id)}
        <div class="item-row">
          <div class="photo" aria-hidden="true"><i class="bi bi-image"></i></div>
          <div class="info">
            <p class="name">{item.name_snapshot}</p>
            {#if variantSummary(item)}
              <p class="variants">{variantSummary(item)}</p>
            {/if}
            <div class="stepper">
              <button type="button" disabled={busyItemId === item.id} onclick={() => changeQty(item, -1)} aria-label="Diminuir">−</button>
              <span>{item.quantity}</span>
              <button type="button" disabled={busyItemId === item.id} onclick={() => changeQty(item, 1)} aria-label="Aumentar">+</button>
            </div>
          </div>
          <p class="line-total fuu-mono">{money(item.line_total)}</p>
        </div>
      {/each}
    </div>

    <div class="coupon-row">
      <input type="text" placeholder="Cupom" bind:value={coupon} />
      <button type="button" onclick={applyCoupon}>Aplicar</button>
    </div>

    <div class="totals">
      <div class="row">
        <span>Subtotal</span>
        <span class="fuu-mono">{money(cart.order.subtotal)}</span>
      </div>
      <div class="row">
        <span>Taxa de entrega</span>
        <span class="fuu-mono">{money(cart.order.delivery_fee)} <small>(definida no checkout)</small></span>
      </div>
      <div class="row total">
        <span>Total</span>
        <span class="fuu-mono">{money(cart.order.total)}</span>
      </div>
      <p class="note">total = subtotal + taxa − desconto (coluna gerada no PostgreSQL)</p>
    </div>

    <button type="button" class="btn-fuu-primary w-100" onclick={goToPayment}>Ir para pagamento</button>
  {/if}
</div>

<style>
  .cart-drawer {
    padding: 12px 20px 24px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-2);
    padding: 4px;
  }
  h1 {
    font-size: 20px;
    margin: 0;
  }
  .empty {
    color: var(--fuu-ink-5);
    font-size: 13.5px;
  }
  .item-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 18px;
  }
  .item-row {
    display: flex;
    gap: 12px;
    align-items: flex-start;
  }
  .photo {
    width: 52px;
    height: 52px;
    border-radius: 10px;
    background: var(--fuu-line-5);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--fuu-ink-6);
    flex: none;
  }
  .info {
    flex: 1;
    min-width: 0;
  }
  .name {
    margin: 0;
    font-weight: 600;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .variants {
    margin: 2px 0 6px;
    font-size: 12px;
    color: var(--fuu-ink-5);
  }
  .stepper {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 999px;
    padding: 3px 10px;
    width: fit-content;
  }
  .stepper button {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: none;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-2);
    font-size: 13px;
  }
  .line-total {
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .coupon-row {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
  }
  .coupon-row input {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 9px 12px;
    font-family: var(--fuu-font-body);
    font-size: 13.5px;
  }
  .coupon-row button {
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 0 16px;
    background: var(--fuu-white);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .totals {
    border-top: 1px solid var(--fuu-line-3);
    padding: 12px 0;
    margin-bottom: 18px;
  }
  .row {
    display: flex;
    justify-content: space-between;
    font-size: 13.5px;
    color: var(--fuu-ink-3);
    margin-bottom: 6px;
  }
  .row small {
    color: var(--fuu-ink-5);
    font-family: var(--fuu-font-body);
    font-size: 10.5px;
  }
  .row.total {
    font-weight: 700;
    font-size: 15px;
    color: var(--fuu-ink-1);
  }
  .note {
    font-size: 10.5px;
    color: var(--fuu-ink-5);
    margin: 6px 0 0;
  }
</style>
