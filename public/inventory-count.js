document.querySelector('[data-count-select-all]')?.addEventListener('click', (event) => {
    const boxes = [...event.currentTarget.form.querySelectorAll('[name="item_ids[]"]')];
    const select = boxes.some((box) => !box.checked);
    boxes.forEach((box) => { box.checked = select; });
    event.currentTarget.textContent = select ? 'Desmarcar todos desta página' : 'Selecionar todos desta página';
});
document.querySelector('[data-count-print]')?.addEventListener('click', () => window.print());
