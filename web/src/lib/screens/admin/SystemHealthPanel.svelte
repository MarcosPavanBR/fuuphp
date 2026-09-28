<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Saúde do sistema (admin/system_health.php): os erros que a API e os
  // scripts registraram, agrupados, e o resultado do backup. Antes, erro em
  // produção só aparecia pra quem lesse o log do servidor.
  const SOURCE = { api: 'API', worker: 'Rotina', backup: 'Backup' };
  const STATUS = { backup: 'Backup' };

  let data = $state(null);
  let busy = $state(false);

  async function load() {
    try {
      data = await api.get('/admin/system_health.php', { token: adminToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar a saúde do sistema.');
    }
  }

  $effect(() => {
    load();
  });

  function when(raw) {
    const d = parsePgTimestamp(raw);
    return d ? d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';
  }

  // Backup com mais de 2 dias sem notícia também é problema: o cron pode ter
  // parado sem ninguém ver.
  function stale(s) {
    const d = parsePgTimestamp(s.updated_at);
    return d ? Date.now() - d.getTime() > 2 * 86400000 : false;
  }

  async function resolve(e) {
    if (busy) return;
    busy = true;
    try {
      await api.post('/admin/system_health.php', { token: adminToken(), body: { id: e.id, action: 'resolve' } });
      toastr.success('Marcado como resolvido. Se voltar, reaparece aqui.');
      await load();
    } catch (err) {
      toastr.error(err.message ?? 'Não deu pra marcar.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="fuu-card block">
  <p class="section">SAÚDE DO SISTEMA</p>
  {#if !data}
    <p class="empty">Carregando…</p>
  {:else}
    {#each data.status as s (s.key)}
      <div class="status" class:bad={!s.ok || stale(s)}>
        <i class={`bi ${s.ok && !stale(s) ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'}`}></i>
        <div>
          <strong>{STATUS[s.key] ?? s.key}</strong>
          <span>{s.detail ?? ''} · {when(s.updated_at)}{stale(s) ? ' · sem notícia há mais de 2 dias' : ''}</span>
        </div>
      </div>
    {:else}
      <p class="empty">O backup ainda não rodou nenhuma vez neste servidor.</p>
    {/each}

    <p class="sub">
      {data.errors.length === 0 ? 'Nenhum erro em aberto.' : `${data.errors.length} erro(s) em aberto`}
      · {data.last_24h} com ocorrência nas últimas 24 h
    </p>
    {#each data.errors as e (e.id)}
      <div class="err">
        <div class="info">
          <strong>{SOURCE[e.source] ?? e.source}{e.route ? ` · ${e.route}` : ''}</strong>
          <span class="fuu-mono">{e.message}</span>
          <span>{e.count}× · primeira {when(e.first_seen)} · última {when(e.last_seen)}{e.trace_id ? ` · trace ${e.trace_id}` : ''}</span>
        </div>
        <button type="button" class="ghost" disabled={busy} onclick={() => resolve(e)}>Resolvido</button>
      </div>
    {/each}
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
  .empty,
  .sub {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 8px 0 0;
  }
  .status {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 8px 0;
  }
  .status i {
    color: var(--fuu-leaf);
    font-size: 18px;
  }
  .status.bad i {
    color: var(--fuu-alert);
  }
  .status div,
  .info {
    display: grid;
    gap: 2px;
    min-width: 0;
  }
  .status span,
  .info span {
    font-size: 12px;
    color: var(--fuu-ink-4);
    overflow-wrap: anywhere;
  }
  .err {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid var(--fuu-line-5);
  }
  .info {
    flex: 1;
  }
  .ghost {
    border: 1.5px solid var(--fuu-line-2);
    background: var(--fuu-white);
    border-radius: 10px;
    padding: 8px 12px;
    font-family: inherit;
    font-weight: 700;
    font-size: 13px;
    color: var(--fuu-ink-2);
    white-space: nowrap;
  }
</style>
