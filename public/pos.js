document.querySelector('[data-pos-fullscreen]')?.addEventListener('click',async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else await document.documentElement.requestFullscreen();}catch{}});
document.querySelectorAll('[data-pos-cart]').forEach(form=>{
    const checkout=form.querySelector('[data-pos-checkout]'),mode=form.querySelector('[data-mode]'),payment=form.querySelector('[data-payment-method]');
    let submitting=false;
    form.addEventListener('submit',event=>{if(!event.defaultPrevented)submitting=true;});
    window.addEventListener('pageshow',()=>{submitting=false;});
    window.addEventListener('beforeunload',event=>{if(!submitting&&form.querySelector('[data-cart-payload] input')){event.preventDefault();event.returnValue='';}});
    form.querySelector('[data-pos-continue]').addEventListener('click',()=>{
        if(!form.querySelector('[data-cart-payload] input')){form.querySelector('[data-cart-message]').textContent='Adicione um produto para continuar.';return;}
        checkout.showModal();
    });
    form.querySelector('[data-pos-back]').addEventListener('click',()=>checkout.close());
    form.querySelector('[data-pos-clear]').addEventListener('click',()=>{if(confirm('Limpar todos os produtos desta venda?'))form.dispatchEvent(new Event('pos-clear'));});
    const update=()=>{
        const delivery=mode.value==='delivery',online=payment.value.startsWith('online_');
        form.querySelector('[name=customer_name]').required=delivery;
        form.querySelector('[name=phone]').required=delivery;
        form.querySelector('[data-pos-received]').hidden=online;
        form.querySelector('[data-pos-online]').hidden=!online;
        if(online)form.querySelector('[name=received]').value='no';
        form.querySelector('[name=preferred_driver_id]').disabled=!delivery;
    };
    form.addEventListener('input',update);form.addEventListener('change',update);update();
    let selected='';
    const search=form.querySelector('[data-menu-search]');
    const filter=()=>{
        let visible=0;
        form.querySelectorAll('[data-menu-category]').forEach(category=>{
            category.querySelectorAll('[data-menu-product]').forEach(product=>{product.hidden=(selected&&category.id!==selected)||!product.dataset.name.toLocaleLowerCase('pt-BR').includes(search.value.toLocaleLowerCase('pt-BR'));if(!product.hidden)visible++;});
            category.hidden=![...category.querySelectorAll('[data-menu-product]')].some(product=>!product.hidden);
        });
        form.querySelector('[data-search-empty]').hidden=visible>0;
    };
    form.querySelectorAll('[data-pos-category]').forEach(button=>button.addEventListener('click',()=>{selected=button.dataset.posCategory;form.querySelectorAll('[data-pos-category]').forEach(other=>other.setAttribute('aria-pressed',String(other===button)));filter();}));
    search.addEventListener('input',filter);
    if(form.dataset.posRestore==='1')checkout.showModal();
});
document.querySelector('[data-pos-copy]')?.addEventListener('click',async()=>{
    const field=document.querySelector('[data-pos-share-link]'),message=document.querySelector('[data-pos-copy-status]');
    try{await navigator.clipboard.writeText(field.value);message.textContent='Link copiado. Cole na conversa com o cliente.';}catch{field.focus();field.select();message.textContent='Selecione e copie o link acima.';}
});
