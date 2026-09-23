<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';

  // Tela 15.3 — "cupom de loja ela cria sozinha no painel, dentro do teto que
  // você liberar aqui". O teto de cupom de cada loja (migração 031).
  //
  // Sem busca, lista as lojas que já têm teto; a busca (nome ou CNPJ) acha
  // qualquer loja aprovada pra liberar a primeira vez.
  let query = $state('');
  let stores = $state(null);
  let drafts = $state({});
  let saving = $state(null);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  async function load(event) {
    event?.preventDefault();
    try {
      const res = await api.get('/admin/store_coupon_limits.php', {
        token: adminToken(),
        query: query.trim() ? { q: query.trim() } : undefined,
      });
      stores = res.stores;
      drafts = Object.fromEntries(res.stores.map((s) => [s.id, Number(s.coupon_budget_limit)]));
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar os tetos.');
    }
  }

  $effect(() => {
    load();
  });

  async function save(store) {
    const limit = Number(drafts[store.id]);
    if (!Number.isFinite(limit) || limit < 0) {
      toastr.warning('O teto é um valor em reais, zero ou mais.');
      return;
    }
    saving = store.id;
    try {
      const res = await api.post('/admin/store_coupon_limits.php', {
        token: adminToken(),
        body: { restaurant_id: store.id, limit },
      });
      toastr.success(
        limit === 0
          ? `${store.name} não cria mais cupom novo (os vivos seguem até vencer).`
          : `${store.name}: teto de ${money(res.budget.limit)}, ${money(res.budget.available)} livres.`
      );
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar o teto.');
    } finally {
      saving = null;
    }
  }
</script>

<section class="limits">
  <p class="section-label">TETO DE CUPOM POR LOJA</p>
  <form class="search" onsubmit={load}>
    <input type="search" placeholder="Buscar loja por nome ou CNPJ" bind:value={query} aria-label="Buscar loja" />
    <button type="submit" class="btn-fuu-primary">Buscar</button>
  </form>

  {#if stores === null}
    <p class="empty">Carregando…</p>
  {:else if stores.length === 0}
    <p class="empty">
      {query.trim() ? 'Nenhuma loja aprovada com esse nome ou CNPJ.' : 'Nenhuma loja com teto liberado ainda. Busque uma loja pra liberar.'}
    </p>
  {:else}
    <div class="table">
      {#each stores as store (store.id)}
        {@const used = Number(store.budget.limit) > 0 ? Math.min(100, Math.round((store.budget.committed * 100) / store.budget.limit)) : 0}
        <div class="row">
          <div class="who">
            <p class="name">{store.name}</p>
            <p class="meta fuu-mono">
              comprometido {money(store.budget.committed)} · livre {money(store.budget.available)}
            </p>
            <span class="bar"><span class="fill" style:width={`${used}%`}></span></span>
          </div>
          <label class="limit">
            <span>Teto (R$)</span>
            <input type="number" min="0" step="50" class="fuu-mono" bind:value={drafts[store.id]} />
          </label>
          <button
            type="button"
            class="btn-fuu-primary save"
            disabled={saving === store.id || Number(drafts[store.id]) === Number(store.coupon_budget_limit)}
            onclick={() => save(store)}
          >
            {saving === store.id ? 'Salvando…' : 'Salvar'}
          </button>
        </div>
      {/each}
    </div>
  {/if}
</section>

<style>
  .limits {
    margin-top: 26px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-6);
    margin: 0 0 11px;
  }
  .search {
    display: flex;
    gap: 10px;
    margin-bottom: 12px;
  }
  .search input {
    flex: 1;
    min-width: 0;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 9px 13px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  .table {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    overflow: hidden;
  }
  .row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
    border-top: 1px solid var(--fuu-line-5);
    flex-wrap: wrap;
  }
  .row:first-child {
    border-top: none;
  }
  .who {
    flex: 1;
    min-width: 180px;
  }
  .name {
    font-size: 14px;
    font-weight: 700;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .meta {
    font-size: 11px;
    color: var(--fuu-ink-4);
    margin: 2px 0 6px;
  }
  .bar {
    display: block;
    height: 5px;
    background: var(--fuu-line-5);
    border-radius: 99px;
    overflow: hidden;
    max-width: 240px;
  }
  .fill {
    display: block;
    height: 100%;
    background: var(--fuu-red);
  }
  .limit span {
    display: block;
    font-size: 11px;
    color: var(--fuu-ink-4);
    margin-bottom: 3px;
  }
  .limit input {
    width: 120px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 8px;
    padding: 7px 10px;
    font-size: 14px;
  }
  .save {
    min-height: 38px;
  }
</style>
