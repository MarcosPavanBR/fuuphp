<?php

declare(strict_types=1);

// Confere um JWT VAPID (tela 7.2) do jeito que o serviço de push confere:
// cabeçalho ES256, audiência = origem do endpoint, validade <= 24 h, e a
// assinatura r||s batendo com a chave pública -- convertida de volta pra
// DER, que é o que o openssl_verify entende.
//
//   php tests/support/verify_vapid.php <endpoint>
// Sai 0 e imprime "ok" se tudo confere.

require_once __DIR__ . '/../../lib/bootstrap.php';

$endpoint = $argv[1] ?? 'https://push.example.test/abc';
$key = push_private_key();
if ($key === null) {
    fwrite(STDERR, "sem chave VAPID\n");
    exit(1);
}

$jwt = push_vapid_jwt($endpoint, $key);
[$h, $p, $sig] = explode('.', $jwt);
$header = json_decode(base64url_decode($h), true);
$payload = json_decode(base64url_decode($p), true);
if (($header['alg'] ?? '') !== 'ES256') {
    fwrite(STDERR, "alg errado\n");
    exit(1);
}
$origin = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
if (($payload['aud'] ?? '') !== $origin || ($payload['exp'] ?? 0) > time() + 86400 || !str_starts_with((string) ($payload['sub'] ?? ''), 'mailto:')) {
    fwrite(STDERR, "claims erradas: " . json_encode($payload) . "\n");
    exit(1);
}

$raw = base64url_decode($sig);
if (strlen($raw) !== 64) {
    fwrite(STDERR, "assinatura não tem 64 bytes (r||s)\n");
    exit(1);
}
$int = static function (string $bytes): string {
    $bytes = ltrim($bytes, "\0");
    if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
        $bytes = "\0" . $bytes;
    }

    return "\x02" . chr(strlen($bytes)) . $bytes;
};
$body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
$der = "\x30" . chr(strlen($body)) . $body;

$details = openssl_pkey_get_details($key);
$ok = openssl_verify($h . '.' . $p, $der, $details['key'], OPENSSL_ALGO_SHA256);
if ($ok !== 1) {
    fwrite(STDERR, "assinatura não confere\n");
    exit(1);
}

// A chave pública entregue ao navegador tem de ser o mesmo ponto.
$pub = base64url_decode((string) push_public_key());
if (strlen($pub) !== 65 || $pub[0] !== "\x04") {
    fwrite(STDERR, "chave pública fora do formato 0x04||X||Y\n");
    exit(1);
}
echo "ok\n";
