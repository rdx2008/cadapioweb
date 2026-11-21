document.querySelectorAll('[data-bi-tenant]').forEach(select=>{
    select.addEventListener('change',()=>{
        const stores=select.form.querySelector('[name=store_id]');
        stores.value='';
        [...stores.options].forEach(option=>{option.hidden=Boolean(select.value&&option.dataset.tenant&&option.dataset.tenant!==select.value);});
    });
});
