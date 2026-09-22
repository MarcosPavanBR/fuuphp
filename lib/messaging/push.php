<?php
declare(strict_types=1);

// Tela 7.2 — notificações push.
//
// "Três tipos que importam: aprovação, saiu para entrega e prazo acabando.
// Origem é a outbox, então nada se perde."
//
// DESENHO: o push que sai daqui é um "acorda" SEM conteúdo, assinado com
// VAPID (RFC 8292). Ao acordar, o service worker busca o texto em
// `push/pending.php` usando o próprio endpoint da assinatura como
// credencial. Por quê: mandar o texto DENTRO do push exige cifrar o corpo
// com ECDH + HKDF + AES-GCM por assinante (RFC 8291). Dá pra fazer com o
// openssl do PHP, mas é criptografia escrita à mão num lugar onde erro não
// aparece em teste -- o navegador só descarta a mensagem em silêncio. O
// "acorda + busca" usa só assinatura (mais simples de conferir) e o texto
// viaja pelo mesmo HTTPS do resto da API.
//
// MODOS (PUSH_MODE): 'fake' (padrão) registra o envio sem sair da máquina --
// este ambiente não alcança os serviços de push dos navegadores; 'live'
// chama o endpoint de verdade. A chave VAPID mora num arquivo PEM fora da
// raiz servida (VAPID_PRIVATE_KEY_FILE), gerado por bin/generate_vapid_keys.php.

// Quanto tempo o serviço de push segura o "acorda" se o aparelho estiver
// desligado. Uma hora: "saiu para entrega" que chega três horas depois é
// ruído, não aviso.
const PUSH_TTL_SECONDS = 3600;

// Tipo de notificação → preferência da assinatura (tela 6.3: status ×
// pagamento × promoção, separados pra ninguém desligar tudo).
const PUSH_KIND_PREF = [
    'payment_approved' => 'want_payment',
    'proof_deadline' => 'want_payment',
    'out_for_delivery' => 'want_status',
    'order_status' => 'want_status',
    'promotion' => 'want_promotion',
];

function push_mode(): string
{
    $mode = (string) env('PUSH_MODE', '');

    return $mode === 'live' ? 'live' : 'fake';
}

function push_key_path(): string
{
    $path = (string) env('VAPID_PRIVATE_KEY_FILE', 'storage/vapid/private.pem');

    return app_path($path);
}

/** A chave privada VAPID, ou null se ainda não foi gerada. */
function push_private_key(): ?OpenSSLAsymmetricKey
{
    $path = push_key_path();
    if (!is_file($path)) {
        return null;
    }
    $key = openssl_pkey_get_private((string) file_get_contents($path));

    return $key === false ? null : $key;
}

function base64url_encode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function base64url_decode(string $text): string
{
    return (string) base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4));
}

/**
 * A chave pública VAPID no formato que o navegador pede em
 * `pushManager.subscribe({applicationServerKey})`: ponto P-256 não
 * comprimido (0x04 || X || Y), base64url.
 */
function push_public_key(): ?string
{
    $key = push_private_key();
    if ($key === null) {
        return null;
    }
    $ec = openssl_pkey_get_details($key)['ec'] ?? null;
    if ($ec === null) {
        return null;
    }

    return base64url_encode("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
}

/**
 * Converte a assinatura ECDSA do openssl (DER: SEQUENCE { r, s }) no formato
 * que o JWT ES256 exige: r || s, 32 bytes cada. É o detalhe que mais
 * derruba implementação caseira de VAPID -- DER tem tamanho variável.
 */
function push_der_to_raw_signature(string $der): string
{
    $offset = 2;
    if ((ord($der[1]) & 0x80) !== 0) {
        $offset += ord($der[1]) & 0x7f;
    }
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $length = ord($der[$offset + 1]);
        $value = substr($der, $offset + 2, $length);
        $parts[] = str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
        $offset += 2 + $length;
    }

    return $parts[0] . $parts[1];
}

/**
 * O JWT VAPID (RFC 8292): quem está mandando (`sub`), pra qual serviço de
 * push (`aud`, a origem do endpoint) e até quando vale (`exp`, no máximo 24 h).
 */
function push_vapid_jwt(string $endpoint, OpenSSLAsymmetricKey $key): string
{
    $parts = parse_url($endpoint);
    $audience = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
    $header = base64url_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = base64url_encode((string) json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => (string) env('VAPID_SUBJECT', 'mailto:suporte@fuudelivery.com.br'),
    ]));
    $signingInput = $header . '.' . $payload;
    openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256);

    return $signingInput . '.' . base64url_encode(push_der_to_raw_signature($der));
}

/**
 * Manda o "acorda" pra uma assinatura. Devolve 'sent', 'gone' (o aparelho
 * não existe mais: 404/410 -- a assinatura deve ser apagada), 'failed' ou
 * 'fake'.
 */
function push_send_tickle(string $endpoint): string
{
    $key = push_private_key();
    if (push_mode() !== 'live' || $key === null) {
        return 'fake';
    }

    $jwt = push_vapid_jwt($endpoint, $key);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'TTL: ' . PUSH_TTL_SECONDS,
            'Urgency: high',
            'Content-Length: 0',
            'Authorization: vapid t=' . $jwt . ', k=' . push_public_key(),
        ],
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return match (true) {
        $status >= 200 && $status < 300 => 'sent',
        in_array($status, [404, 410], true) => 'gone',
        default => 'failed',
    };
}

/**
 * Enfileira uma notificação e acorda os aparelhos da pessoa que querem
 * aquele tipo. Idempotente por (outbox_id, kind) e, no prazo do
 * comprovante, por pedido -- os índices únicos da migração 024 decidem.
 *
 * Devolve quantos aparelhos foram acordados, ou null se a notificação já
 * existia (nada novo a avisar).
 */
function push_notify(PDO $pdo, string $userId, string $kind, string $title, string $body, ?int $orderId, ?int $outboxId): ?int
{
    $insert = $pdo->prepare(
        'INSERT INTO notifications (user_id, kind, title, body, order_id, outbox_id)
         VALUES (:user, :kind, :title, :body, :order, :outbox)
         ON CONFLICT DO NOTHING
         RETURNING id'
    );
    $insert->execute([
        'user' => $userId,
        'kind' => $kind,
        'title' => $title,
        'body' => $body,
        'order' => $orderId,
        'outbox' => $outboxId,
    ]);
    if ($insert->fetchColumn() === false) {
        return null;
    }

    $pref = PUSH_KIND_PREF[$kind] ?? 'want_status';
    $subs = $pdo->prepare("SELECT id, endpoint FROM push_subscriptions WHERE user_id = :user AND {$pref}");
    $subs->execute(['user' => $userId]);

    $woken = 0;
    foreach ($subs->fetchAll() as $sub) {
        $result = push_send_tickle((string) $sub['endpoint']);
        if ($result === 'gone') {
            $pdo->prepare('DELETE FROM push_subscriptions WHERE id = :id')->execute(['id' => $sub['id']]);
            continue;
        }
        $pdo->prepare(
            $result === 'failed'
                ? 'UPDATE push_subscriptions SET failures = failures + 1 WHERE id = :id'
                : 'UPDATE push_subscriptions SET failures = 0, last_push_at = now() WHERE id = :id'
        )->execute(['id' => $sub['id']]);
        if ($result !== 'failed') {
            $woken++;
        }
    }

    return $woken;
}
