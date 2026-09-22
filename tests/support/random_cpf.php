<?php
declare(strict_types=1);

// Gera um CPF aleatório com dígitos verificadores válidos, pra seed de teste
// não colidir na UNIQUE de users.cpf entre execuções no mesmo banco. Mesmo
// algoritmo de lib/core/validation.php::is_valid_cpf, ao contrário.

function cpf_digit(string $base): int
{
    $factor = strlen($base) + 1;
    $sum = 0;
    foreach (str_split($base) as $i => $digit) {
        $sum += (int) $digit * ($factor - $i);
    }
    $mod = ($sum * 10) % 11;

    return $mod === 10 ? 0 : $mod;
}

do {
    $base = '';
    for ($i = 0; $i < 9; $i++) {
        $base .= (string) random_int(0, 9);
    }
    // 111.111.111-11 e afins passam na conta dos dígitos mas o validador
    // rejeita de propósito -- sortear de novo é mais simples que remendar.
} while (preg_match('/^(\d)\1{8}$/', $base) === 1);

$d1 = cpf_digit($base);
$d2 = cpf_digit($base . $d1);

echo $base . $d1 . $d2;
