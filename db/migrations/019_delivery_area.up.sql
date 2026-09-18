-- 019_delivery_area.up.sql
-- Tela 14.3 — "Novo endereço com mapa": ponto de referencia, area de
-- cobertura validada no servidor e taxa que aparece ANTES de salvar.
--
-- Isto fecha tambem o buraco que o proprio README vinha apontando: o frete
-- era o unico numero do dinheiro que ainda vinha do cliente no corpo da
-- requisicao (`orders/checkout.php` aceitava `delivery_fee`), contra o
-- principio que o resto do projeto segue -- preco de item, minimo de pedido
-- e total sao todos decididos no servidor.

BEGIN;

-- "Ponto de referencia (ajuda o entregador)". Cabe em `complement`? Nao:
-- complemento e parte do endereco (apto, bloco) e vai no cupom; referencia e
-- instrucao pra quem entrega ("portao cinza ao lado da padaria"), muda de
-- endereco pra endereco e nao identifica a unidade.
ALTER TABLE addresses
  ADD COLUMN reference text;

-- A tarifa vira politica da plataforma, versionada como todo o resto
-- (platform_policies ja e append-only por versao, e o snapshot congela o que
-- valia no dia do pedido).
--
-- Os tres nascem ZERO/NULL de proposito: inventar "R$ 5,00 + R$ 1,50/km"
-- aqui seria cobrar do cliente um numero que ninguem decidiu. Ate a
-- plataforma publicar uma politica com tarifa (tela 10.5), o frete e zero --
-- e isso e visivel na tela, nao escondido.
ALTER TABLE platform_policies
  ADD COLUMN delivery_base_fee numeric(12,2) NOT NULL DEFAULT 0
    CHECK (delivery_base_fee >= 0),
  ADD COLUMN delivery_per_km   numeric(12,2) NOT NULL DEFAULT 0
    CHECK (delivery_per_km >= 0),
  -- NULL = sem raio declarado. Nao e o mesmo que zero: zero seria "nao
  -- entregamos em lugar nenhum".
  ADD COLUMN delivery_max_km   numeric(6,2)
    CHECK (delivery_max_km IS NULL OR delivery_max_km > 0);

COMMIT;
