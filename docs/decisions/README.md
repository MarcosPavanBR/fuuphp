# Decisões por módulo

Cada arquivo registra as decisões de um módulo: o que a tela do mock pedia, o
que foi feito, o que foi simplificado e por quê, e o que não foi validado. Eram
seções do README, escritas à medida que cada módulo ficava pronto; a numeração
é só a ordem deste índice. Um texto descreve o estado da época em que foi
escrito: quando algo mudou depois, o arquivo mais novo diz, e o antigo aponta
pra ele (ex.: o mapa do acompanhamento em 06 → 16).

- [00 — Histórico de validação](00-historico-de-validacao.md)
- [01 — Módulo identity](01-identity.md)
- [02 — Módulo catalog+ordering+checkout](02-catalogo-pedido-checkout.md)
- [03 — Módulo de descoberta](03-descoberta.md)
- [04 — Módulo de carrinho](04-carrinho.md)
- [05 — Módulo de pagamentos](05-pagamentos.md)
- [06 — Módulo de acompanhamento pós-pedido](06-acompanhamento.md)
- [07 — Módulo de conta](07-conta.md)
- [08 — Front-end (`web/`) — Fases 1 a 6, 7.1, 8, 9, 10, 12, 13, 14, 15.1, decisões](08-front-end.md)
- [09 — Login e cadastro (Fase 10.1 a 10.3)](09-login-e-cadastro.md)
- [10 — Caminho do erro (Fase 13.1 e 13.2)](10-caminho-do-erro.md)
- [11 — Ocorrência na entrega e console de reembolso (Fase 13.3 e 13.4)](11-ocorrencia-e-reembolso.md)
- [12 — Maquininha, conciliação e netting semanal (9.6, 9.7, 10.4, 10.6)](12-maquininha-conciliacao-netting.md)
- [13 — Despacho em rodadas (Fase 15)](13-despacho-em-rodadas.md)
- [14 — Pontas de dinheiro: livro do pedido, estornos executados, CSV](14-pontas-de-dinheiro.md)
- [15 — Troca de método, gorjeta cobrada e Pix automático (migração 025)](15-troca-de-metodo-gorjeta-pix-automatico.md)
- [16 — Lacunas do app do cliente fechadas (migração 026)](16-lacunas-do-app-do-cliente.md)
- [17 — Painel da plataforma (Fase 12 + tela 10.5)](17-painel-da-plataforma.md)
- [18 — PWA e modo offline (Fase 7.1)](18-pwa-offline.md)
- [19 — Push (Fase 7.2 + migração 024)](19-push.md)
- [20 — Chat do pedido e cupons (Fase 14.2 + migração 008)](20-chat-e-cupons.md)
- [21 — Entrada de entregador e campanhas (Fase 15.2 e 15.3)](21-entrada-de-entregador-e-campanhas.md)
- [22 — Pedido agendado (Fase 14.4)](22-pedido-agendado.md)
- [23 — Endereço, área de entrega e frete no servidor (Fase 14.3)](23-endereco-area-e-frete.md)
- [24 — Central de ajuda (Fase 14.1)](24-central-de-ajuda.md)
- [25 — A loja operando a si mesma (Fase 11.2 a 11.4)](25-loja-operando-a-si-mesma.md)
- [26 — Sem entregador disponível (Fase 15.1)](26-sem-entregador.md)
- [27 — App do entregador e caixa (Fase 8 e 9)](27-app-do-entregador-e-caixa.md)
- [28 — Painel da loja (`web/painel.html`) — Fase 7.3 e 11.1 a 11.4](28-painel-da-loja.md)
- [29 — Baixa de espécie por Pix (tela 9.5)](29-baixa-por-pix.md)
- [30 — Cartão salvo, tokenização no navegador e total com frete](30-cartao-salvo-e-total-com-frete.md)
- [31 — Impressão ESC/POS e recibo de baixa (4.5, 7.3, 9.3, 9.4, 11.1)](31-impressao-escpos-e-recibo-de-baixa.md)
- [32 — Fidelidade (tela 2.3)](32-fidelidade.md)
- [33 — Go-live: do simulado ao real](33-go-live.md)
- [34 — Código de login por SMS pela Twilio](34-otp-twilio.md)
- [35 — Limite de tentativas no login de parceiro](35-limite-no-login-de-parceiro.md)
- [36 — Cadastro de loja, chave Pix, saúde e pg_cron](36-cadastro-de-loja-e-saude.md)
- [37 — Cidades atendidas e os números do dia a dia](37-cidades-e-numeros.md)
- [38 — Revisão do código: fuso, cupom, gorjeta e cidades](38-revisao-do-codigo.md)
