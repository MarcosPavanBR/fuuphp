<?php
declare(strict_types=1);

// Resolve a política de um checkout, na ordem loja → praça → plataforma
// (Especificação, Parte II §5), e devolve o snapshot que vai congelado em
// orders.policy_snapshot -- mudar a política depois não reescreve pedido já
// feito.
//
// Exceções (policy_overrides): primeiro as da praça (scope='city', pela
// cidade da loja -- restaurants.city_ibge_code), depois as da loja, que
// ganham da praça. Dentro de cada escopo, a mais nova é aplicada por último
// e vence. scope='courier' não entra aqui: não é política de checkout.
// Formas "online": o dinheiro não passa pela mão do entregador. É o que
// sobra pra loja em atraso de repasse ("Loja com repasse em atraso cai
// automaticamente para somente online", tela 10.5).
const ONLINE_PAYMENT_METHODS = ['mp_card', 'pix_auto', 'pix_manual'];

/**
 * A política que vale pra esta loja agora: a versão mais nova da plataforma,
 * com os meios de pagamento cortados pelos que a loja aceita e, se ela
 * estiver em trava de atraso ou em período só-online, só os online. Todo
 * checkout, troca de método e tela 4.1 passam por aqui.
 */
function resolve_policy(PDO $pdo, string $restaurantId): array
{
    return resolve_policies($pdo, [$restaurantId])[$restaurantId];
}

/**
 * resolve_policy() de várias lojas de uma vez, com o mesmo resultado, em
 * 4 consultas no total em vez de 4 por loja. É o que a Home usa pra montar
 * os cards (auditoria PERF-01: 30 lojas faziam ~465 consultas).
 *
 * @param list<string> $restaurantIds
 * @return array<string, array> política por loja (loja desconhecida recebe
 *                              a da plataforma, como em resolve_policy)
 */
function resolve_policies(PDO $pdo, array $restaurantIds): array
{
    $policy = $pdo->query('SELECT * FROM platform_policies ORDER BY version DESC LIMIT 1')->fetch();
    if ($policy === false) {
        throw new RuntimeException('nenhuma platform_policies cadastrada');
    }
    $platformMethods = pg_text_array_to_php((string) $policy['enabled_methods']);
    $ids = array_values(array_unique($restaurantIds));
    if ($ids === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));

    $settings = [];
    $stmt = $pdo->prepare("SELECT * FROM restaurant_payment_settings WHERE restaurant_id IN ({$in})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $settings[(string) $row['restaurant_id']] = $row;
    }

    // Trava de atraso (bin/apply_financial_blocks.php liga, a baixa em
    // admin/netting.php desliga) e a cidade de cada loja (pras exceções da
    // praça).
    $stores = [];
    $stmt = $pdo->prepare("SELECT id, city_ibge_code, online_only_until > now() AS online_only FROM restaurants WHERE id IN ({$in})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $stores[(string) $row['id']] = $row;
    }

    // Exceções: primeiro as da praça (cidade da loja), depois as da loja,
    // que ganham; dentro de cada escopo, a mais nova é aplicada por último
    // e vence. (Antes vinha "mais nova primeiro", e a velha sobrescrevia.)
    $cities = array_values(array_unique(array_filter(array_column($stores, 'city_ibge_code'))));
    $overrides = ['city' => [], 'restaurant' => []];
    $params = $ids;
    $cityClause = '';
    if ($cities !== []) {
        $cityClause = " OR (scope = 'city' AND scope_id IN (" . implode(',', array_fill(0, count($cities), '?')) . '))';
        $params = array_merge($params, $cities);
    }
    $stmt = $pdo->prepare(
        "SELECT scope, scope_id, patch FROM policy_overrides
          WHERE ((scope = 'restaurant' AND scope_id IN ({$in})){$cityClause})
            AND (expires_at IS NULL OR expires_at > now())
          ORDER BY created_at, id"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $patch = json_decode((string) $row['patch'], true);
        if (is_array($patch)) {
            $overrides[$row['scope']][(string) $row['scope_id']][] = $patch;
        }
    }

    $result = [];
    foreach ($ids as $id) {
        $storeSettings = $settings[$id] ?? false;
        $enabledMethods = $platformMethods;
        if ($storeSettings !== false) {
            $enabledMethods = array_values(array_intersect($enabledMethods, pg_text_array_to_php((string) $storeSettings['methods'])));
        }
        // Em trava: só formas online -- no checkout, na troca de método e na
        // tela 4.1, porque todos passam aqui.
        if (($stores[$id]['online_only'] ?? false) === true) {
            $enabledMethods = array_values(array_intersect($enabledMethods, ONLINE_PAYMENT_METHODS));
        }

        $snapshot = [
            'policy_version' => (int) $policy['version'],
            'commission_bps' => (int) $policy['commission_bps'],
            'cash_ceiling' => (float) $policy['cash_ceiling'],
            'enabled_methods' => $enabledMethods,
            'max_change' => $storeSettings !== false ? (float) $storeSettings['max_change'] : 100.00,
            'min_order' => $storeSettings !== false ? (float) $storeSettings['min_order'] : 0.0,
            // Taxa de cancelamento entra no snapshot pelo mesmo motivo que
            // comissão e teto de espécie: o cliente concorda com a política do
            // dia do pedido, e mudar a política depois não pode reescrever o
            // que já foi combinado (tela 13.1).
            'cancel_fee' => (float) ($policy['cancel_fee'] ?? 0),
            // Tarifa de entrega, pelo mesmo motivo: republicar a política
            // amanhã não reescreve o frete já cobrado (tela 14.3).
            'delivery_base_fee' => (float) ($policy['delivery_base_fee'] ?? 0),
            'delivery_per_km' => (float) ($policy['delivery_per_km'] ?? 0),
            'delivery_max_km' => $policy['delivery_max_km'] === null ? null : (float) $policy['delivery_max_km'],
            'no_courier_timeout_seconds' => pg_interval_to_seconds((string) $policy['no_courier_timeout']),
        ];
        $city = (string) ($stores[$id]['city_ibge_code'] ?? '');
        foreach (array_merge($overrides['city'][$city] ?? [], $overrides['restaurant'][$id] ?? []) as $patch) {
            $snapshot = array_merge($snapshot, $patch);
        }
        $result[$id] = $snapshot;
    }

    return $result;
}

/**
 * As exceções em vigor de uma praça (scope='city'), já mescladas na ordem
 * de resolve_policy() (a mais nova vence). É a política "da cidade" quando
 * ainda não há loja escolhida -- "esse endereço é atendido?" (quote.php).
 */
function city_policy_patch(PDO $pdo, string $cityIbge): array
{
    $stmt = $pdo->prepare(
        "SELECT patch FROM policy_overrides
          WHERE scope = 'city' AND scope_id = :city
            AND (expires_at IS NULL OR expires_at > now())
          ORDER BY created_at, id"
    );
    $stmt->execute(['city' => $cityIbge]);
    $merged = [];
    foreach ($stmt->fetchAll() as $row) {
        $patch = json_decode((string) $row['patch'], true);
        if (is_array($patch)) {
            $merged = array_merge($merged, $patch);
        }
    }

    return $merged;
}

/**
 * Array de texto do PostgreSQL ("{cash,mp_card}") como lista PHP.
 */
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

/**
 * Interval do PostgreSQL ("00:15:00" ou "15 minutes") em segundos; formato
 * desconhecido vale 15 min, o prazo mais seguro.
 */
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
