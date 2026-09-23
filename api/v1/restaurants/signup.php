<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../lib/bootstrap.php';

// Cadastro de loja pela própria loja ("Quero vender no FUU", migração 033).
//
// Público: quem cadastra ainda não tem conta. Cria, numa transação só:
//   - a loja, NÃO aprovada: fora da vitrine, do carrinho e do checkout
//     (require_store_accepting_orders) até a plataforma aprovar na fila 12.1;
//   - a conta do balcão: login pelo CNPJ + a senha escolhida aqui (bcrypt),
//     preso ao primeiro aparelho, como toda conta de loja;
//   - a chave Pix, se veio (store_pix_key_check: CNPJ só se for o da loja,
//     CPF nunca; e-mail/telefone/aleatória ficam pro admin conferir);
//   - o aceite dos termos de parceiro, com versão e IP.
//
// Enquanto espera a análise, a loja já entra no painel e prepara cardápio,
// horário e formas de pagamento -- no dia da aprovação ela abre pronta.
//
// Contra cadastro em massa: CNPJ com dígito válido e único, e-mail único, e
// no máximo 3 cadastros por IP em 24 h (o Cloudflare segura o volume).

require_method('POST');
$body = read_json_body();

const STORE_SIGNUPS_PER_IP_PER_DAY = 3;
const STORE_PASSWORD_MIN_LENGTH = 8;
const PARTNER_TERMS_VERSION = '2026-09-01';

$name = trim((string) ($body['name'] ?? ''));
$cnpj = only_digits((string) ($body['cnpj'] ?? ''));
$category = (string) ($body['category'] ?? '');
$city = only_digits((string) ($body['city_ibge_code'] ?? ''));
$address = trim((string) ($body['address'] ?? ''));
$contactName = trim((string) ($body['contact_name'] ?? ''));
$contactPhone = only_digits((string) ($body['contact_phone'] ?? ''));
$email = strtolower(trim((string) ($body['email'] ?? '')));
$password = (string) ($body['password'] ?? '');
$pixKeyRaw = trim((string) ($body['pix_key'] ?? ''));
$lat = $body['lat'] ?? null;
$lng = $body['lng'] ?? null;

$fields = [];
if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
    $fields['name'] = 'de 3 a 80 caracteres';
}
if (!is_valid_cnpj($cnpj)) {
    $fields['cnpj'] = 'CNPJ inválido';
}
if (!in_array($category, STORE_CATEGORIES, true)) {
    $fields['category'] = 'escolha uma categoria';
}
// A cidade tem de ser atendida (aba Cidades do admin): loja numa cidade
// que o app não mostra nunca receberia pedido.
$servedCity = db()->prepare('SELECT 1 FROM service_cities WHERE ibge_code = :c AND active');
$servedCity->execute(['c' => $city]);
if (strlen($city) !== 7) {
    $fields['city_ibge_code'] = 'escolha a cidade';
} elseif ($servedCity->fetchColumn() === false) {
    $fields['city_ibge_code'] = 'cidade ainda não atendida';
}
if (mb_strlen($address) < 8) {
    $fields['address'] = 'rua, número e bairro';
}
if (mb_strlen($contactName) < 3) {
    $fields['contact_name'] = 'nome de quem responde pela loja';
}
if (!is_valid_phone($contactPhone)) {
    $fields['contact_phone'] = 'telefone com DDD';
}
if (!is_valid_email($email)) {
    $fields['email'] = 'e-mail inválido';
}
if (mb_strlen($password) < STORE_PASSWORD_MIN_LENGTH) {
    $fields['password'] = 'mínimo ' . STORE_PASSWORD_MIN_LENGTH . ' caracteres';
}
$hasLocation = $lat !== null && $lng !== null;
if ($hasLocation && (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)) {
    $fields['lat'] = 'localização inválida';
}
$pix = null;
if ($pixKeyRaw !== '' && is_valid_cnpj($cnpj)) {
    $pix = store_pix_key_check($pixKeyRaw, $cnpj);
    if (!$pix['ok']) {
        $fields['pix_key'] = $pix['error'];
    }
}
if (($body['accept_terms'] ?? false) !== true) {
    $fields['accept_terms'] = 'é preciso aceitar os termos de parceiro';
}
if ($fields !== []) {
    error_response(422, 'invalid_signup', 'Confira os dados do cadastro.', fields: $fields);
}

$pdo = db();
$ip = client_ip();

if ($ip !== null) {
    $recent = $pdo->prepare(
        "SELECT count(*) FROM restaurants WHERE signup_ip = :ip AND created_at > now() - interval '24 hours'"
    );
    $recent->execute(['ip' => $ip]);
    if ((int) $recent->fetchColumn() >= STORE_SIGNUPS_PER_IP_PER_DAY) {
        error_response(429, 'too_many_signups', 'Muitos cadastros saindo deste endereço hoje. Tente amanhã ou fale com a gente.');
    }
}

$pdo->beginTransaction();
try {
    $taken = $pdo->prepare('SELECT (SELECT 1 FROM restaurants WHERE cnpj = :c), (SELECT 1 FROM users WHERE email = :e)');
    $taken->execute(['c' => $cnpj, 'e' => $email]);
    [$cnpjTaken, $emailTaken] = $taken->fetch(PDO::FETCH_NUM);
    if ($cnpjTaken !== null) {
        $pdo->rollBack();
        error_response(409, 'cnpj_taken', 'Esse CNPJ já tem cadastro. Entre com ele e a senha, ou fale com o suporte.',
            fields: ['cnpj' => 'já cadastrado']);
    }
    if ($emailTaken !== null) {
        $pdo->rollBack();
        error_response(409, 'email_taken', 'Esse e-mail já é usado por outra conta.', fields: ['email' => 'já usado']);
    }

    $restaurantId = uuid_v4();
    $pdo->prepare(
        'INSERT INTO restaurants (id, name, cnpj, city_ibge_code, category, lat, lng,
                                  contact_name, contact_phone, address_text, signup_ip)
         VALUES (:id, :name, :cnpj, :city, :category, :lat, :lng, :cname, :cphone, :address, :ip)'
    )->execute([
        'id' => $restaurantId,
        'name' => $name,
        'cnpj' => $cnpj,
        'city' => $city,
        'category' => $category,
        'lat' => $hasLocation ? (float) $lat : null,
        'lng' => $hasLocation ? (float) $lng : null,
        'cname' => $contactName,
        'cphone' => $contactPhone,
        'address' => $address,
        'ip' => $ip,
    ]);

    // A conta do balcão é um usuário próprio (restaurant_staff), com o e-mail
    // de contato: o telefone fica na loja, não aqui -- o dono pode já ter
    // conta de cliente com o mesmo celular.
    $userId = uuid_v4();
    $pdo->prepare(
        "INSERT INTO users (id, role, full_name, email, lgpd_accepted_at)
         VALUES (:id, 'restaurant_staff', :name, :email, now())"
    )->execute(['id' => $userId, 'name' => $contactName, 'email' => $email]);

    $pdo->prepare(
        "INSERT INTO partner_accounts (user_id, kind, restaurant_id, login_code, password_hash)
         VALUES (:u, 'restaurant', :r, :cnpj, :hash)"
    )->execute(['u' => $userId, 'r' => $restaurantId, 'cnpj' => $cnpj, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);

    if ($pix !== null) {
        $pdo->prepare('INSERT INTO restaurant_credentials (restaurant_id, pix_key) VALUES (:r, :k)')
            ->execute(['r' => $restaurantId, 'k' => $pix['key']]);
    }

    $consent = $pdo->prepare("INSERT INTO consents (user_id, kind, version, ip) VALUES (:u, :k, :v, :ip)");
    foreach (['terms', 'privacy_policy'] as $kind) {
        $consent->execute(['u' => $userId, 'k' => $kind, 'v' => PARTNER_TERMS_VERSION, 'ip' => $ip]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

json_response(201, [
    'restaurant_id' => $restaurantId,
    'state' => 'review',
    'login_code' => $cnpj,
    'pix_ownership_checked' => $pix['ownership_checked'] ?? null,
    'message' => 'Cadastro recebido. Entre no painel com o CNPJ e a senha pra preparar cardápio e horário; a loja aparece pros clientes quando a plataforma aprovar.',
]);
