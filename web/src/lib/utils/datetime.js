// PDO devolve timestamptz como "2026-09-17 17:55:53.411442+00" -- espaço em
// vez de T, e um offset de 2 dígitos sem os dois-pontos (+00, não +00:00).
// O construtor de Date aceita a string original tal e qual (com o espaço)
// na maioria dos motores, mas quebra silenciosamente ("Invalid Date") na
// combinação T + offset de 2 dígitos, que é exatamente o que um
// `.replace(' ', 'T')` ingênuo produzia neste projeto. Normaliza pra ISO
// 8601 de verdade antes de entregar pro Date, pra não depender de
// comportamento não padronizado de parsing entre navegadores.
//
// Também corta os microssegundos pra milissegundos (".411442" -> ".411"): o
// ISO do JavaScript prevê 3 casas, e o Safari do iPhone é o motor mais
// estrito. Aceita ISO pronto (com T, Z ou -03:00) sem mexer.
export function parsePgTimestamp(raw) {
  if (!raw) return null;
  let iso = String(raw).replace(' ', 'T').replace(/(\.\d{3})\d+/, '$1');
  if (/[+-]\d{2}$/.test(iso)) {
    iso += ':00';
  }
  return new Date(iso);
}
