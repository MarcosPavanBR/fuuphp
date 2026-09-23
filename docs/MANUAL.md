# Manual de uso

Como cada público usa o FUUdelivery no dia a dia. São quatro apps no mesmo
endereço:

| App | Endereço | Pra quem |
|---|---|---|
| Cliente | `/` | quem pede comida (celular, instala como app) |
| Painel da loja | `/painel.html` | o balcão/cozinha do restaurante (tablet) |
| Entregador | `/entregador.html` | quem entrega (celular) |
| Painel da plataforma | `/admin.html` | o time do FUU (computador) |

Os números entre parênteses (ex.: 4.2) são as telas do mock original. As
regras de negócio por trás de cada uma estão em [decisions/](decisions/README.md).

---

## Cliente

### Entrar
- Na primeira vez, o cliente escolhe o estado e a cidade (1.x), entre as
  cidades que a plataforma liga na aba Cidades, cada uma com o número real de
  lojas. Pode deixar o app usar a localização do aparelho (as lojas mais perto
  aparecem primeiro) e escolhe o bairro. O app lembra a escolha.
- Pra pedir, entra com o **celular**: chega um SMS com um código de 6
  dígitos, válido por 5 minutos (10.1, 10.2). Não existe senha. Quem ainda não
  tem conta informa o nome e a conta é criada na hora.
- Errar o código 5 vezes trava aquele código; é só pedir outro. Mais de 3
  pedidos de código em 10 minutos também espera.

### Pedir
1. **Início / Buscar** (2.1, 2.2): as lojas abertas na cidade, com nota,
   tempo e frete reais, e a busca por prato ou loja.
2. **Loja** (3.1–3.3): o cardápio, as variações (tamanho, borda...) e o
   carrinho. Só dá pra ter carrinho em uma loja por vez.
3. **Cupom**: é digitado no carrinho. Vale um uso por CPF, então o cadastro
   precisa ter CPF. Cupom de frete grátis é descontado no fechamento, quando
   o endereço já definiu o frete.
4. **Endereço e pagamento** (4.1): o frete é calculado pelo endereço. Os
   meios de pagamento são os que a loja aceita:
   - **Cartão** (4.2): novo ou salvo. O cartão é tokenizado no próprio
     navegador (MercadoPago.js); o número não passa pelo nosso servidor.
   - **Pix automático:** QR e copia-e-cola do Mercado Pago. O pagamento se
     confirma sozinho.
   - **Pix direto pra loja** (4.3, 4.4): paga pela chave da loja e envia a foto
     do comprovante. A loja tem **15 minutos** pra conferir; se não conferir,
     o pedido é recusado e nada é cobrado. Sem internet, o comprovante fica na
     fila e sobe quando a conexão volta.
   - **Dinheiro:** informa de quanto precisa de troco. O troco máximo é
     definido pela loja.
   - **Maquininha:** o entregador leva a máquina.
5. **Agendar** (14.4): quando a loja aceita agendamento, dá pra escolher um
   horário. Em dinheiro e maquininha nada é cobrado até a entrega; em cartão e
   Pix a cobrança é no fechamento, e a tela avisa.

### Acompanhar
- **Pedido** (5.x): a linha do tempo atualiza sozinha. Depois que sai pra
  entrega, o mapa mostra o entregador.
- **Código de entrega:** 4 dígitos que o cliente passa ao entregador na porta.
  Só com ele (ou com uma foto da entrega) o pedido fecha.
- **Conversa** (14.2): cliente, loja e entregador na mesma conversa do
  pedido.
- **Sem entregador** (15.1): se ninguém aceitar a corrida, o cliente pode
  turbinar o frete, retirar na loja ou cancelar com devolução integral. Passado
  o prazo, o sistema cancela e devolve sozinho.
- **Cancelar:** antes de a cozinha começar, sem taxa; depois, pode haver a
  taxa de cancelamento da política. A tela mostra antes quanto volta e por qual
  caminho (cartão: estorno; Pix: Pix de volta; dinheiro: nada foi cobrado).
- **Avaliar** (5.5): nota e comentário, e gorjeta opcional pro entregador.

### Conta (aba Perfil)
- **Fidelidade** (2.3): 1 ponto por real de subtotal, creditado na entrega. Os
  pontos são trocados por cupons pessoais (R$ 10, R$ 20, entrega grátis). Um
  estorno tira os pontos do pedido.
- Endereços, cartões salvos, recibos, dados pessoais.
- **Configurações** (6.x): notificações, e os direitos da LGPD:
  - **baixar meus dados** (arquivo com tudo que a plataforma tem da pessoa);
  - **excluir minha conta** (anonimiza; pedidos ficam só pro fisco, sem dado
    pessoal).
- **Ajuda** (14.1): responde sozinha "cadê meu pedido", "e o meu Pix" e "cadê
  meu estorno" com o estado real. Se não resolver, abre chamado.

---

## Loja (painel)

### Cadastrar a loja
Em **painel.html**, no login, **Cadastre sua loja**: dados da loja (nome,
CNPJ, categoria, endereço), quem responde por ela, a chave Pix da loja, a
senha e o aceite dos termos. A loja entra no painel na hora, com o aviso
**Em análise**: dá pra montar o cardápio e o horário, mas ela ainda não
aparece pros clientes. Aprovada pela plataforma, começa a vender (só online
nos primeiros 30 dias). Recusada, o painel mostra o motivo.

A chave Pix precisa ser da própria loja: chave aleatória, e-mail, telefone ou
o CNPJ dela. CPF não é aceito.

### Entrar
- **CNPJ + senha** no tablet do balcão. O painel fica preso a esse
  aparelho. Tablet novo ou quebrado: o suporte do FUU libera a troca.
- 5 senhas erradas seguidas travam o login por 15 minutos.

### Abas
| Aba | Pra quê |
|---|---|
| **Cozinha** (7.3, 11.1) | a fila de pedidos (KDS): aceitar, preparar, marcar pronto ou recusar com motivo. Comprovantes de Pix esperando conferência aparecem em destaque, com o prazo |
| **Visão geral** | a fila de Pix, o resumo da cozinha e os números do dia |
| **Caixa** (9.3–9.5) | dinheiro que os entregadores trouxeram: confirmar a baixa presencial ou conferir o comprovante de Pix da baixa; imprimir o recibo |
| **Loja** (11.2) | pausar e retomar a loja (ex.: cozinha lotada) |
| **Cardápio** (11.3) | itens, preços, variações, foto e disponibilidade |
| **Horário** (11.4) | dois turnos por dia, feriados e quantos pedidos agendados cabem por meia hora. Abrir e fechar é automático |
| **Pagamentos** (10.4) | a chave Pix da loja (onde cai o Pix direto do cliente; a troca fica registrada), quais meios a loja aceita e o troco máximo |
| **Conciliação** (9.6) | importar o extrato da maquininha (CSV) e casar com as vendas |
| **Cupons** (15.3) | criar e desligar cupons da própria loja, pagos pelo repasse dela, dentro do teto que a plataforma liberou |

### Conferir um Pix
Na Cozinha, o comprovante abre em tela cheia com o valor esperado. A loja
confere no extrato do banco dela e **aprova** (o pedido vai pra cozinha) ou
**recusa** com motivo (o cliente é avisado). O sistema já alerta se a mesma
imagem foi usada em outro pedido.

### Impressora
Com uma térmica USB (ESC/POS) no tablet, clique em **Conectar impressora**
no topo. A comanda sai quando o pedido entra na cozinha, e o recibo da baixa
de espécie sai pelo Caixa. Sem USB (Safari, Firefox), a impressão é pelo
navegador. A largura da bobina (58 ou 80 mm) é escolhida no mesmo lugar.

### Dinheiro da loja
A plataforma cobra comissão (8% na política inicial). Toda semana, na
terça, o sistema faz o acerto (netting): o que a loja vendeu online menos
comissão, cupons pagos por ela e ajustes. Loja em atraso fica **só
online** (dinheiro e maquininha suspensos) até acertar. Loja nova também
nasce só online nos primeiros 30 dias.

---

## Entregador

### Entrar na plataforma
1. **Candidatura** (15.2), em `/entregador.html`: dados, veículo, chave Pix
   (no próprio CPF) e fotos dos documentos.
2. A plataforma analisa e, aprovando, gera um **código de acesso de 6
   dígitos**, que é passado à pessoa uma vez.
3. **Login:** CPF + código de acesso. Fica preso ao celular; celular novo, o
   suporte libera. 5 códigos errados seguidos travam por 15 minutos.

### Trabalhar
- **Ofertas** (8.x): as corridas perto, com o valor. Sem ninguém aceitando, o
  raio cresce e o valor sobe (surge) em rodadas.
- **Corrida:** retirar na loja → entregar → **código de 4 dígitos** do
  cliente, ou foto da entrega se o cliente não puder informar.
- **Ocorrência** (13.3): cliente ausente, cliente sem dinheiro, endereço
  errado ou área insegura. O app registra a chegada, as ligações e a
  campainha, com foto e localização, e o pedido para até o suporte decidir.
- **Maquininha** (9.6): quando a plataforma entrega uma máquina, ela fica na
  custódia do entregador até a devolução.

### Dinheiro em espécie
- Pedido pago em dinheiro: o entregador recebe e fica devendo esse valor.
  O **teto** é de R$ 300 em mãos (política inicial). Passou do teto, não recebe
  novas corridas em dinheiro até dar baixa (o sistema confere de hora em
  hora).
- **Dar baixa** (9.1–9.5), no prazo (até o fim do dia seguinte), de dois
  jeitos:
  - **presencial** na loja: a loja confirma e imprime o recibo;
  - **por Pix pra loja**: envia o comprovante pelo app, e a loja confere.
- Fora do prazo: bloqueio de corridas em dinheiro até a baixa.

### Receber
- **Ganhos** (9.7): o que tem a receber na semana.
- Repasse toda **terça**: fretes, gorjetas e ajustes, menos o dinheiro em
  espécie ainda sem baixa.

---

## Plataforma (admin)

### Entrar
Pelo mesmo código por SMS do cliente, com o celular do admin. O primeiro
admin é criado no servidor com `bin/bootstrap_admin.php`
([GO_LIVE](GO_LIVE.md)).

### Abas
| Aba | Pra quê |
|---|---|
| **Lojas** (12.1) | aprovar ou recusar cadastro, com motivo. Mostra responsável, telefone, endereço e a chave Pix; chave que não é o CNPJ vem marcada **confira a titularidade**. Aprovada nasce só online por 30 dias. Alerta CNPJ de mesma raiz já cadastrado |
| **Ocorrências** | divergências de caixa e disputas: decidir quem arca, com lançamento no livro |
| **Reembolsos** (13.3, 13.4) | liberar pedidos parados por ocorrência e decidir devoluções. Depois de decidido, cartão volta pelo estorno do Mercado Pago e Pix volta por Pix pra chave de quem pagou, os dois executados sozinhos (`bin/execute_refunds.php`); maquininha é cancelada na adquirente da loja; dinheiro não tem o que estornar |
| **Financeiro** (9.7) | o acerto semanal de lojas e entregadores, repasses e bloqueios por atraso |
| **Relatórios** (12.3) | GMV, **ticket médio** (com o período anterior ao lado), pedidos por hora do dia e por dia da semana, as lojas que mais vendem, clientes que compraram, voltaram e compraram pela primeira vez, o mix de pagamento e o CSV contábil |
| **Campanhas** (15.3) | cupons da plataforma (quem paga, teto, público, projeção antes de criar) e o **teto de cupom de cada loja** |
| **Políticas** (10.5) | comissão, teto de espécie, prazos, frete e meios de pagamento. Mudar cria uma **versão nova**; pedido antigo continua com a regra do seu tempo |
| **Cidades** | as cidades onde o FUU opera: ligar, editar bairros, desligar. Só cidade ligada aparece no app e aceita cadastro de loja |
| **Aparelhos** | liberar troca de tablet/celular de loja e entregador, com motivo. Encerra as sessões do aparelho antigo |

### Rotina sugerida
- **Todo dia:** Lojas (cadastros novos), Ocorrências, Reembolsos.
- **Toda segunda:** Relatórios (7 dias): o ticket médio subiu ou caiu, em que
  hora está o pico (é quando precisa de mais entregador) e quantos clientes
  voltaram.
- **Terça:** Financeiro (o acerto roda sozinho às 3h de Brasília; conferir e
  pagar os repasses).
- **Toda semana:** conferir o backup (servidor) e os logs
  ([OPERATIONS](OPERATIONS.md)).
- **Sempre:** o monitor externo avisa se `api/v1/system/health.php` parar de
  responder `ok` ([GO_LIVE](GO_LIVE.md#monitoramento-saber-que-caiu-antes-do-cliente)).

Tudo que o admin muda (políticas, tetos, liberações de aparelho, decisões de
dinheiro) fica no registro de auditoria (`audit_log`), com quem, quando e o
valor anterior.
