<script>
  import { api, ApiError } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { staffToken } from '../../state/staffSession.svelte.js';
  import { parsePgTimestamp } from '../../utils/datetime.js';

  // Chave Pix da loja (restaurants/pix_key.php), na aba Pagamentos (10.4).
  //
  // É pra onde vai o "Pix direto pra loja" do cliente (4.3) e a baixa de
  // espécie por Pix do entregador (9.5). Sem chave, esses dois não têm pra
  // onde ir. CNPJ só vale se for o desta loja; CPF não vale. Trocar a chave
  // fica registrado (quem, quando, a anterior) -- a tela diz isso antes.
  let current = $state(undefined);
  let rotatedAt = $state(null);
  let draft = $state('');
  let editing = $state(false);
  let saving = $state(false);
  let error = $state('');

  function when(raw) {
    const d = parsePgTimestamp(raw);
    const two = (n) => String(n).padStart(2, '0');
    return d ? `${two(d.getDate())}/${two(d.getMonth() + 1)}/${d.getFullYear()}` : '';
  }

  async function load() {
    try {
      const res = await api.get('/restaurants/pix_key.php', { token: staffToken() });
      current = res.pix_key;
      rotatedAt = res.rotated_at;
      editing = res.pix_key === null;
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar a chave Pix.');
    }
  }

  $effect(() => {
    load();
  });

  async function save() {
    error = '';
    saving = true;
    try {
      const res = await api.post('/restaurants/pix_key.php', { token: staffToken(), body: { pix_key: draft.trim() } });
      toastr.success(
        res.ownership_checked
          ? 'Chave Pix salva (CNPJ da loja conferido).'
          : 'Chave Pix salva. A equipe do FUU confere a titularidade.'
      );
      draft = '';
      await load();
    } catch (e) {
      error = e instanceof ApiError && e.fields?.pix_key ? e.fields.pix_key : (e.message ?? 'Não deu pra salvar.');
    } finally {
      saving = false;
    }
  }
</script>

<div class="pix fuu-card">
  <p class="k">CHAVE PIX DA LOJA</p>
  {#if current === undefined}
    <p class="muted">Carregando…</p>
  {:else}
    {#if current !== null}
      <p class="key fuu-mono">{current}</p>
      <p class="muted">Alterada em {when(rotatedAt)}. Cai aqui o Pix direto do cliente e a baixa por Pix do entregador.</p>
    {:else}
      <p class="warn">
        <i class="bi bi-exclamation-triangle"></i> Sem chave Pix: o cliente não consegue pagar por Pix direto pra loja.
      </p>
    {/if}

    {#if editing}
      <div class="row">
        <input
          type="text"
          placeholder="CNPJ da loja, e-mail, telefone ou chave aleatória"
          bind:value={draft}
          aria-label="Nova chave Pix"
        />
        <button type="button" class="btn-fuu-primary" disabled={saving || draft.trim() === ''} onclick={save}>
          {saving ? 'Salvando…' : 'Salvar'}
        </button>
      </div>
      {#if error}<p class="error">{error}</p>{/if}
      <p class="muted">Toda troca de chave fica registrada (quando, de onde e qual era a anterior) e a equipe do FUU consegue ver e reverter.</p>
    {:else}
      <button type="button" class="link" onclick={() => (editing = true)}>Trocar chave</button>
    {/if}
  {/if}
</div>

<style>
  .pix {
    padding: 16px;
    margin-bottom: 18px;
  }
  .k {
    font-family: var(--fuu-font-mono);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.1em;
    color: var(--fuu-ink-6);
    margin: 0 0 8px;
  }
  .key {
    font-size: 16px;
    font-weight: 700;
    color: var(--fuu-ink-1);
    margin: 0 0 4px;
    word-break: break-all;
  }
  .muted {
    font-size: 12.5px;
    color: var(--fuu-ink-5);
    margin: 4px 0 0;
  }
  .warn {
    font-size: 13px;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    border-radius: 8px;
    padding: 8px 10px;
    margin: 0 0 8px;
  }
  .row {
    display: flex;
    gap: 8px;
    margin-top: 8px;
  }
  .row input {
    flex: 1;
    min-width: 0;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 9px 12px;
    font-size: 14px;
  }
  .error {
    color: var(--fuu-alert);
    font-size: 12.5px;
    margin: 6px 0 0;
  }
  .link {
    background: none;
    border: none;
    padding: 0;
    margin-top: 8px;
    font-weight: 700;
    font-size: 13px;
    color: var(--fuu-red);
  }
</style>
