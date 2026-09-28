# 52 — Pix só com copia-e-cola; hospedagem Supabase + Render

**Pix:** decisão do Marcos (28/09/2026): o código copia-e-cola basta. Sem
biblioteca de QR Code (nada entra na stack sem autorização). O app mostra o
código com botão de copiar, como já faz.

**Hospedagem:** banco no Supabase (projeto `fuuphp`, novo -- o projeto
"delivery" é do sistema antigo em Go e tem nomes de tabela que colidem) e
aplicação no Render, via Docker (`Dockerfile`, `deploy/render/`,
`render.yaml`). Passo a passo em `docs/SUPABASE_RENDER.md`. A VPS
(docs/GO_LIVE.md) continua valendo como alternativa.

- `lib/core/db.php`: `DATABASE_URL` aceita `?sslmode=` e senha codificada
  na URL (Supabase exige TLS; senha gerada pode ter `@`, `%`, espaço).
- O contêiner só sobe com disco montado e `check_production` aprovado.
- CI constrói a imagem e sobe em modo produção contra o banco do CI.

**Foto de perfil (migração 048):** pedido do Marcos. Bucket `avatars` no
Supabase Storage, leitura pública e escrita só pelo servidor (chave secreta
no ambiente). Sem SUPABASE_URL (dev/VPS) a foto fica no disco. A CSP libera
`https://*.supabase.co` só em img-src. Teste: tests/smoke_avatar.sh.
