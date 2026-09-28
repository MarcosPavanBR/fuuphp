<script>
  import { api } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';

  // 14.4 — quantos pedidos agendados cabem numa faixa de 30 min. Zero é
  // "essa loja não aceita agendamento", e é o padrão. Parte da tela 11.4
  // (HoursScreen.svelte), separada na auditoria ARQ-02.
  //
  // capacity: o valor salvo (vem de restaurants/hours.php).
  // onChanged: salvou -- a tela recarrega.
  let { capacity = 0, onChanged } = $props();

  let value = $state(0);
  let saving = $state(false);

  // Acompanha o valor salvo quando a tela recarrega.
  $effect(() => {
    value = capacity;
  });

  async function save() {
    saving = true;
    try {
      await api.post('/restaurants/hours_save.php', {
        token: staffToken(),
        body: { slot_capacity: Number(value) },
      });
      toastr.success(
        Number(value) > 0
          ? `Agendamento ligado: ${value} pedido(s) por faixa de 30 min.`
          : 'Agendamento desligado — a loja só recebe pedido pra agora.'
      );
      await onChanged?.();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra salvar a capacidade.');
    } finally {
      saving = false;
    }
  }
</script>

<div class="holidays">
  <p class="holidays-title">Pedido agendado</p>
  <p class="capacity-note">
    Quantos pedidos agendados cabem em cada faixa de 30 min. Zero desliga o agendamento — a vaga é
    da capacidade da sua cozinha, não do relógio.
  </p>
  <div class="capacity-row">
    <input type="number" min="0" max="100" step="1" bind:value class="fuu-mono" />
    <button type="button" class="btn-fuu-primary" disabled={saving} onclick={save}>
      {saving ? 'Salvando…' : 'Salvar capacidade'}
    </button>
  </div>
</div>

<style>
  .holidays {
    background: var(--fuu-white);
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 18px;
    margin-top: 14px;
  }
  .holidays-title {
    font-size: 15px;
    font-weight: 800;
    color: var(--fuu-ink-1);
    margin: 0 0 12px;
  }
  .capacity-note {
    font-size: 12.5px;
    color: var(--fuu-ink-2);
    line-height: 1.6;
    margin: 0 0 12px;
    max-width: 46em;
  }
  .capacity-row {
    display: flex;
    gap: 10px;
    align-items: center;
  }
  .capacity-row input {
    width: 90px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 9px;
    padding: 12px;
    font-size: 15px;
    font-weight: 700;
    color: var(--fuu-ink-1);
    background: var(--fuu-white);
  }
  .capacity-row .btn-fuu-primary {
    width: auto;
    padding: 12px 20px;
  }
</style>
