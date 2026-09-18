-- 014_cancellation.up.sql
-- platform_policies.cancel_fee: a taxa de cancelamento da tela 13.1.
--
-- "Antes de a loja aceitar, o cancelamento é livre e sem taxa; depois de 'em
-- preparo', entra a taxa." A regra de QUANDO cobrar está no código (é o
-- status do pedido); QUANTO cobrar é decisão de dono, então mora aqui junto
-- com teto de espécie, comissão e prazo de repasse -- versionado e auditável
-- como o resto da política.
--
-- Padrão 0: nenhuma taxa até alguém decidir uma. O mock mostra R$ 15,00 num
-- pedido de R$ 78,40, mas esse número é exemplo de desenho, não regra de
-- negócio aprovada -- inventar um padrão diferente de zero seria cobrar do
-- cliente um valor que ninguém autorizou.

BEGIN;

ALTER TABLE platform_policies
  ADD COLUMN cancel_fee numeric(12,2) NOT NULL DEFAULT 0 CHECK (cancel_fee >= 0);

COMMIT;
