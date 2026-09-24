<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/guard.php';

// Aba Banners do painel da plataforma: o carrossel da Home do cliente
// (migração 037). É a vitrine que a plataforma pode vender pras lojas.
//
// GET   todos os banners, com cidade, loja do link e situação
//       (no_ar | agendado | encerrado | desligado).
// POST  multipart  {title, image, city_ibge_code?, restaurant_id?,
//                   starts_on?, ends_on?, position?}  cria (201).
//       Datas em AAAA-MM-DD, no relógio da cidade do banner (migração 038;
//       sem cidade, Brasília): começa às 0h de starts_on e termina no fim de
//       ends_on (inclusive). Sem cidade = todas.
// POST  JSON  {id, action: toggle | delete}  liga/desliga ou apaga;
//             {id, action: position, position}  muda a ordem.
//
// O link, quando tem, é uma loja aprovada e da mesma cidade do banner. Toda
// mudança vai pro audit_log.

$claims = require_auth();
$adminId = require_admin($claims);
$pdo = db();

/** Registra a mudança de banner no audit_log. */
function banner_audit(PDO $pdo, string $adminId, int $id, string $action, ?array $before, ?array $after): void
{
    $pdo->prepare(
        'INSERT INTO audit_log (actor_id, action, target, before, after, ip)
         VALUES (:actor, :action, :target, :before, :after, :ip)'
    )->execute([
        'actor' => $adminId,
        'action' => 'promo_banner.' . $action,
        'target' => 'promo_banners:' . $id,
        'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        'ip' => client_ip(),
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $rows = $pdo->query(
        "SELECT b.id, b.title, b.image_key, b.city_ibge_code, c.name AS city_name,
                b.link_restaurant_id AS restaurant_id, r.name AS restaurant_name,
                b.starts_at, b.ends_at, b.position, b.active,
                CASE WHEN NOT b.active THEN 'desligado'
                     WHEN b.ends_at IS NOT NULL AND b.ends_at <= now() THEN 'encerrado'
                     WHEN b.starts_at > now() THEN 'agendado'
                     ELSE 'no_ar' END AS situation
           FROM promo_banners b
           LEFT JOIN service_cities c ON c.ibge_code = b.city_ibge_code
           LEFT JOIN restaurants r ON r.id = b.link_restaurant_id
          ORDER BY b.active DESC, b.position, b.id DESC"
    )->fetchAll();
    json_response(200, ['banners' => $rows, 'max_live' => PROMO_BANNERS_MAX_LIVE]);
}

require_method('POST');

// ── JSON: ligar/desligar, ordem, apagar ──────────────────────────────────
if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $body = read_json_body();
    $id = (int) ($body['id'] ?? 0);
    $action = (string) ($body['action'] ?? '');
    $stmt = $pdo->prepare('SELECT id, title, active, position FROM promo_banners WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $banner = $stmt->fetch();
    if ($banner === false) {
        error_response(404, 'banner_not_found', 'Banner não encontrado.');
    }

    if ($action === 'toggle') {
        $pdo->prepare('UPDATE promo_banners SET active = NOT active WHERE id = :id')->execute(['id' => $id]);
        banner_audit($pdo, $adminId, $id, 'toggled', ['active' => $banner['active']], ['active' => !$banner['active']]);
        json_response(200, ['id' => $id, 'active' => !$banner['active']]);
    }
    if ($action === 'position') {
        $position = $body['position'] ?? null;
        if (!is_int($position) || $position < 0 || $position > 999) {
            error_response(422, 'invalid_position', 'A posição é um número de 0 a 999.', fields: ['position' => 'de 0 a 999']);
        }
        $pdo->prepare('UPDATE promo_banners SET position = :p WHERE id = :id')->execute(['p' => $position, 'id' => $id]);
        banner_audit($pdo, $adminId, $id, 'moved', ['position' => $banner['position']], ['position' => $position]);
        json_response(200, ['id' => $id, 'position' => $position]);
    }
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM promo_banners WHERE id = :id')->execute(['id' => $id]);
        banner_audit($pdo, $adminId, $id, 'deleted', ['title' => $banner['title']], null);
        json_response(200, ['id' => $id, 'deleted' => true]);
    }
    error_response(422, 'invalid_action', 'Ação deve ser toggle, position ou delete.');
}

// ── multipart: criar ─────────────────────────────────────────────────────
$title = trim((string) ($_POST['title'] ?? ''));
$city = only_digits((string) ($_POST['city_ibge_code'] ?? ''));
$restaurantId = trim((string) ($_POST['restaurant_id'] ?? ''));
$startsOn = trim((string) ($_POST['starts_on'] ?? ''));
$endsOn = trim((string) ($_POST['ends_on'] ?? ''));
$position = (int) ($_POST['position'] ?? 0);

$isDate = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
    && ($p = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) !== false && $p->format('Y-m-d') === $d;

$fields = [];
// O relógio das datas: o da cidade do banner; banner de todas as cidades
// segue Brasília, o relógio da plataforma.
$tz = 'America/Sao_Paulo';
if (mb_strlen($title) < 2 || mb_strlen($title) > 80) {
    $fields['title'] = 'de 2 a 80 caracteres (é o texto lido pelo leitor de tela)';
}
if ($city !== '') {
    $cityStmt = $pdo->prepare('SELECT timezone FROM service_cities WHERE ibge_code = :c');
    $cityStmt->execute(['c' => $city]);
    $cityTz = $cityStmt->fetchColumn();
    if ($cityTz === false) {
        $fields['city_ibge_code'] = 'cidade não cadastrada na aba Cidades';
    } else {
        $tz = (string) $cityTz;
    }
}
if ($restaurantId !== '') {
    $storeStmt = $pdo->prepare('SELECT city_ibge_code FROM restaurants WHERE id = :id AND approved_at IS NOT NULL');
    $storeStmt->execute(['id' => is_valid_uuid($restaurantId) ? $restaurantId : '00000000-0000-0000-0000-000000000000']);
    $storeCity = $storeStmt->fetchColumn();
    if ($storeCity === false) {
        $fields['restaurant_id'] = 'loja aprovada não encontrada';
    } elseif ($city !== '' && $storeCity !== $city) {
        $fields['restaurant_id'] = 'a loja é de outra cidade';
    }
}
if ($startsOn !== '' && !$isDate($startsOn)) {
    $fields['starts_on'] = 'data inválida (AAAA-MM-DD)';
}
if ($endsOn !== '' && !$isDate($endsOn)) {
    $fields['ends_on'] = 'data inválida (AAAA-MM-DD)';
} elseif ($endsOn !== '' && $startsOn !== '' && $endsOn < $startsOn) {
    $fields['ends_on'] = 'termina antes de começar';
} elseif ($endsOn !== '' && $endsOn < (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d')) {
    $fields['ends_on'] = 'já passou';
}
if ($position < 0 || $position > 999) {
    $fields['position'] = 'de 0 a 999';
}
if ($fields !== []) {
    error_response(422, 'invalid_request', 'Confira os campos marcados.', fields: $fields);
}

// A imagem por último: só grava arquivo quando o resto já passou.
$imageKey = public_image_from_upload('image', PROMO_BANNER_MAX_PX, PROMO_BANNER_SUBDIR);

// A data vira a meia-noite NO RELÓGIO DA CIDADE ("AT TIME ZONE" de um
// timestamp sem fuso dá o instante daquela hora de parede lá); o fim é a
// meia-noite do dia seguinte ao último dia. Em MS, 1 h depois de Brasília.
$stmt = $pdo->prepare(
    "INSERT INTO promo_banners (title, image_key, city_ibge_code, link_restaurant_id, starts_at, ends_at, position, created_by)
     VALUES (:title, :image, NULLIF(:city, ''), CAST(NULLIF(:store, '') AS uuid),
             COALESCE(CAST(NULLIF(:starts, '') AS date)::timestamp AT TIME ZONE :tz, now()),
             (CAST(NULLIF(:ends, '') AS date) + 1)::timestamp AT TIME ZONE :tz2,
             :position, :admin)
     RETURNING id"
);
$stmt->execute([
    'title' => $title, 'image' => $imageKey, 'city' => $city, 'store' => $restaurantId,
    'starts' => $startsOn, 'ends' => $endsOn, 'position' => $position, 'admin' => $adminId,
    'tz' => $tz, 'tz2' => $tz,
]);
$id = (int) $stmt->fetchColumn();
banner_audit($pdo, $adminId, $id, 'created', null, [
    'title' => $title, 'city_ibge_code' => $city ?: null, 'restaurant_id' => $restaurantId ?: null,
    'starts_on' => $startsOn ?: null, 'ends_on' => $endsOn ?: null,
]);

json_response(201, ['id' => $id, 'image_key' => $imageKey]);
