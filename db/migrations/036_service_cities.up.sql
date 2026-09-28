-- 036_service_cities.up.sql
-- Cidades atendidas ("praças"): onde a plataforma opera.
--
-- Até aqui a lista de UFs e cidades do onboarding (telas 1.2 e 1.3) e do
-- cadastro de loja era um arquivo fixo no front, herdado do mock: sete
-- cidades de exemplo e contagens de "lojas ativas" inventadas (SP 1.284...).
-- Cidade fora da lista não recebia loja nem cliente, e o cliente via número
-- que não existe. Agora a plataforma liga as cidades em que opera (aba
-- Cidades do admin), e o app mostra só essas, com a contagem real de lojas
-- aprovadas (catalog/cities.php).
--
--   ibge_code      o código de 7 dígitos do município (IBGE), o mesmo de
--                  restaurants.city_ibge_code e addresses.city_ibge_code;
--   lat / lng      o centro da cidade: ordena a Home por distância antes de
--                  o cliente ter endereço;
--   neighborhoods  os bairros oferecidos no passo 2 do onboarding;
--   active         desligar esconde a cidade do app sem apagar nada.

BEGIN;

CREATE TABLE service_cities (
  ibge_code     char(7) PRIMARY KEY CHECK (ibge_code ~ '^[0-9]{7}$'),
  name          text NOT NULL CHECK (length(trim(name)) > 0),
  uf            char(2) NOT NULL CHECK (uf ~ '^[A-Z]{2}$'),
  lat           numeric(9,6) NOT NULL CHECK (lat BETWEEN -34 AND 6),
  lng           numeric(9,6) NOT NULL CHECK (lng BETWEEN -74 AND -34),
  neighborhoods text[] NOT NULL DEFAULT '{}',
  active        boolean NOT NULL DEFAULT true,
  created_at    timestamptz NOT NULL DEFAULT now(),
  updated_at    timestamptz NOT NULL DEFAULT now()
);

COMMIT;
