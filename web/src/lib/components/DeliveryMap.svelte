<script>
  import { onMount, onDestroy } from 'svelte';
  import L from 'leaflet';
  import 'leaflet/dist/leaflet.css';
  import { api } from '../services/api.js';

  // Tela 5.3 — "mapa da entrega (Leaflet / tiles)", "1,4 km · 8 min",
  // "Jonas está levando · Honda Biz · placa QQP-1B34" (DeliveryMap.svelte no
  // mock).
  //
  // Três pinos: loja, destino e -- só com o pedido em rota -- o entregador,
  // que o servidor libera apenas nessa janela (orders/courier_location.php).
  // Atualiza a cada POLL_MS, no mesmo ritmo em que o app do entregador
  // manda a posição (15 s). Os ícones são Bootstrap Icons em `divIcon`: os
  // PNGs padrão do Leaflet não sobrevivem ao bundler, e assim o pino fala a
  // mesma língua visual do resto do app.
  //
  // Sem internet pros tiles (OpenStreetMap), o mapa fica cinza mas os pinos
  // e a distância continuam certos -- o dado vem da nossa API, não dos tiles.
  let { orderId, status } = $props();

  const POLL_MS = 15000;
  // A candidatura (15.2) guarda o TIPO de veículo, não o modelo: o mock
  // mostra "Honda Biz", aqui sai "Moto".
  const VEHICLE = { moto: 'Moto', bike: 'Bicicleta', car: 'Carro', foot: 'A pé' };
  const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

  let container;
  let map;
  let markers = {};
  let timer;
  let info = $state(null);

  const pin = (icon, cls) =>
    L.divIcon({ className: `fuu-pin ${cls}`, html: `<i class="bi ${icon}"></i>`, iconSize: [30, 30], iconAnchor: [15, 15] });

  function place(key, point, icon, cls) {
    if (!point) {
      markers[key]?.remove();
      delete markers[key];
      return;
    }
    const at = [point.lat, point.lng];
    if (markers[key]) markers[key].setLatLng(at);
    else markers[key] = L.marker(at, { icon: pin(icon, cls), keyboard: false }).addTo(map);
  }

  async function refresh() {
    try {
      info = await api.get('/orders/courier_location.php', { auth: true, query: { id: orderId } });
    } catch {
      return; // sem rede: fica o último desenho
    }
    place('store', info.store, 'bi-shop', 'store');
    place('destination', info.destination, 'bi-house-door-fill', 'home');
    place('courier', info.courier?.position ?? null, info.courier?.vehicle === 'car' ? 'bi-car-front-fill' : 'bi-bicycle', 'courier');

    const points = Object.values(markers).map((m) => m.getLatLng());
    if (points.length > 1) map.fitBounds(L.latLngBounds(points), { padding: [28, 28], maxZoom: 16 });
    else if (points.length === 1) map.setView(points[0], 15);
  }

  onMount(() => {
    map = L.map(container, { zoomControl: false, attributionControl: true });
    L.tileLayer(TILE_URL, { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    map.setView([-23.55, -46.63], 13);
    refresh();
    timer = setInterval(refresh, POLL_MS);
  });
  onDestroy(() => {
    clearInterval(timer);
    map?.remove();
  });

  // O status muda por SSE lá em cima (OrderTracking): pedido que saiu pra
  // entrega busca o entregador na hora, sem esperar a próxima volta.
  $effect(() => {
    if (status && map) refresh();
  });

  let km = $derived(info?.remaining_km != null ? String(info.remaining_km).replace('.', ',') : null);
</script>

<div class="delivery-map">
  <div class="map" bind:this={container}></div>
  {#if km !== null}
    <span class="badge-eta fuu-mono">{km} km · {info.eta_minutes} min</span>
  {/if}
</div>

{#if info?.courier}
  <div class="courier-line">
    <div class="avatar"><i class="bi bi-person-fill"></i></div>
    <div>
      <p class="who"><strong>{info.courier.first_name}</strong> está levando</p>
      {#if info.courier.vehicle || info.courier.plate}
        <p class="vehicle">{[VEHICLE[info.courier.vehicle] ?? info.courier.vehicle, info.courier.plate ? `placa ${info.courier.plate}` : null].filter(Boolean).join(' · ')}</p>
      {/if}
      {#if info.courier.position?.stale}
        <p class="stale">Sem sinal agora — mostrando a última posição.</p>
      {:else if !info.courier.position}
        <p class="stale">A posição aparece assim que o app do entregador mandar o primeiro sinal.</p>
      {/if}
    </div>
  </div>
{/if}

<style>
  .delivery-map {
    position: relative;
    margin-bottom: 12px;
  }
  .map {
    height: 180px;
    border-radius: var(--fuu-radius-card);
    background: var(--fuu-line-5);
    overflow: hidden;
    z-index: 0;
  }
  .badge-eta {
    position: absolute;
    left: 10px;
    bottom: 10px;
    z-index: 500;
    background: var(--fuu-white);
    border-radius: var(--fuu-radius-pill);
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-1);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
  }
  .courier-line {
    display: flex;
    gap: 10px;
    align-items: center;
    text-align: left;
    margin-bottom: 12px;
  }
  .avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--fuu-red-tint);
    color: var(--fuu-red);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
  }
  .who,
  .vehicle,
  .stale {
    margin: 0;
  }
  .who {
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .vehicle {
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .stale {
    font-size: 11.5px;
    color: var(--fuu-wait-text);
  }
  :global(.fuu-pin) {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    color: var(--fuu-white);
    font-size: 15px;
    border: 2px solid var(--fuu-white);
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.3);
  }
  :global(.fuu-pin.store) {
    background: var(--fuu-ink-2);
  }
  :global(.fuu-pin.home) {
    background: var(--fuu-leaf-dark);
  }
  :global(.fuu-pin.courier) {
    background: var(--fuu-red);
  }
</style>
