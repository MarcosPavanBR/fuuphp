# Painel da plataforma (Fase 12 + tela 10.5)

O quarto público do projeto, no quarto bundle (`admin.html`): quem opera o
negócio. Fecha três coisas que estavam em aberto -- loja nova não tinha como
ser aprovada, ocorrência de caixa não aparecia pra ninguém, e política só
mudava por `INSERT` na mão.

- **Admin não tem conta de parceiro: entra pelo mesmo OTP do cliente.** Loja e
  entregador logam por aparelho (CNPJ+senha, CPF+código, 2FA por device);
  admin é uma pessoa com conta, e o que muda é o papel no token. O front
  confere o papel só pra não deixar alguém preso numa tela que daria 403 em
  tudo -- quem barra de verdade é `require_admin()` a cada chamada.
- **Aprovar loja não é carimbo: é a trava de só-online sendo ligada.** "Loja
  nova nasce só-online por 30 dias -- a liberação de dinheiro e maquininha é
  consequência do histórico, não de negociação." Aprovar grava
  `online_only_until` com o prazo da política E limita
  `restaurant_payment_settings.methods` aos métodos online. É o que o
  checkout vai ler depois; não é conselho na tela.
- **Recusar exige motivo**, porque a loja precisa saber o que corrigir --
  `rejected_at`/`rejection_reason` entraram na migração `016`: antes, uma
  loja recusada era indistinguível de uma que ninguém tinha olhado ainda.
- **O alerta de sócio virou o sinal que este backend consegue dar de
  verdade:** CNPJ de mesma raiz (8 primeiros dígitos) já cadastrado. O mock
  fala em "sócio com histórico", mas não há base de sócios aqui, e um alerta
  de histórico feito a partir de nada seria pior que nenhum alerta.
- **Ocorrência agora existe como registro.** A divergência de caixa (tela 9.3)
  marcava a intenção como `disputed` e parava aí -- ninguém via. Agora abre
  uma linha em `disputes`, e o valor gravado é a DIFERENÇA, não o total: é
  ela que está em disputa.
- **`disputes.order_id` deixou de ser obrigatório** (migração `016`). Uma
  divergência de fechamento é entre um entregador e uma loja num conjunto de
  corridas, não num pedido -- exigir um pedido obrigaria a escolher um no
  chute, e número escolhido no chute é pior que campo vazio. Entraram
  `courier_id` e `restaurant_id`, com CHECK exigindo pelo menos um sujeito.
- **Resolver ocorrência é contrapartida no livro, nunca edição de saldo.** A
  tela pergunta de qual bolso sai o valor, e "ninguém — sem cobrança" é opção
  explícita, não o padrão escondido. Sem valor, resolver só fecha a
  ocorrência.
- **Política é versionada, não editada.** Salvar faz `INSERT` de uma versão
  nova em `platform_policies` (a PK é a versão), copiando o que não mudou da
  anterior. A versão velha continua existindo, e pedido já feito segue a
  política que ele congelou em `policy_snapshot`. O teste prova as duas
  coisas: a versão nova nasce e a anterior continua com os valores antigos.
- **Os relatórios mostram os quatro números que o mock escolheu**, e a
  escolha é o conteúdo: GMV, quanto do GMV depende de gente conferindo
  (Pix manual + dinheiro + maquininha), custo de entrega por pedido e perda
  por fraude como percentual. Onde o dado não sustenta o número, aparece "—".
- **Honestidade sobre os saldos:** só metade do livro existe. Baixa de
  espécie e ocorrência são lançadas; o crédito por pedido (comissão + frete
  que a loja devolve) é o netting semanal da tela 9.7, que não foi
  construído. A tela diz isso em vez de chamar um saldo pela metade de "a
  cobrar na terça" -- foi um achado de olhar o número renderizado, que
  aparecia negativo.
- **Exportação CSV e fechamento contábil (12.3) não foram construídos**, nem
  a aprovação de entregador (15.2) ou o painel de campanhas (15.3).
- **Validado com Postgres e navegador reais.** `tests/smoke_admin.sh` cobre o
  403 pra quem não é admin, a fila de análise, recusa sem motivo barrada,
  aprovação ligando só-online (conferido em
  `restaurant_payment_settings.methods`), decisão dupla barrada, fila de
  ocorrências por risco, a contrapartida caindo no livro com o valor certo,
  resolução dupla barrada, os relatórios e a política versionada com a
  anterior intacta. No Playwright: login por OTP, aprovar, resolver
  ocorrência cobrando do entregador e publicar uma versão nova de política.
