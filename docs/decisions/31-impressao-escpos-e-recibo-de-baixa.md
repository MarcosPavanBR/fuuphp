# Impressão ESC/POS e recibo de baixa (telas 4.5, 7.3, 9.3, 9.4, 11.1 · migração 028)

- **O que o mock pede.** "O valor [do troco] sai impresso em negrito na
  comanda" e "vai para a impressora térmica ESC/POS" (4.5); aprovar o Pix
  "imprime a comanda" (7.3); "ESC/POS recibo … via impressa ficou na loja"
  (9.3); "Impressora OK" no painel e no KDS. Nada disso existia.
- **Quem imprime é o tablet do balcão.** O servidor não alcança a impressora
  na rede da loja. Então ele gera os bytes (`lib/printing/escpos.php`: ESC @,
  página de código 860 pra "ção" sair certo, negrito, letra dupla, corte) e o
  painel entrega por **WebUSB** (Chrome no Android e no computador; impressora
  = interface USB de classe 7) ou **Web Serial**
  (`web/src/lib/services/thermalPrinter.js`). A permissão é pedida uma vez
  ("Conectar impressora USB") e reaberta sozinha depois. Sem USB (Safari,
  Firefox, ou impressora desligada), imprime pelo navegador o mesmo documento
  em texto na largura da bobina.
- **A fila é derivada dos dados, não uma tabela de trabalhos.** Pedido pago sem
  comanda impressa e baixa confirmada sem recibo impresso = pendente
  (`restaurants/print_queue.php`, janela de 12 h). `print_log` registra só o
  que saiu no papel. Tablet desligado não perde comanda: ela espera. Primeira
  via é única (índice parcial); reimpressão (botão no KDS) fica registrada.
- **Documento descrito uma vez** (`lib/printing/documents.php`), renderizado em
  bytes ou texto. A comanda tem itens, variações, observações, totais, como foi
  pago, **TROCO PARA R$ X em letra dupla + "LEVAR R$ Y DE TROCO"**, maquininha
  com crédito/débito, e endereço com referência. **O código de entrega do
  cliente não sai no papel**: é o segredo que prova a entrega (8.6), e papel
  fica largado no balcão. Largura é do aparelho: 48, 42 ou 32 colunas.
- **Recibo de baixa com "hash dos dois lados" (9.4).** A assinatura é um
  HMAC-SHA256 dos dados da baixa com chave derivada do segredo do servidor --
  não dá pra forjar recibo válido fora dele, e a mesma baixa sempre dá a mesma
  assinatura. Sai inteira no papel da loja e aparece no app do entregador
  (`couriers/settlements.php`); o teste confere que são iguais. O mock fala em
  "PDF assinado": aqui é recibo impresso + na tela com assinatura; PDF não foi
  gerado.
- **Tela 9.4 existe agora.** A tela do código (9.2) e a espera do comprovante
  Pix (9.5) consultam a baixa e viram o recibo sozinhas quando a loja
  confirma: quem recebeu e a que horas, saldo em espécie, ganhos a receber,
  próximo repasse (`courier_payout_dow` da política), "Recibo #BX-… ·
  assinatura …· via impressa ficou na loja". Divergência ou expiração avisa e
  volta.
- **Não validado com impressora física** (não há uma neste ambiente): os bytes
  são conferidos no teste (início, negrito, corte, acento em CP860, largura);
  o envio por USB depende do aparelho real.
