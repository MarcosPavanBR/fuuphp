// Lista de UFs para a tela 1.2 (Seleção de estado).
//
// Na especificação, essa lista "é servida do edge cache da Cloudflare" —
// ou seja, é dado estático e cacheado, não uma consulta ao banco a cada
// abertura de tela. Aqui, por enquanto, é um JSON estático local: o
// backend ainda não tem um endpoint público de UFs/cidades (o esquema só
// guarda `city_ibge_code` por endereço/restaurante, não uma tabela de
// UFs). Os números de "lojas ativas" das 4 mais usadas são ilustrativos —
// no mockup de origem aparecem como texto fixo (SP 1.284, MG 612, PR 348,
// BA 297); um endpoint real de contagem por praça é trabalho futuro.
export const MOST_USED_STATES = [
  { uf: 'SP', name: 'São Paulo', stores: 1284 },
  { uf: 'MG', name: 'Minas Gerais', stores: 612 },
  { uf: 'PR', name: 'Paraná', stores: 348 },
  { uf: 'BA', name: 'Bahia', stores: 297 },
];

export const ALL_STATES = [
  { uf: 'AC', name: 'Acre' },
  { uf: 'AL', name: 'Alagoas' },
  { uf: 'AP', name: 'Amapá' },
  { uf: 'AM', name: 'Amazonas' },
  { uf: 'BA', name: 'Bahia' },
  { uf: 'CE', name: 'Ceará' },
  { uf: 'DF', name: 'Distrito Federal' },
  { uf: 'ES', name: 'Espírito Santo' },
  { uf: 'GO', name: 'Goiás' },
  { uf: 'MA', name: 'Maranhão' },
  { uf: 'MT', name: 'Mato Grosso' },
  { uf: 'MS', name: 'Mato Grosso do Sul' },
  { uf: 'MG', name: 'Minas Gerais' },
  { uf: 'PA', name: 'Pará' },
  { uf: 'PB', name: 'Paraíba' },
  { uf: 'PR', name: 'Paraná' },
  { uf: 'PE', name: 'Pernambuco' },
  { uf: 'PI', name: 'Piauí' },
  { uf: 'RJ', name: 'Rio de Janeiro' },
  { uf: 'RN', name: 'Rio Grande do Norte' },
  { uf: 'RS', name: 'Rio Grande do Sul' },
  { uf: 'RO', name: 'Rondônia' },
  { uf: 'RR', name: 'Roraima' },
  { uf: 'SC', name: 'Santa Catarina' },
  { uf: 'SP', name: 'São Paulo' },
  { uf: 'SE', name: 'Sergipe' },
  { uf: 'TO', name: 'Tocantins' },
];

// Cidades de exemplo por UF, só o bastante pra tela 1.3 funcionar de
// verdade. Cobertura completa de municípios (5.570 no Brasil) é trabalho
// de uma fonte de dados real (IBGE) integrada depois -- fora do escopo de
// um mock de tela.
// lat/lng são o centro aproximado da cidade (não do bairro escolhido) --
// bastam pra ordenar a Home por distância de verdade; geocodificação fina
// por bairro é a mesma lacuna já registrada em CityPicker.svelte.
export const CITIES_BY_STATE = {
  SP: [
    { name: 'São Paulo', ibge: '3550308', lat: -23.5505, lng: -46.6333, neighborhoods: ['Pinheiros', 'Cambuí', 'Moema', 'Vila Mariana'] },
    { name: 'Campinas', ibge: '3509502', lat: -22.9056, lng: -47.0608, neighborhoods: ['Cambuí', 'Taquaral', 'Bosque'] },
    { name: 'Santos', ibge: '3548500', lat: -23.9608, lng: -46.3339, neighborhoods: ['Gonzaga', 'Embaré', 'Boqueirão'] },
    { name: 'Sorocaba', ibge: '3552205', lat: -23.5015, lng: -47.4526, neighborhoods: ['Campolim', 'Jardim Vergueiro'] },
  ],
  MG: [
    { name: 'Belo Horizonte', ibge: '3106200', lat: -19.9167, lng: -43.9345, neighborhoods: ['Savassi', 'Lourdes', 'Buritis'] },
  ],
  PR: [
    { name: 'Curitiba', ibge: '4106902', lat: -25.4284, lng: -49.2733, neighborhoods: ['Batel', 'Água Verde'] },
  ],
  BA: [
    { name: 'Salvador', ibge: '2927408', lat: -12.9777, lng: -38.5016, neighborhoods: ['Barra', 'Pituba'] },
  ],
};
