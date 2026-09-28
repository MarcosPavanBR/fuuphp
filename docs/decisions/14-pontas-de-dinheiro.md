# Pontas de dinheiro: livro do pedido, estornos executados, CSV

- **O livro não tinha a linha principal.** `store_receivable` se mexia em
  cupom, estorno, ocorrência e baixa, mas nenhum pedido entregue dizia quanto
  a loja tinha a receber ou a pagar — a coluna "a cobrar" da 9.7 somava um
  livro incompleto. `lib/ledger/order_ledger.php` lança, na mesma transação da
  entrega (e da retirada no balcão), o acerto do pedido: **a plataforma passa
  a dever à loja a parte dela (subtotal − comissão); quem entregar o dinheiro
  à loja abate essa dívida.** Cartão e Pix automático: −parte (repasse).
  Pix manual e maquininha da loja: +(total − parte), porque o dinheiro caiu na
  conta dela. Dinheiro e maquininha do entregador: −parte na entrega, e a
  baixa no balcão lança +total. Depois da baixa sobra exatamente comissão +
  frete + gorjeta — o teste confere essa igualdade.
- **Correção de sinal na baixa de espécie (9.3).** A versão anterior lançava
  `store_receivable −total` na baixa e nada na entrega: o saldo da loja
  ficava negativo pra sempre. Pela frase da tela ("o dinheiro do pedido em
  espécie é seu — o entregador é apenas portador"), a baixa PAGA a parte da
  loja: `+total`.
- **A gorjeta agora vai pro entregador** (`courier_payable`, origem
  `tip:<pedido>`). Antes ela entrava no total e não saía pra ninguém.
- **Estorno de pedido nunca entregue não cobra a loja.** `refund_ledger`
  cobrava a loja pelo valor inteiro do estorno mesmo quando ela nunca tinha
  recebido nada. Agora depende de duas perguntas — o pedido foi entregue? o
  dinheiro está com quem? Antes da entrega, o custo real é a taxa ("fica com
  a loja") e a comida já feita ("FUUDelivery paga tudo, inclusive a comida
  produzida", quando o pagador inclui a plataforma e a cozinha tinha
  começado). O teste da 13.4 que esperava a loja pagando R$ 66 de um pedido
  cancelado em preparo foi corrigido com a explicação.
- **Executor de estornos** (`lib/payments/refund_executor.php` +
  `bin/execute_refunds.php`, cron a cada minuto): cartão e Pix automático vão
  pra `POST /v1/payments/{id}/refunds` do Mercado Pago com o `refund_key`
  como chave de idempotência. Falha vira `last_error`; três falhas, `failed`,
  que aparece na fila "em execução" do console com "Tentar de novo". Pix
  manual e maquininha não passam pela nossa conta: ficam esperando
  confirmação humana **com referência obrigatória** (E2E do Pix, protocolo da
  adquirente). `FOR UPDATE SKIP LOCKED` deixa duas instâncias rodarem juntas.
- **Exportação contábil (12.3)** — `admin/export.php`: livro, pedidos e
  acertos, CSV com `;`, vírgula decimal e BOM UTF-8 (abre certo no Excel em
  português). O front baixa com o token no cabeçalho, nunca na URL.
- **Foto da ocorrência no painel** — `admin/incident_photo.php`, mesmo
  desenho do comprovante de Pix: disco privado, rota autenticada, blob no
  navegador, `Cache-Control: private, no-store`. Foto apagada pela retenção
  de 180 dias aparece como tal, não como erro.
- **Maquininha do próprio entregador** (migração 023): `pos_devices` passa a
  ter UM dono — loja ou entregador (`CHECK`). Só com
  `allow_courier_own_pos` na política. A venda nela lança `courier_cash`
  (dinheiro na conta dele, dívida com a loja) uma vez só — completar o NSU
  depois não cobra de novo — e não aparece na conciliação da loja, que confere
  o extrato da adquirente DELA.
- **O que continua sem integração real:** a chamada ao Mercado Pago roda em
  modo `fake` neste ambiente (sem credencial); em produção, com
  `MERCADOPAGO_ACCESS_TOKEN`, o mesmo código chama a API. O split
  (`application_fee`) não é usado: sem ele o dinheiro online cai na conta da
  plataforma, e é exatamente isso que o livro do pedido registra.
