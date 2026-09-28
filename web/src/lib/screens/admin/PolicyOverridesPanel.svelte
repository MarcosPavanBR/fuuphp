<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Exceções de política (admin/policy_overrides.php), embaixo da política
  // da plataforma na aba Políticas: um número diferente só numa cidade ou só
  // numa loja. Na conta do checkout, a da loja ganha da da cidade, e as duas
  // ganham da política da plataforma. Encerrar não apaga -- fica no
  // histórico, porque pedido já feito congelou o número da época.
  let data = $state(null);
  let form = $state(blank());
  let errors = $state({});
  let busy = $state(false);

  function blank() {
    return { scope: 'city', scope_id: '', reason: '', ends_on: '', patch: {} };
  }

  async function load() {
    try {
      data = await api.get('/admin/policy_overrides.php', { token: adminToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar as exceções.');
    }
  }

  $effect(() => {
    load();
  });

  let targets = $derived(
    !data
      ? []
      : form.scope === 'city'
        ? data.cities.map((c) => ({ id: c.id, name: c.name }))
        : data.stores.map((s) => ({ id: s.id, name: s.city ? `${s.name} (${s.city})` : s.name }))
  );
  let labelOf = $derived(Object.fromEntries((data?.fields ?? []).map((f) => [f.key, f.label])));

  // Como o número aparece na lista: dinheiro em R$, comissão em %, raio em km.
  const SHOW = {
    delivery_base_fee: (v) => `frete base ${money(v)}`,
    delivery_per_km: (v) => `frete ${money(v)}/km`,
    delivery_max_km: (v) => `raio ${String(v).replace('.', ',')} km`,
    commission_bps: (v) => `comissão ${(Number(v) / 100).toFixed(2).replace('.', ',')}%`,
    cancel_fee: (v) => `taxa de cancelamento ${money(v)}`,
  };

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function describe(patch) {
    return Object.entries(patch ?? {})
      .map(([k, v]) => (SHOW[k] ? SHOW[k](v) : `${labelOf[k] ?? k}: ${v}`))
      .join(' · ');
  }

  function day(raw) {
    const d = parsePgTimestamp(raw);
    return d ? d.toLocaleDateString('pt-BR') : '';
  }

  async function create(event) {
    event.preventDefault();
    if (busy) return;
    busy = true;
    errors = {};
    try {
      await api.post('/admin/policy_overrides.php', { token: adminToken(), body: form });
      toastr.success('Exceção criada. Vale a partir do próximo pedido.');
      form = blank();
      await load();
    } catch (e) {
      errors = e.fields ?? {};
      toastr.error(e.message ?? 'Não deu pra criar a exceção.');
    } finally {
      busy = false;
    }
  }

  async function end(row) {
    if (busy) return;
    busy = true;
    try {
      await api.post('/admin/policy_overrides.php', { token: adminToken(), body: { id: row.id, action: 'end' } });
      toastr.success(`Exceção de ${row.target_name ?? 'loja'} encerrada.`);
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra encerrar.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="fuu-card block">
  <p class="section">EXCEÇÕES POR CIDADE OU LOJA</p>
  {#if !data}
    <p class="empty">Carregando…</p>
  {:else}
    <form class="grid" onsubmit={create}>
      <label>
        <span>Vale para</span>
        <select bind:value={form.scope} onchange={() => (form.scope_id = '')}>
          <option value="city">Uma cidade</option>
          <option value="restaurant">Uma loja</option>
        </select>
      </label>
      <label class="wide" class:bad={errors.scope_id}>
        <span>{form.scope === 'city' ? 'Cidade' : 'Loja'}</span>
        <select bind:value={form.scope_id}>
          <option value="">Escolha…</option>
          {#each targets as t (t.id)}<option value={t.id}>{t.name}</option>{/each}
        </select>
        {#if errors.scope_id}<small class="err">{errors.scope_id}</small>{/if}
      </label>
      {#each data.fields as f (f.key)}
        <label class:bad={errors[`patch.${f.key}`]}>
          <span>{f.label}</span>
          <input type="number" step="any" min={f.min} max={f.max} placeholder="igual à plataforma" bind:value={form.patch[f.key]} />
          {#if errors[`patch.${f.key}`]}<small class="err">{errors[`patch.${f.key}`]}</small>{/if}
        </label>
      {/each}
      <label class="wide" class:bad={errors.reason}>
        <span>Motivo (fica no histórico)</span>
        <input type="text" maxlength="200" placeholder="Ex.: frete de lançamento da cidade" bind:value={form.reason} />
        {#if errors.reason}<small class="err">{errors.reason}</small>{/if}
      </label>
      <label class:bad={errors.ends_on}>
        <span>Último dia (opcional)</span>
        <input type="date" bind:value={form.ends_on} />
        {#if errors.ends_on}<small class="err">{errors.ends_on}</small>{/if}
      </label>
      {#if errors.patch}<p class="err full">{errors.patch}</p>{/if}
      <div class="full">
        <button type="submit" class="btn-fuu-primary" disabled={busy}>{busy ? 'Salvando…' : 'Criar exceção'}</button>
      </div>
    </form>
    <p class="note">
      Campo em branco segue a política da plataforma. A exceção da loja ganha da exceção da cidade. Vale para pedidos
      novos: o que já foi pedido continua com o número do dia.
    </p>

    {#if data.overrides.length === 0}
      <p class="empty">Nenhuma exceção. Todas as cidades e lojas seguem a política da plataforma.</p>
    {:else}
      {#each data.overrides as o (o.id)}
        <div class="ov" class:off={!o.live}>
          <div class="info">
            <strong>{o.scope === 'city' ? 'Cidade' : 'Loja'}: {o.target_name ?? o.scope_id}</strong>
            <span class="fuu-mono">{describe(o.patch)}</span>
            <span>
              {o.reason} · {o.created_by_name ?? 'admin'}, {day(o.created_at)}
              {#if o.expires_at}· {o.live ? 'até' : 'encerrada em'} {day(o.expires_at)}{/if}
            </span>
          </div>
          {#if o.live}
            <button type="button" class="ghost" disabled={busy} onclick={() => end(o)}>Encerrar</button>
          {:else}
            <span class="state">Encerrada</span>
          {/if}
        </div>
      {/each}
    {/if}
  {/if}
</div>

<style>
  .block {
    padding: 16px;
    margin-top: 12px;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 12px;
  }
  .grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 10px 12px;
  }
  .grid .wide {
    grid-column: span 2;
  }
  .grid .full {
    grid-column: 1 / -1;
  }
  label {
    display: grid;
    gap: 4px;
    font-size: 12.5px;
    color: var(--fuu-ink-3);
    min-width: 0;
  }
  input,
  select {
    border: 1px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 9px 12px;
    font-family: inherit;
    font-size: 14px;
    background: var(--fuu-white);
    width: 100%;
    min-width: 0;
  }
  label.bad input,
  label.bad select {
    border-color: var(--fuu-alert);
  }
  .err {
    color: var(--fuu-alert);
    font-size: 11.5px;
    margin: 0;
  }
  .note {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 12px 0 8px;
    line-height: 1.55;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .ov {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid var(--fuu-line-5);
    flex-wrap: wrap;
  }
  .ov.off .info {
    opacity: 0.55;
  }
  .info {
    flex: 1;
    min-width: 220px;
    display: grid;
    gap: 2px;
  }
  .info span {
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .ghost {
    border: 1.5px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: 10px;
    padding: 8px 14px;
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .state {
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
  @media (max-width: 520px) {
    .grid .wide {
      grid-column: 1 / -1;
    }
  }
</style>
