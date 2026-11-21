document.querySelector('[data-catalog-copy]')?.addEventListener('click', async () => {
    const input = document.querySelector('[data-catalog-share-link]');
    const status = document.querySelector('[data-catalog-copy-status]');
    try {
        await navigator.clipboard.writeText(input.value);
        status.textContent = 'Link copiado!';
    } catch {
        input.focus();
        input.select();
        status.textContent = 'Link selecionado. Use Copiar no seu dispositivo.';
    }
});
document.querySelector('[data-catalog-print]')?.addEventListener('click', () => window.print());
