<?php
declare(strict_types=1);

// Dinheiro (auditoria COE-02). O banco guarda numeric(12,2) e é a verdade;
// no PHP, o valor em reais vira CENTAVOS INTEIROS pra somar e subtrair, e
// volta a texto "1234.56" pra gravar. Float somado em sequência (o total da
// semana do netting, o dia da maquininha) acumula resto de binário; inteiro
// não. As contas de uma operação só (round(x * y, 2)) já batem no PHP 8.4,
// mas o que grava no livro passa por aqui, num lugar só.

/**
 * Reais -> centavos inteiros, arredondando meio centavo pra longe do zero
 * (o mesmo do round() do PHP e do numeric do PostgreSQL). Aceita o texto
 * que o PDO devolve ("12.34"), número ou null (0).
 */
function money_cents(string|int|float|null $value): int
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (is_string($value)) {
        // Texto do banco ("-12.345" nunca vem, mas "12.3" e "12" vêm): conta
        // pelos dígitos, sem passar por float.
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($value), $m) === 1) {
            $frac = str_pad($m[3] ?? '', 3, '0');
            $cents = (int) $m[2] * 100 + (int) substr($frac, 0, 2) + ((int) $frac[2] >= 5 ? 1 : 0);

            return $m[1] === '-' ? -$cents : $cents;
        }
        $value = (float) $value;
    }

    return (int) round((float) $value * 100);
}

/** Centavos -> texto pro banco/JSON: 123456 -> "1234.56", -5 -> "-0.05". */
function money_str(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $abs = abs($cents);

    return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** Soma de valores em reais, feita em centavos. Devolve reais (float) com 2 casas exatas. */
function money_sum(iterable $values): float
{
    $cents = 0;
    foreach ($values as $v) {
        $cents += money_cents($v);
    }

    return $cents / 100;
}
