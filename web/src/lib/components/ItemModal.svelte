<script>
  import MenuPhoto from './MenuPhoto.svelte';
  import { fly } from 'svelte/transition';
  import { addToCart } from '../cart.svelte.js';
  import { isAuthenticated } from '../session.svelte.js';
  import { toastr } from '../toastr.js';
  import AuthFlow from '../screens/AuthFlow.svelte';

  // Tela 3.2 — Item, extras e observações. "Modal com transição fly do
  // Svelte; preço recalculado no servidor antes de virar item do pedido."
  // (ProductModal.svelte, input-group, toastr "Item adicionado")
  let { item, restaurantId, onClose, onAdded } = $props();

  // Agrupa as variações por group_name, na ordem que já vêm (position).
  // Grupo com max_selections === 1 é rádio (escolha única); senão é
  // checkbox, limitado a max_selections quando ele vem preenchido.
  // `item` é prop fixa pro tempo de vida deste componente -- RestaurantPage
  // recria o ItemModal a cada troca de item (bloco {#if openItem}), então
  // isto não precisa ser $derived; é calculado uma vez, de propósito.
  const groups = Object.values(
    item.variants.reduce((acc, v) => {
      (acc[v.group_name] ??= { name: v.group_name, required: v.required, max: v.max_selections, options: [] })
        .options.push(v);
      return acc;
    }, {})
  );

  let selected = $state(Object.fromEntries(groups.map((g) => [g.name, []])));
  let quantity = $state(1);
  let notes = $state('');
  let busy = $state(false);
  let needsLogin = $state(false);

  function toggle(group, variant) {
    const current = selected[group.name];
    const isRadio = group.max === 1;
    if (isRadio) {
      selected[group.name] = current.includes(variant.id) ? [] : [variant.id];
      return;
    }
    if (current.includes(variant.id)) {
      selected[group.name] = current.filter((id) => id !== variant.id);
    } else if (group.max === null || current.length < group.max) {
      selected[group.name] = [...current, variant.id];
    }
  }

  let unitPrice = $derived(
    Number(item.price) +
      groups.reduce(
        (sum, g) => sum + g.options.filter((o) => selected[g.name].includes(o.id)).reduce((s, o) => s + Number(o.price_delta), 0),
        0
      )
  );
  let missingRequired = $derived(groups.some((g) => g.required && selected[g.name].length === 0));

  async function confirmAdd() {
    if (!isAuthenticated()) {
      needsLogin = true;
      return;
    }
    busy = true;
    try {
      const variantIds = groups.flatMap((g) => selected[g.name]);
      await addToCart({ restaurantId, menuItemId: item.id, quantity, variantIds, notes: notes.trim() || undefined });
      toastr.success('Item adicionado ✓');
      onAdded();
    } catch (e) {
      toastr.error(e.message ?? 'Não deu pra adicionar o item.');
    } finally {
      busy = false;
    }
  }
</script>

<div class="item-modal-backdrop" onclick={onClose} role="presentation"></div>
<div class="item-modal-panel" transition:fly={{ y: 40, duration: 200 }}>
  {#if needsLogin}
    <button type="button" class="close" onclick={onClose} aria-label="Fechar">
      <i class="bi bi-x-lg"></i>
    </button>
    <AuthFlow onSuccess={() => (needsLogin = false)} />
  {:else}
    <button type="button" class="close" onclick={onClose} aria-label="Fechar">
      <i class="bi bi-x-lg"></i>
    </button>

    <div class="photo"><MenuPhoto photoKey={item.photo_key} alt={item.name} /></div>

    <h2 class="fuu-display">{item.name}</h2>
    <p class="price-line fuu-mono">R$ {unitPrice.toFixed(2).replace('.', ',')}</p>
    {#if item.description}
      <p class="description">{item.description}</p>
    {/if}

    {#each groups as group (group.name)}
      <div class="group">
        <div class="group-header">
          <span class="group-name">{group.name}</span>
          {#if group.required}
            <span class="required-badge">Obrigatório</span>
          {:else if group.max}
            <span class="max-badge">até {group.max}</span>
          {/if}
        </div>
        {#each group.options as option (option.id)}
          <label class="option">
            <span>
              <input
                type={group.max === 1 ? 'radio' : 'checkbox'}
                name={group.name}
                checked={selected[group.name].includes(option.id)}
                onclick={() => toggle(group, option)}
              />
              {option.name}
            </span>
            {#if Number(option.price_delta) > 0}
              <span class="delta fuu-mono">+ R$ {Number(option.price_delta).toFixed(2).replace('.', ',')}</span>
            {/if}
          </label>
        {/each}
      </div>
    {/each}

    <div class="group">
      <div class="group-header"><span class="group-name">Observação</span></div>
      <textarea placeholder="Sem cebola, por favor…" bind:value={notes} rows="2"></textarea>
    </div>

    <div class="footer">
      <div class="stepper">
        <button type="button" onclick={() => (quantity = Math.max(1, quantity - 1))} aria-label="Diminuir">−</button>
        <span>{quantity}</span>
        <button type="button" onclick={() => (quantity = quantity + 1)} aria-label="Aumentar">+</button>
      </div>
      <button
        type="button"
        class="btn-fuu-primary"
        disabled={missingRequired || busy}
        onclick={confirmAdd}
      >
        {busy ? 'Adicionando…' : `Adicionar · R$ ${(unitPrice * quantity).toFixed(2).replace('.', ',')}`}
      </button>
    </div>
  {/if}
</div>

<style>
  .item-modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(20, 18, 16, 0.32);
    backdrop-filter: blur(1px);
    z-index: 40;
  }
  .item-modal-panel {
    position: fixed;
    left: 50%;
    bottom: 0;
    transform: translateX(-50%);
    width: 100%;
    max-width: 430px;
    max-height: 88vh;
    overflow-y: auto;
    background: var(--fuu-white);
    border-radius: 18px 18px 0 0;
    padding: 18px 20px 20px;
    z-index: 41;
  }
  .close {
    position: absolute;
    top: 14px;
    right: 14px;
    background: var(--fuu-line-5);
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    color: var(--fuu-ink-3);
  }
  .photo {
    height: 140px;
    border-radius: var(--fuu-radius-card);
    background: var(--fuu-line-5);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--fuu-ink-6);
    font-size: 32px;
    margin-bottom: 14px;
  }
  h2 {
    font-size: 19px;
    margin: 0 0 4px;
  }
  .price-line {
    color: var(--fuu-ink-2);
    font-size: 14px;
    margin: 0 0 8px;
  }
  .description {
    color: var(--fuu-ink-4);
    font-size: 13px;
    margin: 0 0 16px;
  }
  .group {
    border-top: 1px solid var(--fuu-line-3);
    padding: 14px 0;
  }
  .group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
  }
  .group-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--fuu-ink-1);
  }
  .required-badge {
    font-family: var(--fuu-font-mono);
    font-size: 10px;
    letter-spacing: 0.06em;
    color: var(--fuu-alert);
    border: 1px solid var(--fuu-alert);
    border-radius: 999px;
    padding: 1px 8px;
  }
  .max-badge {
    font-size: 11px;
    color: var(--fuu-ink-5);
  }
  .option {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    font-size: 14px;
    color: var(--fuu-ink-2);
  }
  .option input {
    margin-right: 8px;
  }
  .delta {
    color: var(--fuu-ink-5);
    font-size: 12.5px;
  }
  textarea {
    width: 100%;
    border: 1px solid var(--fuu-line-3);
    border-radius: var(--fuu-radius-card);
    padding: 10px 12px;
    font-family: var(--fuu-font-body);
    font-size: 13.5px;
    resize: vertical;
  }
  .footer {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 18px;
    position: sticky;
    bottom: 0;
    background: var(--fuu-white);
    padding-top: 10px;
  }
  .stepper {
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid var(--fuu-line-3);
    border-radius: 999px;
    padding: 6px 12px;
  }
  .stepper button {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    border: none;
    background: var(--fuu-line-5);
    color: var(--fuu-ink-2);
    font-size: 16px;
  }
  .footer .btn-fuu-primary {
    flex: 1;
  }
</style>
