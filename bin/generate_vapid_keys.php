<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

// Gera o par de chaves VAPID (P-256) do push (tela 7.2).
//
// A privada vai pra um arquivo PEM fora da raiz servida, com permissão
// 0600; a pública é derivada dela na hora (push/config.php), então não
// existe segunda cópia pra divergir. Rodar de novo NÃO sobrescreve: trocar a
// chave invalida todas as assinaturas dos aparelhos, e isso tem de ser
// decisão consciente (apague o arquivo antes).
//
//   php bin/generate_vapid_keys.php

$path = push_key_path();
if (is_file($path)) {
    fwrite(STDERR, "Já existe chave em {$path}. Apague o arquivo se quiser mesmo trocar (os aparelhos terão de assinar de novo).\n");
    echo 'Pública: ' . push_public_key() . PHP_EOL;
    exit(0);
}

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($key === false) {
    fwrite(STDERR, "O openssl deste PHP não gerou chave EC P-256.\n");
    exit(1);
}
openssl_pkey_export($key, $pem);

$dir = dirname($path);
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Não deu pra criar {$dir}.\n");
    exit(1);
}
file_put_contents($path, $pem);
chmod($path, 0600);

echo "Chave privada: {$path}\n";
echo 'Pública (applicationServerKey): ' . push_public_key() . PHP_EOL;
