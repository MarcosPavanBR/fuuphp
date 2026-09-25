<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Exceções de política (policy_overrides), na aba Políticas do admin: um
// número diferente da política da plataforma só numa cidade ou só numa loja
// -- frete de lançamento numa cidade nova, comissão negociada com uma loja.
// Quem aplica é resolve_policy() (lib/catalog/policy.php): praça primeiro,
// depois loja; dentro de cada uma, a mais nova vence.
//
// GET   exceções (em vigor e encerradas), os campos aceitos e as cidades e
//       lojas que podem receber exceção.
// POST  {scope: city|restaurant, scope_id, patch: {campo: valor}, reason,
//        ends_on?}  cria (201). ends_on em AAAA-MM-DD: vale até o fim desse
//       dia no relógio da cidade; sem data, até ser encerrada.
// POST  {id, action: 'end'}  encerra agora. Não apaga: pedido já feito
//       congelou a política da época em policy_snapshot, e a exceção é o
//       rastro de por que aquele número era aquele.
//
// Toda mudança vai pro audit_log.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

// Só o que o checkout lê do snapshot. Faixas iguais às de admin/policy.php
// (comissão até 30%), com teto de sanidade no resto: exceção é ajuste, não
// um jeito de digitar R$ 5.000 de frete sem querer.
const OVERRIDE_FIELDS = [
    'delivery_base_fee' => ['label' => 'Frete: tarifa base (R$)', 'min' => 0, 'max' => 100, 'int' => false],
    'delivery_per_km' => ['label' => 'Frete: por km (R$)', 'min' => 0, 'max' => 50, 'int' => false],
    'delivery_max_km' => ['label' => 'Raio de entrega (km)', 'min' => 0.5, 'max' => 50, 'int' => false],
    'commission_bps' => ['label' => 'Comissão (bps, 100 = 1%)', 'min' => 0, 'max' => 3000, 'int' => true],
    'cancel_fee' => ['label' => 'Taxa de cancelamento (R$)', 'min' => 0, 'max' => 100, 'int' => false],
];

/** Registra a mudança de exceção no audit_log. */
function override_audit(PDO $pdo, string $adminId, int $id, string $action, ?array $after): void
{
    $pdo->prepare(
        'INSERT INTO audit_log (actor_id, action, target, before, after, ip)
         VALUES (:actor, :action, :target, NULL, :after, :ip)'
    )->execute([
        'actor' => $adminId,
        'action' => 'policy_override.' . $action,
        'target' => 'policy_overrides:' . $id,
        'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        'ip' => client_ip(),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = $pdo->query(
        "SELECT o.id, o.scope, o.scope_id, o.patch, o.reason, o.expires_at, o.created_at,
                u.full_name AS created_by_name,
                COALESCE(c.name || ' — ' || c.uf, r.name) AS target_name,
                (o.expires_at IS NULL OR o.expires_at > now()) AS live
           FROM policy_overrides o
           LEFT JOIN users u ON u.id = o.created_by
           LEFT JOIN service_cities c ON o.scope = 'city' AND c.ibge_code = o.scope_id
           LEFT JOIN restaurants r ON o.scope = 'restaurant' AND r.id::text = o.scope_id
          WHERE o.scope IN ('city', 'restaurant')
          ORDER BY live DESC, o.created_at DESC
          LIMIT 200"
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['patch'] = json_decode((string) $row['patch'], true);
    }
    unset($row);

    $cities = $pdo->query('SELECT ibge_code AS id, name || \' — \' || uf AS name FROM service_cities ORDER BY name')->fetchAll();
    $stores = $pdo->query(
        'SELECT r.id, r.name, c.name AS city FROM restaurants r
           LEFT JOIN service_cities c ON c.ibge_code = r.city_ibge_code
          WHERE r.approved_at IS NOT NULL ORDER BY r.name'
    )->fetchAll();

    $fields = [];
    foreach (OVERRIDE_FIELDS as $key => $f) {
        $fields[] = ['key' => $key, 'label' => $f['label'], 'min' => $f['min'], 'max' => $f['max']];
    }

    json_response(200, ['overrides' => $rows, 'fields' => $fields, 'cities' => $cities, 'stores' => $stores]);
}

require_method('POST');
$body = read_json_body();

// ── encerrar ─────────────────────────────────────────────────────────────
if (($body['action'] ?? null) === 'end') {
    $id = (int) ($body['id'] ?? 0);
    $stmt = $pdo->prepare(
        'UPDATE policy_overrides SET expires_at = now()
          WHERE id = :id AND (expires_at IS NULL OR expires_at > now())
          RETURNING id'
    );
    $stmt->execute(['id' => $id]);
    if ($stmt->fetchColumn() === false) {
        error_response(404, 'override_not_found', 'Exceção não encontrada ou já encerrada.');
    }
    override_audit($pdo, $adminId, $id, 'ended', null);
    json_response(200, ['id' => $id, 'ended' => true]);
}

// ── criar ────────────────────────────────────────────────────────────────
$scope = (string) ($body['scope'] ?? '');
$scopeId = body_text($body, 'scope_id', 40) ?? '';
$reason = body_text($body, 'reason', 200) ?? '';
$endsOn = body_text($body, 'ends_on', 10) ?? '';
$rawPatch = is_array($body['patch'] ?? null) ? $body['patch'] : [];

$fields = [];
// Relógio da data de fim: o da cidade (da exceção, ou da loja).
$tz = 'America/Sao_Paulo';
if ($scope === 'city') {
    $stmt = $pdo->prepare('SELECT timezone FROM service_cities WHERE ibge_code = :id');
    $stmt->execute(['id' => $scopeId]);
    $found = $stmt->fetchColumn();
    if ($found === false) {
        $fields['scope_id'] = 'cidade não cadastrada na aba Cidades';
    } else {
        $tz = (string) $found;
    }
} elseif ($scope === 'restaurant') {
    if (!is_valid_uuid($scopeId)) {
        $fields['scope_id'] = 'loja aprovada não encontrada';
    } else {
        $stmt = $pdo->prepare('SELECT restaurant_timezone(id) FROM restaurants WHERE id = :id AND approved_at IS NOT NULL');
        $stmt->execute(['id' => $scopeId]);
        $found = $stmt->fetchColumn();
        if ($found === false) {
            $fields['scope_id'] = 'loja aprovada não encontrada';
        } else {
            $tz = (string) $found;
        }
    }
} else {
    $fields['scope'] = 'city ou restaurant';
}

$patch = [];
foreach ($rawPatch as $key => $value) {
    $rule = OVERRIDE_FIELDS[$key] ?? null;
    if ($rule === null) {
        $fields["patch.{$key}"] = 'campo que exceção não muda';
        continue;
    }
    if ($value === null || $value === '') {
        continue; // campo em branco no formulário = não mexe
    }
    if (!is_numeric($value)) {
        $fields["patch.{$key}"] = 'precisa ser número';
        continue;
    }
    $n = $rule['int'] ? (int) $value : round((float) $value, 2);
    if ($n < $rule['min'] || $n > $rule['max']) {
        $fields["patch.{$key}"] = "de {$rule['min']} a {$rule['max']}";
        continue;
    }
    $patch[$key] = $n;
}
if ($patch === [] && !array_filter(array_keys($fields), static fn ($k) => str_starts_with($k, 'patch.'))) {
    $fields['patch'] = 'preencha pelo menos um número';
}
if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
    $fields['reason'] = 'de 5 a 200 caracteres (fica no histórico: por que esse número)';
}
if ($endsOn !== '') {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $endsOn);
    if ($d === false || $d->format('Y-m-d') !== $endsOn) {
        $fields['ends_on'] = 'data inválida (AAAA-MM-DD)';
    } elseif ($endsOn < (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d')) {
        $fields['ends_on'] = 'já passou';
    }
}
if ($fields !== []) {
    error_response(422, 'invalid_request', 'Confira os campos marcados.', fields: $fields);
}

// Fim = meia-noite do dia seguinte ao último dia, no relógio da cidade.
$stmt = $pdo->prepare(
    "INSERT INTO policy_overrides (scope, scope_id, patch, reason, created_by, expires_at)
     VALUES (:scope, :scope_id, :patch, :reason, :admin,
             (CAST(NULLIF(:ends, '') AS date) + 1)::timestamp AT TIME ZONE :tz)
     RETURNING id"
);
$stmt->execute([
    'scope' => $scope, 'scope_id' => $scopeId, 'patch' => json_encode($patch),
    'reason' => $reason, 'admin' => $adminId, 'ends' => $endsOn, 'tz' => $tz,
]);
$id = (int) $stmt->fetchColumn();
override_audit($pdo, $adminId, $id, 'created', [
    'scope' => $scope, 'scope_id' => $scopeId, 'patch' => $patch, 'reason' => $reason, 'ends_on' => $endsOn ?: null,
]);

json_response(201, ['id' => $id, 'patch' => $patch]);
