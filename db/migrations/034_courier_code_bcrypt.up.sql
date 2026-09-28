-- 034_courier_code_bcrypt.up.sql
-- O código de acesso do entregador (6 dígitos) era guardado como SHA-256 sem
-- sal: com o banco vazado, os 10^6 códigos possíveis se testam em segundos
-- (decisão 35, "limites conhecidos" do SECURITY.md). Agora é bcrypt, como a
-- senha da loja. A coluna vira `text` (o hash bcrypt tem 60 caracteres); o
-- formato antigo continua aceito no login e é trocado por bcrypt no primeiro
-- acerto (auth/partner_login.php), então nenhum entregador precisa de código
-- novo.

BEGIN;

ALTER TABLE partner_accounts ALTER COLUMN access_code_hash TYPE text;

COMMIT;
