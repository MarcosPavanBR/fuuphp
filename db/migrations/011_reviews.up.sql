-- 011_reviews.up.sql
-- reviews: avaliação do pedido (Fase 5.5 das telas).
--
-- Não está na Parte II original da especificação -- mesma situação já
-- registrada na migração 010 (a especificação fixa 42 tabelas e não prevê
-- reviews). Ali, o comentário deixou de propósito de fora qualquer coisa
-- que exigisse "agregação, moderação" (nota média da loja exibida em
-- Home/Search, moderação de comentário abusivo) por ser decisão de
-- produto em aberto. Esta migração NÃO mexe nisso: nenhuma coluna de nota
-- agregada entra em restaurants, nenhuma tela lista/pondera reviews. O que
-- entra aqui é só o registro do feedback em si -- rating, tags, gorjeta
-- pro entregador e comentário -- exatamente os campos que a tela 5.5
-- describe, um por pedido, sem inventar nada além disso.

BEGIN;

CREATE TABLE reviews (
  id           bigserial PRIMARY KEY,
  order_id     bigint NOT NULL UNIQUE REFERENCES orders(id),
  user_id      uuid NOT NULL REFERENCES users(id),
  rating       smallint NOT NULL CHECK (rating BETWEEN 1 AND 5),
  tags         text[] NOT NULL DEFAULT '{}',   -- "Comida quente", "Chegou rápido" etc.
  comment      text,
  courier_tip  numeric(12,2) NOT NULL DEFAULT 0 CHECK (courier_tip >= 0),
  created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX reviews_user_idx ON reviews (user_id, created_at DESC);

COMMIT;
