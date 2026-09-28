<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Suporte — troca de aparelho de loja e entregador.
  //
  // O login de parceiro fica preso ao primeiro aparelho (partner_login). Quando
  // o tablet quebra ou o entregador troca de celular, o app diz "Peça ao
  // suporte para liberar a troca" -- é aqui. Liberar encerra as sessões do
  // aparelho antigo e deixa o próximo login virar o aparelho confiável; o
  // motivo fica no registro de auditoria.
  let query = $state('');
  let accounts = $state(null);
  let searching = $state(false);
  let releasing = $state(null);
  let reason = $state('');
  let busy = $state(false);

  function doc(account) {
    const d = account.login_code ?? '';
    if (account.kind === 'restaurant' && d.length === 14) {
      return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}`;
    }
    // CPF do entregador aparece mascarado: o suporte confere, não copia.
    return d.length === 11 ? `***.${d.slice(3, 6)}.${d.slice(6, 9)}-**` : d;
  }

  function when(raw) {
    const d = parsePgTimestamp(raw);
    if (!d) return 'nunca entrou';
    const two = (n) => String(n).padStart(2, '0');
    return `último login ${two(d.getDate())}/${two(d.getMonth() + 1)} ${two(d.getHours())}:${two(d.getMinutes())}`;
  }

  async function search(event) {
    event?.preventDefault();
    if (query.trim().length < 3) {
      toastr.warning('Digite pelo menos 3 caracteres: CNPJ, CPF ou nome.');
      return;
    }
    searching = true;
    try {
      const res = await api.get('/admin/partner_devices.php', { token: adminToken(), query: { q: query.trim() } });
      accounts = res.accounts;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra buscar.');
    } finally {
      searching = false;
    }
  }

  async function release(account) {
    if (reason.trim().length < 5) {
      toastr.warning('Escreva o motivo — fica no registro de auditoria.');
      return;
    }
    busy = true;
    try {
      const res = await api.post('/admin/partner_devices.php', {
        token: adminToken(),
        body: { partner_account_id: account.id, reason: reason.trim() },
      });
      toastr.success(
        `Aparelho liberado. ${res.sessions_revoked} sessão(ões) encerrada(s); o próximo login vira o aparelho novo.`
      );
      releasing = null;
      reason = '';
      await search();
    } catch (e) {
      toastr.error(
        e instanceof ApiError && e.code === 'no_device_bound'
          ? 'Essa conta já estava livre: o próximo login entra.'
          : (e.message ?? 'Não deu pra liberar.')
      );
    } finally {
      busy = false;
    }
  }
</script>

<p class="section">TROCA DE APARELHO · LOJAS E ENTREGADORES</p>

<form class="search" onsubmit={search}>
  <input
    type="search"
    placeholder="CNPJ, CPF ou nome"
    bind:value={query}
    aria-label="Buscar conta de parceiro"
  />
  <button type="submit" class="btn-fuu-primary" disabled={searching}>
    {searching ? 'Buscando…' : 'Buscar'}
  </button>
</form>

{#if accounts !== null}
  {#if accounts.length === 0}
    <p class="empty">Nenhuma loja ou entregador com esse CNPJ, CPF ou nome.</p>
  {:else}
    <div class="list">
      {#each accounts as account (account.id)}
        <article class="card fuu-card">
          <header>
            <div>
              <p class="name">
                <i class={`bi ${account.kind === 'restaurant' ? 'bi-shop' : 'bi-bicycle'}`}></i>
                {account.name}
              </p>
              <p class="meta fuu-mono">{doc(account)} · {when(account.last_login_at)}</p>
            </div>
            <span class={`badge ${account.device_bound ? 'bound' : 'free'}`}>
              {account.device_bound ? 'PRESO A UM APARELHO' : 'LIVRE'}
            </span>
          </header>

          {#if account.device_bound}
            {#if releasing === account.id}
              <label class="reason">
                <span>Motivo (fica na auditoria)</span>
                <textarea maxlength="300" rows="2" placeholder="Ex.: tablet quebrou; dono confirmou por telefone" bind:value={reason}></textarea>
              </label>
              <div class="actions">
                <button type="button" class="btn-fuu-primary" disabled={busy} onclick={() => release(account)}>
                  Liberar e encerrar sessões
                </button>
                <button type="button" class="link" onclick={() => (releasing = null)}>Voltar</button>
              </div>
            {:else}
              <div class="actions">
                <button type="button" class="btn-fuu-danger-outline" onclick={() => { releasing = account.id; reason = ''; }}>
                  Liberar troca de aparelho
                </button>
              </div>
            {/if}
          {/if}
        </article>
      {/each}
    </div>
  {/if}
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
  .search {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
  }
  .search input {
    flex: 1;
    min-width: 0;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    font-family: var(--fuu-font-body);
    font-size: 14px;
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
    font-size: 15px;
    font-weight: 800;
    margin: 0;
    color: var(--fuu-ink-1);
  }
  .meta {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 3px 0 0;
  }
  .badge {
    margin-left: auto;
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    border-radius: 999px;
    padding: 3px 9px;
  }
  .badge.bound {
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
  }
  .badge.free {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .reason {
    display: block;
    margin: 14px 0 12px;
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
    margin-top: 14px;
  }
  .actions button:not(.link) {
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
</style>
