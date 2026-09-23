<?php
declare(strict_types=1);

// Validação de dado brasileiro e de contato: CPF e CNPJ pelos dígitos
// verificadores, telefone com DDD, e-mail. Tudo recebe só os dígitos
// (`only_digits`) -- máscara é coisa da tela, não do dado.

function only_digits(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

function is_valid_cpf(string $cpf): bool
{
    $cpf = only_digits($cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($t = 9; $t <= 10; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) {
            $sum += (int) $cpf[$i] * (($t + 1) - $i);
        }
        $digit = ((10 * $sum) % 11) % 10;
        if ($digit !== (int) $cpf[$t]) {
            return false;
        }
    }
    return true;
}

function is_valid_cnpj(string $cnpj): bool
{
    $cnpj = only_digits($cnpj);
    if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
        return false;
    }
    $calc = static function (string $base) {
        $weights = strlen($base) === 12
            ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
            : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach (str_split($base) as $i => $digit) {
            $sum += (int) $digit * $weights[$i];
        }
        $mod = $sum % 11;
        return $mod < 2 ? 0 : 11 - $mod;
    };
    $d1 = $calc(substr($cnpj, 0, 12));
    $d2 = $calc(substr($cnpj, 0, 12) . $d1);
    return $cnpj === substr($cnpj, 0, 12) . $d1 . $d2;
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function is_valid_phone(string $phone): bool
{
    $digits = only_digits($phone);
    return strlen($digits) >= 10 && strlen($digits) <= 13;
}

/** UUID no formato do PostgreSQL. Conferir antes evita que id malformado vire 500 (22P02) no banco. */
function is_valid_uuid(string $value): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
}
