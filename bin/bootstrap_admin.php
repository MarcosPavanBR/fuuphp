<?php

declare(strict_types=1);

// Primeiro deploy: cria o admin fundador e a política da plataforma v1.
//
// A migração 003 diz que platform_policies v1 é "inserida pela aplicação no
// primeiro deploy" -- é este script. Roda UMA vez, só pela linha de comando:
//
//   php bin/bootstrap_admin.php --name="Marcos Pavan" --phone=11999998888 [--email=...]
//
// - O admin entra pelo mesmo código por SMS/e-mail do cliente (10.1/10.2):
//   não existe senha, então nenhuma senha passa por aqui nem fica no código.
// - Recusa se já existe admin (idempotente: rodar de novo não cria outro).
// - A política v1 usa os padrões da migração 003 (comissão 8%, teto de espécie
//   R$ 300, prazo de baixa, repasse na terça...) com os cinco meios de
//   pagamento ligados; frete e taxas começam zerados e são ajustados depois
//   no painel (tela 10.5). Se já existe política, não mexe.
// - Roda antes da trava de produção passar (o provedor de OTP, por exemplo,
//   pode ainda não estar configurado): só mexe no banco local.

const FUU_SKIP_PRODUCTION_GUARD = true;
require_once __DIR__ . '/../lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['name:', 'phone:', 'email:']);
$name = trim((string) ($opts['name'] ?? ''));
$phone = isset($opts['phone']) ? only_digits((string) $opts['phone']) : null;
$email = isset($opts['email']) ? trim((string) $opts['email']) : null;

$usage = "uso: php bin/bootstrap_admin.php --name=\"Nome Completo\" --phone=DDDNUMERO [--email=voce@dominio]\n";
if ($name === '' || ($phone === null && $email === null)) {
    fwrite(STDERR, $usage);
    exit(2);
}
if ($phone !== null && !is_valid_phone($phone)) {
    fwrite(STDERR, "Telefone inválido (DDD + número, só dígitos).\n");
    exit(2);
}
if ($email !== null && !is_valid_email($email)) {
    fwrite(STDERR, "E-mail inválido.\n");
    exit(2);
}

$pdo = db();
$pdo->beginTransaction();
try {
    // Trava a tabela de usuários só pro "já existe admin?" não correr com outra execução.
    $pdo->exec('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
    $existing = $pdo->query("SELECT full_name FROM users WHERE role = 'admin' AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    if ($existing !== false) {
        $pdo->rollBack();
        fwrite(STDERR, "Já existe admin ({$existing}). Nada foi feito -- novos admins entram pelo painel.\n");
        exit(1);
    }

    $taken = $pdo->prepare('SELECT 1 FROM users WHERE phone = :p OR email = :e');
    $taken->execute(['p' => $phone, 'e' => $email]);
    if ($taken->fetchColumn() !== false) {
        $pdo->rollBack();
        fwrite(STDERR, "Esse telefone/e-mail já pertence a outra conta.\n");
        exit(1);
    }

    $user = $pdo->prepare(
        "INSERT INTO users (role, full_name, phone, email, lgpd_accepted_at)
         VALUES ('admin', :name, :phone, :email, now()) RETURNING id"
    );
    $user->execute(['name' => $name, 'phone' => $phone, 'email' => $email]);
    $adminId = (string) $user->fetchColumn();

    $policyCreated = false;
    if ($pdo->query('SELECT 1 FROM platform_policies LIMIT 1')->fetchColumn() === false) {
        $pdo->prepare(
            "INSERT INTO platform_policies (version, enabled_methods, created_by)
             VALUES (1, ARRAY['mp_card','pix_auto','pix_manual','cash','pos_machine']::payment_method[], :by)"
        )->execute(['by' => $adminId]);
        $policyCreated = true;
    }

    $pdo->prepare("INSERT INTO audit_log (actor_id, action, target) VALUES (:id, 'platform.bootstrap', :target)")
        ->execute(['id' => $adminId, 'target' => 'users:' . $adminId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Falhou: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Admin criado: {$name} ({$adminId}).\n";
echo $policyCreated
    ? "Política da plataforma v1 criada com os padrões (comissão 8%, teto de espécie R$ 300, 5 meios de pagamento).\n"
    : "Política da plataforma já existia -- mantida.\n";
echo "Entre em /admin.html com o " . ($phone !== null ? 'telefone' : 'e-mail') . " e o código que chegar.\n";
