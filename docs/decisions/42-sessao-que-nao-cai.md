# 42 — Sessão que não cai aos 15 minutos

**De onde veio:** a auditoria 360° de 25/09/2026 (achado COE-01). O access
token vale 15 minutos e o back sempre entregou um refresh token, mas nenhum
app guardava nem usava. Aos 15 minutos o tablet da cozinha parava de receber
pedido (o polling engolia o 401 e a tela só dizia que parou de atualizar), o
entregador parava de ver corrida, e o admin e o cliente eram deslogados. Os
testes de navegador duravam menos que isso, por isso nunca apareceu.

## O que foi feito

- **Renovação no front** (`web/src/lib/services/api.js`): cada app registra
  a sua sessão (cliente, loja, entregador, admin, cada uma com a sua chave).
  O token é renovado sozinho um minuto antes de vencer, de novo quando a
  tela volta do descanso ou a rede volta, e, como rede de segurança, uma
  chamada que leva 401 `invalid_token` renova e é repetida uma vez.
- **Uma renovação por vez.** O refresh é de uso único e reuso revoga a
  família inteira. Duas abas renovando juntas derrubariam a sessão; por isso
  há uma promessa compartilhada na aba, Web Locks entre abas, e quem chega
  depois usa o token que a outra aba já gravou (evento `storage`).
- **Sessão morta volta pro login.** Refresh recusado (suporte liberou troca
  de aparelho, conta bloqueada, 30 dias sem uso) limpa a sessão; painel,
  entregador e admin voltam pro login com o aviso "Sua sessão terminou".
- **Renovação devolve o mesmo token** (migração 039, `sessions.claims`). Sem
  isso, a loja renovada perdia o `restaurant_id` e o painel pararia do mesmo
  jeito. Os claims são conferidos de novo contra `partner_accounts`; sessão
  de antes da 039 reconstrói pelo único vínculo do usuário.
- **Bloqueio vale em 15 minutos:** conta bloqueada não renova (403).
- **Sair revoga no servidor** (`api/v1/auth/logout.php`, 204 sempre).
- **Acompanhamento ao vivo:** o `EventSource` reconecta com a mesma URL, e o
  token dela vence. No 401 o navegador desiste de vez; agora a tela refaz a
  conexão com o token atual.

## Achado no caminho

- **O painel pedia a fila de impressão sem parar.** O efeito do
  `PrinterPanel` chamava `tick()`, que lê e escreve `busy`; cada fim de
  requisição disparava o efeito de novo. Medido no navegador: cerca de 50
  pedidos em 10 s por tablet. Com `untrack`, 4 em 20 s (um a cada 5 s, como
  pretendido). Admin, cliente e entregador foram medidos ou revisados e não
  têm o mesmo padrão.

## Como foi conferido

- `smoke_partner_device.sh`:
  - a loja renovada continua com `restaurant_id` e o painel aceita o token;
  - sessão sem claims reconstrói;
  - conta bloqueada recebe 403;
  - depois de sair, o refresh dá 401.
- `smoke_courier.sh`: o entregador renovado continua sendo ele.
- No navegador:
  - token vencido no painel é renovado sozinho e a tela segue;
  - sessão revogada no servidor volta pro login com aviso.
