// Estado do carrinho, reativo, espelhando o pedido status='cart' no banco
// (não um store local que finge sincronizar -- cada ação chama a API e
// substitui o estado pela resposta real do servidor).
import { api } from './api.js';

let cart = $state({ order: null, items: [] });

export function cartState() {
  return cart;
}

export async function loadCart(restaurantId) {
  const data = await api.get('/cart/show.php', { auth: true, query: { restaurant_id: restaurantId } });
  cart = data;
  return data;
}

export async function addToCart({ restaurantId, menuItemId, quantity, variantIds, notes }) {
  const data = await api.post('/cart/add_item.php', {
    auth: true,
    body: { restaurant_id: restaurantId, menu_item_id: menuItemId, quantity, variant_ids: variantIds, notes },
  });
  cart = data;
  return data;
}

export async function removeFromCart(orderItemId) {
  const data = await api.post('/cart/remove_item.php', { auth: true, body: { order_item_id: orderItemId } });
  cart = data;
  return data;
}

export async function updateCartQuantity(orderItemId, quantity) {
  const data = await api.post('/cart/update_quantity.php', {
    auth: true,
    body: { order_item_id: orderItemId, quantity },
  });
  cart = data;
  return data;
}

export function clearCartState() {
  cart = { order: null, items: [] };
}
