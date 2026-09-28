# 41 — Conferência final: o que ainda era do mock

**De onde veio:** depois de "não falta nada", o Marcos perguntou "Tem certeza???".
A conferência foi refeita olhando o que cada tela **mostra**, não só o que o
código faz. Achou texto e número do mock prometendo o que o sistema não faz.

## O que foi corrigido

- **Barra de status falsa** (9:41, sinal, bateria) no splash e na escolha de
  estado e cidade. Saiu o componente; o topo usa a área segura do aparelho.
- **Troco fixo em 60/70/100.** Agora sai do total: o próximo múltiplo de 10,
  de 50 e de 100 acima do valor, sem repetir.
- **Splash** dizia "aquecendo cardápios" (não aquece nada): virou "abrindo…".
- **Configurações** mostravam "PWA 2.0.0": mostra a versão real do service
  worker, ou nada.
- **Pausa da loja** avisava que pausa longa "derruba o ranking" e tira o selo
  "Confiável". Nenhum dos dois existe (a Home ordena por distância). Ficou o
  limite de referência de 2 h e quanto já pausou hoje.
- **Pix direto na chave da loja** tinha uma caixa "QR Code gerado pelo backend"
  sem QR nenhum. Virou a instrução do copia e cola. Desenhar o QR pede uma
  biblioteca: **decisão do Marcos**, não entrou.
- **Avisos com linguagem de desenvolvedor** ("BETA no mock", "neste backend",
  "Fase 4") trocados por frases pro cliente.
- **"Chega entre" na escolha do pagamento** era 25–45 min fixo, pra qualquer
  loja. Agora `addresses/quote.php` devolve `eta_minutes` com a mesma conta do
  card da Home (preparo efetivo com a fila + viagem à velocidade média); sem
  distância, não devolve nada e a linha some. Pedido agendado mostra a faixa
  escolhida.
- **Exceções de política** (`policy_overrides`): eram aplicadas da mais nova
  pra mais antiga, então uma exceção velha sobrescrevia a nova; e as da praça
  (`scope='city'`) eram ignoradas. Agora entram praça → loja, e dentro de cada
  uma a mais nova vence. A praça é a cidade da loja (`city_ibge_code`), que
  não existia quando a [02](02-catalogo-pedido-checkout.md) foi escrita. Não
  havia tela que criasse exceção: veio logo abaixo.

## Depois, com o sim do Marcos

- **Aba de exceções no admin** (`admin/policy_overrides.php`, painel embaixo
  da política na aba Políticas). Cria exceção pra uma cidade ou uma loja:
  frete (base, por km, raio), comissão e taxa de cancelamento -- só o que o
  checkout lê do snapshot, com as mesmas faixas da política (comissão até
  30%). Motivo obrigatório, último dia opcional (no relógio da cidade),
  campo em branco segue a plataforma. Encerrar não apaga: fica o rastro de
  por que um pedido antigo teve aquele número. Tudo no `audit_log`.
- **Banner de cidade no relógio da cidade.** As datas do banner viram a
  meia-noite da cidade dele (em MS, 1 h depois de Brasília); banner de todas
  as cidades segue Brasília.

Testes: `smoke_address.sh` confere a aba de exceções (só admin, faixas,
campo fora da lista, motivo, precedência, encerrar); `smoke_timezone.sh`, o
banner de MS na meia-noite de MS. `smoke_address.sh` confere também a estimativa (e a ausência dela sem
distância) e a ordem das exceções.

## Fora, de propósito

- Login social ("em breve"), cripto (BETA desligado) e cashback: decisões já
  tomadas. Backup fora do servidor: aguardando o Marcos.
