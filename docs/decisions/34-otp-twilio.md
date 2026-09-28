# 34 — Código de login por SMS pela Twilio

**O que foi decidido:** o Marcos escolheu a Twilio pra mandar o código de
login (OTP) por SMS. Era a decisão que segurava a produção: sem provedor, a
trava de produção não deixa a API subir ([33](33-go-live.md)).

**O que foi feito:**

- **Driver `twilio` em `lib/messaging/otp_sender.php`.** Faz um `POST` em
  `/2010-04-01/Accounts/{SID}/Messages.json`, com Basic auth `SID:token`,
  pelo `curl` nativo do PHP. Nenhum SDK e nenhum Composer entrou na stack.
  Conexão em até 3 s e resposta em até 6 s; só `201` com `sid` conta como
  enviado. O telefone, guardado só com dígitos, vira E.164 (`+55...`).
- **Canais de verdade, não de desenho.** O mock (10.1) oferece telefone ou
  e-mail, e a 10.2 oferece "receber por WhatsApp". A Twilio manda SMS; e-mail,
  por esta API, não manda. Por isso:
  - `otp_sender_channels()` diz o que o provedor configurado entrega: SMS, e
    WhatsApp só com `TWILIO_WHATSAPP_FROM`;
  - `auth/channels.php` publica essa lista, e o app esconde a aba "E-mail" e
    o link "Receber por WhatsApp" quando o canal não existe;
  - pedir por canal indisponível dá `422 channel_unavailable` **antes** de
    criar conta. Antes, um cadastro por e-mail criaria o usuário e só depois
    falharia no envio.
- **Falhas.** Twilio recusando ou fora do ar devolve `502 otp_send_failed`, e
  o código gerado é apagado. O log registra o status HTTP e o código de erro
  da Twilio, com o telefone mascarado. O código e o token nunca vão pro log;
  o corpo da resposta da Twilio também não, porque ecoa a mensagem com o
  código.
- **Trava de produção.** Com `OTP_SENDER=twilio`, a API recusa subir sem:
  - Account SID válido (`AC` + 32 hex);
  - Auth Token;
  - remetente: `TWILIO_FROM` em E.164 ou `TWILIO_MESSAGING_SERVICE_SID`.

  Também recusa `TWILIO_API_BASE` diferente da oficial, porque trocar a base
  mandaria a credencial pra outro lugar. Com a Twilio configurada, a produção
  sobe: `tests/smoke_production_guard.sh` agora prova esse caso.
- **Teste contra uma Twilio falsa.** `tests/smoke_otp_twilio.sh` usa uma
  Twilio falsa local (`tests/support/fake_twilio.php`), mas o código de curl
  é o de produção; só a base muda. Ele confere:
  - caminho, Basic auth, `To` em E.164, `From` e o texto com o código;
  - que o código enviado funciona no login;
  - recusa (400/21211) e timeout viram 502 em menos de 10 s, sem o código
    nem o token no log;
  - o WhatsApp como `whatsapp:+55...`;
  - a trava de produção.

**Simplificado / fora:**

- **Login por e-mail sai do ar com a Twilio.** Mandar e-mail seria outro
  serviço (ex.: SendGrid, da própria Twilio, ou um SMTP), e a cláusula zero
  pede autorização pra serviço novo. A API continua aceitando e-mail quando o
  provedor aceitar, então ligar isso depois é só um driver.
- **A conta trial da Twilio só manda SMS pra números verificados.** Serve pra
  homologação; antes de abrir é preciso fazer o upgrade da conta. Isso está
  no roteiro do [GO_LIVE](../GO_LIVE.md).
- **Não houve teste contra a Twilio real** neste ambiente: a rede não chega
  lá. O primeiro SMS de verdade é item da validação em homologação.
