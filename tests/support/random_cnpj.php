<?php
declare(strict_types=1);

// Gera um CNPJ aleatório com dígito verificador válido, pra seed de teste
// que não colide entre scripts de smoke test rodando no mesmo banco (a
// UNIQUE de restaurants.cnpj não perdoa dois scripts usando o mesmo valor
// fixo). Mesmo algoritmo de lib/core/validation.php::is_valid_cnpj, ao contrário.

function calc_digit(string $base): int
{
    $weights = strlen($base) === 12
        ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
        : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    $sum = 0;
    foreach (str_split($base) as $i => $digit) {
        $sum += (int) $digit * $weights[$i];
    }
    $mod = $sum % 11;
    return $mod < 2 ? 0 : 11 - $mod;
}

$base = '';
for ($i = 0; $i < 12; $i++) {
    $base .= (string) random_int(0, 9);
}
$d1 = calc_digit($base);
$d2 = calc_digit($base . $d1);

echo $base . $d1 . $d2;
