<script>
  import { api, ApiError } from '../api.js';
  import { toastr } from '../toastr.js';

  // Tela 5.5 — Avaliação. "Só habilitada para pedido com status =
  // 'delivered' -- uma nota por pedido, garantida por índice único."
  let { orderId, orderCode, restaurantName, onDone, onSkip } = $props();

  const RATING_LABELS = { 1: 'Ruim', 2: 'Poderia ser melhor', 3: 'OK', 4: 'Bom', 5: 'Muito bom' };
  const TAGS = ['Comida quente', 'Chegou rápido', 'Bem embalado', 'Entregador educado'];
  const TIP_AMOUNTS = [2, 5, 10];

  let rating = $state(0);
  let selectedTags = $state([]);
  let tipAmount = $state(null);
  let customTip = $state('');
  let comment = $state('');
  let busy = $state(false);

  function toggleTag(tag) {
    selectedTags = selectedTags.includes(tag) ? selectedTags.filter((t) => t !== tag) : [...selectedTags, tag];
  }

  function pickTip(value) {
    tipAmount = value;
    customTip = '';
  }

  let effectiveTip = $derived(customTip !== '' ? Number(customTip) || 0 : tipAmount ?? 0);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  async function submit() {
    if (rating < 1) {
      toastr.warning('Escolha uma nota de 1 a 5.');
      return;
    }
    busy = true;
    try {
      await api.post('/reviews/create.php', {
        auth: true,
        body: {
          order_id: orderId,
          rating,
          tags: selectedTags,
          comment: comment.trim() || undefined,
          courier_tip: effectiveTip,
        },
      });
      toastr.success('Avaliação enviada, obrigado ✓');
      onDone();
    } catch (e) {
      const message = e instanceof ApiError ? e.message : 'Não deu pra enviar a avaliação.';
      toastr.error(message);
    } finally {
      busy = false;
    }
  }
</script>

<div class="review-screen">
  <div class="header">
    <div class="mark">FUU</div>
    <h1 class="fuu-display">Como foi o pedido?</h1>
    <p class="subtitle">{restaurantName} · #{orderCode}</p>
  </div>

  <div class="stars">
    {#each [1, 2, 3, 4, 5] as n (n)}
      <button type="button" class="star" class:filled={n <= rating} onclick={() => (rating = n)} aria-label={`Nota ${n}`}>
        <i class={n <= rating ? 'bi bi-star-fill' : 'bi bi-star'}></i>
      </button>
    {/each}
  </div>
  {#if rating > 0}
    <p class="rating-label">{RATING_LABELS[rating]}</p>
  {/if}

  <p class="section-label">O QUE SE DESTACOU?</p>
  <div class="tags">
    {#each TAGS as tag (tag)}
      <button type="button" class="tag" class:selected={selectedTags.includes(tag)} onclick={() => toggleTag(tag)}>
        {tag}
      </button>
    {/each}
  </div>

  <p class="section-label">GORJETA PARA O ENTREGADOR</p>
  <div class="tips">
    {#each TIP_AMOUNTS as amount (amount)}
      <button type="button" class="tip" class:selected={tipAmount === amount && customTip === ''} onclick={() => pickTip(amount)}>
        {money(amount)}
      </button>
    {/each}
    <input
      type="number"
      inputmode="decimal"
      class="tip-custom"
      placeholder="Outro"
      bind:value={customTip}
      onfocus={() => (tipAmount = null)}
    />
  </div>
  <p class="tip-note">
    Vai 100% para o entregador, registrada no repasse — cobrança separada não está implementada nesta passada.
  </p>

  <p class="section-label">COMENTÁRIO (OPCIONAL)</p>
  <textarea placeholder="Conte como foi…" rows="3" bind:value={comment}></textarea>

  <div class="footer">
    <button type="button" class="btn-fuu-primary w-100" disabled={busy || rating < 1} onclick={submit}>
      {busy ? 'Enviando…' : 'Enviar avaliação'}
    </button>
    <button type="button" class="link-btn" onclick={onSkip}>Agora não</button>
  </div>
</div>

<style>
  .review-screen {
    padding: 20px 20px 40px;
  }
  .header {
    text-align: center;
    margin-bottom: 20px;
  }
  .mark {
    width: 44px;
    height: 44px;
    border-radius: 30%;
    background: var(--fuu-red);
    color: var(--fuu-white);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--fuu-font-display);
    font-weight: 800;
    font-size: 13px;
    margin: 0 auto 12px;
  }
  h1 {
    font-size: 20px;
    margin: 0 0 4px;
  }
  .subtitle {
    color: var(--fuu-ink-5);
    font-size: 12.5px;
    margin: 0;
  }
  .stars {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-bottom: 4px;
  }
  .star {
    background: none;
    border: none;
    font-size: 30px;
    color: var(--fuu-line-1);
    padding: 4px;
  }
  .star.filled {
    color: var(--fuu-wait-text);
  }
  .rating-label {
    text-align: center;
    font-weight: 600;
    font-size: 13.5px;
    color: var(--fuu-ink-2);
    margin: 0 0 20px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 8px;
  }
  .tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 20px;
  }
  .tag {
    border: 1px solid var(--fuu-line-3);
    border-radius: 999px;
    padding: 8px 14px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    color: var(--fuu-ink-2);
    background: var(--fuu-white);
  }
  .tag.selected {
    background: var(--fuu-red);
    border-color: var(--fuu-red);
    color: var(--fuu-white);
  }
  .tips {
    display: flex;
    gap: 8px;
    margin-bottom: 6px;
  }
  .tip {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 0;
    font-family: var(--fuu-font-mono);
    font-size: 13.5px;
    color: var(--fuu-ink-2);
    background: var(--fuu-white);
  }
  .tip.selected {
    border-color: var(--fuu-red);
    background: var(--fuu-red-tint);
    color: var(--fuu-red-hover);
  }
  .tip-custom {
    flex: 1;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 0 10px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    text-align: center;
  }
  .tip-note {
    font-size: 11px;
    color: var(--fuu-ink-5);
    margin: 0 0 20px;
  }
  textarea {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 12px;
    font-family: var(--fuu-font-body);
    font-size: 13.5px;
    resize: vertical;
    margin-bottom: 20px;
  }
  .footer {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
  }
  .link-btn {
    background: none;
    border: none;
    color: var(--fuu-ink-4);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 600;
    padding: 6px 0;
  }
</style>
