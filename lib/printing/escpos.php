<?php
declare(strict_types=1);

// Impressora térmica ESC/POS (cláusula zero da stack) -- a comanda da cozinha
// (4.5, 7.3, 11.1) e o recibo de baixa de espécie (9.3/9.4).
//
// Um documento é descrito UMA vez, como lista de linhas, e sai de dois jeitos:
//   escpos_render()  bytes pra impressora (padrão Epson, o que as térmicas
//                    nacionais de 58/80 mm entendem);
//   escpos_text()    texto puro na mesma largura -- a pré-visualização da tela
//                    e a impressão pelo navegador quando não há USB.
//
// Linha: ['text' => '...', 'bold' => bool, 'big' => bool, 'align' => 'left'|'center'|'right']
//        ['pair' => ['esquerda', 'direita'], 'bold' => bool]   (valor alinhado à direita)
//        ['rule' => true]                                      (linha tracejada)
//        ['feed' => n]                                         (n linhas em branco)
//
// Texto vai em CP860 (página de código portuguesa, tabela 3 do ESC t): é o
// que faz "ção" sair "ção" e não "Ã§Ã£o". Largura em colunas: 48 (80 mm) ou
// 32 (58 mm) -- é do aparelho, então quem pede diz.

const ESCPOS_COLUMNS = [32, 42, 48];

/** Quebra um texto na largura, sem cortar palavra quando dá. */
function escpos_wrap(string $text, int $width): array
{
    $lines = [];
    foreach (explode("\n", $text) as $paragraph) {
        // Recuo no começo ("   + borda", "   OBS: ...") vale pra todas as
        // linhas do parágrafo: é ele que mostra que a opção é do item de cima.
        $indent = str_repeat(' ', min(strspn($paragraph, ' '), intdiv($width, 2)));
        if ($indent !== '') {
            foreach (escpos_wrap(ltrim($paragraph, ' '), $width - strlen($indent)) as $line) {
                $lines[] = $indent . $line;
            }
            continue;
        }
        $current = '';
        foreach (preg_split('/\s+/', trim($paragraph)) ?: [] as $word) {
            while (mb_strlen($word) > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }
            $candidate = $current === '' ? $word : "{$current} {$word}";
            if (mb_strlen($candidate) > $width) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        $lines[] = $current;
    }

    return $lines;
}

/**
 * Completa o texto até a largura, alinhado à esquerda, direita ou centro,
 * contando caracteres (não bytes: "ç" ocupa uma coluna na bobina).
 */
function escpos_pad(string $text, int $width, string $align): string
{
    $gap = max(0, $width - mb_strlen($text));

    return match ($align) {
        'center' => str_repeat(' ', intdiv($gap, 2)) . $text . str_repeat(' ', $gap - intdiv($gap, 2)),
        'right' => str_repeat(' ', $gap) . $text,
        default => $text . str_repeat(' ', $gap),
    };
}

/**
 * As linhas de texto que um item vira, na largura dada (letra grande ocupa
 * o dobro, então cabe metade).
 */
function escpos_item_lines(array $item, int $columns, bool $forPrinter = true): array
{
    if (isset($item['rule'])) {
        return [str_repeat('-', $columns)];
    }
    if (isset($item['feed'])) {
        return array_fill(0, (int) $item['feed'], '');
    }
    // No papel, letra dupla ocupa duas colunas; na tela (texto), uma.
    $width = !empty($item['big']) && $forPrinter ? intdiv($columns, 2) : $columns;
    if (isset($item['pair'])) {
        [$left, $right] = $item['pair'];
        $room = $width - mb_strlen($right) - 1;
        $wrapped = escpos_wrap($left, max(8, $room));
        $last = array_pop($wrapped);
        $out = array_map(static fn (string $l): string => escpos_pad($l, $width, 'left'), $wrapped);
        $out[] = escpos_pad($last, $width - mb_strlen($right), 'left') . $right;

        return $out;
    }

    return array_map(
        static fn (string $l): string => escpos_pad($l, $width, $item['align'] ?? 'left'),
        escpos_wrap((string) $item['text'], $width)
    );
}

/** Texto puro na largura da impressora (pré-visualização e impressão pelo navegador). */
function escpos_text(array $items, int $columns): string
{
    $out = [];
    foreach ($items as $item) {
        foreach (escpos_item_lines($item, $columns, false) as $line) {
            $out[] = rtrim($line);
        }
    }

    return implode("\n", $out) . "\n";
}

/** Os bytes ESC/POS do documento, com corte de papel no fim. */
function escpos_render(array $items, int $columns): string
{
    $ESC = "\x1B";
    $GS = "\x1D";
    $bytes = "{$ESC}@" . "{$ESC}t\x03";            // reinicia; página de código 860 (português)
    foreach ($items as $item) {
        $big = !empty($item['big']);
        $bold = !empty($item['bold']);
        $bytes .= "{$ESC}E" . ($bold ? "\x01" : "\x00");
        $bytes .= "{$GS}!" . ($big ? "\x11" : "\x00"); // altura e largura dobradas
        foreach (escpos_item_lines($item, $columns) as $line) {
            $encoded = iconv('UTF-8', 'CP860//TRANSLIT', rtrim($line));
            $bytes .= ($encoded === false ? rtrim($line) : $encoded) . "\n";
        }
    }
    $bytes .= "{$ESC}E\x00{$GS}!\x00";
    $bytes .= "\n\n\n" . "{$GS}V\x42\x00";            // avança e corta (corte parcial)

    return $bytes;
}
