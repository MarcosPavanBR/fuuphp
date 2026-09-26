# PWA e modo offline (Fase 7.1)

O app do cliente instala e abre sem rede. Até aqui o rodapé do perfil dizia
"PWA v2.0.0 (parcial)" porque não havia manifest nem service worker; agora
há, e o que ele faz é limitado de propósito.

- **A regra que organiza o cache: navegação pode vir do disco, dinheiro
  nunca.** Só `GET` entra em cache, e entre os GETs só as rotas públicas --
  `restaurants/list`, `show`, `menu` e `search_products`. Perfil, pedidos,
  carrinho, pagamento, painel e entregador passam direto pra rede. Servir
  dado de conta de outra pessoa que usou o mesmo aparelho seria pior que
  ficar sem dado.
- **Requisição com `Authorization` não entra em cache nem em rota pública**,
  porque o header muda o que o servidor devolve e o cache do service worker
  não varia por header.
- **Três estratégias, por motivo diferente.**
  - Arquivos com hash no nome (`/assets/`): cache primeiro. O nome muda a
    cada build, então o que está guardado nunca fica velho.
  - A página e o resto do app (HTML, manifest, ícones): rede primeiro, com
    cópia pro offline. Até a decisão 47 era cache primeiro, e isso prendia o
    cliente na versão antiga depois de cada deploy (conferido no
    navegador).
  - Cardápio e listas: rede primeiro com cópia no cache. Preço velho é pior
    que espera, então a rede ganha sempre que existe, e o cache é o plano B.
  - O resto da API nunca passa pelo cache, com ou sem `Authorization`. O
    acompanhamento ao vivo autentica por ticket na URL, e health e config
    mudam: antes, todo GET da API sem o cabeçalho ficava guardado.
- **A praça escolhida passou a ser salva.** Não era: todo reload mandava o
  cliente refazer a Fase 1. Offline isso seria fatal -- quem reabre o app no
  metrô quer o cardápio salvo, não a tela "onde você está". Foi um achado do
  teste de PWA, não do plano.
- **O convite de instalar só aparece quando o navegador diz que dá.** O
  banner é desenhado pela tela (`beforeinstallprompt` com `preventDefault`),
  mas nunca é mostrado sem o evento -- um botão "Instalar" que não instala
  seria pior que nenhum. "Depois" fica salvo.
- **A fila de upload offline existe (tela 7.1, migração 024).** Só o
  comprovante entra nela, como o mock manda ("Pagamento nunca é enfileirado
  offline"). Sem rede -- ou com a rede caindo no meio do envio -- o arquivo
  vai pro IndexedDB (`fuu-offline` / `proof-uploads`,
  `web/src/lib/uploadQueue.svelte.js`) com um UUID gerado no aparelho. Esse
  UUID viaja como `X-Idempotency-Key` e fica em `payment_proofs.upload_key`
  (índice único parcial): reenviar o mesmo item devolve `200
  {replayed: true}` e o MESMO comprovante, nunca um segundo.
- **Quem esvazia a fila é a página, não o service worker.** A página tem o
  token da sessão; o SW não. O SW só recebe o `sync` (Background Sync, onde
  existe) e pede pra página esvaziar; nos navegadores sem Background Sync,
  o evento `online` e a abertura do app cobrem. 401 deixa o item na fila até
  a pessoa entrar de novo; recusa definitiva (pedido cancelado) tira.
