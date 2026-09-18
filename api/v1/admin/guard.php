<?php

declare(strict_types=1);

// Porta única do painel da plataforma. Admin não tem `partner_accounts`:
// entra pelo mesmo OTP do cliente (users.role = 'admin'), porque é uma pessoa
// com conta, não um aparelho de balcão.
function require_admin(array $claims): string
{
    if (($claims['role'] ?? null) !== 'admin') {
        error_response(403, 'forbidden', 'Área restrita ao time da plataforma.');
    }

    return (string) $claims['sub'];
}
