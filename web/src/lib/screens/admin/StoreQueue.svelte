<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 12.1 — "Cadastro e aprovação de lojas".
  //
  // "O alerta de sócio com histórico é o tipo de sinal que evita prejuízo":
  // o sinal que este backend consegue dar de verdade é CNPJ de mesma raiz
  // (os 8 primeiros dígitos) já cadastrado -- matriz e filial, ou o mesmo
  // dono voltando. Não há base de sócios aqui, e inventar um alerta de
  // "histórico" a partir de nada seria pior que não alertar.
  let { data, onRefresh } = $props();

  let rejecting = $state(null);
  let reason = $state('');
  let busy = $state(false);

  function when(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}` : '';
  }

  function phoneMask(v) {
    const d = (v ?? '').replace(/\D/g, '');
    return d.length === 11 ? `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}` : d.length === 10 ? `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}` : d;
  }

  function cnpjMask(v) {
    const d = (v ?? '').replace(/\D/g, '');
    return d.length === 14
      ? `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}`
      : v;
  }

  async function decide(store, decision) {
    if (decision === 'reject' && reason.trim() === '') {
      toastr.warning('Escreva o motivo — a loja precisa saber o que corrigir.');
      return;
    }
    busy = true;
    try {
      const res = await api.post('/admin/restaurants.php', {
        token: adminToken(),
        body: {
          restaurant_id: store.id,
          decision,
          ...(decision === 'reject' ? { reason: reason.trim() } : {}),
        },
      });
      toastr.success(
        decision === 'approve'
          ? `Aprovada. Só-online por ${res.online_only_days} dias — dinheiro e maquininha entram pelo histórico.`
          : 'Cadastro recusado, com o motivo registrado.'
      );
      rejecting = null;
      reason = '';
      onRefresh();
    } catch (e) {
      toastr.error(
        e instanceof ApiError && e.code === 'already_decided'
          ? 'Essa loja já foi analisada por alguém.'
          : (e.message ?? 'Não deu pra decidir.')
      );
      if (e instanceof ApiError && e.code === 'already_decided') onRefresh();
    } finally {
      busy = false;
    }
  }
</script>

<p class="section">ESPERANDO ANÁLISE · {data?.pending?.length ?? 0}</p>

{#if (data?.pending ?? []).length === 0}
  <p class="empty">Nenhuma loja esperando decisão.</p>
{:else}
  <div class="list">
    {#each data.pending as store (store.id)}
      <article class="card fuu-card">
        <header>
          <div>
            <p class="name">{store.name}</p>
            <p class="meta fuu-mono">{cnpjMask(store.cnpj)} · cadastrada {when(store.created_at)}</p>
          </div>
          {#if Number(store.same_root_cnpj) > 0}
            <span class="flag">
              <i class="bi bi-exclamation-triangle-fill"></i>
              CNPJ de mesma raiz já cadastrado ({store.same_root_cnpj})
            </span>
          {/if}
        </header>

        <div class="checks">
          <span class="ok"><i class="bi bi-check-circle"></i> CNPJ com dígito válido</span>
          {#if store.category}<span class="pending"><i class="bi bi-tag"></i> {store.category}</span>{/if}
          <span class={store.has_location ? 'ok' : 'pending'}>
            <i class={`bi ${store.has_location ? 'bi-geo-alt-fill' : 'bi-geo-alt'}`}></i>
            {store.has_location ? 'Localização marcada' : 'Sem localização: frete usa o centro da cidade'}
          </span>
        </div>

        {#if store.contact_name || store.address_text}
          <dl class="facts">
            {#if store.contact_name}
              <dt>Responsável</dt>
              <dd>{store.contact_name} · <span class="fuu-mono">{phoneMask(store.contact_phone)}</span></dd>
            {/if}
            {#if store.address_text}<dt>Endereço</dt><dd>{store.address_text}</dd>{/if}
            <dt>Chave Pix</dt>
            <dd>
              {#if store.pix_key}
                <span class="fuu-mono">{store.pix_key}</span>
                {#if store.pix_key_checked}
                  <span class="ok-tag">CNPJ da loja</span>
                {:else}
                  <span class="check-tag">confira a titularidade antes de aprovar</span>
                {/if}
              {:else}
                <span class="muted">não informada (Pix direto fica indisponível)</span>
              {/if}
            </dd>
          </dl>
        {/if}

        {#if rejecting === store.id}
          <label class="reason">
            <span>Motivo da recusa (a loja lê isto)</span>
            <textarea maxlength="500" rows="2" placeholder="Ex.: contrato social ilegível" bind:value={reason}></textarea>
          </label>
          <div class="actions">
            <button type="button" class="btn-fuu-danger-outline" disabled={busy} onclick={() => decide(store, 'reject')}>
              Confirmar recusa
            </button>
            <button type="button" class="link" onclick={() => (rejecting = null)}>Voltar</button>
          </div>
        {:else}
          <div class="actions">
            <button type="button" class="btn-fuu-danger-outline" onclick={() => (rejecting = store.id)}>
              Recusar
            </button>
            <button type="button" class="btn-fuu-primary approve" disabled={busy} onclick={() => decide(store, 'approve')}>
              Aprovar · só-online por 30 dias
            </button>
          </div>
        {/if}
      </article>
    {/each}
  </div>
{/if}

{#if (data?.recent ?? []).length > 0}
  <p class="section later">DECIDIDAS NOS ÚLTIMOS 7 DIAS</p>
  <div class="recent">
    {#each data.recent as store (store.id)}
      <div class="row">
        <span class="rname">{store.name}</span>
        <span class={`badge ${store.approved_at ? 'ok' : 'bad'}`}>
          {store.approved_at ? 'APROVADA' : 'RECUSADA'}
        </span>
        {#if store.rejection_reason}<span class="why">{store.rejection_reason}</span>{/if}
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
  header {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    flex-wrap: wrap;
  }
  .name {
    font-size: 16px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .meta {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 3px 0 0;
  }
  .flag {
    margin-left: auto;
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
    border-radius: 8px;
    padding: 7px 11px;
    font-size: 11.5px;
    font-weight: 700;
  }
  .checks {
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
    margin: 14px 0;
    font-size: 12.5px;
  }
  .checks .ok {
    color: var(--fuu-leaf-dark);
  }
  .checks .pending {
    color: var(--fuu-ink-4);
  }
  .reason {
    display: block;
    margin-bottom: 12px;
  }
  .reason span {
    display: block;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin-bottom: 4px;
  }
  textarea {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
    resize: vertical;
  }
  .actions {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .actions .approve {
    flex: 1;
    min-height: var(--fuu-tap-operator);
  }
  .actions .btn-fuu-danger-outline {
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
  .recent .row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--fuu-line-5);
    font-size: 13px;
  }
  .rname {
    font-weight: 600;
    color: var(--fuu-ink-1);
  }
  .badge {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    border-radius: 999px;
    padding: 3px 9px;
  }
  .badge.ok {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .badge.bad {
    background: var(--fuu-red-tint);
    color: var(--fuu-red);
  }
  .why {
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .facts {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 14px;
    font-size: 13px;
    margin: 0 0 14px;
  }
  .facts dt {
    color: var(--fuu-ink-5);
    font-weight: 600;
  }
  .facts dd {
    margin: 0;
    color: var(--fuu-ink-1);
    word-break: break-word;
  }
  .ok-tag,
  .check-tag {
    font-size: 11px;
    font-weight: 700;
    border-radius: 999px;
    padding: 2px 8px;
    margin-left: 6px;
  }
  .ok-tag {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .check-tag {
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
  }
  .muted {
    color: var(--fuu-ink-5);
  }
</style>
