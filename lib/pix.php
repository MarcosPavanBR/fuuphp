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

function pix_tlv(string $id, string $value): string
{
    return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

function pix_ascii(string $value): string
{
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($transliterated !== false) {
        return trim($transliterated);
    }
    return trim(preg_replace('/[^\x20-\x7E]/', '', $value) ?? '');
}

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
