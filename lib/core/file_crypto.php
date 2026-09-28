<?php
declare(strict_types=1);

// Arquivo sensível cifrado em disco (auditoria DevSecOps de 27/09/2026):
// documento de entregador (CNH, selfie, CRLV, comprovante de residência).
// Fica fora da raiz servida e só sai por rota autenticada; cifrado, um
// vazamento do disco ou de uma cópia do storage não expõe a CNH de ninguém.
//
// sodium (secretbox: XSalsa20-Poly1305), que já vem no PHP -- nenhuma
// biblioteca nova. Formato: "FUUE1" + nonce (24 bytes) + cifra. O prefixo
// deixa ler o arquivo antigo, gravado em claro antes disto, sem migração.
//
// Chave: DATA_ENCRYPTION_KEY no .env do servidor, 32 bytes em base64
// (`php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`). Em produção e
// homologação a trava de produção exige a chave. Fora delas, sem a variável,
// vale uma chave derivada do JWT_SECRET, pra que o caminho cifrado rode nos
// testes. PERDER A CHAVE É PERDER OS DOCUMENTOS: ela vai junto no cofre de
// senhas, fora da VPS (docs/GO_LIVE.md).

const FILE_CRYPTO_MAGIC = 'FUUE1';

/** A chave de 32 bytes, ou null se a configurada é inválida. */
function file_crypto_key(): ?string
{
    $configured = (string) env('DATA_ENCRYPTION_KEY', '');
    if ($configured !== '') {
        $key = base64_decode($configured, true);

        return $key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $key : null;
    }
    if (is_production_like()) {
        return null;
    }

    return sodium_crypto_generichash('fuu-dev-data-key|' . (string) env('JWT_SECRET', ''), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function file_encrypt(string $plain): string
{
    $key = file_crypto_key() ?? throw new RuntimeException('DATA_ENCRYPTION_KEY ausente ou inválida');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    return FILE_CRYPTO_MAGIC . $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
}

/** Decifra; arquivo sem o prefixo é o antigo, em claro, e volta como está. */
function file_decrypt(string $stored): string
{
    if (!str_starts_with($stored, FILE_CRYPTO_MAGIC)) {
        return $stored;
    }
    $key = file_crypto_key() ?? throw new RuntimeException('DATA_ENCRYPTION_KEY ausente ou inválida');
    $offset = strlen(FILE_CRYPTO_MAGIC);
    $nonce = substr($stored, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($stored, $offset + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    if ($plain === false) {
        throw new RuntimeException('arquivo cifrado não abriu (chave trocada ou arquivo corrompido)');
    }

    return $plain;
}
