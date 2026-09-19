<script>
  import { api } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { courierToken } from '../../courierSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Tela 13.3 — "Registrar ocorrência".
  //
  // "Prova obrigatória (foto, GPS, tentativas de ligação) e a garantia de
  // que ele recebe a corrida de qualquer forma — sem isso, entregador
  // abandona pedido difícil em vez de registrar."
  //
  // As duas metades dessa frase mandam na tela: o botão de abrir só liga com
  // a prova na mão, e a garantia da corrida aparece ANTES de escolher o
  // motivo, não depois de confirmar.
  let { order, onDone, onBack } = $props();

  // O id fica preso na montagem de propósito. O app do entregador refaz o
  // objeto do pedido a cada leitura de `couriers/me.php`, e um efeito que
  // dependesse do OBJETO recomeçaria o relógio a cada poucos segundos --
  // relendo a ocorrência sem parar em vez de a cada 30 s.
  const orderId = order.id;

  let ctx = $state(null);
  let kind = $state(null);
  let photoKey = $state(null);
  let photoName = $state('');
  let busy = $state(false);
  // Registrar uma ligação e abrir a ocorrência são esperas diferentes, e o
  // botão principal dizia "Abrindo…" durante a primeira. Dois estados.
  let marking = $state(false);
  let uploading = $state(false);

  const ICON = {
    customer_absent: 'bi-person-x-fill',
    no_cash: 'bi-cash-coin',
    bad_address: 'bi-geo-alt-fill',
    unsafe_area: 'bi-exclamation-octagon-fill',
  };

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function hm(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : '';
  }

  const METHOD_LABEL = {
    mp_card: 'no cartão',
    pix_auto: 'no Pix',
    pix_manual: 'no Pix',
    cash: 'em dinheiro',
    pos_machine: 'na maquininha',
  };

  // O carimbo vale mais que o texto: sem GPS a ocorrência ainda abre, mas a
  // posição é o que sustenta a prova depois.
  //
  // O prazo é nosso, não do navegador: `getCurrentPosition` tem a opção
  // `timeout`, e mesmo assim existe aparelho que não chama nenhum dos dois
  // callbacks (permissão negada sem diálogo, GPS sem provedor). Quando isso
  // acontece, a tela inteira trava esperando -- e travar é exatamente o que
  // esta tela não pode fazer: quem está na porta com a comida na mão precisa
  // conseguir registrar. Daí o corte por fora.
  function position() {
    return Promise.race([
      new Promise((resolve) => {
        if (!navigator.geolocation) return resolve({});
        navigator.geolocation.getCurrentPosition(
          (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude }),
          () => resolve({}),
          { timeout: 4000 }
        );
      }),
      new Promise((resolve) => setTimeout(() => resolve({}), 4500)),
    ]);
  }

  async function pull() {
    try {
      ctx = await api.get(`/couriers/incident.php?order_id=${orderId}`, { token: courierToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra abrir a ocorrência.');
    }
  }

  $effect(() => {
    pull();
    // O relógio dos 10 min só é honesto se a tela reler: "faltam 4 min" tem
    // que virar "pode abrir" sozinho.
    const t = setInterval(pull, 30000);
    return () => clearInterval(t);
  });

  async function mark(action) {
    if (marking || busy) return;
    marking = true;
    try {
      const where = await position();
      ctx = { ...ctx, ...(await api.post('/couriers/incident.php', {
        token: courierToken(),
        body: { order_id: orderId, action, ...where },
      })) };
      if (action === 'arrive') toastr.info('Chegada registrada. O prazo começa agora.');
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra registrar.');
    } finally {
      marking = false;
    }
  }

  async function upload(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    uploading = true;
    try {
      const form = new FormData();
      form.append('order_id', orderId);
      form.append('photo', file);
      const res = await api.post('/couriers/incident_photo.php', {
        token: courierToken(),
        form,
      });
      photoKey = res.photo_storage_key;
      photoName = file.name;
      toastr.success('Foto guardada com GPS e hora.');
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra enviar a foto.');
    } finally {
      uploading = false;
    }
  }

  async function open() {
    if (!ready || busy) return;
    busy = true;
    try {
      const where = await position();
      const res = await api.post('/couriers/incident.php', {
        token: courierToken(),
        body: { order_id: orderId, action: 'open', kind, photo_storage_key: photoKey, ...where },
      });
      toastr.success(res.notice);
      onDone(res);
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra abrir a ocorrência.');
      pull();
    } finally {
      busy = false;
    }
  }

  let context = $derived(ctx?.context ?? null);
  let waited = $derived(context?.waited_minutes ?? null);
  let needsWait = $derived(kind === 'customer_absent');
  let waitLeft = $derived(
    waited === null ? null : Math.max(0, (context?.wait_minutes_required ?? 10) - waited)
  );
  let ready = $derived(
    kind !== null &&
      photoKey !== null &&
      (!needsWait || ((context?.call_attempts ?? 0) > 0 && (context?.wait_satisfied ?? false)))
  );
</script>

<div class="incident">
  <header>
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1>Registrar ocorrência</h1>
  </header>

  <p class="sub">
    #{order.public_code} · {money(order.total)} {METHOD_LABEL[order.payment_method] ?? ''}
    {#if waited !== null}· {waited} min no endereço.{:else}· chegada ainda não marcada.{/if}
  </p>

  {#if context && context.arrived_at === null}
    <button type="button" class="arrive" disabled={marking} onclick={() => mark('arrive')}>
      <i class="bi bi-geo"></i> Cheguei no endereço
    </button>
  {/if}

  <p class="k">O QUE ACONTECEU?</p>
  <div class="kinds">
    {#each Object.entries(context?.kinds ?? {}) as [code, meta] (code)}
      <button type="button" class="kind" class:on={kind === code} onclick={() => (kind = code)}>
        <i class="bi {ICON[code]}"></i>
        <span class="body">
          <span class="label">{meta.label}</span>
          {#if code === 'customer_absent' && context}
            <span class="trail">
              {context.call_attempts === 0
                ? 'nenhuma ligação registrada'
                : `liguei ${context.call_attempts} ${context.call_attempts === 1 ? 'vez' : 'vezes'}`}{context.bell_attempts >
              0
                ? ' · toquei a campainha'
                : ''}
            </span>
          {/if}
        </span>
      </button>
    {/each}
  </div>

  {#if needsWait}
    <div class="attempts">
      <button type="button" disabled={marking} onclick={() => mark('call')}>
        <i class="bi bi-telephone"></i> Liguei
      </button>
      <button type="button" disabled={marking} onclick={() => mark('bell')}>
        <i class="bi bi-bell"></i> Toquei a campainha
      </button>
    </div>
  {/if}

  <div class="proof">
    <p class="k">PROVA (OBRIGATÓRIA)</p>
    <label class="drop" class:filled={photoKey !== null}>
      <input type="file" accept="image/*" capture="environment" onchange={upload} hidden />
      {#if uploading}
        <span>Enviando…</span>
      {:else if photoKey === null}
        <span><i class="bi bi-camera"></i> foto do local · GPS e hora gravados</span>
      {:else}
        <span><i class="bi bi-check-circle-fill"></i> {photoName}</span>
      {/if}
    </label>

    {#if context}
      <p class="trail-line">
        Tentativas registradas:
        <strong>
          {context.call_attempts}
          {context.call_attempts === 1 ? 'ligação' : 'ligações'}
        </strong>
        {#if context.call_times.length > 0}
          às {context.call_times.map(hm).join(' e ')}
        {/if}
        {#if waited !== null}· {waited} min no local.{/if}
      </p>
    {/if}
  </div>

  <div class="wait">
    <i class="bi bi-hourglass-split"></i>
    <p>
      Espere <strong>{context?.wait_minutes_required ?? 10} min</strong> no local. Passado o prazo,
      o suporte libera: devolver à loja ou descartar. Você recebe a corrida integral nas duas
      saídas{#if ctx}&nbsp;— {money(ctx.guaranteed_fee)}{/if}.
      {#if needsWait && waitLeft !== null && waitLeft > 0}
        <span class="left">Faltam {waitLeft} min.</span>
      {/if}
    </p>
  </div>

  <button type="button" class="btn-fuu-primary w-100 big" disabled={!ready || busy || marking} onclick={open}>
    {busy ? 'Abrindo…' : 'Abrir ocorrência'}
  </button>
</div>

<style>
  .incident {
    background: var(--fuu-paper);
    min-height: 100vh;
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 0;
  }
  header {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .back {
    border: 0;
    background: transparent;
    font-size: 18px;
    color: var(--fuu-ink-1);
    padding: 0;
  }
  h1 {
    font-size: 17px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .sub {
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    line-height: 1.5;
    margin: 10px 0 0;
  }
  .arrive {
    margin-top: 12px;
    width: 100%;
    border: 1.5px solid var(--fuu-ink-1);
    background: var(--fuu-white);
    color: var(--fuu-ink-1);
    border-radius: 11px;
    padding: 13px;
    font-family: inherit;
    font-weight: 800;
    font-size: 14px;
  }
  .k {
    font-size: 11px;
    font-weight: 800;
    color: var(--fuu-ink-3);
    letter-spacing: 0.1em;
    margin: 14px 0 9px;
  }
  .kinds {
    display: flex;
    flex-direction: column;
    gap: 9px;
  }
  .kind {
    display: flex;
    align-items: center;
    gap: 11px;
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 11px;
    padding: 15px;
    font-family: inherit;
    font-size: 14px;
    text-align: left;
    color: var(--fuu-ink-1);
  }
  .kind i {
    font-size: 18px;
    color: var(--fuu-ink-2);
  }
  .kind.on {
    border: 2px solid var(--fuu-red);
    background: var(--fuu-red-tint);
  }
  .kind.on i {
    color: var(--fuu-alert);
  }
  .kind .body {
    flex: 1;
  }
  .kind .label {
    display: block;
    font-weight: 700;
  }
  .kind.on .label {
    font-weight: 800;
  }
  .trail {
    display: block;
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin-top: 2px;
  }
  .kind.on .trail {
    color: #8a3a32;
  }
  .attempts {
    display: flex;
    gap: 9px;
    margin-top: 10px;
  }
  .attempts button {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 10px;
    padding: 12px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-1);
  }
  .proof {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: 11px;
    padding: 14px;
    margin-top: 14px;
  }
  .proof .k {
    margin: 0 0 9px;
  }
  .drop {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 110px;
    border: 1px dashed var(--fuu-line-2);
    border-radius: 9px;
    background: var(--fuu-paper);
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    text-align: center;
    padding: 0 12px;
  }
  .drop.filled {
    border-style: solid;
    border-color: var(--fuu-leaf);
    color: var(--fuu-leaf-dark);
    background: var(--fuu-leaf-tint);
  }
  .trail-line {
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    line-height: 1.55;
    margin: 9px 0 0;
  }
  .wait {
    border: 1px solid var(--fuu-red-tint-2);
    background: var(--fuu-red-tint);
    border-radius: 11px;
    padding: 14px;
    margin-top: 12px;
    display: flex;
    gap: 10px;
  }
  .wait i {
    color: var(--fuu-alert);
    margin-top: 1px;
  }
  .wait p {
    font-size: 11.5px;
    color: #8a3a32;
    line-height: 1.55;
    margin: 0;
  }
  .left {
    display: block;
    font-weight: 800;
    margin-top: 4px;
  }
  .big {
    margin-top: auto;
    padding: 16px;
    font-size: 15px;
    font-weight: 800;
  }
</style>
