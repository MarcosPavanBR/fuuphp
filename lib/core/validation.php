<?php
declare(strict_types=1);

// Validação de dado brasileiro e de contato: CPF e CNPJ pelos dígitos
// verificadores, telefone com DDD, e-mail. Tudo recebe só os dígitos
// (`only_digits`) -- máscara é coisa da tela, não do dado.

function only_digits(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

/**
 * CPF com os dois dígitos verificadores certos (e não todos iguais).
 */
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

/**
 * CNPJ com os dois dígitos verificadores certos (e não todos iguais).
 */
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

/**
 * E-mail no formato aceito pelo filtro do PHP.
 */
function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Telefone brasileiro: de 10 a 13 dígitos (DDD + número, com ou sem 55).
 */
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

/**
 * Data AAAA-MM-DD que existe no calendário: 2026-02-30 não passa. Conferir
 * antes evita que a data impossível vire 500 (22008) no banco.
 */
function is_valid_date(mixed $value): bool
{
    if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1900 && (int) $m[1] <= 2100;
}

/**
 * Número (int, float ou texto numérico) finito entre $min e $max. Booleano,
 * lista, "NaN" e 1e30 não passam: o que não cabe na coluna numeric vira 500
 * (22003) se chegar no banco.
 */
function is_number_between(mixed $value, float $min, float $max): bool
{
    if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
        return false;
    }
    $n = (float) $value;
    return is_finite($n) && $n >= $min && $n <= $max;
}

/** Inteiro (int, ou texto só com dígitos e sinal) entre $min e $max. */
function is_int_between(mixed $value, int $min, int $max): bool
{
    if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
        $value = (int) $value;
    }
    return is_int($value) && $value >= $min && $value <= $max;
}

/** Código IBGE de município: 7 dígitos. */
function is_valid_ibge(mixed $value): bool
{
    return is_string($value) && preg_match('/^\d{7}$/', $value) === 1;
}

/**
 * Id numérico de registro (bigint positivo) vindo do corpo ou da query:
 * devolve o int ou null. "12" e 12 valem; "", "x", 1.5, [] e 0 não.
 */
function positive_id(mixed $value): ?int
{
    return is_int_between($value, 1, PHP_INT_MAX) ? (int) $value : null;
}

/**
 * Horário HH:MM (ou HH:MM:SS) que existe no relógio: 24:00 e 99:99 não
 * passam (o formato sozinho aceitava, e o banco dava 500 no cast pra time).
 */
function is_valid_time(mixed $value): bool
{
    return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) === 1;
}

/**
 * Valor em reais vindo da requisição: número finito entre $min e $max,
 * arredondado nos centavos -- ou null se não for. É o que separa "valor"
 * de "(float) de qualquer coisa": (float) "x" dava 0, (float) [] dava 0 ou
 * 1, e 1e30 passava no is_numeric e estourava a coluna numeric (500).
 */
function money_input(mixed $value, float $min, float $max): ?float
{
    return is_number_between($value, $min, $max) ? round((float) $value, 2) : null;
}

/** Coordenada (lat até 90, lng até 180) vinda da requisição, ou null. */
function coord_input(mixed $value, float $limit): ?float
{
    return is_number_between($value, -$limit, $limit) ? round((float) $value, 6) : null;
}

/**
 * Texto do corpo da requisição, aparado: null se não veio (ou veio null).
 * Número inteiro vira texto (CEP e número de casa às vezes chegam assim);
 * lista, objeto, booleano ou texto acima de $max encerram com 422, com o
 * campo marcado. Substitui o `trim((string) $body[...])`, que transformava
 * lista em "Array" e deixava 10 mil letras irem pro banco -- e pra comanda
 * impressa, pro painel e pro SMS.
 */
function body_text(array $body, string $key, int $max): ?string
{
    $value = $body[$key] ?? null;
    if ($value === null) {
        return null;
    }
    if (is_int($value)) {
        $value = (string) $value;
    }
    // A mensagem é pra pessoa (o app mostra ela); o nome técnico do campo vai
    // só em `fields`, que é o que a tela usa pra marcar o campo.
    if (!is_string($value)) {
        error_response(422, 'invalid_text', 'Esse campo precisa ser um texto.', fields: [$key => 'texto']);
    }
    $value = trim($value);
    if (mb_strlen($value) > $max) {
        error_response(422, 'text_too_long', "Texto longo demais: vai até {$max} caracteres.", fields: [$key => "até {$max} caracteres"]);
    }
    return $value;
}

/**
 * Texto curto de uma entrada (corpo JSON, query, sub-objeto) pra comparar
 * com lista fechada, extrair dígitos ou buscar: código de ação, tipo,
 * chave, id em texto.
 *
 * - campo ausente (ou null) → $default;
 * - texto → ele mesmo; número → o número em texto;
 * - lista, objeto ou booleano → '' (inválido: não bate com lista fechada
 *   nenhuma, e NÃO vira o $default -- mandar {"action": []} não pode
 *   executar a ação padrão).
 *
 * Substitui o `(string) $body[...]`, que transformava lista em "Array" e
 * deixava um aviso no log a cada requisição de robô.
 */
function input_str(mixed $source, string $key, string $default = ''): string
{
    if (!is_array($source) || !array_key_exists($key, $source) || $source[$key] === null) {
        return $default;
    }
    $value = $source[$key];
    if (is_string($value)) {
        return $value;
    }
    return is_int($value) || is_float($value) ? (string) $value : '';
}

/**
 * Texto digitado numa busca, pronto pra ir dentro de LIKE/ILIKE: %, _ e \
 * viram literais (senão "%" casava com tudo e "_" com qualquer letra).
 * O \ é o caractere de escape padrão do LIKE no PostgreSQL.
 */
function like_escape(string $text): string
{
    return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
}
