<script>
  import { api } from '../../services/api.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Sinais de fraude (admin/fraud_signals.php), embaixo das ocorrências. O
  // sistema já anotava os sinais, mas nenhuma tela mostrava. Aqui é só
  // leitura: a decisão (bloquear, abrir disputa) segue pelas telas de sempre.
  const KIND = {
    proof_reuse: 'Foto de entrega repetida',
    proof_phash: 'Comprovante parecido com outro',
    ip_velocity: 'Muitas contas no mesmo IP',
    geo_impossible: 'Posição impossível',
    cpf_multi: 'CPF em várias contas',
    address_reuse: 'Cupom de 1º pedido repetido no mesmo endereço',
  };

  let data = $state(null);
  let days = $state(30);
  let failed = $state(false);

  async function load() {
    try {
      data = await api.get('/admin/fraud_signals.php', { token: adminToken(), query: { days } });
      failed = false;
    } catch {
      failed = true;
    }
  }

  $effect(() => {
    days;
    load();
  });

  function when(raw) {
    const d = parsePgTimestamp(raw);
    return d ? d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
  }
</script>

<div class="fuu-card block">
  <div class="head">
    <p class="section">SINAIS DE FRAUDE</p>
    <select bind:value={days} aria-label="Período">
      <option value={7}>7 dias</option>
      <option value={30}>30 dias</option>
      <option value={90}>90 dias</option>
    </select>
  </div>
  {#if failed}
    <p class="empty">Não deu pra carregar os sinais.</p>
  {:else if !data}
    <p class="empty">Carregando…</p>
  {:else if data.signals.length === 0}
    <p class="empty">Nenhum sinal nos últimos {data.days} dias.</p>
  {:else}
    <div class="counts">
      {#each Object.entries(data.by_kind) as [kind, n] (kind)}
        <span class="count"><strong>{n}</strong> {KIND[kind] ?? kind}</span>
      {/each}
    </div>
    {#each data.signals as s (s.id)}
      <div class="signal">
        <span class="score" class:high={s.score >= 60} title="Gravidade (0 a 100)">{s.score}</span>
        <div class="info">
          <strong>{KIND[s.kind] ?? s.kind}</strong>
          <span>
            {#if s.user_name}{s.user_name}{s.user_phone ? ` · ${s.user_phone}` : ''}{s.user_blocked ? ' · conta bloqueada' : ''}{/if}
            {#if s.courier_name}Entregador {s.courier_name}{/if}
            {#if s.order_code} · pedido #{s.order_code}{/if}
          </span>
        </div>
        <span class="when">{when(s.created_at)}</span>
      </div>
    {/each}
  {/if}
</div>

<style>
  .block {
    padding: 16px;
    margin-top: 12px;
  }
  .head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
  }
  .section {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  select {
    border: 1px solid var(--fuu-line-2);
    border-radius: 10px;
    padding: 6px 10px;
    font-family: inherit;
    font-size: 13px;
    background: var(--fuu-white);
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 0;
  }
  .counts {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 8px;
  }
  .count {
    font-size: 12px;
    color: var(--fuu-ink-3);
    background: var(--fuu-line-5);
    border-radius: 999px;
    padding: 4px 10px;
  }
  .signal {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--fuu-line-5);
  }
  .score {
    font-family: var(--fuu-font-mono);
    font-weight: 700;
    font-size: 13px;
    min-width: 34px;
    text-align: center;
    border-radius: 8px;
    padding: 4px 0;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-2);
  }
  .score.high {
    background: var(--fuu-alert);
    color: var(--fuu-white);
  }
  .info {
    flex: 1;
    min-width: 0;
    display: grid;
    gap: 2px;
  }
  .info span {
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .when {
    font-size: 12px;
    color: var(--fuu-ink-4);
    white-space: nowrap;
  }
</style>
