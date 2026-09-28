# 44 — Lote rápido da auditoria (SEG-01, SEG-03, INFRA-03)

**De onde veio:** a auditoria 360° de 25/09/2026. O Marcos pediu "faça o que
for recomendado".

## SEG-01: `APP_ENV` falha fechado

`app_env()` (`lib/core/production_guard.php`) devolvia `development` quando
`APP_ENV` faltava. Um `.env` de produção que perdesse a linha (ou tivesse
"prod") passaria a devolver o código de login na resposta do OTP e a aceitar
webhook do Mercado Pago sem assinatura. O deploy pegava isso
(`check_production.php`), mas não uma edição feita depois.

Agora ausente ou desconhecido vale **production**. A trava lista o motivo
("APP_ENV ausente: vale production por segurança..."), e maiúsculas são
aceitas (`Development`). Tudo que roda PHP já declara o ambiente: testes
(`APP_ENV` explícito), cron (lê `/etc/fuuphp/fuuphp.env`), dev local
(`.env`). As ferramentas de linha de comando que não carregam o bootstrap,
ou que já pulam a trava, não mudam.

Teste: `smoke_production_guard.sh` (sem APP_ENV recusa e diz por quê;
`prod` recusa; sem APP_ENV o código de login não voltaria; maiúscula vale).

## SEG-03: token do acompanhamento fora do log

O `EventSource` não manda cabeçalho, então `orders/track.php` recebe o
access token na URL, e o `access_log` do Nginx gravava a URL inteira. Novo
formato `fuu_combined`: nessa rota, só o caminho; nas outras, igual ao
combined. Conferido com `nginx -t` e requisição real: o log mostra
`GET /api/v1/orders/track.php`, sem o token.

**Resolvido de vez (lote seguinte):** a URL não leva mais o access token.
O app pede `orders/track_ticket.php` (com o cabeçalho normal) e recebe um
ticket que só abre o acompanhamento **daquele pedido**, por **5 minutos**, e
que **não vale como access token** em rota nenhuma (`decode_access_token`
recusa token com `purpose`). `?token=` deixou de ser aceito. Assim, mesmo o
`error_log` do Nginx (sem formato configurável) ou um log do Cloudflare
guardariam no máximo um ticket de leitura de um pedido que vence em 5 min.
Teste em `smoke_tracking.sh`: ticket de outro pedido, ticket como access
token, access token na URL e ticket pro pedido alheio são recusados.

## INFRA-03: CI

- `permissions: contents: read`: o token do workflow só lê.
- Ações fixadas por SHA de commit (`actions/checkout`, `actions/setup-node`,
  `shivammathur/setup-php`), com a tag no comentário.
- `timeout-minutes: 30` no job.
- `npm audit --omit=dev --audit-level=high` depois do build do front.
