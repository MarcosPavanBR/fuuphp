<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 12.2 — "Disputas e galeria antifraude".
  //
  // "Toda decisão é lançamento no livro e notificação às duas partes, nunca
  // edição de saldo": por isso a tela pergunta DE QUEM sai o valor, e
  // resolver sem cobrar ninguém é uma opção explícita -- não o padrão
  // escondido.
  let { data, onRefresh } = $props();

  let openId = $state(null);
  let resolution = $state('');
  let charge = $state('');
  let busy = $state(false);

  function money(v) {
    return v === null || v === undefined ? '—' : `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function when(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}` : '';
  }

  const KIND_LABEL = {
    fake_proof: 'Comprovante falso',
    nsu_divergent: 'NSU divergente',
    not_delivered: 'Não entregue',
    cash_unsettled: 'Caixa não fecha',
    wrong_item: 'Item errado',
    pos_not_returned: 'Maquininha não devolvida',
  };

  async function resolve(dispute) {
    if (resolution.trim() === '' || busy) return;
    busy = true;
    try {
      await api.post('/admin/disputes.php', {
        token: adminToken(),
        body: {
          dispute_id: dispute.id,
          resolution: resolution.trim(),
          ...(charge === '' ? {} : { charge }),
        },
      });
      toastr.success(
        charge === ''
          ? 'Ocorrência resolvida sem cobrança.'
          : 'Ocorrência resolvida — contrapartida lançada no livro.'
      );
      openId = null;
      resolution = '';
      charge = '';
      onRefresh();
    } catch (e) {
      toastr.error(
        e instanceof ApiError && e.code === 'dispute_not_found'
          ? 'Alguém já resolveu essa ocorrência.'
          : (e.message ?? 'Não deu pra resolver.')
      );
      if (e instanceof ApiError && e.code === 'dispute_not_found') onRefresh();
    } finally {
      busy = false;
    }
  }
</script>

<p class="section">FILA POR RISCO E VALOR · {data?.open?.length ?? 0}</p>

{#if (data?.open ?? []).length === 0}
  <p class="empty">Nenhuma ocorrência aberta.</p>
{:else}
  <div class="list">
    {#each data.open as d (d.id)}
      <article class="card fuu-card" class:high={d.risk === 'high'}>
        <header>
          <span class={`risk ${d.risk}`}>{d.risk === 'high' ? 'ALTO' : d.risk === 'medium' ? 'MÉDIO' : 'BAIXO'}</span>
          <span class="kind">{KIND_LABEL[d.kind] ?? d.kind}</span>
          <span class="amount">{money(d.amount)}</span>
        </header>
        <p class="who">
          {#if d.public_code}<span class="fuu-mono">#{d.public_code}</span> ·{/if}
          {d.restaurant_name ?? 'loja não identificada'}
          {#if d.courier_name}· {d.courier_name}{/if}
          · aberta {when(d.created_at)}
        </p>

        {#if openId === d.id}
          <label class="field">
            <span>O que foi decidido</span>
            <textarea maxlength="1000" rows="2" placeholder="Ex.: faltou dinheiro na conferência" bind:value={resolution}></textarea>
          </label>
          <label class="field">
            <span>Quem arca com {money(d.amount)}</span>
            <select bind:value={charge}>
              <option value="">Ninguém — sem cobrança</option>
              {#if d.courier_name}<option value="courier">Entregador</option>{/if}
              {#if d.restaurant_name}<option value="store">Loja</option>{/if}
              <option value="platform">FUUdelivery</option>
            </select>
          </label>
          <div class="actions">
            <button type="button" class="btn-fuu-primary" disabled={busy} onclick={() => resolve(d)}>
              {busy ? 'Resolvendo…' : 'Resolver'}
            </button>
            <button type="button" class="link" onclick={() => (openId = null)}>Cancelar</button>
          </div>
        {:else}
          <button type="button" class="btn-fuu-primary open" onclick={() => (openId = d.id)}>Decidir</button>
        {/if}
      </article>
    {/each}
  </div>
{/if}

<p class="section later">GALERIA ANTIFRAUDE · COMPROVANTE REUSADO</p>
{#if (data?.gallery ?? []).length === 0}
  <p class="empty">Nenhuma imagem repetida até agora — é o resultado bom.</p>
{:else}
  <div class="gallery">
    {#each data.gallery as g (g.sha256)}
      <div class="tile">
        <p class="uses">{g.uses}× a mesma imagem</p>
        <p class="hash fuu-mono">{g.sha256.slice(0, 16)}…</p>
        <p class="orders">{(typeof g.orders === 'string' ? g.orders.replace(/[{}"]/g, '') : (g.orders ?? []).join(', '))}</p>
      </div>
    {/each}
  </div>
{/if}

<style>
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 12px;
  }
  .section.later {
    margin-top: 26px;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  .list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .card {
    padding: 16px;
  }
  .card.high {
    border: 2px solid var(--fuu-red);
  }
  header {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .risk {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    border-radius: 999px;
    padding: 3px 9px;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-3);
  }
  .risk.high {
    background: var(--fuu-red);
    color: var(--fuu-white);
  }
  .risk.medium {
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
  }
  .kind {
    font-size: 15px;
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .amount {
    margin-left: auto;
    font-size: 17px;
    font-weight: 800;
    color: var(--fuu-ink-1);
  }
  .who {
    font-size: 12.5px;
    color: var(--fuu-ink-4);
    margin: 8px 0 12px;
  }
  .field {
    display: block;
    margin-bottom: 12px;
  }
  .field span {
    display: block;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin-bottom: 4px;
  }
  textarea,
  select {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
  }
  .actions {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .actions .btn-fuu-primary,
  .open {
    min-height: var(--fuu-tap-operator);
  }
  .link {
    background: none;
    border: none;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
  .gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
  }
  .tile {
    border: 1px solid var(--fuu-red-tint-2);
    background: var(--fuu-red-tint);
    border-radius: 11px;
    padding: 13px;
  }
  .uses {
    font-size: 13px;
    font-weight: 800;
    color: var(--fuu-red);
    margin: 0;
  }
  .hash {
    font-size: 10.5px;
    color: var(--fuu-ink-4);
    margin: 4px 0 0;
  }
  .orders {
    font-size: 11.5px;
    color: var(--fuu-ink-3);
    margin: 4px 0 0;
  }
</style>
