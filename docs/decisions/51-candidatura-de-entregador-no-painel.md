# 51 — Candidatura de entregador no painel, e documento cifrado (migração 047)

**De onde veio:** o último item da auditoria DevSecOps
([50](50-auditoria-devsecops.md)) era cifrar o documento do entregador em
disco. Ao seguir o caminho do arquivo até quem o lê, apareceram três buracos
maiores que o da cifra.

## O que estava faltando

1. **Não existia tela pra aprovar entregador.** A rota `admin/couriers.php`
   existia e tinha teste, mas nenhuma aba do painel a chamava. Aprovar um
   entregador exigia chamar a API na mão.
2. **Ninguém conseguia abrir os documentos.** A fila dizia "4 documentos",
   mas não havia rota que servisse o arquivo. A conferência da selfie com a
   CNH, que é o antifraude da entrada, não tinha como ser feita.
3. **O motivo da decisão era jogado fora.** Recusar ou pedir correção exigia
   motivo ("é o que a pessoa vai ler"), mas ele não era gravado. A pessoa via
   "precisa de correção" sem saber do quê.

E a fila devolvia a lista de documentos como texto JSON, não como lista; a
primeira tela que a usasse quebraria.

## O que foi feito

- **Aba "Entregadores" no painel** (`CourierQueue.svelte`): a fila com prazo
  de 48 h, dados, chave Pix ("tem que ser do mesmo CPF") e os documentos.
  - **Aprovar** pede a praça (lista de cidades) e mostra o código de acesso
    uma vez.
  - **Pedir correção** e **recusar** pedem o motivo.
- **Abrir o documento** (`admin/courier_document.php`): só admin (com o
  segundo fator, em produção). Decifra na hora, responde `no-store`, confere
  o formato da chave e grava cada abertura no `audit_log` (LGPD: quem viu o
  documento de quem).
- **Documento cifrado em disco** (`lib/core/file_crypto.php`): sodium
  secretbox, que já vem no PHP. O arquivo gravado começa com `FUUE1`.
  - Documento antigo, em claro, continua abrindo.
  - A chave é `DATA_ENCRYPTION_KEY`, no `.env`; a trava de produção a exige.
    Fora de produção, sem a variável, vale uma chave derivada do
    `JWT_SECRET`, pra que os testes passem pelo caminho cifrado.
  - O sha256 que pega CNH repetida continua sendo do conteúdo aberto.
- **O motivo fica gravado** (`courier_applications.review_note` e
  `reviewed_at`, migração 047), e o app do candidato mostra.

**Perder a `DATA_ENCRYPTION_KEY` é perder os documentos.** Ela vai no cofre
de senhas, fora da VPS (docs/GO_LIVE.md).

## Como foi conferido

- `smoke_growth.sh`:
  - o arquivo em disco começa com `FUUE1`;
  - o candidato não abre pela rota do admin (403);
  - o admin recebe exatamente os bytes enviados (sha256 igual) com
    `no-store`;
  - a abertura fica no `audit_log`;
  - o motivo da correção aparece no app do candidato.
- `smoke_production_guard.sh`: sem a chave, produção não sobe.
- **No navegador:** a aba mostra as 4 candidaturas da fila, e uma CNH
  cifrada abre decifrada na tela.
