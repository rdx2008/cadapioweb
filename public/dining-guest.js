document.querySelector('[data-dining-copy]')?.addEventListener('click',async()=>{
    const field=document.querySelector('[data-dining-guest-link]'),message=document.querySelector('[data-dining-copy-status]');
    try{await navigator.clipboard.writeText(field.value);message.textContent='Link copiado. Compartilhe com o grupo desta mesa.';}
    catch{field.focus();field.select();message.textContent='Selecione e copie o link para compartilhar com o grupo.';}
});
