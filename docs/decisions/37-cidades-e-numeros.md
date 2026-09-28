# 37 — Cidades atendidas e os números do dia a dia

## 1. A lista de cidades era do mock

**O que foi achado:** o onboarding do cliente (telas 1.2 e 1.3) e o cadastro de
loja liam um arquivo fixo no front (`web/src/lib/data/states.js`): sete
cidades de exemplo (São Paulo, Campinas, Santos, Sorocaba, Belo Horizonte,
Curitiba, Salvador) e contagens de "lojas ativas" inventadas no mock (SP 1.284,
MG 612...). Em qualquer outra cidade, nenhuma loja conseguia se cadastrar e
nenhum cliente conseguia escolher onde está; e no lançamento o cliente veria
"1.284 lojas" e acharia cinco.

**O que foi feito:**

- Migração 036: `service_cities` (código IBGE, nome, UF, centro em lat/lng,
  bairros, ligada/desligada).
- `GET cities/list.php`, público: só as cidades ligadas, agrupadas por UF,
  com a contagem **real** de lojas aprovadas. O service worker guarda a última
  cópia pra abrir offline.
- `admin/cities.php` e a aba **Cidades** do painel: ligar, editar, desligar.
  Desligar tira do app sem apagar nada. Toda mudança vai pro `audit_log`.
- Onboarding e cadastro de loja leem essa lista; o arquivo estático saiu. O
  cadastro de loja recusa cidade que não está ligada (`cidade ainda não
  atendida`).
- A localização do aparelho, na tela 1.3, dizia "Bairro identificado" e pegava
  o primeiro bairro da lista. Agora a posição de verdade vale pra ordenar as
  lojas por distância, e o bairro o cliente escolhe.
- Endereço sem cidade escolhida mandava a de Campinas como padrão; agora não
  manda nada e a API recusa.
- Teste: `tests/smoke_cities.sh`, e `smoke_store_signup.sh` com a cidade
  não atendida.

**Simplificado / fora:** o código IBGE e o centro da cidade são digitados pelo
admin (a tela diz onde achar). Consultar a API do IBGE seria um serviço novo,
e é uma operação de uma vez por cidade.

## 2. Ticket médio e os números do dia a dia

**Pedido do Marcos.** A aba Relatórios (12.3) ganhou, no período escolhido:

- **ticket médio** (o que o cliente paga por pedido: itens, frete e gorjeta,
  já com desconto), com o período anterior do mesmo tamanho ao lado;
- **pedidos por hora do dia** e **por dia da semana**, no horário de Brasília:
  é o que diz quando pôr mais entregador e quando vale campanha;
- **as 5 lojas que mais vendem**, com pedidos, vendas e ticket médio de cada;
- **clientes**: quantos compraram, quantos voltaram (2 pedidos ou mais) e
  quantos compraram pela primeira vez.

Tudo calculado no banco, sobre pedido pago. O teste (`smoke_admin.sh`) confere
ticket médio, total de pedidos, soma por hora e por dia e número de clientes
contra uma consulta direta ao banco.
