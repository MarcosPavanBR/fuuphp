<script>
  import { toastr } from '../../utils/toastr.js';

  // Tela 2.3 — Fidelidade. "progress do Bootstrap; saldo calculado no
  // banco (soma dos lançamentos), nunca no cliente." (LoyaltyDashboard.svelte,
  // api/loyalty.php, numeric(12,2))
  //
  // Não há tabela de pontos/fidelidade nas 42 da especificação (Parte II) —
  // "saldo calculado no banco" pressupõe um ledger de pontos que não foi
  // desenhado. Diferente das outras telas da Fase 2, esta aqui NÃO chama a
  // API: os números são os mesmos do mock, fixos, com aviso visível. Criar
  // a tabela de verdade é decisão de produto (fica registrado no README),
  // não algo pra inventar silenciosamente numa tela.
  const MOCK = {
    points: 1240,
    goal: 1500,
    history: [
      { label: 'Pedido #A38F2C', delta: 128 },
      { label: 'Cupom R$ 10', delta: -800 },
      { label: 'Pedido #77B105', delta: 86 },
    ],
  };

  let progressPct = Math.round((MOCK.points / MOCK.goal) * 100);
  let missing = MOCK.goal - MOCK.points;

  function notReal() {
    toastr.warning('Fidelidade ainda é só desenho de tela — sem tabela no banco (ver README).');
  }
</script>

<div class="loyalty-screen">
  <p class="badge-note">dado de exemplo · sem tabela de pontos no banco ainda</p>

  <h1 class="fuu-display">Seus pontos</h1>
  <p class="points">
    <strong>{MOCK.points.toLocaleString('pt-BR')}</strong>
    <span>de {MOCK.goal.toLocaleString('pt-BR')}</span>
  </p>
  <div class="progress-track">
    <div class="progress-fill" style={`width:${progressPct}%`}></div>
  </div>
  <p class="hint">Faltam {missing} pontos para o cupom de R$ 20</p>

  <button type="button" class="btn-fuu-primary w-100" onclick={notReal}>Resgatar agora</button>

  <div class="rewards">
    <button type="button" class="reward fuu-card" onclick={notReal}>
      <div>
        <p class="title">R$ 10 de desconto</p>
        <p class="cost">800 pontos</p>
      </div>
      <span class="trade">Trocar</span>
    </button>
    <button type="button" class="reward fuu-card" onclick={notReal}>
      <div>
        <p class="title">Entrega grátis</p>
        <p class="cost">1.500 pontos</p>
      </div>
      <span class="trade">Trocar</span>
    </button>
  </div>

  <p class="section-label">HISTÓRICO</p>
  <div class="history">
    {#each MOCK.history as h (h.label)}
      <div class="history-row">
        <span>{h.label}</span>
        <span class={h.delta >= 0 ? 'positive' : 'negative'}>
          {h.delta >= 0 ? '+' : ''}{h.delta}
        </span>
      </div>
    {/each}
  </div>
</div>

<style>
  .loyalty-screen {
    padding: 12px 20px 24px;
  }
  .badge-note {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    letter-spacing: 0.06em;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    border-radius: 999px;
    padding: 3px 10px;
    display: inline-block;
    margin-bottom: 14px;
  }
  h1 {
    font-size: 20px;
    margin: 0 0 6px;
  }
  .points {
    margin: 0 0 10px;
    color: var(--fuu-ink-2);
  }
  .points strong {
    font-family: var(--fuu-font-mono);
    font-size: 22px;
    color: var(--fuu-ink-1);
  }
  .progress-track {
    height: 8px;
    border-radius: 999px;
    background: var(--fuu-line-4);
    overflow: hidden;
    margin-bottom: 8px;
  }
  .progress-fill {
    height: 100%;
    background: var(--fuu-red);
  }
  .hint {
    color: var(--fuu-ink-5);
    font-size: 12.5px;
    margin: 0 0 16px;
  }
  .rewards {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 18px 0;
  }
  .reward {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px;
    width: 100%;
    text-align: left;
  }
  .title {
    margin: 0;
    font-weight: 600;
    color: var(--fuu-ink-1);
    font-size: 14px;
  }
  .cost {
    margin: 2px 0 0;
    color: var(--fuu-ink-5);
    font-size: 12px;
  }
  .trade {
    color: var(--fuu-red);
    font-weight: 600;
    font-size: 13px;
  }
  .section-label {
    font-family: var(--fuu-font-mono);
    font-size: 10.5px;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-5);
    margin: 8px 0 10px;
  }
  .history {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .history-row {
    display: flex;
    justify-content: space-between;
    font-size: 13.5px;
    color: var(--fuu-ink-2);
  }
  .positive {
    color: var(--fuu-leaf);
    font-family: var(--fuu-font-mono);
  }
  .negative {
    color: var(--fuu-ink-4);
    font-family: var(--fuu-font-mono);
  }
</style>
