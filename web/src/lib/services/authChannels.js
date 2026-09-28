// Por quais canais o código de login chega agora (auth/channels.php).
//
// Depende do provedor do servidor: com a Twilio, SMS (e WhatsApp se houver
// remetente aprovado), sem e-mail. As telas 10.1 e 10.2 escondem o que não
// existe em vez de oferecer e falhar depois. Uma consulta por carregamento
// do app; se ela falhar, vale só o telefone por SMS -- o canal que todo
// provedor configurado entrega.
import { api } from './api.js';

const PHONE_ONLY = { phone: true, email: false, whatsapp: false };
let pending = null;

export function authChannels() {
  pending ??= api.get('/auth/channels.php').catch(() => {
    pending = null;
    return PHONE_ONLY;
  });
  return pending;
}
