<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Tela 2.3 — Fidelidade. "Saldo calculado no banco (soma dos lançamentos),
// nunca no cliente."
//
//   GET                  saldo, a próxima meta ("Faltam 260 pontos para o
//                        cupom de R$ 20"), as trocas com o que já dá, o
//                        histórico e os cupons de pontos ainda não usados.
//   POST {reward_id}     troca pontos por um cupom pessoal (lib/account/loyalty.php);
//                        409 not_enough_points se o saldo não dá.

$claims = require_auth();
if (($claims['role'] ?? null) !== 'customer') {
    error_response(403, 'forbidden', 'Fidelidade é da conta de cliente.');
}
$userId = (string) $claims['sub'];
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(200, loyalty_summary($pdo, $userId));
}

require_method('POST');
$body = read_json_body();
$rewardId = positive_id($body['reward_id'] ?? null) ?? 0;
if ($rewardId <= 0) {
    error_response(422, 'reward_id_required', 'Escolha uma troca.', fields: ['reward_id' => 'obrigatório']);
}

$coupon = loyalty_redeem($pdo, $userId, $rewardId);
json_response(201, ['coupon' => $coupon, 'summary' => loyalty_summary($pdo, $userId)]);
