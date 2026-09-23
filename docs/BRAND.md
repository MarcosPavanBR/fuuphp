# Marca FUUdelivery

A identidade parte do guia do mock ("IDENTIDADE FUUDELIVERY": cores, fontes e
voz). O símbolo e o slogan foram refeitos na v2. Este documento
diz onde estão os arquivos e como usar. O guia visual (as mesmas regras,
com as imagens) foi entregue ao Marcos como página à parte.

## Conceito

O nome é um som: o "fuu" do vapor da comida quente e da entrega que passa
ligeiro. O **símbolo** é um U que é ao mesmo tempo tigela e sorriso, com dois
fios de vapor subindo: a letra do nome, comida quente e cliente satisfeito.
O **logotipo** é em minúsculas: **fuu** em peso máximo (tinta) e delivery em
peso leve (cinza), colados. No texto corrido, o nome continua FUUdelivery.

O vermelho vai em dose pequena, sobre branco: a comida é o que tem cor. Não
existe versão pra fundo escuro, por escolha.

## Slogan

- **Principal: "Pediu, fuu, chegou."** Na splash do app, no título do site e
  na prévia do link.
- Apoio, conforme o canal:
  - "Bateu a fome? Fuu.": anúncio, story, vídeo curto.
  - "O delivery da sua cidade.": subtítulo na loja de apps e no Google.
  - Lojas: "Venda mais, pague só 8%." Vale enquanto a comissão for 8%.
  - Entregadores: "Você vê o valor antes de aceitar. Recebe toda terça."

## Arquivos (`web/public/`, publicados com o site)

| Arquivo | Uso |
|---|---|
| `brand/fuu-simbolo.svg`, `-negativo`, `-mono` | símbolo (ícone): principal, sobre vermelho, uma cor |
| `brand/fuu-logotipo.svg`, `-branco` | só a palavra |
| `brand/fuu-logo-horizontal.svg`, `-branco`, `-mono` | símbolo + palavra lado a lado |
| `brand/fuu-logo-vertical.svg`, `-slogan` | símbolo em cima, palavra (e slogan) embaixo |
| `brand/og-image.png` | prévia do link no WhatsApp, Instagram e Facebook (1200 × 630) |
| `favicon.svg` | aba do navegador |
| `icon-192.png`, `icon-512.png` | ícone do app instalado |
| `icon-maskable-512.png` | Android: quadrado cheio, desenho dentro da zona segura |
| `apple-touch-icon.png` | tela inicial do iPhone/iPad (quadrado cheio; o iOS arredonda) |

Os SVG são o original: o símbolo é desenho geométrico e as letras estão em
contorno (Bricolage Grotesque 800 e 600, Familjen Grotesk 500 no slogan, todas
com licença OFL), então abrem
iguais em qualquer editor vetorial, sem fonte instalada. Os PNG foram
rasterizados desses SVG no Chromium.

A prévia do link precisa de endereço absoluto: o deploy lê o `PUBLIC_ORIGIN`
do `.env` e passa pro build (`VITE_PUBLIC_ORIGIN`, usado no `og:image` de
`web/index.html`).

## Regras

- **fuu em peso máximo, delivery em peso leve, em minúsculas.** Nunca o logo
  em caixa alta, nunca as duas partes na mesma cor.
- Em volta do símbolo, deixe livre pelo menos 1/4 da largura dele.
- Tamanhos mínimos: 24 px pro símbolo (abaixo disso, use o favicon), 24 px de
  altura pro horizontal e 80 px de largura pro logotipo.
- Não esticar, inclinar, girar, contornar nem pôr sombra ou degradê. Não
  trocar o tom do vermelho.
- Cores e tipografia: os tokens de `web/src/styles/tokens.css` (vermelho
  `#CC2B1D`, folha `#10633A`, alerta `#B3231A` só em texto e contorno, espera
  `#FFF6E5`/`#8A5A00`, papel `#F7F7F7`, tinta `#2F2F2F`/`#717171`).
- Voz: frase curta, sem emoji na interface, número sempre com unidade, e nunca
  comemorar o que ainda não aconteceu.

No app, o símbolo é o componente `web/src/lib/components/BrandMark.svelte`
(splash, logins, avaliação e topo dos painéis).
