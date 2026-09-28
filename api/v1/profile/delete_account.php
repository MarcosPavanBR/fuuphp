<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 6.3 — "Excluir conta" (LGPD art. 18, VI).
//
//   GET   o que impede excluir agora (pedido em andamento, reembolso vivo)
//         e o saldo de carteira que seria perdido -- a tela mostra antes
//         de pedir confirmação.
//   POST  {"confirm": "EXCLUIR"} exclui. Com saldo de carteira, exige também
//         {"forfeit_wallet": true}: crédito é dinheiro, e ninguém perde
//         dinheiro por um toque sem ter lido.
//
// Excluir = anonimizar (ver lib/account/account_privacy.php e migração 026).

$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Conta de parceiro se encerra pelo suporte.');
}
$userId = (string) $claims['sub'];
$pdo = db();

$blockers = account_delete_blockers($pdo, $userId);
$walletBalance = wallet_balance($pdo, $userId);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(200, ['blockers' => $blockers, 'wallet_balance' => $walletBalance]);
}

require_method('POST');
$body = read_json_body();
if (($body['confirm'] ?? null) !== 'EXCLUIR') {
    error_response(422, 'confirmation_required', 'Digite EXCLUIR pra confirmar.', fields: ['confirm' => 'obrigatório']);
}
if ($blockers !== []) {
    error_response(409, $blockers[0]['code'], $blockers[0]['message']);
}
if ($walletBalance > 0 && ($body['forfeit_wallet'] ?? false) !== true) {
    error_response(409, 'wallet_balance', 'Você tem R$ ' . number_format($walletBalance, 2, ',', '.') . ' de crédito na carteira, que será perdido.');
}

account_anonymize($pdo, $userId);
json_response(200, ['deleted' => true]);
