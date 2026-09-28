-- 010_catalog_discovery.up.sql
-- Adiciona a restaurants o que a Fase 2 das telas (home/busca) precisa pra
-- listar e ordenar por distância de verdade: category, logo_key, lat, lng.
--
-- Não está na Parte II original da especificação -- o documento fixa 42
-- tabelas e essas 4 colunas não existem em nenhuma delas. É extensão
-- necessária, não capricho: a tela 2.1 (Home/Explorar) mostra categoria,
-- logo e distância em km por loja, e não tem como isso ser real sem que
-- o restaurante tenha coordenadas e categoria no banco. "Rating" (a nota
-- 4,8 do mock) fica de fora -- isso pede um sistema de avaliação inteiro
-- (tabela de reviews, agregação, moderação) que não está em nenhum lugar
-- da especificação; inventar essa tabela sem decisão do dono do produto
-- seria escopo novo demais para uma migração de suporte a tela.

BEGIN;

ALTER TABLE restaurants
  ADD COLUMN category text,
  ADD COLUMN logo_key text,
  ADD COLUMN lat numeric(9,6),
  ADD COLUMN lng numeric(9,6);

CREATE INDEX restaurants_city_category_idx ON restaurants (city_ibge_code, category) WHERE is_open;

-- Busca de produto por nome (tela 2.2, "Busca por produto... com índice
-- do PostgreSQL" / "PostgreSQL trigram" no chip da própria tela).
CREATE INDEX menu_items_name_trgm_idx ON menu_items USING gin (name gin_trgm_ops);

COMMIT;
