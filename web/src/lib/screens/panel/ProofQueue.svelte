<script>
  import { parsePgTimestamp } from '../../datetime.js';

  // Coluna "FILA DE VALIDAÇÃO · 3" do mock (tela 7.3). O primeiro da fila
  // vem destacado em vermelho porque ele é o que está mais perto de
  // expirar -- a ordenação vem do servidor (verification_deadline ASC),
  // não daqui.
  let { proofs, selectedId, onSelect } = $props();

  let now = $state(Date.now());
  $effect(() => {
    const t = setInterval(() => (now = Date.now()), 1000);
    return () => clearInterval(t);
  });

  function countdown(deadline) {
    if (!deadline) return null;
    const secs = Math.floor((parsePgTimestamp(deadline).getTime() - now) / 1000);
    if (secs <= 0) return 'expirado';
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `expira em ${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
  }

  function itemCount(raw) {
    const items = typeof raw === 'string' ? JSON.parse(raw ?? '[]') : (raw ?? []);
    const n = items.reduce((acc, i) => acc + Number(i.quantity), 0);
    return `${n} ${n === 1 ? 'item' : 'itens'}`;
  }

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }
</script>

<p class="col-label">FILA DE VALIDAÇÃO · {proofs.length}</p>

{#if proofs.length === 0}
  <p class="empty">Nenhum comprovante esperando. 🎉</p>
{:else}
  <div class="queue">
    {#each proofs as proof, index (proof.id)}
      <button
        type="button"
        class="proof-card"
        class:urgent={index === 0}
        class:active={proof.id === selectedId}
        onclick={() => onSelect(proof)}
      >
        <span class="code fuu-mono">PIX · #{proof.public_code}</span>
        <span class="amount">{money(proof.total)} · {itemCount(proof.items)}</span>
        {#if countdown(proof.verification_deadline)}
          <span class="deadline">{countdown(proof.verification_deadline)}</span>
        {/if}
      </button>
    {/each}
  </div>
{/if}

<style>
  .col-label {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 10px;
  }
  .empty {
    font-size: 12px;
    color: var(--fuu-ink-5);
    margin: 0;
  }
  .queue {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .proof-card {
    display: flex;
    flex-direction: column;
    gap: 3px;
    text-align: left;
    width: 100%;
    background: var(--fuu-line-6);
    border: 1px solid var(--fuu-line-3);
    border-radius: 11px;
    padding: 11px;
    font-family: var(--fuu-font-body);
  }
  .proof-card.urgent {
    background: var(--fuu-wait-tint);
    border: 2px solid var(--fuu-red);
  }
  .proof-card.active {
    outline: 2px solid var(--fuu-ink-2);
    outline-offset: 1px;
  }
  .code {
    font-size: 11px;
    font-weight: 700;
    color: var(--fuu-ink-4);
  }
  .proof-card.urgent .code,
  .proof-card.urgent .deadline {
    color: var(--fuu-wait-text);
  }
  .amount {
    font-size: 12px;
    font-weight: 700;
    color: var(--fuu-ink-1);
  }
  .deadline {
    font-size: 10px;
    color: var(--fuu-ink-5);
  }
</style>
