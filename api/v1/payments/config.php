<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 4.2 — o que o navegador precisa pra tokenizar o cartão com
// MercadoPago.js: a Public Key (pública por definição; o Access Token fica só
// no PHP) e o modo. Em `fake` não há chave, e o app usa um token de teste
// local em vez de carregar o SDK -- o backend fake aceita qualquer token.

require_method('GET');

$publicKey = (string) env('MERCADOPAGO_PUBLIC_KEY', '');

json_response(200, [
    'mode' => mp_mode(),
    'public_key' => mp_mode() === 'live' && $publicKey !== '' ? $publicKey : null,
]);
