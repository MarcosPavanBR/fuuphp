-- Senhas dos papéis do banco em produção (go-live, passo 7).
--
-- Os papéis foram criados pela migração 001 (sem senha):
--   app_rw   -- a API e os scripts de bin/. Lê e grava dados, sujeito à RLS
--               da migração 009; NÃO altera nem apaga o livro-razão e os
--               pontos; NÃO cria tabela.
--   app_ro   -- relatório/consulta; não vê restaurant_credentials.
--   migrator -- não usado na VPS: as migrações rodam como postgres (o
--               pg_cron só é criado por superusuário). Fica sem login.
--
-- Rodar DEPOIS das migrações, pelo socket local, sem a senha passar pelo
-- histórico do shell:
--
--   read -rs APP_RW_PW && read -rs APP_RO_PW
--   sudo -u postgres psql -d fuudelivery -v ON_ERROR_STOP=1 \
--     -v app_rw_pw="$APP_RW_PW" -v app_ro_pw="$APP_RO_PW" \
--     -f deploy/postgres/set_passwords.sql
--
-- A senha do app_rw vai no DATABASE_URL de /etc/fuuphp/fuuphp.env.

\if :{?app_rw_pw}
\else
  \echo 'defina -v app_rw_pw=...'
  \quit
\endif
\if :{?app_ro_pw}
\else
  \echo 'defina -v app_ro_pw=...'
  \quit
\endif

ALTER ROLE app_rw   LOGIN PASSWORD :'app_rw_pw';
ALTER ROLE app_ro   LOGIN PASSWORD :'app_ro_pw';
ALTER ROLE migrator NOLOGIN;

-- Nenhum dos papéis da aplicação passa por cima da RLS.
ALTER ROLE app_rw NOBYPASSRLS NOSUPERUSER NOCREATEDB NOCREATEROLE;
ALTER ROLE app_ro NOBYPASSRLS NOSUPERUSER NOCREATEDB NOCREATEROLE;
