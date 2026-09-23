# Maquininha, conciliação e netting semanal (9.6, 9.7, 10.4, 10.6)

Servidor e telas construídos. Painel da loja: abas **Pagamentos** (10.4) e
**Conciliação** (9.6); app do entregador: **Maquininha** (10.6) e o campo de
NSU na entrega de pedido de maquininha; painel da plataforma: **Financeiro**
(9.7). O servidor roda contra Postgres real em `tests/smoke_machine.sh`; as
telas foram percorridas no Playwright de ponta a ponta (retirar, vender sem
NSU, completar NSU, devolver, a loja confirmar, importar extrato com
divergência, gerar lote e dar baixa), sem erro de console.

- **Custódia de dois lados de verdade.** A primeira versão fechava a
  custódia no "devolvi" do entregador — e o navegador mostrou o efeito: ela
  sumia das duas telas e a loja nunca via o botão de confirmar. Agora a
  custódia fica aberta até `confirmed_by`; a máquina só sai de novo depois da
  conferência no balcão (`device_taken`), e o entregador que já devolveu pode
  retirar outra enquanto isso.
- **Completar o NSU atualiza a mesma venda**, não cria uma segunda; NSU de
  outra venda é recusado (`nsu_already_used`, a `UNIQUE (acquirer, nsu)`).

- **"A máquina é do estabelecimento" (10.4) é a regra de contabilidade.**
  Venda na maquininha NÃO lança `courier_cash`: o dinheiro cai na adquirente
  da loja, e o entregador "não deve nada por essas vendas. Só o equipamento
  e os NSUs." Espécie vira dívida; maquininha vira conferência.
- **O esquema já existia quase todo** (`card_transactions`, `pos_devices`,
  `pos_custody`, `payouts`, `restaurant_payment_settings`, e na política
  `cash_ceiling`, `cash_settle_deadline`, `store_debit_dow`,
  `pos_return_deadline`, `allow_courier_own_pos`). A migração `022` cria só
  `acquirer_statements`: a memória de cada extrato importado ("Último
  extrato: 15/09 23:58 · 17 transações"), com `sha256` pra o mesmo arquivo
  não entrar duas vezes.
- **10.4 — formas de pagamento da loja** (`restaurants/payment_settings.php`):
  cada método vem com a consequência operacional escrita pelo servidor; o
  teto de dinheiro da loja não passa do teto da plataforma; loja com
  `online_only_until` vigente só enxerga e só consegue ligar as formas
  online. `restaurants/pos_devices.php` cadastra, desativa (nunca máquina na
  rua) e **confirma a devolução** — a segunda ponta da custódia.
- **10.6 — custódia** (`couriers/pos.php`): retirar grava posse com prazo da
  política; `pos_one_holder` impede a mesma máquina com duas pessoas, e quem
  já está com uma não pega outra. A venda é conferida contra o total do
  pedido na hora (`amount_mismatch`), e venda sem NSU entra mesmo assim —
  ela existiu, e é justamente ela que trava o fechamento do dia. Devolver
  não fecha sozinho: a loja confirma.
- **9.6 — conciliação** (`restaurants/reconciliation.php`): importa o CSV da
  adquirente e casa por NSU, com fallback valor+horário (janela de 30 min, só
  em linha ainda pendente). Divergência vira `disputes` com kind
  `nsu_divergent` — a fila do admin que já existe — e o dia só fecha sem
  nenhuma linha aberta. Linha do extrato sem venda informada fica como órfã:
  não se inventa venda. **Não há API de adquirente** ("conecte a API" da
  tela); o CSV é o caminho real.
- **9.7 — netting** (`admin/netting.php`): a coluna "a cobrar" é
  `store_receivable` da semana, direto do livro; "taxa já split" (cartão e Pix
  automático) é separada de "taxa em aberto" pra não cobrar duas vezes. Gerar
  usa a mesma `generate_weekly_payouts()` do pg_cron (idempotente). "Gerar
  lote de Pix" marca `sent` — **não há Pix em lote integrado**, a
  transferência é feita no banco com a lista. Baixa é **lançamento negativo
  de contrapartida** com origem `payout`, nunca edição, e quitar o débito
  tira a loja da trava de só-online.
- **Bloqueios automáticos** (`bin/apply_financial_blocks.php`, cron de hora
  em hora): `couriers.cash_blocked` e `restaurants.online_only_until` eram
  lidos por todo mundo e **escritos por ninguém**. A varredura liga e desliga
  os dois a partir da política — espécie acima do teto ou mais velha que o
  prazo de baixa; débito semanal vencido. Em PHP pelo mesmo motivo do
  auto-cancel da 15.1: a interpretação da política mora aqui.
- **Não modelado:** maquininha do próprio entregador. A política tem
  `allow_courier_own_pos`, mas `pos_devices` só pertence a loja; cadastrar
  máquina de entregador pede decisão de esquema, não um campo improvisado.
