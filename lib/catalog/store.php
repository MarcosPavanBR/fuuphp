<?php
declare(strict_types=1);

// Operação da loja (Fase 11.2 a 11.4).

// "Aumentar sozinho quando a fila passar de 8 pedidos" (tela 11.2). O número
// 8 é do mock; o acréscimo não está escrito em lugar nenhum, então é o menor
// valor que muda alguma coisa pra quem lê: dez minutos.
const PREP_QUEUE_THRESHOLD = 8;
const PREP_BUMP_MINUTES = 10;

/**
 * Tempo de preparo que o cliente vê agora.
 *
 * O acréscimo automático é calculado na hora de ler, nunca gravado por cima
 * de `restaurants.prep_minutes`: se fosse gravado, a fila esvaziaria e o
 * número combinado pela loja teria sumido -- e ninguém saberia qual era o
 * valor "normal" pra voltar.
 */
function effective_prep_minutes(PDO $pdo, string $restaurantId, ?array $store = null): array
{
    if ($store === null) {
        $stmt = $pdo->prepare('SELECT prep_minutes, prep_auto_bump FROM restaurants WHERE id = :id');
        $stmt->execute(['id' => $restaurantId]);
        $store = $stmt->fetch();
        if ($store === false) {
            return ['base' => 30, 'effective' => 30, 'bumped' => false, 'queue' => 0];
        }
    }

    $queueStmt = $pdo->prepare(
        "SELECT count(*) FROM orders
          WHERE restaurant_id = :id AND status IN ('paid','preparing')"
    );
    $queueStmt->execute(['id' => $restaurantId]);
    $queue = (int) $queueStmt->fetchColumn();

    $base = (int) $store['prep_minutes'];
    // Quem chama pode passar uma linha sem prep_auto_bump (ex.: vitrine): sem a coluna, não acelera.
    $bumped = ($store['prep_auto_bump'] ?? false) === true &&$queue > PREP_QUEUE_THRESHOLD;

    return [
        'base' => $base,
        'effective' => $bumped ? $base + PREP_BUMP_MINUTES : $base,
        'bumped' => $bumped,
        'queue' => $queue,
    ];
}

/**
 * Categorias de loja, na ordem em que aparecem nos atalhos da Home (tela
 * 2.1). O cadastro só aceita estas: a categoria vira filtro, e texto livre
 * quebraria o filtro.
 *
 * As seis primeiras do mock (Lanches, Pizza, Restaurante, Mercado, Farmácia,
 * Doces) continuam; as outras vieram da comparação com o concorrente, que
 * vende também o comércio do bairro que não é comida (perfumaria, moda,
 * pet shop...). A Home só mostra as que têm loja na cidade.
 */
const STORE_CATEGORIES = [
    'Lanches', 'Hamburgueria', 'Pizza', 'Marmitas', 'Restaurante', 'Japonesa', 'Espetinhos e porções',
    'Açaí e sorvetes', 'Doces', 'Padaria e café', 'Mercado', 'Bebidas', 'Farmácia', 'Pet shop',
    'Beleza e perfumaria', 'Moda e presentes', 'Tabacaria', 'Outros',
];

/**
 * A loja pode receber pedido agora? Encerra com 404 se não existe e 409 se
 * ainda não foi aprovada pela plataforma ou está fechada. Devolve a linha.
 *
 * A lista da Home já esconde loja não aprovada, mas link direto chegaria no
 * carrinho e no checkout: com o cadastro aberto (restaurants/signup.php),
 * loja ainda não conferida não pode vender. Usada em carrinho, pedido,
 * checkout e "pedir de novo".
 */
function require_store_accepting_orders(PDO $pdo, string $restaurantId): array
{
    $stmt = $pdo->prepare('SELECT * FROM restaurants WHERE id = :id');
    $stmt->execute(['id' => $restaurantId]);
    $restaurant = $stmt->fetch();
    if ($restaurant === false) {
        error_response(404, 'restaurant_not_found', 'Loja não encontrada.');
    }
    if ($restaurant['approved_at'] === null) {
        error_response(409, 'store_not_available', 'Essa loja ainda não está recebendo pedidos.');
    }
    if (!$restaurant['is_open']) {
        error_response(409, 'store_closed', 'Essa loja está fechada agora.');
    }

    return $restaurant;
}
