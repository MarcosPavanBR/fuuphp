# Pagar com cartão salvo, tokenização no navegador e total com frete

- **Cartão salvo paga (6.2 → 4.2).** "Pagar com ele ainda exige CVV e gera
  novo token de uso único." A tela de cartão mostra os salvos primeiro (o
  padrão já escolhido); a pessoa digita só o CVV. O token sai do navegador
  (`lib/services/mercadopago.js`: `mp.createCardToken({cardId, securityCode})`)
  e o servidor recebe o token + `saved_card_id`. `payments/pay.php` confere que
  o cartão é da pessoa e cobra com `payer.type = customer` (o que o Mercado
  Pago exige pra token de cartão salvo). O CVV nunca chega ao nosso servidor.
  O pagamento registra qual cartão foi usado, e a linha do tempo mostra o final
  certo.
- **Tokenização real no front.** Antes, a tela mandava o número do cartão como
  "token" (só funcionava porque o backend estava em modo fake). Agora
  `payments/config.php` diz o modo e a Public Key: em `live`, o SDK oficial
  (`sdk.mercadopago.com/js/v2`) é carregado e tokeniza cartão novo e salvo; em
  `fake`, um token de teste local, e a tela avisa "modo de teste · nenhuma
  cobrança real". **Não validado contra o MP real** (este ambiente não o
  alcança) -- a troca é de configuração (`MERCADOPAGO_PUBLIC_KEY`), não de
  código.
- **O total da tela é o total cobrado.** Achado ao testar o cartão salvo: o
  botão dizia "Pagar R$ 48,00" e o pedido cobrava R$ 54,00 -- o frete só
  entrava no checkout, porque o carrinho ainda não tem endereço. Agora, ao
  escolher o endereço, o fluxo cota o frete pelo mesmo cálculo do checkout
  (`addresses/quote.php`), soma ao total, mostra "Frete de R$ 6,00 incluído
  no total" e para ali mesmo se o endereço estiver fora da área. Depois do
  checkout vale o total do pedido (que já tem crédito de carteira e cupom de
  frete grátis aplicados).
