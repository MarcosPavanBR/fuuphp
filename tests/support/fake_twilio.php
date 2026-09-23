<?php
// Twilio falsa pros testes (tests/smoke_otp_twilio.sh), servida com
// `php -S ... tests/support/fake_twilio.php`. Grava cada pedido em
// FAKE_TWILIO_LOG (uma linha JSON) e responde conforme o arquivo
// FAKE_TWILIO_MODE:
//   ok    201 com um sid, como a Twilio quando aceita a mensagem;
//   fail  400 com o código 21211 (número inválido);
//   slow  demora 10 s (o cliente tem que desistir antes).
declare(strict_types=1);

$mode = trim((string) @file_get_contents((string) getenv('FAKE_TWILIO_MODE'))) ?: 'ok';
file_put_contents((string) getenv('FAKE_TWILIO_LOG'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'form' => $_POST,
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if ($mode === 'slow') {
    sleep(10);
}
if ($mode === 'fail') {
    http_response_code(400);
    echo json_encode(['code' => 21211, 'message' => "The 'To' number is not a valid phone number.", 'status' => 400]);
    return;
}
http_response_code(201);
echo json_encode(['sid' => 'SM' . bin2hex(random_bytes(16)), 'status' => 'queued', 'to' => $_POST['To'] ?? null]);
