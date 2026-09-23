# Login e cadastro (Fase 10.1 a 10.3)

O acesso do cliente saiu do provisório. Até aqui havia um `QuickLogin` de
três campos com um selo "login provisório"; agora são as três telas
desenhadas, com o mesmo backend de sempre (o módulo identity nunca foi
mock).

- **Um formulário só serve pra entrar e pra criar conta.** Quem digita um
  telefone sem conta recebe `user_not_found` do servidor, e é aí que o
  campo de nome aparece. Ninguém precisa escolher "entrar ou cadastrar"
  antes de digitar nada: quem sabe a resposta é o banco, não a tela.
- **O cadastro (10.3) é um passo do fluxo, não uma tela solta.** Ele só
  existe depois do OTP -- a conta já foi criada com nome e telefone
  verificados, e o que falta é CPF, e-mail e consentimentos. Quem já tinha
  conta entra direto; pedir CPF a cada login seria pedir o mesmo dado duas
  vezes, o oposto do "mínimo necessário" que a tela promete.
- **"Conta recém-criada" é estado de sessão, não de tela.** Essa foi a
  correção de um bug real que o Playwright pegou: com a condição escrita
  como "não autenticado", a aba trocava o fluxo pela tela dela no instante
  em que o token chegava, e o cadastro nunca aparecia. Agora
  `customerSession.svelte.js` guarda `pendingSignup`, ligado no `verifyOtp` com
  `purpose=signup` e desligado quando o cadastro termina -- a aba e o modal
  de item leem o mesmo sinal.
- **Os três blocos de 10.3 são bases legais diferentes, e isso muda onde o
  dado é gravado.** CPF vai pra `users` (obrigação legal, nota fiscal);
  marketing e data de nascimento são consentimento, então viram registros
  próprios em `consents` -- versão e IP em cada um. Aceitar os termos não
  liga marketing junto: são três chamadas distintas a `consent.php`, que é
  exatamente o que a tela promete ("sem consentimento embutido em aceite de
  termos").
- **`users.birth_date` é a única coluna nova** (migração `013`). Anulável
  de propósito: quem não consente não preenche, e a ausência é a resposta
  certa, não um valor padrão.
- **CPF é validado no servidor, com dígito verificador** (`is_valid_cpf`,
  que já existia) e é `UNIQUE` em `users` -- CPF de outra conta devolve 409
  com a saída possível ("entre com ela"), não 500 cru.
- **"Receber por WhatsApp" é um canal de verdade**, não um link decorativo:
  `otp_codes.channel` já previa `whatsapp` no enum desde a migração `001`,
  e `otp_request.php` passou a aceitar `channel`. O envio em si continua
  sendo integração externa (hoje um `error_log`) em qualquer canal -- o que
  o teste prova é que o canal pedido é o canal gravado. A "ligação
  automática" que o mock também cita ficou de fora: o enum não prevê esse
  canal, e inventar valor de enum pra caber numa tela é a ordem errada.
- **Google e Apple aparecem desabilitados, com "em breve".** Não existe
  OAuth neste backend nem tabela de identidade federada. Mesma escolha já
  feita com o BitPay na tela 4.1: melhor um botão que diz o que é do que um
  botão que não faz nada. **Decidido pelo Marcos: fica pra v2**, e não
  entra no lançamento.
- **As seis caixas do código são um input só.** Um campo por dígito quebra
  colar o código, o preenchimento automático do SMS e o apagar pra trás. O
  que se vê são seis caixas desenhadas sobre um input transparente com
  `autocomplete="one-time-code"` -- o navegador continua tratando como um
  campo de 6 dígitos.
- **A tela de código não inventa o prazo.** O texto diz o que o servidor
  realmente faz (5 minutos, 5 tentativas) e o contador de reenvio é local,
  mas quem bloqueia é o backend: código errado zera as caixas e mostra a
  mensagem que veio de lá, incluindo quantas tentativas restam.
- **Validado com Postgres e navegador reais.** `tests/smoke_identity.sh`
  ganhou o cadastro completo (CPF gravado, conferido por query direta),
  CPF inválido barrado, data no futuro barrada, CPF de outra conta em 409 e
  o canal WhatsApp indo parar em `otp_codes.channel`. No Playwright, numa
  janela de 430px: alternar telefone/e-mail, máscara de telefone, o pivô
  pra cadastro vindo do servidor, código errado rejeitado, cadastro com os
  dois opcionais marcados, e depois sair e entrar de novo caindo direto no
  perfil -- sem repetir o cadastro. As únicas respostas não-200 no console
  são os três erros que o próprio teste provoca.
