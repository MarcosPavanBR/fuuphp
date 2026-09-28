-- 037_showcase.up.sql
-- A vitrine da Home do cliente, no nível do concorrente (MaisDelivery):
-- banners promocionais e loja favorita. (O logo da loja já tinha coluna,
-- restaurants.logo_key, desde a migração 010; faltava subir.)
--
-- promo_banners: a plataforma cria na aba Banners do admin. Aparece no
-- carrossel da Home dentro da janela [starts_at, ends_at), na cidade
-- escolhida (city_ibge_code) ou em todas (NULL). Tocar leva à loja do
-- link, quando tem -- é a vitrine que a plataforma pode vender pras lojas.
--
-- favorite_restaurants: o coração do card. Dado pessoal: sai no "baixar
-- meus dados" e some no "excluir conta" (lib/account/account_privacy.php).

BEGIN;

CREATE TABLE promo_banners (
  id                 bigserial PRIMARY KEY,
  title              text NOT NULL CHECK (length(trim(title)) BETWEEN 2 AND 80),
  image_key          text NOT NULL CHECK (image_key ~ '^[0-9a-f]{64}\.jpg$'),
  city_ibge_code     char(7) REFERENCES service_cities(ibge_code),
  link_restaurant_id uuid REFERENCES restaurants(id) ON DELETE SET NULL,
  starts_at          timestamptz NOT NULL DEFAULT now(),
  ends_at            timestamptz,
  position           int NOT NULL DEFAULT 0,
  active             boolean NOT NULL DEFAULT true,
  created_by         uuid REFERENCES users(id),
  created_at         timestamptz NOT NULL DEFAULT now(),
  CHECK (ends_at IS NULL OR ends_at > starts_at)
);
CREATE INDEX promo_banners_live_idx ON promo_banners (position, id) WHERE active;

CREATE TABLE favorite_restaurants (
  user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  restaurant_id uuid NOT NULL REFERENCES restaurants(id) ON DELETE CASCADE,
  created_at    timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, restaurant_id)
);

COMMIT;
