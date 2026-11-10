document.querySelectorAll('[data-purchase-form]').forEach(form => {
    const list = form.querySelector('[data-purchase-lines]');
    let next = Math.max(-1, ...Array.from(list.querySelectorAll('select')).map(el => Number(el.name.match(/\[(\d+)\]/)?.[1] ?? 0))) + 1;
    form.querySelector('[data-purchase-add]').addEventListener('click', () => {
        if (list.children.length >= 50) { form.querySelector('[data-purchase-status]').textContent = 'Limite de 50 insumos por compra.'; return; }
        list.insertAdjacentHTML('beforeend', form.querySelector('template').innerHTML.replaceAll('__INDEX__', String(next++)));
    });
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-purchase-remove]');
        if (button && list.children.length > 1) button.closest('[data-purchase-line]').remove();
    });
});
document.querySelectorAll('[data-stock-movement]').forEach(form => {
    const select = form.querySelector('[name="kind"]');
    const input = form.querySelector('[name="entry_value"]');
    const update = () => { input.disabled = select.value !== 'in'; input.closest('label').hidden = input.disabled; };
    select.addEventListener('change', update); update();
});
