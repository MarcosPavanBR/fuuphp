-- 044_cart_coupon.up.sql
-- O carrinho passa a guardar QUAL cupom foi aplicado, não só o desconto.
--
-- Antes, cart/apply_coupon.php gravava só orders.discount, e o checkout
-- registrava o resgate pelo código que viesse no corpo da requisição. Três
-- furos (achados no fuzz profundo de 25/09/2026, reproduzidos antes de
-- corrigir):
--   * não mandar coupon_code ao fechar: o desconto ficava, o resgate não era
--     gravado -- cupom de uso único usado quantas vezes quisesse, orçamento
--     da campanha intocado e o custo fora do livro;
--   * mandar OUTRO código ao fechar (um de frete grátis): desconto em dobro,
--     e o uso ia pra conta do cupom errado;
--   * aplicar e depois tirar itens: o desconto ficava maior do que o pedido
--     mínimo do cupom permitia.
-- Agora o checkout usa o cupom do carrinho, confere tudo de novo com o
-- subtotal do momento e recalcula o desconto (lib/ordering/coupons.php).
BEGIN;
ALTER TABLE orders ADD COLUMN coupon_id bigint REFERENCES coupons(id);
-- Carrinho aberto com desconto de cupom que não se sabe qual era: zera. O
-- cliente aplica de novo (o app mostra o campo vazio).
UPDATE orders SET discount = 0 WHERE status = 'cart' AND discount > 0;
COMMIT;
