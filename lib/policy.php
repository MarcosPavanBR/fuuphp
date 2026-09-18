<?php
declare(strict_types=1);

// Resolve a política de um checkout, na ordem loja → praça → plataforma
// (Especificação, Parte II §5), e devolve o snapshot que vai congelado em
// orders.policy_snapshot -- mudar a política depois não reescreve pedido já
// feito.
//
// Simplificação assumida: overrides de policy_overrides com scope='city'
// não são aplicados aqui (exigiriam cruzar city_ibge_code do endereço de
// entrega, que este módulo ainda não resolve por geocodificação). Só
// scope='restaurant' entra no merge. Fica registrado no README.
function resolve_policy(PDO $pdo, string $restaurantId): array
{
    $policyStmt = $pdo->query(
        'SELECT * FROM platform_policies ORDER BY version DESC LIMIT 1'
    );
    $policy = $policyStmt->fetch();
    if ($policy === false) {
        throw new RuntimeException('nenhuma platform_policies cadastrada');
    }

    $enabledMethods = pg_text_array_to_php((string) $policy['enabled_methods']);

    $settingsStmt = $pdo->prepare('SELECT * FROM restaurant_payment_settings WHERE restaurant_id = :id');
    $settingsStmt->execute(['id' => $restaurantId]);
    $settings = $settingsStmt->fetch();

    if ($settings !== false) {
        $storeMethods = pg_text_array_to_php((string) $settings['methods']);
        $enabledMethods = array_values(array_intersect($enabledMethods, $storeMethods));
    }

    $overrideStmt = $pdo->prepare(
        "SELECT patch FROM policy_overrides
         WHERE scope = 'restaurant' AND scope_id = :id
           AND (expires_at IS NULL OR expires_at > now())
         ORDER BY created_at DESC"
    );
    $overrideStmt->execute(['id' => $restaurantId]);

    $snapshot = [
        'policy_version' => (int) $policy['version'],
        'commission_bps' => (int) $policy['commission_bps'],
        'cash_ceiling' => (float) $policy['cash_ceiling'],
        'enabled_methods' => $enabledMethods,
        'max_change' => $settings !== false ? (float) $settings['max_change'] : 100.00,
        'min_order' => $settings !== false ? (float) $settings['min_order'] : 0.0,
        // Taxa de cancelamento entra no snapshot pelo mesmo motivo que
        // comissão e teto de espécie: o cliente concorda com a política do
        // dia do pedido, e mudar a política depois não pode reescrever o que
        // já foi combinado (tela 13.1).
        'cancel_fee' => (float) ($policy['cancel_fee'] ?? 0),
        'no_courier_timeout_seconds' => pg_interval_to_seconds((string) $policy['no_courier_timeout']),
    ];

    foreach ($overrideStmt->fetchAll() as $row) {
        $patch = json_decode((string) $row['patch'], true);
        if (is_array($patch)) {
            $snapshot = array_merge($snapshot, $patch);
        }
    }

    return $snapshot;
}

function pg_text_array_to_php(string $pgArray): array
{
    $trimmed = trim($pgArray, '{}');
    if ($trimmed === '') {
        return [];
    }
    return array_map(
        static fn (string $v) => trim($v, '"'),
        str_getcsv($trimmed)
    );
}

function pg_interval_to_seconds(string $interval): int
{
    // formatos comuns do PostgreSQL para interval: "00:15:00" ou "15 minutes"
    if (preg_match('/^(\d+):(\d+):(\d+)/', $interval, $m) === 1) {
        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    }
    if (preg_match('/(\d+)\s*min/', $interval, $m) === 1) {
        return ((int) $m[1]) * 60;
    }
    return 900; // default de segurança: 15 min
}
