<?php
declare(strict_types=1);

// Gera o "Pix Copia e Cola" (BR Code) no formato EMV do Banco Central
// (Manual de Padrões para Iniciação do Pix, QR estático): campos TLV
// (id + tamanho em 2 dígitos + valor), fechados por CRC16 (ID 63).
// O algoritmo do CRC (poly 0x1021, init 0xFFFF, sem reflexão) é o
// CRC-16/CCITT-FALSE -- conferido aqui contra o vetor de teste padrão
// ("123456789" -> 0x29B1) antes de entrar em uso, porque é dinheiro de
// verdade saindo da conta de alguém: um CRC errado gera um código que
// nenhum banco aceita.

function pix_copy_paste(string $pixKey, float $amount, string $txid, string $merchantName, string $merchantCity): string
{
    $merchantAccount = pix_tlv('00', 'br.gov.bcb.pix') . pix_tlv('01', $pixKey);
    $cleanTxid = preg_replace('/[^A-Za-z0-9]/', '', $txid) ?: '***';
    $additionalData = pix_tlv('05', substr($cleanTxid, 0, 25));

    $payload =
        pix_tlv('00', '01') .
        pix_tlv('26', $merchantAccount) .
        pix_tlv('52', '0000') .
        pix_tlv('53', '986') .
        pix_tlv('54', number_format($amount, 2, '.', '')) .
        pix_tlv('58', 'BR') .
        pix_tlv('59', substr(pix_ascii($merchantName), 0, 25) ?: 'FUUDELIVERY') .
        pix_tlv('60', substr(pix_ascii($merchantCity), 0, 15) ?: 'BRASIL') .
        pix_tlv('62', $additionalData);

    $payload .= '6304'; // ID 63, tamanho fixo 04: os 4 dígitos hex do CRC
    return $payload . pix_crc16($payload);
}

/**
 * Um campo do BR Code: id + tamanho com 2 dígitos + valor (EMV TLV).
 */
function pix_tlv(string $id, string $value): string
{
    return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

/**
 * Tira acento e o que não é ASCII imprimível: o BR Code só aceita isso em
 * nome e cidade do recebedor.
 */
function pix_ascii(string $value): string
{
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($transliterated !== false) {
        return trim($transliterated);
    }
    return trim(preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');
}

/**
 * CRC16-CCITT (0x1021, início 0xFFFF) do payload, em 4 dígitos hex: o campo
 * 63 que fecha o copia-e-cola.
 */
function pix_crc16(string $payload): string
{
    $crc = 0xFFFF;
    for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
        $crc ^= ord($payload[$i]) << 8;
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/**
 * Confere a chave Pix que uma LOJA cadastra pra receber o Pix direto (4.3).
 *
 * O dinheiro do cliente cai nessa chave, então ela precisa ser da loja:
 *   - CNPJ: tem que ser o CNPJ da própria loja (confere aqui);
 *   - CPF: recusado -- loja recebe como empresa, e CPF de terceiro seria o
 *     jeito mais fácil de desviar o Pix;
 *   - e-mail, telefone (+55) ou chave aleatória (EVP): aceitos, mas a
 *     titularidade não dá pra conferir aqui -- volta `ownership_checked=false`
 *     e o admin confere antes de aprovar (a fila 12.1 mostra).
 *
 * Devolve a chave normalizada, o tipo e se a titularidade foi conferida, ou
 * a mensagem do erro (pro campo `pix_key` do formulário).
 *
 * @return array{ok: bool, key?: string, kind?: string, ownership_checked?: bool, error?: string}
 */
function store_pix_key_check(string $rawKey, string $storeCnpj): array
{
    $key = trim($rawKey);
    $digits = only_digits($key);

    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) === 1) {
        return ['ok' => true, 'key' => strtolower($key), 'kind' => 'evp', 'ownership_checked' => false];
    }
    if (filter_var($key, FILTER_VALIDATE_EMAIL) !== false) {
        return ['ok' => true, 'key' => strtolower($key), 'kind' => 'email', 'ownership_checked' => false];
    }
    if (strlen($digits) === 14 && is_valid_cnpj($digits) && preg_match('/^[\d.\/\-\s]+$/', $key) === 1) {
        if ($digits !== $storeCnpj) {
            return ['ok' => false, 'error' => 'A chave CNPJ precisa ser o CNPJ desta loja.'];
        }

        return ['ok' => true, 'key' => $digits, 'kind' => 'cnpj', 'ownership_checked' => true];
    }
    if (strlen($digits) === 11 && is_valid_cpf($digits) && !str_starts_with($key, '+')) {
        return ['ok' => false, 'error' => 'Use uma chave da empresa (CNPJ, e-mail, telefone ou aleatória), não um CPF.'];
    }
    $phone = phone_to_e164($digits);
    if ($phone !== null && preg_match('/^[\d()+\-\s]+$/', $key) === 1) {
        return ['ok' => true, 'key' => $phone, 'kind' => 'phone', 'ownership_checked' => false];
    }

    return ['ok' => false, 'error' => 'Chave Pix inválida: use CNPJ, e-mail, telefone com DDD ou chave aleatória.'];
}
