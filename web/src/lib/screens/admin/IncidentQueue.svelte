<script>
  import { api, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 13.3, lado do suporte: "Passado o prazo, o suporte libera: devolver
  // à loja ou descartar."
  //
  // Fica junto dos reembolsos porque é a mesma mesa: quem decide o destino
  // da sacola é quem decide se o cliente recebe dinheiro de volta. Separar
  // as duas telas é como se perde meia hora por ocorrência procurando o
  // pedido de novo.
  let { onDecided } = $props();

  let data = $state(null);
  let openId = $state(null);
  let refund = $state(false);
  let payer = $state('platform');
  let busy = $state(false);

  function money(v) {
    return v === null || v === undefined ? '—' : `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function hm(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : '';
  }

  // A foto da porta mora em disco privado: busca com o token no cabeçalho e
  // vira um blob local. Nunca uma URL pública, nunca token na query string.
  let photos = $state({});
  async function loadPhoto(incident) {
    if (!incident.photo_key || photos[incident.id]) return;
    try {
      const res = await fetch(`${BASE}/admin/incident_photo.php?id=${incident.id}`, {
        headers: { Authorization: `Bearer ${adminToken()}` },
      });
      if (!res.ok) throw new Error();
      photos = { ...photos, [incident.id]: URL.createObjectURL(await res.blob()) };
    } catch {
      photos = { ...photos, [incident.id]: 'missing' };
    }
  }

  function toggle(row) {
    openId = openId === row.id ? null : row.id;
    if (openId !== null) loadPhoto(row);
  }

  const ATTEMPT_LABEL = { arrival: 'chegou', call: 'ligou', bell: 'campainha' };

  // `waited` é um interval do Postgres e chega como "00:11:00". Na fila isso
  // precisa virar "11 min no local" -- ninguém lê tempo de espera em relógio.
  function waitedMinutes(raw) {
    if (typeof raw !== 'string') return null;
    const parts = raw.split(':').map(Number);
    if (parts.length !== 3 || parts.some(Number.isNaN)) return null;
    return parts[0] * 60 + parts[1];
  }

  async function pull() {
    try {
      data = await api.get('/admin/incidents.php', { token: adminToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar as ocorrências.');
    }
  }

  $effect(() => {
    pull();
  });

  async function decide(incident, resolution) {
    if (busy) return;
    busy = true;
    try {
      const res = await api.post('/admin/incidents.php', {
        token: adminToken(),
        body: {
          incident_id: incident.id,
          resolution,
          // Dinheiro não devolve nada; nos outros métodos, quem paga é
          // escolha de gente -- a lista "quem paga a conta, por causa" da
          // 13.4 não cobre cliente ausente, e chutar aqui seria inventar
          // política.
          ...(refund && resolution !== 'delivered'
            ? { refund: true, refund_payer: payer }
            : {}),
        },
      });
      toastr.success(res.notice);
      openId = null;
      refund = false;
      await pull();
      onDecided?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra resolver.');
    } finally {
      busy = false;
    }
  }
</script>

{#if data && data.queue.length > 0}
  <section class="incidents">
    <p class="k">OCORRÊNCIAS ESPERANDO LIBERAÇÃO — A SACOLA ESTÁ COM O ENTREGADOR</p>

    {#each data.queue as row (row.id)}
      <article class="card" class:on={openId === row.id}>
        <button type="button" class="line" onclick={() => toggle(row)}>
          <span class="code fuu-mono">#{row.public_code}</span>
          <span class="what">
            <strong>{data.kinds[row.kind]?.label ?? row.kind}</strong>
            <span class="who">{row.courier_name} · {row.restaurant_name}</span>
          </span>
          <span class="amount">{money(row.total)}</span>
          <span class="age">{Math.round(Number(row.minutes_open))} min</span>
        </button>

        {#if openId === row.id}
          <div class="detail">
            <p class="trail">
              {#each row.attempts ?? [] as a, i (i)}
                <span class="pill">{ATTEMPT_LABEL[a.kind] ?? a.kind} {hm(a.at)}</span>
              {/each}
              {#if waitedMinutes(row.waited) !== null}
                <span class="pill">{waitedMinutes(row.waited)} min no local</span>
              {/if}
            </p>

            {#if photos[row.id] && photos[row.id] !== 'missing'}
              <img class="photo" src={photos[row.id]} alt="Foto do local enviada pelo entregador" />
            {:else if photos[row.id] === 'missing'}
              <p class="proof">A foto não está mais disponível (retenção de 180 dias).</p>
            {:else}
              <p class="proof fuu-mono">carregando a foto…</p>
            {/if}
            {#if row.geo_lat}
              <p class="proof fuu-mono">gps: {row.geo_lat}, {row.geo_lng}</p>
            {/if}

            <p class="guarantee">
              O entregador recebe {money(row.delivery_fee)} nas duas saídas — devolver e descartar
              pagam a corrida igual.
            </p>

            {#if row.payment_method !== 'cash'}
              <label class="refund">
                <input type="checkbox" bind:checked={refund} />
                Devolver o valor ao cliente
              </label>
              {#if refund}
                <div class="payer">
                  {#each [['platform', 'FUUdelivery'], ['store', 'Loja'], ['shared', 'Dividido']] as [code, label] (code)}
                    <button type="button" class:on={payer === code} onclick={() => (payer = code)}>
                      {label}
                    </button>
                  {/each}
                </div>
              {/if}
            {:else}
              <p class="cash">Pedido em dinheiro: nada foi cobrado, não há o que devolver.</p>
            {/if}

            <div class="actions">
              {#each Object.entries(data.resolutions) as [code, label] (code)}
                <button
                  type="button"
                  class:primary={code === 'returned'}
                  disabled={busy}
                  onclick={() => decide(row, code)}
                >
                  {label}
                </button>
              {/each}
            </div>
          </div>
        {/if}
      </article>
    {/each}
  </section>
{/if}

<style>
  .incidents {
    padding: 20px 20px 0;
  }
  .k {
    font-size: 11px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.1em;
    margin: 0 0 10px;
  }
  .card {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 12px;
    margin-bottom: 9px;
    overflow: hidden;
  }
  .card.on {
    border-color: var(--fuu-red);
  }
  .line {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    text-align: left;
    border: 0;
    background: transparent;
    padding: 13px 16px;
    font-family: inherit;
    font-size: 13px;
    color: var(--fuu-ink-1);
  }
  .code {
    flex: 0 0 92px;
  }
  .what {
    flex: 1;
    min-width: 0;
  }
  .who {
    display: block;
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin-top: 2px;
  }
  .amount {
    font-weight: 800;
  }
  .age {
    flex: 0 0 62px;
    text-align: right;
    font-size: 11.5px;
    color: var(--fuu-wait-text);
    font-weight: 700;
  }
  .detail {
    border-top: 1px solid var(--fuu-line-4);
    padding: 14px 16px;
  }
  .trail {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0 0 10px;
  }
  .pill {
    background: var(--fuu-line-6, var(--fuu-paper));
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-pill);
    padding: 4px 10px;
    font-size: 11px;
    color: var(--fuu-ink-2);
  }
  .proof {
    font-size: 10.5px;
    color: var(--fuu-ink-4);
    margin: 0 0 3px;
    word-break: break-all;
  }
  .photo {
    display: block;
    max-width: 100%;
    max-height: 260px;
    border-radius: 9px;
    border: 1px solid var(--fuu-line-3);
    margin-bottom: 6px;
  }
  .guarantee {
    font-size: 12px;
    color: var(--fuu-leaf-dark);
    background: var(--fuu-leaf-tint);
    border-radius: 9px;
    padding: 9px 11px;
    margin: 10px 0;
    line-height: 1.5;
  }
  .refund {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--fuu-ink-1);
  }
  .cash {
    font-size: 12px;
    color: var(--fuu-ink-3);
    margin: 0;
  }
  .payer {
    display: flex;
    gap: 7px;
    margin-top: 9px;
  }
  .payer button {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 8px;
    padding: 9px 0;
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--fuu-ink-2);
  }
  .payer button.on {
    border-color: var(--fuu-ink-1);
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
  }
  .actions button {
    flex: 1;
    border: 1.5px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: 10px;
    padding: 12px 6px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-1);
  }
  .actions button.primary {
    background: var(--fuu-red);
    border-color: var(--fuu-red);
    color: var(--fuu-white);
    font-weight: 800;
  }
</style>
