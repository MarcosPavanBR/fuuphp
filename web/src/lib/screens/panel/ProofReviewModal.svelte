<script>
  import { onMount, onDestroy } from 'svelte';
  import { api, ApiError, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Tela 7.3 — "Modal split: comprovante à esquerda, pedido à direita,
  // dois botões grandes. A conferência automática (hash, phash, horário,
  // marca d'água) reduz o trabalho do atendente a uma decisão: o valor
  // bate?"
  //
  // Desvio assumido do mock: ele diz "SweetAlert em tela cheia", mas este
  // modal tem imagem com zoom/rotação e duas colunas de conteúdo -- coisas
  // que o swal() não monta bem (ele recebe um nó de conteúdo, não um
  // componente). É um modal Svelte próprio, mesma decisão já tomada no
  // ItemModal (3.2). O SweetAlert continua sendo usado onde ele é bom:
  // decisão curta de sim/não.
  //
  // A classe NÃO se chama .modal de propósito: o Bootstrap reserva esse
  // nome com display:none, e isso já causou um bug real neste projeto
  // (ver "Módulo de carrinho" no README).
  let { proof, onClose, onReviewed } = $props();

  let imageUrl = $state(null);
  let zoomed = $state(false);
  let rotation = $state(0);
  let frameEl = $state(null);
  let frameW = $state(0);
  let frameH = $state(0);
  let natW = $state(0);
  let natH = $state(0);
  let busy = $state(false);
  let rejecting = $state(false);
  let reason = $state('');
  let countedAmount = $state('');
  let now = $state(Date.now());
  let clock;

  // A imagem é buscada com Authorization (não como <img src> direto): o
  // arquivo mora fora da raiz servida e proof_image.php exige o header,
  // justamente pra não precisar aceitar token por query string.
  onMount(async () => {
    clock = setInterval(() => (now = Date.now()), 1000);
    try {
      const res = await fetch(`${BASE}/restaurants/proof_image.php?id=${proof.id}`, {
        headers: { Authorization: `Bearer ${staffToken()}` },
      });
      if (res.ok) {
        imageUrl = URL.createObjectURL(await res.blob());
      }
    } catch {
      // sem imagem: a tela avisa embaixo, o atendente decide se recusa
    }
  });
  onDestroy(() => {
    clearInterval(clock);
    if (imageUrl) URL.revokeObjectURL(imageUrl);
  });

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  // Girar e ampliar juntos exigem conta: `transform` não muda a caixa de
  // layout, então uma foto em pé girada 90° passa a desenhar fora da moldura
  // e a barra de rolagem continua achando que ela está em pé -- o atendente
  // rola e vê faixa branca. A saída é a moldura ter um "calço" do tamanho
  // VISUAL da imagem (já girada e ampliada) e a imagem ficar centrada nele:
  // aí a rolagem passeia pelo comprovante de verdade, nos dois sentidos.
  const ZOOM = 1.8;

  function measure(e) {
    if (e?.target) {
      natW = e.target.naturalWidth;
      natH = e.target.naturalHeight;
    }
    if (frameEl) {
      frameW = frameEl.clientWidth;
      frameH = frameEl.clientHeight;
    }
  }

  // Ampliar joga a rolagem pro canto se ninguém centralizar: quem amplia quer
  // ver melhor o que já estava no meio da tela, não o canto do papel.
  function recenter() {
    requestAnimationFrame(() => {
      if (!frameEl) return;
      frameEl.scrollLeft = (frameEl.scrollWidth - frameEl.clientWidth) / 2;
      frameEl.scrollTop = (frameEl.scrollHeight - frameEl.clientHeight) / 2;
    });
  }

  function rotate() {
    rotation = (rotation + 90) % 360;
    measure();
    recenter();
  }

  function toggleZoom() {
    zoomed = !zoomed;
    recenter();
  }

  let sideways = $derived(rotation % 180 !== 0);
  let scale = $derived.by(() => {
    if (!natW || !frameW) return 1;
    const fit = sideways
      ? Math.min(frameW / natH, frameH / natW)
      : Math.min(frameW / natW, frameH / natH);
    return fit * (zoomed ? ZOOM : 1);
  });
  let visualW = $derived((sideways ? natH : natW) * scale);
  let visualH = $derived((sideways ? natW : natH) * scale);
  let padStyle = $derived(
    `width:${Math.max(visualW, frameW)}px; height:${Math.max(visualH, frameH)}px`
  );
  let imgStyle = $derived(
    `width:${natW}px; height:${natH}px; transform: translate(-50%, -50%) rotate(${rotation}deg) scale(${scale})`
  );

  let remaining = $derived(
    proof.verification_deadline
      ? Math.max(0, Math.floor((parsePgTimestamp(proof.verification_deadline).getTime() - now) / 1000))
      : null
  );
  let remainingLabel = $derived(
    remaining === null
      ? null
      : `${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(remaining % 60).padStart(2, '0')}`
  );

  let uploadedMinutesAgo = $derived(
    Math.max(0, Math.round((now - parsePgTimestamp(proof.uploaded_at).getTime()) / 60000))
  );

  let items = $derived(
    typeof proof.items === 'string' ? JSON.parse(proof.items ?? '[]') : (proof.items ?? [])
  );

  let isUnseenImage = $derived(Number(proof.same_image_count) === 0);

  async function decide(decision) {
    if (decision === 'reject' && reason.trim() === '') {
      toastr.warning('Escreva o motivo — ele aparece pro cliente.');
      return;
    }
    busy = true;
    try {
      const body = { proof_id: proof.id, decision };
      if (decision === 'approve' && countedAmount !== '') {
        body.counted_amount = Number(countedAmount);
      }
      if (decision === 'reject') body.reason = reason.trim();

      await api.post('/restaurants/approve_pix.php', { token: staffToken(), body });
      toastr.success(decision === 'approve' ? 'Pagamento aprovado — pedido foi pra cozinha ✓' : 'Comprovante recusado');
      onReviewed();
    } catch (e) {
      const message =
        e instanceof ApiError && e.code === 'proof_already_reviewed'
          ? 'Esse comprovante já foi revisado (talvez em outra aba).'
          : (e.message ?? 'Não deu pra registrar a decisão.');
      toastr.error(message);
      if (e instanceof ApiError && e.code === 'proof_already_reviewed') onReviewed();
    } finally {
      busy = false;
    }
  }
</script>

<div class="proof-modal-backdrop" onclick={onClose} role="presentation"></div>
<div class="proof-modal-panel" role="dialog" aria-label="Validar pagamento Pix">
  <div class="proof-modal-head">
    <h2 class="fuu-display">Validar pagamento Pix · #{proof.public_code}</h2>
    {#if remainingLabel}
      <span class="deadline fuu-mono" class:urgent={remaining < 180}>expira em {remainingLabel}</span>
    {/if}
    <button type="button" class="close" onclick={onClose} aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
  </div>

  <div class="proof-modal-body">
    <div class="left">
      <div class="image-frame" bind:this={frameEl}>
        {#if imageUrl}
          <div class="image-pad" style={padStyle}>
            <img src={imageUrl} alt="Comprovante enviado pelo cliente" style={imgStyle} onload={measure} />
          </div>
        {:else}
          <p class="no-image">Não deu pra carregar a imagem do comprovante.</p>
        {/if}
      </div>
      <div class="image-actions">
        <button type="button" onclick={toggleZoom}>
          <i class="bi bi-zoom-in"></i> {zoomed ? 'Reduzir' : 'Ampliar'}
        </button>
        <button type="button" onclick={rotate}>
          <i class="bi bi-arrow-clockwise"></i> Girar
        </button>
        <span class="image-badge" class:ok={isUnseenImage} class:warn={!isUnseenImage}>
          {isUnseenImage ? 'Nunca usado antes' : `Imagem repetida (${proof.same_image_count}x)`}
        </span>
      </div>
    </div>

    <div class="right">
      <p class="section-label">CONFERÊNCIA AUTOMÁTICA</p>
      <ul class="checks">
        <li class:ok={isUnseenImage} class:warn={!isUnseenImage}>
          <i class={`bi ${isUnseenImage ? 'bi-check-circle' : 'bi-exclamation-triangle'}`}></i>
          {isUnseenImage ? 'Imagem inédita (sha256 e phash)' : 'Essa imagem já foi enviada antes'}
        </li>
        <li class="ok">
          <i class="bi bi-check-circle"></i>
          Enviada há {uploadedMinutesAgo} min · marca d'água aplicada
        </li>
        <li class="warn">
          <i class="bi bi-exclamation-triangle"></i>
          Valor precisa de conferência humana
        </li>
      </ul>

      <p class="section-label">DADOS DO PEDIDO</p>
      <dl class="order-data">
        <dt>Cliente</dt>
        <dd>{proof.customer_name} · {proof.customer_order_count}º pedido nesta loja</dd>
        <dt>Itens</dt>
        <dd>{items.map((i) => `${i.quantity}× ${i.name}`).join(', ') || '—'}</dd>
        <dt>Entrega</dt>
        <dd>
          {proof.street ?? '—'}{proof.number ? `, ${proof.number}` : ''}{proof.complement ? ` · ${proof.complement}` : ''}
        </dd>
      </dl>

      <div class="expected">
        <span>Total esperado</span>
        <strong class="fuu-mono">{money(proof.total)}</strong>
      </div>

      {#if rejecting}
        <label class="reason-field">
          <span>Motivo da recusa (o cliente lê isto)</span>
          <textarea maxlength="300" rows="2" placeholder="Ex.: valor divergente — recebemos R$ 68,40" bind:value={reason}></textarea>
        </label>
      {:else}
        <label class="counted-field">
          <span>Valor conferido no extrato (opcional)</span>
          <input type="number" step="0.01" inputmode="decimal" placeholder={money(proof.total)} bind:value={countedAmount} />
        </label>
      {/if}

      <div class="decision">
        {#if rejecting}
          <button type="button" class="btn-fuu-danger-outline" disabled={busy} onclick={() => decide('reject')}>
            {busy ? 'Recusando…' : 'Confirmar recusa'}
          </button>
          <button type="button" class="link-btn" onclick={() => (rejecting = false)}>Voltar</button>
        {:else}
          <button type="button" class="btn-fuu-danger-outline" disabled={busy} onclick={() => (rejecting = true)}>
            Rejeitar
          </button>
          <button type="button" class="btn-fuu-primary approve" disabled={busy} onclick={() => decide('approve')}>
            {busy ? 'Aprovando…' : 'Aprovar e mandar p/ cozinha'}
          </button>
        {/if}
      </div>
    </div>
  </div>
</div>

<style>
  .proof-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(20, 18, 16, 0.45);
    z-index: 60;
  }
  .proof-modal-panel {
    position: fixed;
    inset: 4vh 4vw auto;
    max-height: 92vh;
    max-width: 1000px;
    margin: 0 auto;
    background: var(--fuu-white);
    border-radius: 16px;
    z-index: 61;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .proof-modal-head {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
    border-bottom: 1px solid var(--fuu-line-3);
  }
  .proof-modal-head h2 {
    margin: 0;
    font-size: 18px;
    flex: 1;
    color: var(--fuu-ink-1);
  }
  .deadline {
    font-size: 14px;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    border-radius: 999px;
    padding: 4px 12px;
  }
  .deadline.urgent {
    color: var(--fuu-alert);
    border: 1px solid var(--fuu-alert);
    background: transparent;
  }
  .close {
    background: var(--fuu-line-5);
    border: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    color: var(--fuu-ink-3);
  }
  .proof-modal-body {
    flex: 1;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    padding: 20px;
    overflow-y: auto;
  }
  @media (max-width: 860px) {
    .proof-modal-body {
      grid-template-columns: 1fr;
    }
  }
  .image-frame {
    background: var(--fuu-line-5);
    border-radius: var(--fuu-radius-card);
    height: 52vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: auto;
  }
  /* min-width:0 porque o "calço" da imagem ampliada é maior que a coluna: sem
     isso o grid cresce junto e empurra os dados do pedido pra fora do modal. */
  .left,
  .right {
    min-width: 0;
  }
  .image-pad {
    position: relative;
    flex: none;
  }
  .image-frame img {
    position: absolute;
    left: 50%;
    top: 50%;
    transform-origin: center;
    transition: transform 0.2s;
  }
  .no-image {
    color: var(--fuu-alert);
    font-size: 13px;
    padding: 20px;
    text-align: center;
  }
  .image-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
    flex-wrap: wrap;
  }
  .image-actions button {
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-pill);
    background: var(--fuu-white);
    padding: 8px 14px;
    font-family: var(--fuu-font-body);
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .image-badge {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.04em;
    border-radius: 999px;
    padding: 4px 10px;
  }
  .image-badge.ok {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .image-badge.warn {
    color: var(--fuu-alert);
    border: 1px solid var(--fuu-alert);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 0 0 8px;
  }
  .checks {
    list-style: none;
    padding: 0;
    margin: 0 0 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .checks li {
    font-size: 13.5px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .checks li.ok {
    color: var(--fuu-leaf-dark);
  }
  .checks li.warn {
    color: var(--fuu-wait-text);
  }
  .order-data {
    margin: 0 0 16px;
    display: grid;
    grid-template-columns: 90px 1fr;
    gap: 6px 12px;
  }
  .order-data dt {
    font-size: 12px;
    color: var(--fuu-ink-5);
  }
  .order-data dd {
    margin: 0;
    font-size: 13.5px;
    color: var(--fuu-ink-1);
  }
  .expected {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--fuu-line-6);
    border-radius: var(--fuu-radius-card);
    padding: 12px 16px;
    margin-bottom: 16px;
  }
  .expected span {
    font-size: 13px;
    color: var(--fuu-ink-3);
  }
  .expected strong {
    font-size: 22px;
    color: var(--fuu-ink-1);
  }
  .counted-field,
  .reason-field {
    display: block;
    margin-bottom: 16px;
  }
  .counted-field span,
  .reason-field span {
    display: block;
    font-size: 12px;
    color: var(--fuu-ink-4);
    margin-bottom: 4px;
  }
  input,
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
  .decision {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  /* Verde = manda pra cozinha, vermelho = recusa. É o par de cores do mock e
     o mesmo do "Aceitar" no KDS: o operador olha de pé, a um metro, e decide
     pela cor antes de ler. */
  .decision .approve {
    flex: 1.4;
    min-height: var(--fuu-tap-operator);
    background: var(--fuu-leaf-dark);
  }
  .decision .btn-fuu-danger-outline {
    flex: 1;
    min-height: var(--fuu-tap-operator);
  }
  .link-btn {
    background: none;
    border: none;
    color: var(--fuu-ink-4);
    font-family: var(--fuu-font-body);
    font-size: 13px;
    font-weight: 600;
  }
</style>
