<?php

declare(strict_types=1);

// Porta única do painel da plataforma. Admin não tem `partner_accounts`:
// entra pelo mesmo OTP do cliente (users.role = 'admin'), porque é uma pessoa
// com conta, não um aparelho de balcão.
//
// Segundo fator obrigatório (auditoria DevSecOps de 27/09/2026): em produção
// e homologação, admin sem autenticador confirmado não usa rota nenhuma do
// painel, só a que liga o autenticador (admin/totp.php, com
// $allowWithoutTotp). Antes era opcional, e um admin sem o fator entrava só
// com o SMS: clonar o chip dele dava o painel inteiro.
function require_admin(array $claims, bool $allowWithoutTotp = false): string
{
    if (($claims['role'] ?? null) !== 'admin') {
        error_response(403, 'forbidden', 'Área restrita ao time da plataforma.');
    }
    $adminId = (string) $claims['sub'];

    if (!$allowWithoutTotp && admin_totp_required() && !admin_totp_confirmed($adminId)) {
        error_response(403, 'totp_setup_required', 'Ligue o segundo fator (app autenticador) na aba Aparelhos antes de usar o painel.');
    }

    return $adminId;
}

/**
 * O segundo fator é exigido? Sempre em produção e homologação. Fora delas,
 * ADMIN_TOTP_REQUIRED=1 liga a exigência (é assim que o teste confere a
 * trava) -- a variável só LIGA: não existe jeito de desligar em produção.
 */
function admin_totp_required(): bool
{
    return is_production_like() || env('ADMIN_TOTP_REQUIRED', '') === '1';
}

function admin_totp_confirmed(string $adminId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM admin_totp WHERE user_id = :u AND confirmed_at IS NOT NULL');
    $stmt->execute(['u' => $adminId]);

    return $stmt->fetchColumn() !== false;
}
