# Documentação do FUUdelivery

Por onde começar, conforme quem você é e o que precisa.

| Se você quer... | Leia |
|---|---|
| saber como cada público **usa** o sistema (cliente, loja, entregador, plataforma) | [MANUAL.md](MANUAL.md) |
| entender **como o sistema é montado** (camadas, requisição, banco, dinheiro, front) | [ARCHITECTURE.md](ARCHITECTURE.md) |
| achar uma **rota da API**: método, quem chama, o que faz (gerado do código) | [API.md](API.md) |
| achar uma **tabela ou coluna** do banco (gerado das migrações) | [DATABASE.md](DATABASE.md) |
| saber **o que protege** o sistema e onde (login, banco, dinheiro, arquivos, LGPD) | [SECURITY.md](SECURITY.md) |
| **subir e operar**: variáveis, cron, migrações, testes | [OPERATIONS.md](OPERATIONS.md) |
| **pôr no ar** na VPS: servidor, credenciais reais, validação antes de abrir | [GO_LIVE.md](GO_LIVE.md) |
| **escrever código** aqui: nomes, comentários, erros, dinheiro, commits | [CONVENTIONS.md](CONVENTIONS.md) |
| saber **por que** algo é do jeito que é (o que o mock pedia, o que foi feito, o que ficou de fora) | [decisions/](decisions/README.md) |
| entender um **termo** (baixa, netting, KDS, só-online...) | [GLOSSARY.md](GLOSSARY.md) |

## Onde mais há documentação

- **No topo de cada arquivo.** Toda rota (`api/v1/**`) começa com a tela do
  mock que serve e as regras dela; o CI recusa rota sem esse comentário
  (`php bin/generate_api_catalog.php --missing`). Toda lib, script de `bin/`,
  migração, teste e componente Svelte também explica o que é e por quê.
- **Em cada função de `lib/`**, um docblock com o que ela faz e o que ela
  garante.
- **Nos testes** (`tests/smoke_<área>.sh`): o cabeçalho lista as regras que o
  teste prova, e cada passo tem um título legível.
- **Nos arquivos da VPS** (`deploy/`): cada arquivo explica onde instalar e o
  que garante.

## Mantendo em dia

- `docs/API.md` e `docs/DATABASE.md` são **gerados**:
  `php bin/generate_api_catalog.php` e `php bin/generate_db_map.php`. O CI
  falha se estiverem desatualizados.
- Decisão nova de módulo: um arquivo novo em `decisions/`, numerado, e uma
  linha no índice.
- Mudou o comportamento de algo documentado aqui? Atualize o documento no
  mesmo commit. O que não é atualizado vira mentira.
