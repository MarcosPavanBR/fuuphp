# Despacho em rodadas (Fase 15)

- **O despacho era uma rodada só**, sem raio: todo entregador da praça via
  toda corrida. Agora a oferta sobe de rodada enquanto ninguém aceita
  (`DISPATCH_ROUNDS` em `lib/dispatch/dispatch.php`): 2 km → 4 km → 7 km com R$ 2 de
  surge → praça inteira com R$ 4. **Os números são parâmetros de operação,
  não do mock** — a especificação pede rodada, raio e surge sem fixar
  valores; ficam num lugar só pra ajustar.
- **`dispatch_attempts` finalmente recebe linha** (existia desde a migração
  007): uma por rodada, com raio, quantos candidatos havia dentro dele e o
  surge. Rodada pulada (ninguém perguntou durante uma janela) entra também —
  o registro é "por que não achou", e apagar o raio intermediário apagaria
  parte da resposta.
- **Posição do entregador** — `couriers/position.php`, UPSERT em
  `courier_positions` (UNLOGGED, migração 009) a cada 15 s, como a
  especificação manda; só com turno aberto. O app manda sem travar nada: se o
  GPS não responde, é fogo-e-esquece.
- **Quem vê a corrida** é quem está dentro do raio da rodada, medido da loja
  até a última posição. **Sem posição recente, só na última rodada** —
  desligar o GPS não pode dar prioridade sobre quem está perto.
- **Surge soma só a diferença** entre rodadas, por cima do turbo que o
  cliente pagou (tela 15.1), que não é tocado.
- **O bônus agora é pago.** Até aqui o turbo entrava na oferta e no total,
  mas a entrega só lançava o frete: o bônus ia pra lugar nenhum. Na entrega,
  `courier_payable` recebe o bônus inteiro, e a parte que o cliente não
  pagou (o surge) é `platform_expense`.
- **Quem move as rodadas:** a vitrine de ofertas avança na passagem (o app
  pergunta a cada 4 s), a tela do cliente também, e `bin/dispatch_rounds.php`
  no cron de minuto cobre a hora em que ninguém está perguntando.
- **A tela 15.1 do cliente** passou a dizer em que raio a busca está e se
  já há bônus pago por nós — o que é verdade, em vez de "aguarde".
