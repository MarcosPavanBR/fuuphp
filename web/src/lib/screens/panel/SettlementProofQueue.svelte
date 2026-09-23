<script>
  import { api, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 9.5, lado da loja — os comprovantes de baixa por Pix que os
  // entregadores mandaram (restaurants/settlement_proofs.php). "Reaproveita a
  // validação humana já existente": a mesma conferência do Pix do cliente
  // (7.3) -- olhar o comprovante, conferir o valor no extrato, aprovar ou
  // recusar com motivo. Aprovar baixa a espécie do entregador na hora; marcar
  // como falso bloqueia as corridas em dinheiro dele e abre ocorrência.
  //
  // A imagem vem por fetch com o token e vira blob URL (sem token na URL).
  let { onDecided = () => {} } = $props();

  const REASONS = ['Valor diferente do combinado', 'Não aparece no extrato', 'Imagem ilegível', 'Comprovante repetido'];

  let proofs = $state([]);
  let images = $state({});
  let rejecting = $state(null); // id do comprovante sendo recusado
  let reason = $state(REASONS[0]);
  let fraud = $state(false);
  let busy = $state(false);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }
  function time(ts) {
    return parsePgTimestamp(ts)?.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) ?? '';
  }

  async function load() {
    try {
      const data = await api.get('/restaurants/settlement_proofs.php', { token: staffToken() });
      proofs = data.proofs;
      for (const p of proofs) {
        if (images[p.id]) continue;
        const res = await fetch(`${BASE}/restaurants/settlement_proofs.php?image=${p.id}`, {
          headers: { Authorization: `Bearer ${staffToken()}` },
        });
        if (res.ok) images = { ...images, [p.id]: URL.createObjectURL(await res.blob()) };
      }
    } catch {
      // sem rede: a próxima atualização do painel tenta de novo
    }
  }
  load();
  $effect(() => {
    const t = setInterval(load, 20000);
    return () => clearInterval(t);
  });

  async function decide(proof, decision) {
    busy = true;
    try {
      await api.post('/restaurants/settlement_proofs.php', {
        token: staffToken(),
        body: { proof_id: proof.id, decision, ...(decision === 'reject' ? { reason, fraud } : {}) },
      });
      toastr.success(
        decision === 'approve'
          ? `Baixa de ${money(proof.amount)} confirmada — o saldo de ${proof.courier_name} zerou.`
          : fraud
            ? 'Recusado como falso: ocorrência aberta e o entregador sem corridas em dinheiro.'
            : 'Comprovante recusado. O entregador vê o motivo e pode mandar outro.'
      );
      rejecting = null;
      fraud = false;
      await load();
      onDecided();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra registrar a conferência.');
    } finally {
      busy = false;
    }
  }
</script>

{#if proofs.length > 0}
  <div class="queue fuu-card">
    <p class="section-label">BAIXAS POR PIX PARA CONFERIR</p>
    {#each proofs as p (p.id)}
      <div class="proof">
        {#if images[p.id]}
          <img src={images[p.id]} alt="Comprovante da baixa" />
        {:else}
          <div class="img-slot"><i class="bi bi-image"></i></div>
        {/if}
        <div class="info">
          <p class="who">{p.courier_name}</p>
          <p class="amount fuu-mono">{money(p.amount)}</p>
          <p class="meta">enviado às {time(p.created_at)} · baixa BX{p.intent_id}</p>
          {#if p.seen_before}
            <p class="alert"><i class="bi bi-exclamation-triangle"></i> Esse comprovante (ou um muito parecido) já apareceu antes.</p>
          {/if}
          {#if rejecting === p.id}
            <select bind:value={reason}>
              {#each REASONS as r (r)}<option>{r}</option>{/each}
            </select>
            <label class="fraud"><input type="checkbox" bind:checked={fraud} /> É falso — bloquear e abrir ocorrência</label>
            <div class="actions">
              <button type="button" class="no" disabled={busy} onclick={() => decide(p, 'reject')}>Confirmar recusa</button>
              <button type="button" class="ghost" onclick={() => (rejecting = null)}>Voltar</button>
            </div>
          {:else}
            <div class="actions">
              <button type="button" class="yes" disabled={busy} onclick={() => decide(p, 'approve')}>
                Valor confere — dar baixa
              </button>
              <button type="button" class="ghost" onclick={() => (rejecting = p.id)}>Recusar</button>
            </div>
          {/if}
        </div>
      </div>
    {/each}
  </div>
{/if}

<style>
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 12px;
  }
  .queue {
    padding: 16px;
    margin-bottom: 14px;
  }
  .proof {
    display: flex;
    gap: 14px;
    padding: 12px 0;
    border-top: 1px solid var(--fuu-line-4);
  }
  .proof:first-of-type {
    border-top: none;
  }
  img,
  .img-slot {
    width: 120px;
    height: 160px;
    object-fit: cover;
    border-radius: 8px;
    background: var(--fuu-line-5);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--fuu-ink-5);
    flex: none;
  }
  .info {
    flex: 1;
  }
  .who,
  .amount,
  .meta,
  .alert {
    margin: 0 0 4px;
  }
  .who {
    font-weight: 700;
  }
  .amount {
    font-size: 22px;
    font-weight: 800;
  }
  .meta {
    font-size: 12px;
    color: var(--fuu-ink-4);
  }
  .alert {
    font-size: 12px;
    color: var(--fuu-alert);
    font-weight: 600;
  }
  select {
    width: 100%;
    margin: 6px 0;
    padding: 8px;
    border-radius: 8px;
    border: 1px solid var(--fuu-line-2);
  }
  .fraud {
    display: flex;
    gap: 6px;
    font-size: 12.5px;
    margin-bottom: 6px;
  }
  .actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
  }
  .actions button {
    border-radius: 10px;
    padding: 10px 14px;
    font-weight: 700;
    font-size: 13px;
    border: 1px solid var(--fuu-line-2);
    background: var(--fuu-white);
    min-height: var(--fuu-tap-operator);
  }
  .actions .yes {
    background: var(--fuu-leaf-dark);
    border-color: var(--fuu-leaf-dark);
    color: var(--fuu-white);
  }
  .actions .no {
    background: var(--fuu-alert);
    border-color: var(--fuu-alert);
    color: var(--fuu-white);
  }
</style>
