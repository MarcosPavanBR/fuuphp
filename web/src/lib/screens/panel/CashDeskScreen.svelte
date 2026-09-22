<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 9.3 — "Esta é a resposta a 'como eu sei que o motoboy entregou': a
  // loja conta, digita o código do app dele e confirma."
  //
  // A ordem dos campos na tela é a ordem do trabalho real: primeiro conta o
  // dinheiro, depois digita. Por isso o valor contado vem antes do código, e
  // o valor declarado aparece ao lado -- mas não preenchido no campo, senão
  // ninguém conta nada e só confirma.
  let { settlements, onDone } = $props();

  let code = $state('');
  let counted = $state('');
  let busy = $state(false);
  let now = $state(Date.now());

  $effect(() => {
    const t = setInterval(() => (now = Date.now()), 1000);
    return () => clearInterval(t);
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  function countdown(raw) {
    const end = parsePgTimestamp(raw);
    if (!end) return null;
    const secs = Math.max(0, Math.floor((end.getTime() - now) / 1000));
    return secs === 0 ? 'expirado' : `${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}`;
  }

  let open = $derived(settlements.filter((s) => s.state === 'open'));
  let done = $derived(settlements.filter((s) => s.state !== 'open'));

  async function confirm() {
    if (code.replace(/\D/g, '').length !== 6 || counted === '' || busy) return;
    busy = true;
    try {
      const data = await api.post('/restaurants/confirm_settlement.php', {
        token: staffToken(),
        body: { code: code.replace(/\D/g, ''), counted_amount: Number(counted) },
      });
      if (data.settled) {
        toastr.success(data.message);
      } else {
        // Divergência não é erro de digitação: é ocorrência, e a tela precisa
        // dizer isso com todas as letras porque nada foi lançado.
        toastr.warning(data.message);
      }
      code = '';
      counted = '';
      onDone();
    } catch (e) {
      const known = e instanceof ApiError ? e.code : null;
      toastr.error(
        known === 'intent_not_found'
          ? 'Código não confere com nenhuma baixa aberta desta loja.'
          : known === 'intent_expired'
            ? 'Esse código expirou. Peça um novo no app do entregador.'
            : (e.message ?? 'Não deu pra confirmar.')
      );
    } finally {
      busy = false;
    }
  }
</script>

<div class="desk">
  <div class="confirm-card fuu-card">
    <p class="section-label">CONFIRMAR RECEBIMENTO</p>

    <label class="field">
      <span>1. Quanto você contou</span>
      <input type="number" step="0.01" inputmode="decimal" placeholder="0,00" bind:value={counted} />
    </label>

    <label class="field">
      <span>2. Código no app do entregador</span>
      <input
        type="text"
        inputmode="numeric"
        maxlength="6"
        class="code fuu-mono"
        placeholder="000000"
        bind:value={code}
      />
    </label>

    <button
      type="button"
      class="btn-fuu-primary w-100"
      disabled={busy || code.replace(/\D/g, '').length !== 6 || counted === ''}
      onclick={confirm}
    >
      {busy ? 'Confirmando…' : 'Confirmar recebimento'}
    </button>

    <p class="warn">
      Valor diferente do declarado abre ocorrência e não lança nada — o livro é append-only, então um
      número errado não teria como ser desfeito.
    </p>
  </div>

  <div class="lists">
    <p class="section-label">ESPERANDO NO CAIXA · {open.length}</p>
    {#if open.length === 0}
      <p class="empty">Ninguém esperando pra baixar espécie.</p>
    {:else}
      {#each open as item (item.id)}
        <div class="row-card">
          <div>
            <p class="who">{item.courier_name}</p>
            <p class="meta">declarou {money(item.amount)} · {item.method === 'pix' ? 'Pix' : 'em mãos'}</p>
          </div>
          <span class="badge">{countdown(item.expires_at)}</span>
        </div>
      {/each}
    {/if}

    {#if done.length > 0}
      <p class="section-label later">HOJE</p>
      {#each done as item (item.id)}
        <div class="row-card muted">
          <div>
            <p class="who">{item.courier_name}</p>
            <p class="meta">
              {money(item.amount)} declarado
              {#if item.counted_amount}· {money(item.counted_amount)} contado{/if}
            </p>
          </div>
          <span class={`badge ${item.state}`}>
            {item.state === 'settled' ? 'BAIXADO' : item.state === 'disputed' ? 'OCORRÊNCIA' : 'EXPIROU'}
          </span>
        </div>
      {/each}
    {/if}
  </div>
</div>

<style>
  .desk {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 18px;
    padding: 18px;
    align-items: start;
  }
  @media (max-width: 860px) {
    .desk {
      grid-template-columns: 1fr;
    }
  }
  .confirm-card {
    padding: 18px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 12px;
  }
  .section-label.later {
    margin-top: 22px;
  }
  .field {
    display: block;
    margin-bottom: 14px;
  }
  .field span {
    display: block;
    font-size: 12.5px;
    font-weight: 700;
    color: var(--fuu-ink-3);
    margin-bottom: 5px;
  }
  input {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 13px 14px;
    font-family: var(--fuu-font-body);
    font-size: 16px;
    color: var(--fuu-ink-1);
  }
  input.code {
    text-align: center;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: 0.2em;
  }
  .confirm-card .btn-fuu-primary {
    min-height: var(--fuu-tap-operator);
  }
  .warn {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    line-height: 1.55;
    margin: 12px 0 0;
  }
  .empty {
    font-size: 13px;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  .row-card {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: 11px;
    padding: 13px 15px;
    margin-bottom: 9px;
  }
  .row-card.muted {
    opacity: 0.75;
  }
  .who {
    font-size: 14px;
    font-weight: 700;
    color: var(--fuu-ink-1);
    margin: 0;
  }
  .meta {
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin: 3px 0 0;
  }
  .badge {
    margin-left: auto;
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    font-weight: 700;
    background: var(--fuu-wait-tint);
    color: var(--fuu-wait-text);
    border-radius: 999px;
    padding: 4px 10px;
    white-space: nowrap;
  }
  .badge.settled {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .badge.disputed {
    background: var(--fuu-red-tint);
    color: var(--fuu-red);
  }
</style>
