<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import StoreCouponLimitPanel from './StoreCouponLimitPanel.svelte';

  // Tela 15.3 — "Cupons e campanhas".
  //
  // "A coluna que falta em quase todo painel: quem paga o desconto. Teto
  // obrigatório com desativação automática, um uso por CPF (não por conta) e
  // projeção de retorno antes de criar — cupom sem teto é como o dinheiro
  // vaza sem ninguém ver."
  //
  // A projeção é pedida ao servidor (dry_run) antes de criar: ela usa ticket
  // médio e comissão REAIS, então não dá pra calcular no navegador.
  let data = $state(null);
  let busy = $state(false);
  let projecting = $state(false);
  let projection = $state(null);

  const KINDS = [
    { code: 'fixed', label: 'R$' },
    { code: 'percent', label: '%' },
    { code: 'free_delivery', label: 'Frete' },
  ];
  const PAYERS = [
    { code: 'platform', label: 'Nós' },
    { code: 'store', label: 'Loja' },
    { code: 'shared', label: '50/50' },
  ];
  const PAYER_BADGE = { platform: 'PLATAFORMA', store: 'LOJA', shared: 'DIVIDIDO 50/50' };
  const KIND_LABEL = { fixed: 'off', percent: '% off', free_delivery: 'frete grátis' };

  let form = $state({
    code: '',
    kind: 'fixed',
    value: 10,
    min_order: 30,
    audience: 'all',
    payer: 'platform',
    budget_cap: 2500,
    restaurant_id: '',
  });

  async function load() {
    try {
      data = await api.get('/admin/campaigns.php', { token: adminToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar as campanhas.');
    }
  }

  $effect(() => {
    load();
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function rule(coupon) {
    const value =
      coupon.kind === 'percent'
        ? `${Number(coupon.value)}% off`
        : coupon.kind === 'free_delivery'
          ? 'frete grátis'
          : `${money(coupon.value)} off`;
    const parts = [value];
    if (coupon.restaurant_name) parts.push(`só ${coupon.restaurant_name}`);
    if (Number(coupon.min_order) > 0) parts.push(`mín. ${money(coupon.min_order)}`);
    const audience = data?.audiences.find((a) => a.code === coupon.audience);
    if (audience && coupon.audience !== 'all') parts.push(audience.label.toLowerCase());
    return parts.join(' · ');
  }

  async function project() {
    projecting = true;
    try {
      const res = await api.post('/admin/campaigns.php', {
        token: adminToken(),
        body: { ...body(), dry_run: true },
      });
      projection = res;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra projetar.');
      projection = null;
    } finally {
      projecting = false;
    }
  }

  function body() {
    return {
      code: form.code.trim().toUpperCase(),
      kind: form.kind,
      value: Number(form.value),
      min_order: Number(form.min_order),
      audience: form.audience,
      payer: form.payer,
      budget_cap: Number(form.budget_cap),
      ...(form.restaurant_id.trim() !== '' ? { restaurant_id: form.restaurant_id.trim() } : {}),
    };
  }

  async function create() {
    busy = true;
    try {
      const res = await api.post('/admin/campaigns.php', { token: adminToken(), body: body() });
      toastr.success(`Campanha ${res.coupon.code} criada com teto de ${money(res.coupon.budget_cap)}.`);
      form.code = '';
      projection = null;
      await load();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra criar a campanha.');
    } finally {
      busy = false;
    }
  }
</script>

{#if data === null}
  <p class="loading">Carregando campanhas…</p>
{:else}
  <div class="campaigns-layout">
    <main class="main">
      <p class="section-label">CAMPANHAS ATIVAS</p>
      <div class="table">
        <div class="thead">
          <span class="c-code">CÓDIGO</span>
          <span class="c-rule">REGRA</span>
          <span class="c-payer">QUEM PAGA</span>
          <span class="c-budget">GASTO / TETO</span>
          <span class="c-uses">USOS</span>
        </div>
        {#each data.coupons as coupon (coupon.id)}
          {@const pct = Math.min(100, Math.round((Number(coupon.spent) * 100) / Number(coupon.budget_cap)))}
          <div class="trow" class:off={!coupon.active}>
            <span class="c-code fuu-mono">{coupon.code}</span>
            <span class="c-rule">{rule(coupon)}</span>
            <span class="c-payer">
              <span class={`badge ${coupon.payer}`}>{PAYER_BADGE[coupon.payer]}</span>
            </span>
            <span class="c-budget">
              <span class="amount">{money(coupon.spent)} / {money(coupon.budget_cap)}</span>
              <span class="bar"><span class={`fill ${coupon.payer}`} style:width={`${pct}%`}></span></span>
            </span>
            <span class="c-uses">
              {coupon.uses}
              {#if !coupon.active}<span class="off-tag">desativado</span>{/if}
            </span>
          </div>
        {:else}
          <p class="empty">Nenhuma campanha criada ainda.</p>
        {/each}
      </div>

      <div class="rules">
        <p class="rules-title">Regras que evitam prejuízo com cupom</p>
        <ul>
          <li>
            <strong>Teto de gasto é obrigatório.</strong> Estourado, o cupom desativa sozinho — o
            <code>CHECK (spent &lt;= budget_cap)</code> do banco impede passar, e o resgate que encosta no teto
            desliga a campanha.
          </li>
          <li>
            <strong>Um uso por CPF</strong>, não por conta: <code>UNIQUE (coupon_id, cpf)</code>. E-mail novo
            com o mesmo CPF não reativa o cupom de primeira compra.
          </li>
          <li>
            <strong>Nunca acumula</strong> com outro cupom; o desconto entra como linha própria no pedido e no
            livro, com a conta de quem pagou.
          </li>
          <li>
            <strong>Cupom de loja sai do repasse dela</strong> — e ela mesma cria, no painel, dentro do teto que
            você liberar abaixo (0 = não cria). O teto conta o orçamento dos cupons vivos dela; desativar ou vencer
            devolve o que não gastou.
          </li>
        </ul>
      </div>

      <p class="paid">
        Já saiu de cada bolso, pelo livro: <strong>{money(data.paid_so_far.platform)}</strong> da plataforma e
        <strong>{money(data.paid_so_far.store)}</strong> do repasse das lojas.
      </p>

      <StoreCouponLimitPanel />
    </main>

    <aside class="side">
      <p class="section-label">NOVA CAMPANHA</p>

      <label class="field">
        <span>Código</span>
        <input maxlength="20" type="text" class="fuu-mono" placeholder="SEXTAPIZZA" bind:value={form.code} />
      </label>

      <p class="field-label">Desconto</p>
      <div class="pills">
        {#each KINDS as option (option.code)}
          <button
            type="button"
            class="pill"
            class:on={form.kind === option.code}
            onclick={() => (form.kind = option.code)}
          >
            {option.label}
          </button>
        {/each}
      </div>
      <input type="number" step="0.5" min="0" class="fuu-mono value" bind:value={form.value} />

      <label class="field">
        <span>Pedido mínimo</span>
        <input type="number" step="1" min="0" class="fuu-mono" bind:value={form.min_order} />
      </label>

      <label class="field">
        <span>Quem recebe</span>
        <select bind:value={form.audience}>
          {#each data.audiences as option (option.code)}
            <option value={option.code}>{option.label}</option>
          {/each}
        </select>
      </label>

      <label class="field">
        <span>Teto de gasto</span>
        <input type="number" step="50" min="1" class="fuu-mono" bind:value={form.budget_cap} />
      </label>

      <p class="field-label">Quem paga</p>
      <div class="pills">
        {#each PAYERS as option (option.code)}
          <button
            type="button"
            class="pill"
            class:on={form.payer === option.code}
            onclick={() => (form.payer = option.code)}
          >
            {option.label}
          </button>
        {/each}
      </div>

      {#if form.payer === 'store' || form.restaurant_id}
        <label class="field">
          <span>Loja (obrigatório quando ela paga)</span>
          <input type="text" class="fuu-mono small" placeholder="uuid da loja" bind:value={form.restaurant_id} />
        </label>
      {/if}

      <button type="button" class="project" disabled={projecting} onclick={project}>
        {projecting ? 'Projetando…' : 'Ver projeção'}
      </button>

      {#if projection}
        <div class="projection">
          <p>
            <strong>Público:</strong>
            {projection.audience_size} {projection.audience_size === 1 ? 'pessoa' : 'pessoas'}.
          </p>
          {#if projection.projection.redemptions !== null}
            <p>
              <strong>Projeção:</strong> ~{projection.projection.redemptions} resgates até o teto.
              {#if projection.projection.gmv !== null}
                Com ticket médio de {money(projection.projection.ticket_avg)}, gera ≈
                {money(projection.projection.gmv)} de GMV e {money(projection.projection.commission)} de comissão.
              {:else}
                Sem pedidos suficientes pra estimar GMV e comissão.
              {/if}
            </p>
          {:else}
            <p>{projection.projection.reason}</p>
          {/if}
        </div>
      {/if}

      <button type="button" class="btn-fuu-primary w-100 create" disabled={busy} onclick={create}>
        {busy ? 'Criando…' : 'Criar campanha'}
      </button>

      <p class="tech fuu-mono">
        coupons + coupon_redemptions<br />
        UNIQUE (coupon_id, cpf)<br />
        teto verificado em transação
      </p>
    </aside>
  </div>
{/if}

<style>
  .campaigns-layout {
    display: flex;
    align-items: flex-start;
  }
  .loading {
    padding: 40px 22px;
    color: var(--fuu-ink-5);
  }
  .main {
    flex: 1;
    padding: 22px;
    min-width: 0;
  }
  .side {
    width: 330px;
    flex: none;
    background: var(--fuu-white);
    border-left: 1px solid var(--fuu-line-3);
    padding: 20px;
    min-height: calc(100vh - 63px);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-6);
    margin: 0 0 11px;
  }
  .table {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    overflow: hidden;
  }
  .thead {
    display: flex;
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    font-weight: 800;
    color: var(--fuu-ink-6);
    letter-spacing: 0.06em;
    padding: 12px 16px;
    border-bottom: 1px solid var(--fuu-line-4);
  }
  .trow {
    display: flex;
    align-items: center;
    font-size: 12.5px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--fuu-line-6);
  }
  .trow.off {
    opacity: 0.55;
  }
  .c-code {
    width: 130px;
    font-weight: 600;
  }
  .c-rule {
    flex: 1;
    color: var(--fuu-ink-2);
    min-width: 0;
  }
  .c-payer {
    width: 130px;
  }
  .c-budget {
    width: 140px;
  }
  .c-uses {
    width: 90px;
    font-weight: 700;
    color: var(--fuu-ink-1);
  }
  .badge {
    font-size: 10px;
    font-weight: 800;
    padding: 4px 8px;
    border-radius: 20px;
  }
  .badge.platform {
    background: var(--fuu-red-tint);
    color: var(--fuu-alert);
  }
  .badge.store {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .badge.shared {
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .amount {
    display: block;
    font-weight: 700;
    color: var(--fuu-ink-1);
  }
  .bar {
    display: block;
    height: 5px;
    background: var(--fuu-line-4);
    border-radius: 3px;
    margin-top: 4px;
    overflow: hidden;
  }
  .fill {
    display: block;
    height: 5px;
  }
  .fill.platform {
    background: var(--fuu-red);
  }
  .fill.store {
    background: var(--fuu-leaf);
  }
  .fill.shared {
    background: var(--fuu-wait-text);
  }
  .off-tag {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: var(--fuu-ink-5);
  }
  .empty {
    padding: 16px;
    font-size: 13px;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  .rules {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 18px;
    margin-top: 14px;
  }
  .rules-title {
    font-size: 14px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    margin: 0 0 9px;
  }
  .rules ul {
    margin: 0;
    padding-left: 18px;
    font-size: 12.5px;
    line-height: 1.85;
    color: var(--fuu-ink-2);
  }
  .rules code {
    font-family: var(--fuu-font-mono);
    font-size: 11.5px;
    color: var(--fuu-ink-3);
  }
  .paid {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    margin-top: 14px;
  }
  .paid strong {
    color: var(--fuu-ink-1);
  }
  .field {
    display: block;
    margin-bottom: 12px;
  }
  .field > span,
  .field-label {
    display: block;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--fuu-ink-2);
    margin-bottom: 5px;
  }
  .field input,
  .field select,
  .value {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 11px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    color: var(--fuu-ink-1);
    background: var(--fuu-white);
  }
  .value {
    margin: 7px 0 12px;
    font-weight: 700;
  }
  .field .small {
    font-size: 11px;
  }
  .pills {
    display: flex;
    gap: 7px;
  }
  .pill {
    flex: 1;
    text-align: center;
    font-family: var(--fuu-font-body);
    font-size: 12.5px;
    font-weight: 700;
    background: var(--fuu-line-5);
    border: none;
    color: var(--fuu-ink-1);
    padding: 10px 0;
    border-radius: 8px;
  }
  .pill.on {
    background: var(--fuu-ink-1);
    color: var(--fuu-white);
  }
  .project {
    width: 100%;
    background: var(--fuu-white);
    border: 1.5px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 12px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 700;
    color: var(--fuu-ink-2);
    margin-top: 14px;
  }
  .projection {
    background: var(--fuu-line-6);
    border-radius: 10px;
    padding: 12px;
    margin-top: 12px;
    font-size: 11.5px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
  }
  .projection p {
    margin: 0 0 6px;
  }
  .projection p:last-child {
    margin-bottom: 0;
  }
  .projection strong {
    color: var(--fuu-ink-1);
  }
  .create {
    margin-top: 14px;
  }
  .tech {
    font-size: 10px;
    color: var(--fuu-ink-6);
    margin-top: 12px;
    line-height: 1.6;
  }
  @media (max-width: 1000px) {
    .campaigns-layout {
      flex-direction: column;
    }
    .side {
      width: 100%;
      min-height: 0;
      border-left: none;
      border-top: 1px solid var(--fuu-line-3);
    }
  }
</style>
