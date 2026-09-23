# Marca FUUdelivery

A identidade parte do guia do mock ("IDENTIDADE FUUDELIVERY"). Este documento
diz onde estão os arquivos e como usar. O guia visual (as mesmas regras,
com as imagens) foi entregue ao Marcos como página à parte.

## Conceito

O nome já é um som: *fuu* é sopro, velocidade, comida quente saindo. O
vermelho é quente e vai em dose pequena, sobre branco: a comida é o que tem
cor, e a marca aparece no botão, no logotipo e no que está ativo. Não existe
versão pra fundo escuro, por escolha.

## Slogan

- **Principal: "Sua cidade, num sopro."** Já está no mock e na splash do app,
  e é o que vai na prévia do link.
- Apoio, conforme o canal. A escolha é do Marcos:
  - "Pediu, fuu, chegou.": anúncio e vídeo curto. Promete rapidez, então só
    vale com o tempo de entrega bom de verdade.
  - "O delivery da sua cidade.": subtítulo na loja de apps e no Google.
  - Lojas: "Sua loja no app da cidade, com 8% de comissão." Vale enquanto a
    política for 8%.
  - Entregadores: "Você vê o valor antes de aceitar. Repasse toda terça."

## Arquivos (`web/public/`, publicados com o site)

| Arquivo | Uso |
|---|---|
| `brand/fuu-simbolo.svg`, `-negativo`, `-mono` | símbolo (ícone): principal, sobre vermelho, uma cor |
| `brand/fuu-logotipo.svg`, `-branco` | só a palavra |
| `brand/fuu-logo-horizontal.svg`, `-branco`, `-mono` | símbolo + palavra lado a lado |
| `brand/fuu-logo-vertical.svg`, `-slogan` | símbolo em cima, palavra (e slogan) embaixo |
| `brand/og-image.png` | prévia do link no WhatsApp, Instagram e Facebook (1200 × 630) |
| `favicon.svg` | aba do navegador (letras maiores, pra ler em 16 px) |
| `icon-192.png`, `icon-512.png` | ícone do app instalado |
| `icon-maskable-512.png` | Android: quadrado cheio, letras dentro da zona segura |
| `apple-touch-icon.png` | tela inicial do iPhone/iPad (quadrado cheio; o iOS arredonda) |

Os SVG são o original: as letras estão em contorno (Bricolage Grotesque 800 e
600, Familjen Grotesk 500 no slogan, todas com licença OFL), então abrem
iguais em qualquer editor vetorial, sem fonte instalada. Os PNG foram
rasterizados desses SVG no Chromium.

A prévia do link precisa de endereço absoluto: o deploy lê o `PUBLIC_ORIGIN`
do `.env` e passa pro build (`VITE_PUBLIC_ORIGIN`, usado no `og:image` de
`web/index.html`).

## Regras

- **FUU em peso máximo, delivery em peso leve.** Nunca a palavra inteira em
  caixa alta, nunca as duas partes na mesma cor.
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
