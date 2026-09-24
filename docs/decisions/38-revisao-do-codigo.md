# 38 — Revisão do código: fuso, cupom, gorjeta e cidades

Revisão pedida pelo Marcos antes do lançamento. Duas passadas: a ferramenta
de revisão no último lote (cidades e relatórios) e uma auditoria do projeto
inteiro por área de risco.

## O que a auditoria conferiu e estava certo

- **Toda rota confere quem chama.** As 17 públicas são públicas de propósito
  (login, vitrine, cadastro de loja, webhook, saúde, configuração pública);
  `orders/track.php` confere o login por cabeçalho ou query, e
  `push/pending.php` usa o endpoint da assinatura como credencial
  (documentado na própria rota).
- **Ninguém lê ou mexe no que é de outro** trocando um id: pedido, conversa,
  candidatura de entregador e baixa de espécie filtram pelo usuário, loja ou
  entregador do token.
- **SQL:** nenhuma consulta monta texto com entrada da requisição; as partes
  dinâmicas vêm de listas fixas do código.
- **Dinheiro:** valores do cliente têm faixa (turbo até R$ 50, preço, troco,
  baixa contra o saldo real); o webhook confere a assinatura e, em produção,
  pergunta o status ao Mercado Pago em vez de confiar no corpo.
- **Front:** nenhum `{@html}` (sem porta pra injetar HTML). **Token:** a
  assinatura é sempre HMAC-SHA256, seja qual for o `alg` do cabeçalho.
- **Nenhum segredo** nos arquivos do repositório.

## O que foi corrigido

1. **Fuso horário (o mais sério).** O PHP não tinha fuso configurado e usava
   o do servidor (UTC). Loja aberta das 11h às 23h oferecia faixa de
   agendamento das 8h às 20h; depois das 21h o "hoje" já era amanhã; a hora
   carimbada no comprovante de Pix saía 3 horas adiantada. Agora o PHP
   (`lib/bootstrap.php`) e a sessão do banco (`lib/core/db.php`) usam
   America/Sao_Paulo (e, desde a [40](40-fuso-por-cidade.md), o relógio de
   cada loja é o fuso da cidade dela). O teste de agendamento passou a comparar instantes, não
   texto, e confere que "hoje" é o dia de Brasília -- ele falhava só entre
   21h e meia-noite de Brasília, e por isso passava no CI.
2. **Cupom reutilizável trocando o CPF.** "Um uso por CPF", e o CPF se troca
   no perfil: usar, trocar, usar de novo passava. Agora é um uso por CPF **e**
   por conta (`coupon_used_by`), conferido ao aplicar e de novo no
   fechamento. Teste em `smoke_support.sh`.
3. **Gorjeta sem teto no pedido.** A da avaliação tinha teto de R$ 200; a do
   fechamento não. Mesmo teto nos três lugares (`TIP_MAX`). Teste em
   `smoke_support.sh`.
4. **Cidades** (achados da ferramenta de revisão no lote da 37):
   - a posição do aparelho no onboarding virava a coordenada de endereço
     novo; agora fica à parte (`location.near`) e só ordena lojas;
   - cidade desligada continuava pra quem já tinha escolhido; o app confere a
     cidade salva ao abrir e pede pra escolher de novo;
   - resposta de GPS atrasada de uma escolha anterior gravava posição na
     escolha atual;
   - endereço sem cidade ainda mandava "Campinas" e as coordenadas de lá;
   - latitude em branco virava 0 (o Equador) e passava;
   - salvar a edição de uma cidade religava uma cidade desligada no meio;
   - UF que não bate com o código IBGE (35 = SP) passava;
   - o rótulo do ticket médio mostrava o período novo com o número do antigo
     enquanto carregava.

## O que ficou como está

- A tabela de cidades nasce vazia na migração 036: o banco de produção começa
  limpo, e ligar a cidade é passo do GO_LIVE.
- Os relatórios fazem algumas consultas a mais sobre `orders` por abertura da
  aba; no volume do lançamento isso é irrelevante. Juntar em uma consulta fica
  pra quando a aba ficar lenta.
- Continua valendo o "Limites conhecidos" do [SECURITY.md](../SECURITY.md):
  não houve teste de invasão externo.
