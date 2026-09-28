<script>
  import swal from 'sweetalert';
  import { api, ApiError, BASE } from '../../services/api.js';
  import { toastr } from '../../utils/toastr.js';
  import { adminToken } from '../../state/adminSession.svelte.js';

  // Tela 15.2, lado de dentro: a fila das candidaturas de entregador
  // (admin/couriers.php). Faltava no painel: a API existia, mas aprovar um
  // entregador exigia chamar a rota na mão, e ninguém conseguia ABRIR a CNH
  // e a selfie -- a conferência da selfie com o documento é o antifraude
  // (decisão 51).
  //
  // Os documentos vêm de admin/courier_document.php, decifrados na hora e
  // sem cache (é documento de identidade). Cada abertura fica no audit_log.

  const KIND_LABELS = {
    cnh: 'CNH',
    selfie: 'Selfie com o documento',
    crlv: 'CRLV do veículo',
    address_proof: 'Comprovante de residência',
  };
  const VEHICLE_LABELS = { moto: 'Moto', bike: 'Bike', car: 'Carro', foot: 'A pé' };
  const STATE_LABELS = { review: 'EM ANÁLISE', needs_fix: 'ESPERANDO CORREÇÃO' };

  let data = $state(null);
  let cities = $state([]);
  let city = $state({}); // id da candidatura -> ibge da praça escolhida
  let noting = $state(null); // {id, decision}
  let note = $state('');
  let busy = $state(false);
  let viewer = $state(null); // {url, kind, pdf}

  async function load() {
    try {
      data = await api.get('/admin/couriers.php', { token: adminToken() });
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra carregar a fila.');
    }
  }

  $effect(() => {
    load();
    api
      .get('/admin/cities.php', { token: adminToken() })
      .then((res) => (cities = (res.cities ?? []).filter((c) => c.active !== false)))
      .catch(() => {});
    return () => closeViewer();
  });

  function cpfMask(v) {
    const d = (v ?? '').replace(/\D/g, '');
    return d.length === 11 ? `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}` : v;
  }

  async function openDoc(doc) {
    closeViewer();
    try {
      const res = await fetch(new URL(`${BASE}/admin/courier_document.php?id=${doc.id}`, window.location.origin), {
        headers: { Authorization: `Bearer ${adminToken()}` },
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.message ?? 'Não deu pra abrir o documento.');
      }
      const blob = await res.blob();
      viewer = { url: URL.createObjectURL(blob), kind: doc.kind, pdf: blob.type === 'application/pdf' };
    } catch (e) {
      toastr.error(e.message);
    }
  }

  function closeViewer() {
    if (viewer) URL.revokeObjectURL(viewer.url);
    viewer = null;
  }

  async function decide(app, decision) {
    if (decision !== 'approve' && note.trim() === '') {
      toastr.warning('Escreva o motivo: é o que a pessoa vai ler no app.');
      return;
    }
    if (decision === 'approve' && !city[app.id]) {
      toastr.warning('Escolha a praça onde essa pessoa vai rodar.');
      return;
    }
    busy = true;
    try {
      const res = await api.post('/admin/couriers.php', {
        token: adminToken(),
        body: {
          application_id: app.id,
          decision,
          ...(decision === 'approve' ? { city_ibge_code: city[app.id] } : { note: note.trim() }),
        },
      });
      noting = null;
      note = '';
      if (decision === 'approve') {
        // O código aparece UMA vez (só o hash fica no banco).
        await swal({
          title: 'Entregador aprovado',
          text: `Código de acesso: ${res.access_code}\n\n${res.access_code_note}`,
          icon: 'success',
          button: 'Já anotei',
        });
      } else {
        toastr.success(decision === 'reject' ? 'Candidatura recusada, com o motivo.' : 'Pedido de correção enviado, com o motivo.');
      }
      await load();
    } catch (e) {
      toastr.error(e instanceof ApiError ? e.message : 'Não deu pra decidir.');
      if (e instanceof ApiError && e.code === 'already_approved') load();
    } finally {
      busy = false;
    }
  }
</script>

<p class="section">
  CANDIDATURAS DE ENTREGADOR · {data?.queue?.length ?? 0}
  {#if data}<span class="sla">análise em até {data.sla_hours} h</span>{/if}
</p>

{#if !data}
  <p class="empty">Carregando…</p>
{:else if data.queue.length === 0}
  <p class="empty">Nenhuma candidatura esperando decisão.</p>
{:else}
  <div class="list">
    {#each data.queue as app (app.id)}
      <article class="card fuu-card">
        <header>
          <div>
            <p class="name">{app.full_name}</p>
            <p class="meta fuu-mono">
              CPF {cpfMask(app.cpf)} · {VEHICLE_LABELS[app.vehicle] ?? app.vehicle}{app.plate ? ` · ${app.plate}` : ''}
            </p>
          </div>
          <span class={`badge ${Number(app.hours_waiting) > data.sla_hours ? 'late' : ''}`}>
            {STATE_LABELS[app.state] ?? app.state} · {Math.floor(Number(app.hours_waiting))} h
          </span>
        </header>

        <dl class="facts">
          <dt>Chave Pix</dt>
          <dd class="fuu-mono">{app.pix_key} <span class="check">tem que ser do mesmo CPF</span></dd>
          {#if app.review_note}
            <dt>Último motivo</dt>
            <dd>{app.review_note}</dd>
          {/if}
        </dl>

        <p class="docs-label">DOCUMENTOS · confira a selfie com a CNH</p>
        <div class="docs">
          {#each app.documents ?? [] as doc (doc.id)}
            <button type="button" class="doc" onclick={() => openDoc(doc)}>
              <i class="bi bi-file-earmark-person"></i>
              {KIND_LABELS[doc.kind] ?? doc.kind}
              {#if doc.expires_on}<span class="exp">vence {doc.expires_on.split('-').reverse().join('/')}</span>{/if}
            </button>
          {:else}
            <span class="empty">Nenhum documento enviado.</span>
          {/each}
        </div>

        {#if noting?.id === app.id}
          <label class="reason">
            <span>{noting.decision === 'reject' ? 'Motivo da recusa' : 'O que precisa corrigir'} (a pessoa lê isto no app)</span>
            <textarea maxlength="1000" rows="2" bind:value={note}></textarea>
          </label>
          <div class="actions">
            <button type="button" class="btn-fuu-danger-outline" disabled={busy} onclick={() => decide(app, noting.decision)}>
              {noting.decision === 'reject' ? 'Confirmar recusa' : 'Pedir correção'}
            </button>
            <button type="button" class="link" onclick={() => (noting = null)}>Voltar</button>
          </div>
        {:else}
          <div class="actions">
            <select bind:value={city[app.id]} aria-label="Praça onde a pessoa vai rodar">
              <option value={undefined}>Praça…</option>
              {#each cities as c (c.ibge)}
                <option value={c.ibge}>{c.name}/{c.uf}</option>
              {/each}
            </select>
            <button type="button" class="btn-fuu-primary approve" disabled={busy} onclick={() => decide(app, 'approve')}>
              Aprovar
            </button>
            <button type="button" class="ghost" onclick={() => ((noting = { id: app.id, decision: 'needs_fix' }), (note = ''))}>
              Pedir correção
            </button>
            <button type="button" class="btn-fuu-danger-outline" onclick={() => ((noting = { id: app.id, decision: 'reject' }), (note = ''))}>
              Recusar
            </button>
          </div>
        {/if}
      </article>
    {/each}
  </div>
{/if}

{#if (data?.recent ?? []).length > 0}
  <p class="section later">DECIDIDAS POR ÚLTIMO</p>
  <div class="recent">
    {#each data.recent as app (app.id)}
      <div class="row">
        <span class="rname">{app.full_name}</span>
        <span class={`badge ${app.state === 'approved' ? 'ok' : 'bad'}`}>{app.state === 'approved' ? 'APROVADA' : 'RECUSADA'}</span>
      </div>
    {/each}
  </div>
{/if}

{#if viewer}
  <div class="viewer-backdrop" onclick={closeViewer} role="presentation"></div>
  <div class="viewer fuu-card" role="dialog" aria-label={KIND_LABELS[viewer.kind] ?? 'Documento'}>
    <header>
      <strong>{KIND_LABELS[viewer.kind] ?? viewer.kind}</strong>
      <button type="button" class="link" onclick={closeViewer} aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
    </header>
    {#if viewer.pdf}
      <iframe src={viewer.url} title="Documento"></iframe>
    {:else}
      <img src={viewer.url} alt={KIND_LABELS[viewer.kind] ?? 'Documento'} />
    {/if}
  </div>
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
  .section.later {
    margin-top: 26px;
  }
  .sla {
    margin-left: 8px;
    font-weight: 500;
    letter-spacing: 0;
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
    font-size: 16px;
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
    font-size: 10.5px;
    font-weight: 700;
    border-radius: 6px;
    padding: 4px 8px;
    background: var(--fuu-wait-bg);
    color: var(--fuu-wait-text);
  }
  .badge.late,
  .badge.bad {
    background: var(--fuu-danger-tint, #fde8e8);
    color: var(--fuu-danger, #b42318);
  }
  .badge.ok {
    background: var(--fuu-leaf-tint);
    color: var(--fuu-leaf-dark);
  }
  .facts {
    display: grid;
    grid-template-columns: max-content 1fr;
    gap: 4px 12px;
    font-size: 13px;
    margin: 12px 0;
  }
  .facts dt {
    color: var(--fuu-ink-4);
  }
  .facts dd {
    margin: 0;
    overflow-wrap: anywhere;
  }
  .check {
    font-family: var(--fuu-font-body);
    font-size: 11.5px;
    color: var(--fuu-wait-text);
    margin-left: 6px;
  }
  .docs-label {
    font-size: 11.5px;
    color: var(--fuu-ink-4);
    margin: 0 0 6px;
  }
  .docs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
  }
  .doc {
    border: 1px solid var(--fuu-line-3);
    background: var(--fuu-paper);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 13px;
    cursor: pointer;
  }
  .doc .exp {
    font-size: 11px;
    color: var(--fuu-ink-4);
    margin-left: 4px;
  }
  .reason {
    display: block;
    margin-bottom: 12px;
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
    gap: 10px;
    flex-wrap: wrap;
  }
  .actions select {
    border: 1px solid var(--fuu-line-3);
    border-radius: 8px;
    padding: 8px;
    font-size: 13px;
    max-width: 100%;
  }
  .actions .approve {
    flex: 1;
    min-height: var(--fuu-tap-operator);
  }
  .ghost,
  .link {
    background: none;
    border: none;
    color: var(--fuu-ink-3);
    font-size: 13px;
    cursor: pointer;
    text-decoration: underline;
  }
  .recent {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .row {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
  }
  .viewer-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.55);
    z-index: 40;
  }
  .viewer {
    position: fixed;
    inset: 4vh 4vw;
    z-index: 41;
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .viewer header {
    align-items: center;
  }
  .viewer header .link {
    margin-left: auto;
  }
  .viewer img,
  .viewer iframe {
    flex: 1;
    min-height: 0;
    width: 100%;
    object-fit: contain;
    border: none;
  }
</style>
