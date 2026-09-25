# 45 — Correções da auditoria 360°

**De onde veio:** a auditoria de 25/09/2026. Depois do lote rápido
([44](44-lote-rapido-da-auditoria.md)), o Marcos pediu "corrija tudo que
foi localizado na auditoria". Este documento junta o porquê de cada
correção; o como está no código, comentado, e nos testes.

Regra seguida em todos: nada de biblioteca, SDK ou serviço novo. O que
precisaria de um ficou listado no fim, esperando o Marcos.

## PERF-01: Home em consultas fixas

A lista da Home fazia uma consulta de política, fila e horário **por loja**:
~230 consultas com 30 lojas, crescendo junto com a cidade.
`resolve_policies()` (`lib/catalog/policy.php`) resolve a política de
várias lojas de uma vez, com o mesmo resultado de `resolve_policy()`, que
passou a usar ela. Com a fila da cozinha em lote, a Home faz 9 consultas,
qualquer que seja o número de lojas.

`restaurants/list.php` ficou paginada (`limit` 40, até 200; `offset`;
`has_more`; desempate por id pra não repetir nem pular loja entre páginas)
e aceita `?ids=` pras favoritas. A Home ganhou "Ver mais lojas".

## SEG-02: Content-Security-Policy

Sem CSP, qualquer injeção de HTML que escapasse viraria script rodando com
a sessão do usuário. O Nginx manda a política completa: script só do nosso
domínio e do Mercado Pago, conexão com a API, o Mercado Pago e o ViaCEP,
imagem do mapa do OpenStreetMap, e `object`, `base` e `form-action`
travados. As violações chegam em `system/csp_report.php`, logadas sem query
string.

Conferido com os quatro apps servidos pelo Nginx de produção: nenhum
bloqueio. **Falta conferir o formulário de cartão do Mercado Pago de
verdade**, o que só dá na homologação (passo no GO_LIVE).

## Fuzz de todas as rotas

`tests/smoke_fuzz.sh` manda entrada malformada (tipo trocado, uuid
inválido, texto no lugar de lista) pra todas as rotas, como anônimo,
cliente, loja, entregador e admin: 1.860 chamadas. Nenhuma pode responder
5xx. Achou 7 rotas que davam 500 com erro do PostgreSQL vazando; agora
respondem 422. Está no CI: rota nova entra no fuzz sozinha.

## Acompanhamento ao vivo sem access token na URL

Fechado no [44](44-lote-rapido-da-auditoria.md#seg-03-token-do-acompanhamento-fora-do-log):
ticket de 5 minutos que só abre o acompanhamento de um pedido.

## NEG-01: cupom de primeiro pedido, um por casa

CPF gerado e chip novo repetiam o "primeiro pedido" na mesma casa, sem
limite. O checkout recusa o cupom de primeiro pedido quando outra conta já
usou um no mesmo endereço (mesmo CEP e número, ou a menos de ~50 m) e
grava o sinal `address_reuse` (migração 040).

O sistema já gravava sinais de fraude que nenhuma tela mostrava. Agora o
painel "Sinais de fraude", na aba Ocorrências, mostra os sinais com o
telefone mascarado.

## ARQ-01: um caminho só de checkout

`orders/create.php` era um segundo checkout, sem cupom, carteira nem
agendamento, que o app não usava mas qualquer um podia chamar. Foi
removido. O teste de pedidos passou a usar o caminho real (carrinho e
`orders/checkout.php`).

## COE-02: dinheiro em centavos

Somas em `float` erram na casa dos centavos quando se juntam milhares de
lançamentos (0,1 + 0,2). `lib/core/money.php` converte o texto do banco em
centavos inteiros sem passar por `float`. Todo lançamento do livro grava
com duas casas exatas, e as somas do netting e do dia da maquininha são
feitas em centavos.

## INFRA-01/02: backup conferido e erros visíveis

Um backup que nunca foi restaurado não prova que funciona. Toda semana, o
`backup.sh` restaura o dump num banco descartável e confere tabelas,
usuários, pedidos e lançamentos. O resultado vai pra `system_status`
(migração 041), e qualquer falha vira erro na lista do admin.

Erro não tratado da API e dos workers ficava só no log do servidor, que
ninguém abre. Agora ele vai também pra `app_errors`, agrupado por
impressão digital, sem token, query string nem número longo. O painel
"Saúde do sistema", na aba Relatórios, mostra os erros e avisa quando
passam dois dias sem backup.

**Falta:** a cópia fora do servidor (offsite) depende de o Marcos escolher
o destino.

## TST-01: testes do front sem biblioteca nova

A renovação de sessão (a parte mais delicada do front) tem 8 testes em
`node:test`, que já vem no Node 22. Eles foram conferidos por mutação:
tirar a lógica faz o teste falhar. `bin/check_front_routes.php` quebra o CI
se o front chamar uma rota que não existe mais. Os dois rodam no CI.

**Falta:** teste de tela com navegador de verdade (Playwright), que é
biblioteca nova e espera a autorização do Marcos.

## SEG-04: segundo fator do admin

Admin entrava só pelo SMS. Quem clonasse o chip do dono entrava no painel
que mexe em dinheiro, política e cadastro de todo mundo. O painel agora
tem um código de app autenticador (TOTP, RFC 6238), feito em PHP puro e
conferido contra os vetores da RFC (migração 042).

- É opcional por admin e se liga na aba Aparelhos. Com ele ligado, o SMS
  sozinho não entra.
- Código já usado não vale de novo, nem em dois logins simultâneos.
  Código errado conta nas mesmas 5 tentativas do SMS.
- Não há rota pra um admin desligar o fator de outro: seria o atalho de
  quem roubou uma sessão. Perdeu o celular do autenticador? A recuperação
  é no servidor ([OPERATIONS.md](../OPERATIONS.md#admin-perdeu-o-app-autenticador)).

Ficou opcional pra não trancar o admin fundador fora no primeiro deploy.
**Recomendação:** ligar em todo admin antes de abrir.

## O que depende do Marcos

| Item | Por quê |
|---|---|
| Destino do backup fora do servidor | serviço novo (S3, B2, outro servidor) |
| Playwright nos testes | biblioteca nova |
| Biblioteca de QR pro Pix manual | biblioteca nova |
| Login por e-mail | serviço de envio de e-mail |
