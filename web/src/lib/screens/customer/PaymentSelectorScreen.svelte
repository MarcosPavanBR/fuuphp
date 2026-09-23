<script>
  import { toastr } from '../../utils/toastr.js';

  // Tela 4.1 — Seleção do método. "Cinco métodos em grid do Bootstrap: dois
  // online, um com validação humana e dois na entrega." O sexto tile
  // (Cripto via BitPay) é BETA no mock e não existe no enum payment_method
  // deste backend -- mostrado desabilitado, com aviso, em vez de escondido
  // ou fingindo que funciona.
  //
  // `acceptedMethods` (restaurants/show.php): o que a loja aceita agora. Os
  // quatro tiles do mock aparecem sempre -- o que a loja não aceita fica
  // desabilitado com o motivo, em vez de sumir (o cliente entende por que
  // não tem dinheiro hoje). O Pix automático não está no grid do mock: é
  // forma que a LOJA liga (tela 10.5, "RECOMENDADO"), então só aparece
  // quando ela ligou.
  let { total, addressLabel, deliveryFee = null, acceptedMethods = null, onContinue, onBack } = $props();

  function accepted(id) {
    return acceptedMethods === null || acceptedMethods.includes(id);
  }

  let selected = $state(null);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  // Sem motor de logística real ainda (Fase 8/9), a janela de entrega é
  // uma estimativa fixa no cliente -- registrado como simplificação.
  function etaWindow() {
    const start = new Date(Date.now() + 25 * 60000);
    const end = new Date(Date.now() + 45 * 60000);
    const fmt = (d) => d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    return `${fmt(start)} e ${fmt(end)}`;
  }

  const METHODS = [
    { id: 'mp_card', title: 'Cartão no app', subtitle: 'Aprovação imediata', badge: 'RECOMENDADO', group: 'app' },
    { id: 'pix_auto', title: 'Pix automático', subtitle: 'Confirmação automática', badge: 'SEM COMPROVANTE', group: 'app', optIn: true },
    { id: 'pix_manual', title: 'Pix + comprovante', subtitle: 'Validação em até 15 min', badge: 'MANUAL', group: 'app' },
    { id: 'cash', title: 'Dinheiro', subtitle: 'Informe o troco', group: 'entrega' },
    { id: 'pos_machine', title: 'Maquininha', subtitle: 'Débito ou crédito', group: 'entrega' },
  ];
</script>

<div class="payment-selector">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar">
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Pagamento</h1>
    <span class="total fuu-mono">{money(total)}</span>
  </div>

  <div class="delivery-info">
    <p class="street"><i class="bi bi-geo-alt"></i> Entrega em {addressLabel}</p>
    {#if deliveryFee !== null}
      <p class="eta">{deliveryFee > 0 ? `Frete de ${money(deliveryFee)} incluído no total` : 'Frete grátis'}</p>
    {/if}
    <p class="eta">Chega entre {etaWindow()}</p>
  </div>

  <p class="section-label">PAGUE AGORA NO APP</p>
  <div class="grid">
    {#each METHODS.filter((m) => m.group === 'app' && (!m.optIn || (acceptedMethods ?? []).includes(m.id))) as m (m.id)}
      <button type="button" class="tile" class:selected={selected === m.id} class:disabled={!accepted(m.id)} disabled={!accepted(m.id)} onclick={() => (selected = m.id)}>
        {#if m.badge}<span class="badge">{m.badge}</span>{/if}
        <p class="title">{m.title}</p>
        <p class="subtitle">{accepted(m.id) ? m.subtitle : 'A loja não aceita agora'}</p>
      </button>
    {/each}
  </div>

  <p class="section-label">PAGUE NA ENTREGA</p>
  <div class="grid">
    {#each METHODS.filter((m) => m.group === 'entrega') as m (m.id)}
      <button type="button" class="tile" class:selected={selected === m.id} class:disabled={!accepted(m.id)} disabled={!accepted(m.id)} onclick={() => (selected = m.id)}>
        <p class="title">{m.title}</p>
        <p class="subtitle">{accepted(m.id) ? m.subtitle : 'A loja não aceita agora'}</p>
      </button>
    {/each}
    <button
      type="button"
      class="tile disabled"
      onclick={() => toastr.info('Cripto via BitPay é BETA no mock e ainda não existe neste backend.')}
    >
      <span class="badge beta">BETA</span>
      <p class="title">Cripto via BitPay</p>
      <p class="subtitle">Cotação travada por 15 min</p>
    </button>
  </div>

  <div class="footer">
    <button type="button" class="btn-fuu-primary w-100" disabled={!selected} onclick={() => onContinue(selected)}>
      Continuar
    </button>
  </div>
</div>

<style>
  .payment-selector {
    padding: 12px 20px 100px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-2);
    padding: 4px;
  }
  h1 {
    font-size: 20px;
    margin: 0;
    flex: 1;
  }
  .total {
    font-size: 16px;
    color: var(--fuu-ink-1);
  }
  .delivery-info {
    background: var(--fuu-line-6);
    border-radius: var(--fuu-radius-card);
    padding: 10px 14px;
    margin-bottom: 18px;
  }
  .street {
    margin: 0 0 2px;
    font-size: 13px;
    color: var(--fuu-ink-2);
  }
  .eta {
    margin: 0;
    font-size: 12px;
    color: var(--fuu-ink-5);
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 4px 0 8px;
  }
  .grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 16px;
  }
  .tile {
    position: relative;
    text-align: left;
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 14px 12px;
  }
  .tile.selected {
    border-color: var(--fuu-red);
    background: var(--fuu-red-tint);
  }
  .tile.disabled {
    opacity: 0.6;
  }
  .badge {
    display: inline-block;
    font-family: var(--fuu-font-mono);
    font-size: 9px;
    letter-spacing: 0.06em;
    color: var(--fuu-leaf-dark);
    background: var(--fuu-leaf-tint);
    border-radius: 999px;
    padding: 2px 8px;
    margin-bottom: 6px;
  }
  .badge.beta {
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
  }
  .title {
    margin: 0 0 2px;
    font-weight: 600;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .subtitle {
    margin: 0;
    font-size: 11.5px;
    color: var(--fuu-ink-5);
  }
  .footer {
    position: fixed;
    left: 50%;
    bottom: 0;
    transform: translateX(-50%);
    width: 100%;
    max-width: 430px;
    padding: 14px 20px 22px;
    background: linear-gradient(to top, var(--fuu-paper) 60%, transparent);
  }
</style>
