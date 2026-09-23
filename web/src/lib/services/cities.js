// Cidades atendidas (cities/list.php, migração 036): o que as telas 1.2 e
// 1.3 do onboarding e o cadastro de loja mostram. Uma busca só por abertura
// do app, compartilhada pelas telas; se falhar, a próxima chamada tenta de
// novo. Offline, o service worker devolve a última cópia (sw.js).
import { api } from './api.js';

let pending = null;

/** Lista de estados atendidos, cada um com as cidades e as lojas aprovadas. */
export function loadServiceStates() {
  pending ??= api
    .get('/cities/list.php')
    .then((res) => res.states ?? [])
    .catch((e) => {
      pending = null;
      throw e;
    });
  return pending;
}
