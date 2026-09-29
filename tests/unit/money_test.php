<?php
declare(strict_types=1);

// Testes unitários das regras de dinheiro: funções puras, sem banco e sem
// servidor -- rodam em milissegundos e pegam erro de conta antes das suítes
// de ponta a ponta. Runner próprio (nada de biblioteca nova, cláusula zero).
//
//   php tests/unit/money_test.php      (sai com 1 se alguma falhar)

putenv('APP_ENV=testing');
putenv('FUU_ENV_FILE=/dev/null');
putenv('DATABASE_URL=postgres://ninguem@127.0.0.1:1/nada'); // nunca conectado aqui
require __DIR__ . '/../../lib/bootstrap.php';

$failures = 0;
$count = 0;
function eq(string $name, mixed $expected, mixed $got): void
{
    global $failures, $count;
    $count++;
    if ($expected !== $got) {
        $failures++;
        fwrite(STDERR, "FALHOU {$name}: esperado " . var_export($expected, true) . ', veio ' . var_export($got, true) . "\n");
    }
}

// ── Centavos: o livro-razão soma aqui ────────────────────────────────────
eq('cents texto', 1234, money_cents('12.34'));
eq('cents uma casa', 1230, money_cents('12.3'));
eq('cents inteiro', 1200, money_cents('12'));
eq('cents meio centavo sobe', 1235, money_cents('12.345'));
eq('cents negativo', -505, money_cents('-5.05'));
eq('cents null', 0, money_cents(null));
eq('cents float', 1999, money_cents(19.99));
eq('str', '1234.56', money_str(123456));
eq('str negativo pequeno', '-0.05', money_str(-5));
eq('str zero', '0.00', money_str(0));
// 0.1 somado 10 vezes em float dá 0.9999999999999999; em centavos, 1.0.
eq('soma sem resto binário', 1.0, money_sum(array_fill(0, 10, '0.1')));
eq('soma mista', 30.0, money_sum(['10.10', 9.9, '10']));

// ── Valor vindo da requisição ────────────────────────────────────────────
eq('input ok', 12.35, money_input('12.345', 0, 100));
eq('input acima', null, money_input(101, 0, 100));
eq('input texto', null, money_input('abc', 0, 100));
eq('input lista', null, money_input([1], 0, 100));
eq('input gigante', null, money_input(1e30, 0, 1e6));
eq('input NaN', null, money_input(NAN, 0, 100));

// ── Cupom: nunca vira crédito ────────────────────────────────────────────
eq('cupom fixo', 10.0, coupon_discount(['kind' => 'fixed', 'value' => '10'], 50.0));
eq('cupom fixo acima do subtotal', 8.0, coupon_discount(['kind' => 'fixed', 'value' => '10'], 8.0));
eq('cupom percentual', 7.5, coupon_discount(['kind' => 'percent', 'value' => '15'], 50.0));
eq('cupom percentual arredonda', 3.33, coupon_discount(['kind' => 'percent', 'value' => '10'], 33.33));
eq('cupom 150%', 20.0, coupon_discount(['kind' => 'percent', 'value' => '150'], 20.0));
eq('frete grátis', 7.9, coupon_discount(['kind' => 'free_delivery', 'value' => '0'], 50.0, 7.9));
eq('frete grátis sem frete', 0.0, coupon_discount(['kind' => 'free_delivery', 'value' => '0'], 50.0));
eq('cupom desconhecido', 0.0, coupon_discount(['kind' => 'xyz', 'value' => '99'], 50.0));
eq('cupom negativo', 0.0, coupon_discount(['kind' => 'fixed', 'value' => '-5'], 50.0));

// ── Estorno: taxa, valor e quem paga ─────────────────────────────────────
$policy = ['cancel_fee' => '5.00'];
$order = fn (string $status, string $method, string $total = '50.00') => ['status' => $status, 'payment_method' => $method, 'total' => $total];

$p = refund_plan($order('paid', 'mp_card'), $policy, 'customer_cancel');
eq('antes da cozinha: sem taxa', 0.0, $p['fee']);
eq('antes da cozinha: devolve tudo', 50.0, $p['amount']);
eq('antes da cozinha: grátis', true, $p['free_cancel']);
eq('cartão volta pelo gateway', 'gateway', $p['channel']);

$p = refund_plan($order('preparing', 'mp_card'), $policy, 'customer_cancel');
eq('depois do preparo: taxa', 5.0, $p['fee']);
eq('depois do preparo: devolve o resto', 45.0, $p['amount']);
eq('cliente cancelando: loja paga', 'store', $p['payer']);

$p = refund_plan($order('preparing', 'mp_card', '3.00'), $policy, 'customer_cancel');
eq('taxa nunca passa do total', 3.0, $p['fee']);
eq('taxa = total: nada a devolver', 0.0, $p['amount']);

$p = refund_plan($order('preparing', 'pix_auto'), $policy, 'store_reject');
eq('recusa da loja: sem taxa', 0.0, $p['fee']);
eq('recusa da loja: devolve tudo', 50.0, $p['amount']);

$p = refund_plan($order('preparing', 'cash'), $policy, 'customer_cancel');
eq('dinheiro: nada a estornar', 0.0, $p['amount']);
eq('dinheiro: canal none', 'none', $p['channel']);
eq('dinheiro: plataforma compensa', 'platform', $p['payer']);

$p = refund_plan($order('paid', 'forma_inventada'), $policy, 'customer_cancel');
eq('forma desconhecida: não estorna', 0.0, $p['amount']);

eq('falha nossa: plataforma', 'platform', refund_payer('platform_failure', 'gateway'));
eq('sem entregador: plataforma', 'platform', refund_payer('no_courier', 'pix_return'));
eq('item errado: loja', 'store', refund_payer('wrong_item', 'gateway'));

// Admin mexendo na taxa: o total pago não muda.
$r = ['amount' => '45.00', 'fee' => '5.00'];
eq('perdoar taxa', ['fee' => 0.0, 'amount' => 50.0, 'paid' => 50.0], refund_with_fee($r, 'forgive'));
eq('metade da taxa', ['fee' => 2.5, 'amount' => 47.5, 'paid' => 50.0], refund_with_fee($r, 'half'));
eq('manter taxa', ['fee' => 5.0, 'amount' => 45.0, 'paid' => 50.0], refund_with_fee($r, 'keep'));
eq('ajuste inválido = manter', ['fee' => 5.0, 'amount' => 45.0, 'paid' => 50.0], refund_with_fee($r, 'dobrar'));

if ($failures > 0) {
    fwrite(STDERR, "{$failures} de {$count} falharam\n");
    exit(1);
}
echo "PASS money_test ({$count} verificações)\n";
