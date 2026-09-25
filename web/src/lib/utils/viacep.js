// Busca de endereço pelo CEP no ViaCEP (API pública e gratuita, sem chave).
// Usada no cadastro e na edição de endereço (telas 6.1/14.3).
//
// Devolve { street, neighborhood } ou null. Falha de rede, CEP inexistente ou
// CEP incompleto voltam null: o formulário continua editável à mão -- é
// degradação graciosa, não erro. O domínio está liberado no connect-src da
// CSP (deploy/nginx/fuuphp.conf).
export async function lookupCep(cep) {
  const digits = String(cep ?? '').replace(/\D/g, '');
  if (digits.length !== 8) return null;
  try {
    const res = await fetch(`https://viacep.com.br/ws/${digits}/json/`);
    const data = await res.json();
    if (data.erro) return null;
    return { street: data.logradouro || '', neighborhood: data.bairro || '' };
  } catch {
    return null;
  }
}
