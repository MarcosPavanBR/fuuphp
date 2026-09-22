<script>
  import { toastr } from '../toastr.js';
  import { ApiError, BASE, getStoredToken } from '../api.js';
  import { enqueueProof } from '../uploadQueue.svelte.js';

  // Tela 4.4 — Upload do comprovante. "Compressão via canvas antes do
  // envio; no servidor, MIME real por finfo, marca d'água, hash sha256 e
  // phash contra reuso de print." Progresso é real (XMLHttpRequest upload
  // progress), não uma barra decorativa -- fetch() não expõe isso, por
  // isso este componente não usa lib/api.js pra esta chamada específica.
  let { orderId, orderCode, onUploaded, onBack } = $props();

  let file = $state(null);
  let previewUrl = $state(null);
  let progress = $state(0);
  let uploading = $state(false);
  let originalSize = $state(0);
  let compressedSize = $state(0);
  // Tela 7.1: sem rede, o comprovante vai pra fila e sobe sozinho depois.
  let queuedOffline = $state(false);

  function money(v) {
    return `R$ ${Number(v).toFixed(2).replace('.', ',')}`;
  }

  // Compressão real via canvas (redimensiona pro lado maior caber em
  // 1280px e reexporta como JPEG 80%) -- o mock descreve exatamente isso
  // ("comprimido no aparelho: 3,8 MB → 412 KB").
  function compress(inputFile) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      const reader = new FileReader();
      reader.onload = () => {
        img.onload = () => {
          const scale = Math.min(1, 1280 / Math.max(img.width, img.height));
          const canvas = document.createElement('canvas');
          canvas.width = Math.round(img.width * scale);
          canvas.height = Math.round(img.height * scale);
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
          canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('canvas vazio'))),
            'image/jpeg',
            0.8
          );
        };
        img.onerror = () => reject(new Error('não deu pra ler a imagem'));
        img.src = reader.result;
      };
      reader.onerror = () => reject(new Error('não deu pra ler o arquivo'));
      reader.readAsDataURL(inputFile);
    });
  }

  async function onFileChosen(e) {
    const chosen = e.target.files?.[0];
    if (!chosen) return;
    originalSize = chosen.size;
    try {
      const compressed = await compress(chosen);
      compressedSize = compressed.size;
      file = new File([compressed], 'comprovante.jpg', { type: 'image/jpeg' });
    } catch {
      // Se a compressão falhar (ex.: arquivo não é imagem legível pro
      // canvas), envia o original -- o servidor ainda valida MIME de verdade.
      compressedSize = chosen.size;
      file = chosen;
    }
    previewUrl = URL.createObjectURL(file);
  }

  function uploadWithProgress() {
    return new Promise((resolve, reject) => {
      const form = new FormData();
      form.append('order_id', String(orderId));
      form.append('proof', file);

      const xhr = new XMLHttpRequest();
      xhr.open('POST', `${BASE}/payments/upload_proof.php`);
      const token = getStoredToken();
      if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`);
      xhr.upload.onprogress = (evt) => {
        if (evt.lengthComputable) progress = Math.round((evt.loaded / evt.total) * 100);
      };
      xhr.onload = () => {
        let data = {};
        try {
          data = JSON.parse(xhr.responseText || '{}');
        } catch {
          // corpo não era JSON -- data fica {}
        }
        if (xhr.status >= 200 && xhr.status < 300) resolve(data);
        else reject(new ApiError(xhr.status, data));
      };
      xhr.onerror = () => {
        const err = new Error('Falha de rede no envio do comprovante.');
        err.network = true;
        reject(err);
      };
      xhr.send(form);
    });
  }

  async function submit() {
    if (!file) {
      toastr.warning('Escolha uma foto do comprovante primeiro.');
      return;
    }
    // Offline declarado: nem tenta -- vai direto pra fila (tela 7.1).
    if (!navigator.onLine) {
      await queue();
      return;
    }
    uploading = true;
    progress = 0;
    try {
      const data = await uploadWithProgress();
      toastr.success('Comprovante enviado ✓');
      onUploaded(data);
    } catch (e) {
      // A rede caiu no meio: o mesmo arquivo vai pra fila, e o UUID dela
      // garante que um envio que chegou a entrar não vira dois.
      if (e.network) {
        await queue();
      } else {
        toastr.error(e.message ?? 'Não deu pra enviar o comprovante.');
      }
    } finally {
      uploading = false;
    }
  }

  async function queue() {
    try {
      await enqueueProof(orderId, file);
      queuedOffline = true;
      toastr.info('Sem conexão. O comprovante está na fila e sobe sozinho quando a rede voltar.');
    } catch {
      toastr.error('Sem conexão, e este navegador não deixou guardar o comprovante. Tente de novo com rede.');
    }
  }
</script>

<div class="proof-uploader">
  <div class="header-row">
    <button type="button" class="back" onclick={onBack} aria-label="Voltar" disabled={uploading}>
      <i class="bi bi-arrow-left"></i>
    </button>
    <h1 class="fuu-display">Enviar comprovante</h1>
  </div>

  <p class="hint">Tire uma foto legível do comprovante. A loja valida em até 15 minutos.</p>

  <div class="preview-box">
    {#if previewUrl}
      <img src={previewUrl} alt="Pré-visualização do comprovante" />
      <span class="order-tag fuu-mono">PEDIDO #{orderCode}</span>
    {:else}
      <i class="bi bi-image"></i>
      <span>pré-visualização do comprovante</span>
    {/if}
  </div>

  <div class="picker-row">
    <label class="picker-btn">
      <input type="file" accept="image/*" capture="environment" onchange={onFileChosen} hidden />
      Tirar outra
    </label>
    <label class="picker-btn">
      <input type="file" accept="image/*" onchange={onFileChosen} hidden />
      Galeria
    </label>
  </div>

  {#if uploading}
    <p class="progress-label">ENVIANDO — {progress}%</p>
    <div class="progress-bar"><div class="progress-fill" style={`width:${progress}%`}></div></div>
    {#if compressedSize}
      <p class="compress-note">
        comprimido no aparelho: {(originalSize / 1024 / 1024).toFixed(1)} MB → {Math.round(compressedSize / 1024)} KB
      </p>
    {/if}
  {/if}

  <div class="checklist">
    <p class="checklist-title">O que a loja confere</p>
    <p><i class="bi bi-check2"></i> Valor igual ao do pedido</p>
    <p><i class="bi bi-check2"></i> Data e hora de hoje</p>
    <p><i class="bi bi-check2"></i> Nome do recebedor</p>
  </div>

  <div class="footer">
    {#if queuedOffline}
      <!-- 7.1: "1 comprovante na fila. Vai subir sozinho quando a conexão
           voltar (background sync)." -->
      <p class="queued"><i class="bi bi-cloud-arrow-up"></i> Comprovante na fila. Vai subir sozinho quando a conexão voltar.</p>
    {/if}
    <button type="button" class="btn-fuu-primary w-100" disabled={!file || uploading || queuedOffline} onclick={submit}>
      {uploading ? 'Enviando…' : queuedOffline ? 'Na fila' : 'Enviar comprovante'}
    </button>
  </div>
</div>

<style>
  .queued {
    font-size: 12.5px;
    color: var(--fuu-wait-text);
    background: var(--fuu-wait-bg);
    border-radius: 10px;
    padding: 10px 12px;
    margin: 0 0 10px;
  }
  .proof-uploader {
    padding: 12px 20px 100px;
  }
  .header-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
  }
  .back {
    background: none;
    border: none;
    font-size: 18px;
    color: var(--fuu-ink-2);
    padding: 4px;
  }
  h1 {
    font-size: 19px;
    margin: 0;
  }
  .hint {
    font-size: 13px;
    color: var(--fuu-ink-4);
    margin: 0 0 14px;
  }
  .preview-box {
    position: relative;
    height: 220px;
    border-radius: var(--fuu-radius-card);
    background: var(--fuu-line-5);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    color: var(--fuu-ink-5);
    font-size: 11.5px;
    overflow: hidden;
    margin-bottom: 12px;
  }
  .preview-box img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .preview-box i {
    font-size: 36px;
  }
  .order-tag {
    position: absolute;
    left: 10px;
    bottom: 10px;
    background: rgba(0, 0, 0, 0.55);
    color: var(--fuu-white);
    font-size: 10px;
    padding: 3px 8px;
    border-radius: 999px;
  }
  .picker-row {
    display: flex;
    gap: 10px;
    margin-bottom: 14px;
  }
  .picker-btn {
    flex: 1;
    text-align: center;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-pill);
    background: var(--fuu-white);
    min-height: var(--fuu-tap-customer);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--fuu-font-body);
    font-weight: 600;
    font-size: 13.5px;
    color: var(--fuu-ink-1);
  }
  .progress-label {
    font-family: var(--fuu-font-mono);
    font-size: 12px;
    color: var(--fuu-ink-3);
    margin: 0 0 4px;
  }
  .progress-bar {
    height: 6px;
    border-radius: 999px;
    background: var(--fuu-line-3);
    overflow: hidden;
    margin-bottom: 6px;
  }
  .progress-fill {
    height: 100%;
    background: var(--fuu-red);
    transition: width 0.15s;
  }
  .compress-note {
    font-size: 11px;
    color: var(--fuu-ink-5);
    margin: 0 0 14px;
  }
  .checklist {
    background: var(--fuu-line-6);
    border-radius: var(--fuu-radius-card);
    padding: 12px 14px;
    margin-bottom: 16px;
  }
  .checklist-title {
    margin: 0 0 8px;
    font-weight: 600;
    font-size: 12.5px;
    color: var(--fuu-ink-2);
  }
  .checklist p {
    margin: 0 0 4px;
    font-size: 12.5px;
    color: var(--fuu-ink-3);
  }
  .checklist i {
    color: var(--fuu-leaf);
    margin-right: 4px;
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
