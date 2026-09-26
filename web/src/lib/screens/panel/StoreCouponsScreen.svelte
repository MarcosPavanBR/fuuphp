<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 15.3, lado da loja — "cupom de loja ela cria sozinha no painel,
  // dentro do teto que você liberar aqui".
  //
  // O desconto sai do repasse da loja (a tela diz isso antes de criar, não
  // depois). O teto é da plataforma; o que a loja vê é quanto sobra dele. A
  // projeção vem do servidor (dry_run), com o ticket médio real desta loja.
  let data = $state(null);
  let busy = $state(false);
  let projecting = $state(false);
  let projection = $state(null);

  const KINDS = [
    { code: 'fixed', label: 'R$ off' },
    { code: 'percent', label: '% off' },
    { code: 'free_delivery', label: 'Frete grátis' },
  ];

  let form = $state({ code: '', kind: 'fixed', value: 10, min_order: 40, audience: 'inactive_15d', budget_cap: 200, days: 14 });

  function money(v) {
    return `R$ ${Number(v ?? 0).toFixed(2).replace('.', ',')}`;
  }

  function rule(c) {
    const off = c.kind === 'percent' ? `${Number(c.value)}% off` : c.kind === 'free_delivery' ? 'frete grátis' : `${money(c.value)} off`;
    return Number(c.min_order) > 0 ? `${off} acima de ${money(c.min_order)}` : off;
  }

  function until(raw) {
    const d = parsePgTimestamp(raw);
    return d ? `até ${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}` : 'sem prazo';
  }

  async function load() {
    try {
      data = await api.get('/restaurants/coupons.php', { token: staffToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar os cupons.');
    }
  }

  $effect(() => {
    load();
  });

  function body(extra = {}) {
    return {
      action: 'create',
      code: form.code.trim().toUpperCase(),
      kind: form.kind,
      value: Number(form.value),
      min_order: Number(form.min_order),
      audience: form.audience,
      budget_cap: Number(form.budget_cap),
      days: Number(form.days),
      ...extra,
    };
  }

  function explain(e, fallback) {
    if (e instanceof ApiError && e.fields) {
      return `${e.message} ${Object.entries(e.fields).map(([k, v]) => `${k}: ${v}`).join(' · ')}`;
    }
    return e.message ?? fallback;
  }

  async function project() {
    projecting = true;
    try {
      projection = await api.post('/restaurants/coupons.php', { token: staffToken(), body: body({ dry_run: true }) });
    } catch (e) {
      toastr.error(explain(e, 'Não deu pra projetar.'));
    } finally {
      projecting = false;
    }
  }

  async function create() {
    busy = true;
    try {
      const res = await api.post('/restaurants/coupons.php', { token: staffToken(), body: body() });
      toastr.success(`Cupom ${res.coupon.code} no ar. Sobram ${money(res.budget.available)} do teto.`);
      form.code = '';
      projection = null;
      await load();
    } catch (e) {
      toastr.error(explain(e, 'Não deu pra criar o cupom.'));
    } finally {
      busy = false;
    }
  }

  async function deactivate(coupon) {
    try {
      const res = await api.post('/restaurants/coupons.php', {
        token: staffToken(),
        body: { action: 'deactivate', coupon_id: coupon.id },
      });
      toastr.info(`${coupon.code} desligado. Livre no teto: ${money(res.budget.available)}.`);
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra desligar.');
    }
  }
</script>

{#if data === null}
  <p class="loading">Carregando cupons…</p>
{:else}
  <div class="layout">
    <main class="main">
      <div class="budget fuu-card">
        <p class="k">TETO DE CUPOM LIBERADO PELA PLATAFORMA</p>
        {#if data.budget.limit <= 0}
          <p class="big">Ainda não liberado</p>
          <p class="note">Peça ao suporte um teto de cupom: com ele, a loja cria as próprias promoções aqui.</p>
        {:else}
          <p class="big fuu-mono">{money(data.budget.available)} <span>livres de {money(data.budget.limit)}</span></p>
          <span class="bar"><span class="fill" style:width={`${Math.min(100, Math.round((data.budget.committed * 100) / data.budget.limit))}%`}></span></span>
          <p class="note">O teto conta o orçamento dos cupons vivos. Desligar ou vencer devolve o que não foi gasto.</p>
        {/if}
      </div>

      <p class="section-label">CUPONS DESTA LOJA · PAGOS PELO SEU REPASSE</p>
      {#each data.coupons as coupon (coupon.id)}
        {@const pct = Math.min(100, Math.round((Number(coupon.spent) * 100) / Number(coupon.budget_cap)))}
        <div class="coupon fuu-card" class:off={!coupon.live}>
          <div class="c-main">
            <p class="code fuu-mono">{coupon.code}</p>
            <p class="rule">{rule(coupon)} · {until(coupon.ends_at)}</p>
            <p class="spent fuu-mono">
              {money(coupon.spent)} de {money(coupon.budget_cap)} · {coupon.uses} uso{Number(coupon.uses) === 1 ? '' : 's'}
            </p>
            <span class="bar"><span class="fill" style:width={`${pct}%`}></span></span>
          </div>
          <div class="c-side">
            {#if !coupon.created_by_store}
              <span class="tag">criado pela plataforma</span>
            {/if}
            {#if coupon.live && coupon.created_by_store}
              <button type="button" class="btn-fuu-danger-outline" onclick={() => deactivate(coupon)}>Desligar</button>
            {:else if !coupon.live}
              <span class="tag">encerrado</span>
            {/if}
          </div>
        </div>
      {:else}
        <p class="empty">Nenhum cupom ainda.</p>
      {/each}
    </main>

    {#if data.budget.limit > 0}
      <aside class="side">
        <p class="section-label">NOVO CUPOM</p>
        <label class="field">
          <span>Código</span>
          <input maxlength="20" type="text" class="fuu-mono" placeholder="VOLTA10" bind:value={form.code} />
        </label>
        <p class="field-label">Desconto</p>
        <div class="pills">
          {#each KINDS as k (k.code)}
            <button type="button" class="pill" class:on={form.kind === k.code} onclick={() => (form.kind = k.code)}>{k.label}</button>
          {/each}
        </div>
        <label class="field">
          <span>{form.kind === 'percent' ? 'Percentual' : form.kind === 'free_delivery' ? 'Frete coberto até (R$)' : 'Valor (R$)'}</span>
          <input type="number" min="0" step="0.5" class="fuu-mono" bind:value={form.value} />
        </label>
        <label class="field">
          <span>Pedido mínimo (R$)</span>
          <input type="number" min="0" step="1" class="fuu-mono" bind:value={form.min_order} />
        </label>
        <label class="field">
          <span>Quem recebe</span>
          <select bind:value={form.audience}>
            {#each data.audiences as a (a.code)}
              <option value={a.code}>{a.label}</option>
            {/each}
          </select>
        </label>
        <div class="two">
          <label class="field">
            <span>Teto do cupom (R$)</span>
            <input type="number" min="1" step="10" class="fuu-mono" bind:value={form.budget_cap} />
          </label>
          <label class="field">
            <span>Dias no ar</span>
            <input type="number" min="1" max={data.max_days} step="1" class="fuu-mono" bind:value={form.days} />
          </label>
        </div>

        <p class="warn">
          <i class="bi bi-info-circle"></i>
          O desconto sai do seu repasse semanal. Ao chegar no teto, o cupom desliga sozinho.
        </p>

        <button type="button" class="project" disabled={projecting} onclick={project}>
          {projecting ? 'Projetando…' : 'Ver projeção'}
        </button>
        {#if projection}
          <div class="projection">
            <p><strong>Público:</strong> {projection.audience_size} {projection.audience_size === 1 ? 'pessoa' : 'pessoas'}.</p>
            {#if projection.projection.redemptions !== null}
              <p>
                ~{projection.projection.redemptions} usos até o teto.
                {#if projection.projection.gmv !== null}
                  Com o ticket médio da loja ({money(projection.projection.ticket_avg)}), ≈
                  {money(projection.projection.gmv)} em pedidos.
                {:else}
                  Ainda não há pedidos suficientes pra estimar quanto isso vende.
                {/if}
              </p>
            {:else}
              <p>{projection.projection.reason}</p>
            {/if}
          </div>
        {/if}
        <button type="button" class="btn-fuu-primary w-100 create" disabled={busy || form.code.trim() === ''} onclick={create}>
          {busy ? 'Criando…' : 'Criar cupom'}
        </button>
      </aside>
    {/if}
  </div>
{/if}

<style>
  .loading {
    padding: 40px 22px;
    color: var(--fuu-ink-5);
  }
  .layout {
    display: flex;
    align-items: flex-start;
  }
  .main {
    flex: 1;
    min-width: 0;
    padding: 22px;
  }
  .side {
    width: 320px;
    flex: none;
    background: var(--fuu-white);
    border-left: 1px solid var(--fuu-line-3);
    padding: 20px;
    min-height: calc(100vh - 63px);
  }
  @media (max-width: 820px) {
    .layout {
      flex-direction: column;
    }
    .side {
      width: 100%;
      box-sizing: border-box;
      border-left: none;
      border-top: 1px solid var(--fuu-line-3);
      min-height: 0;
    }
  }
  .section-label,
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-6);
    margin: 0 0 11px;
  }
  .budget {
    padding: 16px;
    margin-bottom: 22px;
  }
  .big {
    font-size: 22px;
    font-weight: 800;
    margin: 0 0 8px;
    color: var(--fuu-ink-1);
  }
  .big span {
    font-size: 13px;
    font-weight: 600;
    color: var(--fuu-ink-4);
  }
  .note {
    font-size: 12.5px;
    color: var(--fuu-ink-4);
    margin: 8px 0 0;
  }
  .bar {
    display: block;
    height: 6px;
    background: var(--fuu-line-5);
    border-radius: 99px;
    overflow: hidden;
  }
  .fill {
    display: block;
    height: 100%;
    background: var(--fuu-red);
  }
  .coupon {
    display: flex;
    gap: 14px;
    align-items: center;
    padding: 14px 16px;
    margin-bottom: 10px;
  }
  .coupon.off {
    opacity: 0.55;
  }
  .c-main {
    flex: 1;
    min-width: 0;
  }
  .code {
    font-size: 15px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .rule {
    font-size: 13px;
    margin: 2px 0;
    color: var(--fuu-ink-2);
  }
  .spent {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 0 0 6px;
  }
  .c-side {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 6px;
  }
  .tag {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    color: var(--fuu-ink-5);
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
  }
  .field {
    display: block;
    margin-bottom: 12px;
  }
  .field span,
  .field-label {
    display: block;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin: 0 0 4px;
  }
  .field input,
  .field select {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: 8px;
    padding: 8px 11px;
    font-size: 14px;
    background: var(--fuu-white);
  }
  .two {
    display: flex;
    gap: 10px;
  }
  .two .field {
    flex: 1;
    min-width: 0;
  }
  .pills {
    display: flex;
    gap: 6px;
    margin-bottom: 10px;
  }
  .pill {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-white);
    border-radius: var(--fuu-radius-pill);
    padding: 7px 4px;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--fuu-ink-3);
  }
  .pill.on {
    border-color: var(--fuu-ink-1);
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .warn {
    font-size: 12px;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-tint);
    border-radius: 8px;
    padding: 8px 10px;
    margin: 4px 0 12px;
  }
  .project {
    width: 100%;
    border: 1px dashed var(--fuu-line-2);
    background: none;
    border-radius: 8px;
    padding: 8px;
    font-weight: 700;
    color: var(--fuu-ink-3);
    margin-bottom: 10px;
  }
  .projection {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    background: var(--fuu-paper);
    border-radius: 8px;
    padding: 8px 10px;
    margin-bottom: 10px;
  }
  .projection p {
    margin: 0 0 4px;
  }
  .create {
    min-height: var(--fuu-tap-operator);
  }
</style>
