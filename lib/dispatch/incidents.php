<?php
declare(strict_types=1);

// Tela 13.3 — "Entregador: ocorrência na entrega".
//
// É o "Problema" da tela 8.5 aberto. A tela existe por um motivo econômico,
// não estético: "sem isso, entregador abandona pedido difícil em vez de
// registrar". Então tudo aqui está a serviço de duas garantias:
//
//   1. a prova é obrigatória e é gravada (foto, GPS, hora, tentativas);
//   2. "Você recebe a corrida integral nas duas saídas" -- devolver à loja
//      ou descartar paga o frete igual.
//
// Os quatro motivos são, literalmente, o CHECK de `delivery_incidents.kind`
// (migração 008). Não há um quinto: a tela e o banco dizem a mesma coisa.

const INCIDENT_WAIT_MINUTES = 10;

const INCIDENT_KINDS = [
    'customer_absent' => [
        'label' => 'Cliente não atende',
        // A linha de baixo do mock ("liguei 2 vezes · toquei a campainha")
        // não é texto fixo: é o que foi registrado. Ver incident_context().
        'needs_call' => true,
    ],
    'no_cash' => [
        'label' => 'Cliente sem o valor / sem troco',
        'needs_call' => false,
    ],
    'bad_address' => [
        'label' => 'Endereço não existe ou errado',
        'needs_call' => false,
    ],
    'unsafe_area' => [
        'label' => 'Local sem segurança / recusei subir',
        'needs_call' => false,
    ],
];

// O CHECK de `delivery_incidents.resolution`. Quem escolhe é o suporte, não
// o entregador -- "passado o prazo, o suporte libera".
const INCIDENT_RESOLUTIONS = [
    'returned' => 'Devolver à loja',
    'discarded' => 'Descartar',
    'delivered' => 'Entregar assim mesmo',
];

/**
 * O rastro registrado nesta corrida: chegada, ligações e campainha, com hora.
 */
function incident_attempts(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, kind, created_at FROM delivery_attempts
          WHERE order_id = :id ORDER BY created_at, id'
    );
    $stmt->execute(['id' => $orderId]);

    return $stmt->fetchAll();
}

/**
 * Monta o cabeçalho da tela 13.3 a partir do que existe, nunca de texto
 * inventado: "#C71A04 · R$ 78,40 em dinheiro · 6 min no endereço."
 *
 * `waited_minutes` conta da marca de chegada (`delivery_attempts` kind
 * 'arrival'). Sem chegada registrada, não há quanto tempo no local -- e a
 * tela diz isso em vez de chutar zero.
 */
function incident_context(PDO $pdo, array $order): array
{
    $attempts = incident_attempts($pdo, (int) $order['id']);

    $arrivedAt = null;
    $calls = [];
    $bells = 0;
    foreach ($attempts as $attempt) {
        if ($attempt['kind'] === 'arrival') {
            $arrivedAt = (string) $attempt['created_at'];
        } elseif ($attempt['kind'] === 'call') {
            $calls[] = (string) $attempt['created_at'];
        } else {
            $bells++;
        }
    }

    $waited = $arrivedAt === null
        ? null
        : (int) floor((time() - strtotime($arrivedAt)) / 60);

    return [
        'order_id' => (int) $order['id'],
        'public_code' => (string) $order['public_code'],
        'total' => (float) $order['total'],
        'payment_method' => (string) $order['payment_method'],
        'delivery_fee' => (float) $order['delivery_fee'],
        'arrived_at' => $arrivedAt,
        'waited_minutes' => $waited,
        'call_times' => $calls,
        'call_attempts' => count($calls),
        'bell_attempts' => $bells,
        'wait_minutes_required' => INCIDENT_WAIT_MINUTES,
        // "Espere 10 min no local." Vale só pra cliente ausente: endereço que
        // não existe não melhora esperando, e local sem segurança piora.
        'wait_satisfied' => $waited !== null && $waited >= INCIDENT_WAIT_MINUTES,
        'kinds' => INCIDENT_KINDS,
    ];
}

/**
 * Por que o pedido não pôde ser entregue, em uma frase, pra fila do admin e
 * pro cliente que vai ler no acompanhamento.
 */
function incident_summary(array $incident): string
{
    $label = INCIDENT_KINDS[(string) $incident['kind']]['label'] ?? (string) $incident['kind'];
    $calls = (int) $incident['call_attempts'];

    $trail = $calls > 0
        ? sprintf('%d %s registrada%s', $calls, $calls === 1 ? 'ligação' : 'ligações', $calls === 1 ? '' : 's')
        : 'sem ligação registrada';

    return $label . ' · ' . $trail;
}
