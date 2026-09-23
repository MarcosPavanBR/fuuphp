<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// GET /v1/push/config.php — a chave pública VAPID (tela 7.2).
//
// É o `applicationServerKey` que o navegador exige pra criar a assinatura
// de push. Pública por definição: não autentica ninguém, só diz "estes
// avisos vêm deste servidor". Sem chave gerada, `enabled` vem false e a
// tela de configurações diz que o push não está ligado neste servidor, em
// vez de mostrar um botão que falharia.

require_method('GET');

$key = push_public_key();

json_response(200, [
    'enabled' => $key !== null,
    'public_key' => $key,
    'mode' => push_mode(),
]);
