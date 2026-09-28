# Push (Fase 7.2 + migração 024)

- **O push é um "acorda" sem conteúdo, assinado com VAPID (RFC 8292).** Ao
  acordar, o service worker busca o texto em `push/pending.php`, usando o
  endpoint da própria assinatura como credencial (é segredo do navegador,
  URL longa e aleatória). Mandar o texto dentro do push exigiria cifrar o
  corpo por assinante (RFC 8291: ECDH + HKDF + AES-GCM) -- dá pra escrever
  com o openssl do PHP, mas é o tipo de erro que o navegador engole em
  silêncio. Assinatura dá pra conferir em teste (`tests/support/verify_vapid.php`
  verifica o JWT ES256 com a chave pública), cifra caseira não.
- **Origem é a outbox, "então nada se perde".** `bin/push_worker.php` é O
  publicador da `outbox` (migração 004): gera aviso para `order.paid` (só
  Pix -- "Pix confirmado 🎉 / A cozinha já começou o pedido #X"; cartão
  aprovado com a pessoa olhando a tela não precisa) e `order.delivering`
  ("Jonas saiu para entrega / Chega em torno de 20:35", estimativa a
  20 km/h, fuso de São Paulo; retirada no balcão não avisa) e marca toda
  linha como publicada, com aviso ou sem. O terceiro tipo, "Faltam 5 min
  para expirar", não é evento -- é relógio --, então sai de uma varredura
  dos pedidos em Pix manual perto do `verification_deadline`.
- **Uma notificação por fato.** Índices únicos em `(outbox_id, kind)` e,
  no prazo, por pedido: o worker pode rodar duas vezes sem avisar duas.
- **Preferências por aparelho (tela 6.3): status × pagamento × promoção.**
  Guardadas na assinatura (`push_subscriptions.want_*`), filtradas no envio
  e de novo no `pending.php`. Endpoint que responde 404/410 é apagado.
- **Modo `fake` por padrão (`PUSH_MODE`).** Este ambiente não alcança os
  serviços de push dos navegadores; o fake grava a notificação e conta o
  "acorda" sem sair da máquina. `PUSH_MODE=live` chama o endpoint de verdade.
  A chave fica num PEM fora da raiz servida (`VAPID_PRIVATE_KEY_FILE`,
  padrão `storage/vapid/private.pem`), gerado por
  `php bin/generate_vapid_keys.php` com permissão 0600 e que nunca
  sobrescreve uma chave existente (trocar a chave invalida todas as
  assinaturas).
- **Agendar:** `* * * * * php bin/push_worker.php` (cron do cPanel).
- **Não validado:** a entrega real por FCM/Mozilla/APNs, que depende de
  internet aberta. O teste (`tests/smoke_push.sh`) cobre assinatura,
  preferências, outbox → notificação, idempotência, o prazo, o `pending`
  e a validade criptográfica do JWT.

- **Validado com navegador real, offline de verdade.** Playwright registra o
  service worker, confere o manifest (`display: standalone`, tema `#CC2B1D`,
  três ícones), navega com rede, corta a rede com `setOffline(true)`,
  recarrega -- e o app abre, mostra a faixa "Você está offline — mostrando o
  que está salvo", lembra a praça e lista as lojas que estavam no cache.
