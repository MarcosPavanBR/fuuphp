<?php
declare(strict_types=1);

// JWT HS256 mínimo, escrito à mão de propósito: a cláusula zero não lista
// nenhuma biblioteca de token, e o formato é simples o bastante para não
// justificar puxar uma dependência nova sem autorização.
final class Jwt
{
    public static function encode(array $claims, string $secret, int $ttlSeconds): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $now = time();
        $claims['iat'] = $now;
        $claims['exp'] = $now + $ttlSeconds;

        $segments = [
            self::b64(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::b64($signature);

        return implode('.', $segments);
    }

    /**
     * @throws RuntimeException se o token for inválido, malformado ou expirado
     */
    public static function decode(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('token malformado');
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expectedSig = self::b64(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $secret, true));
        if (!hash_equals($expectedSig, $sigB64)) {
            throw new RuntimeException('assinatura inválida');
        }

        $claims = json_decode(self::unb64($payloadB64), true);
        if (!is_array($claims)) {
            throw new RuntimeException('payload inválido');
        }
        if (($claims['exp'] ?? 0) < time()) {
            throw new RuntimeException('token expirado');
        }

        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int) (4 * ceil(strlen($data) / 4)), '=');
        return base64_decode($padded);
    }
}
