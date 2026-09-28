<?php

declare(strict_types=1);

// Fotos de entrega e de ocorrência (telas 8.6 e 13.3): onde ficam e como
// conferir que a foto citada existe mesmo.
//
// Quem grava é couriers/incident_photo.php, só pro entregador DAQUELE
// pedido, com o nome `delivery/<pedido>-<12 primeiros do sha256>.<ext>`.
// Antes, couriers/deliver.php aceitava qualquer texto não vazio como
// "chave da foto" -- dava pra fechar a entrega sem código e sem foto -- e
// gravava o sha256 que o aparelho dizia, o que deixava a foto reaproveitada
// escapar do alerta de fraude. Agora a chave tem que ser deste pedido, o
// arquivo tem que existir, e o hash é recalculado aqui, do conteúdo.

/** Pasta privada das fotos (nunca servida direto pelo Nginx). */
function delivery_photo_dir(): string
{
    return app_path(rtrim((string) env('PROOF_STORAGE_DIR', 'storage/proofs'), '/') . '/delivery');
}

/**
 * A foto que o entregador citou existe e é deste pedido?
 *
 * @return array{key: string, sha256: string}|null  null = chave inventada,
 *         de outro pedido, ou arquivo que não chegou no servidor.
 */
function verified_delivery_photo(int $orderId, mixed $key): ?array
{
    if (!is_string($key) || preg_match('#^delivery/' . $orderId . '-([0-9a-f]{12})\.(jpg|png|webp)$#', $key, $m) !== 1) {
        return null;
    }
    $path = delivery_photo_dir() . '/' . basename($key);
    if (!is_file($path)) {
        return null;
    }
    $sha256 = hash_file('sha256', $path);
    if ($sha256 === false || !str_starts_with($sha256, $m[1])) {
        return null; // arquivo trocado por baixo do nome
    }
    return ['key' => $key, 'sha256' => $sha256];
}
