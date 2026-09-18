<script>
  import { api } from '../../api.js';
  import { toastr } from '../../toastr.js';
  import { adminToken } from '../../adminSession.svelte.js';
  import { parsePgTimestamp } from '../../datetime.js';

  // Tela 10.5 — políticas da plataforma.
  //
  // "tudo versionado em auditoria": salvar não edita nada, cria uma versão
  // nova. A tela diz isso com todas as letras, porque a diferença importa --
  // pedido já feito continua valendo pela política que ele congelou.
  let data = $state(null);
  let form = $state(null);
  let busy = $state(false);

  const ALL_METHODS = [
    { id: 'mp_card', label: 'Cartão (Mercado Pago)' },
    { id: 'pix_auto', label: 'Pix automático' },
    { id: 'pix_manual', label: 'Pix + comprovante' },
    { id: 'cash', label: 'Dinheiro' },
    { id: 'pos_machine', label: 'Maquininha' },
  ];

  async function load() {
    data = await api.get('/admin/policy.php', { token: adminToken() });
    const c = data.current;
    form = {
      cash_ceiling: Number(c.cash_ceiling),
      cancel_fee: Number(c.cancel_fee),
      commission_bps: Number(c.commission_bps),
      new_store_online_only_days: Number(c.new_store_online_only_days),
      delivery_base_fee: Number(c.delivery_base_fee ?? 0),
      delivery_per_km: Number(c.delivery_per_km ?? 0),
      // Vazio = sem raio declarado, que é diferente de zero.
      delivery_max_km: c.delivery_max_km === null || c.delivery_max_km === undefined
        ? ''
        : Number(c.delivery_max_km),
      allow_partial_settle: c.allow_partial_settle === true,
      methods: String(c.enabled_methods).replace(/[{}"]/g, '').split(',').filter(Boolean),
    };
  }

  $effect(() => {
    load().catch(() => {});
  });

  function toggleMethod(id) {
    form.methods = form.methods.includes(id)
      ? form.methods.filter((m) => m !== id)
      : [...form.methods, id];
  }

  function when(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}` : '';
  }

  async function save() {
    if (busy || !form) return;
    busy = true;
    try {
      const res = await api.post('/admin/policy.php', {
        token: adminToken(),
        body: {
          cash_ceiling: form.cash_ceiling,
          cancel_fee: form.cancel_fee,
          commission_bps: form.commission_bps,
          new_store_online_only_days: form.new_store_online_only_days,
          delivery_base_fee: form.delivery_base_fee,
          delivery_per_km: form.delivery_per_km,
          delivery_max_km: form.delivery_max_km === '' ? null : form.delivery_max_km,
          allow_partial_settle: form.allow_partial_settle,
          enabled_methods: form.methods,
        },
      });
      toastr.success(`Versão ${res.policy.version} publicada. A anterior continua no histórico.`);
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra publicar a política.');
    } finally {
      busy = false;
    }
  }
</script>

{#if form}
  <div class="grid">
    <div class="fuu-card block">
      <p class="section">DINHEIRO EM ESPÉCIE</p>

      <label class="field">
        <span>Teto de espécie por entregador</span>
        <input type="number" step="10" min="0" bind:value={form.cash_ceiling} />
        <small>Acima disso ele para de receber corrida em dinheiro até baixar o caixa.</small>
      </label>

      <label class="check">
        <input type="checkbox" bind:checked={form.allow_partial_settle} />
        <span>Permitir baixa parcial <small>(entregar parte do dinheiro sem zerar)</small></span>
      </label>
    </div>

    <div class="fuu-card block">
      <p class="section">COMISSÃO E TAXAS</p>

      <label class="field">
        <span>Comissão da plataforma</span>
        <input type="number" step="25" min="0" max="3000" bind:value={form.commission_bps} />
        <small>{(form.commission_bps / 100).toFixed(2).replace('.', ',')}% do subtotal · em pontos-base</small>
      </label>

      <label class="field">
        <span>Taxa de cancelamento</span>
        <input type="number" step="1" min="0" bind:value={form.cancel_fee} />
        <small>Cobrada só do cliente que cancela depois de a cozinha começar.</small>
      </label>

      <label class="field">
        <span>Dias de só-online para loja nova</span>
        <input type="number" step="1" min="0" bind:value={form.new_store_online_only_days} />
        <small>Dinheiro e maquininha entram pelo histórico, não por negociação.</small>
      </label>

      <!-- 14.3 — a tarifa de entrega é política da plataforma, não número que
           o app do cliente manda. Enquanto estiver zerada, o frete é zero:
           inventar um valor padrão seria cobrar do cliente um número que
           ninguém decidiu. -->
      <label class="field">
        <span>Frete — valor base</span>
        <input type="number" step="0.50" min="0" bind:value={form.delivery_base_fee} />
        <small>Cobrado em todo pedido com entrega, antes da distância.</small>
      </label>

      <label class="field">
        <span>Frete — por quilômetro</span>
        <input type="number" step="0.10" min="0" bind:value={form.delivery_per_km} />
        <small>Distância em linha reta entre a loja e o endereço (sem roteamento).</small>
      </label>

      <label class="field">
        <span>Raio máximo de entrega (km)</span>
        <input type="number" step="0.5" min="0" placeholder="sem limite" bind:value={form.delivery_max_km} />
        <small>Em branco = sem limite. O checkout recusa endereço fora do raio.</small>
      </label>
    </div>

    <div class="fuu-card block">
      <p class="section">FORMAS QUE AS LOJAS PODEM LIGAR</p>
      {#each ALL_METHODS as m (m.id)}
        <label class="check">
          <input type="checkbox" checked={form.methods.includes(m.id)} onchange={() => toggleMethod(m.id)} />
          <span>{m.label}</span>
        </label>
      {/each}
      <p class="note">
        Desligar aqui tira a opção de TODAS as lojas — cada loja ainda escolhe dentro do que sobrar.
      </p>
    </div>

    <div class="fuu-card block">
      <p class="section">HISTÓRICO</p>
      {#each data.history as v (v.version)}
        <div class="kv">
          <span>v{v.version} · {when(v.created_at)}</span>
          <span class="fuu-mono">
            teto {Number(v.cash_ceiling).toFixed(0)} · {(v.commission_bps / 100).toFixed(1)}%
          </span>
        </div>
      {/each}
      <p class="note fuu-mono">
        salvar cria versão nova · pedido já feito segue a política que ele congelou
      </p>
    </div>
  </div>

  <button type="button" class="btn-fuu-primary publish" disabled={busy} onclick={save}>
    {busy ? 'Publicando…' : 'Publicar nova versão'}
  </button>
{:else}
  <p class="loading">Carregando política…</p>
{/if}

<style>
  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 12px;
  }
  .block {
    padding: 16px;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 14px;
  }
  .field {
    display: block;
    margin-bottom: 16px;
  }
  .field > span {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-2);
    margin-bottom: 5px;
  }
  input[type='number'] {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 11px 14px;
    font-family: var(--fuu-font-body);
    font-size: 15px;
    color: var(--fuu-ink-1);
  }
  small {
    display: block;
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin-top: 5px;
    line-height: 1.45;
  }
  .check {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    margin-bottom: 11px;
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .check input {
    margin-top: 2px;
    width: 17px;
    height: 17px;
    accent-color: var(--fuu-red);
  }
  .check small {
    display: inline;
    margin: 0;
  }
  .kv {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-size: 13px;
    padding: 8px 0;
    border-bottom: 1px solid var(--fuu-line-5);
    color: var(--fuu-ink-2);
  }
  .note {
    font-size: 11px;
    color: var(--fuu-ink-4);
    margin: 12px 0 0;
    line-height: 1.55;
  }
  .publish {
    margin-top: 14px;
    min-height: var(--fuu-tap-operator);
    padding: 0 28px;
  }
  .loading {
    color: var(--fuu-ink-5);
    font-size: 13px;
  }
</style>
