<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 10.1 — por quais canais o código de login pode chegar agora.
//
// Depende do provedor configurado (lib/messaging/otp_sender.php): com a
// Twilio, SMS (e WhatsApp, se houver remetente aprovado), mas não e-mail. A
// tela esconde a aba "E-mail" quando o canal não existe, em vez de deixar a
// pessoa digitar e só então receber erro. Público: não diz nada sobre contas.

require_method('GET');

$channels = otp_sender_channels();

json_response(200, [
    'channels' => $channels,
    'phone' => in_array('sms', $channels, true) || in_array('whatsapp', $channels, true),
    'email' => in_array('email', $channels, true),
    'whatsapp' => in_array('whatsapp', $channels, true),
]);
