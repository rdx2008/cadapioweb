const menuMoney = value => new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(value/100);
async function addressRequest(url, address) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 12000);
    try {
        const response = await fetch(url,{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(typeof address==='string'?{address}:{address_fields:address})});
        const data = await response.json();
        if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat()[0] : 'Consulta indisponível. Tente novamente.');
        return data.choices;
    } finally { clearTimeout(timeout); }
}
document.querySelectorAll('[data-menu-cart]').forEach(form => {
    const products = [...form.querySelectorAll('[data-menu-product]')];
    const mode = form.querySelector('[data-mode]'), address = form.querySelector('[data-delivery-address]');
    const token = form.querySelector('[name=delivery_token]');
    const addressParts = [...form.querySelectorAll('[data-address-part]')];
    const postcode = addressParts.find(field=>field.dataset.addressPart==='postcode');
    const addressDetails = form.querySelector('[data-address-details]');
    const postcodeMessage = form.querySelector('[data-postcode-message]');
    const manualAddress = form.querySelector('[data-postcode-manual]');
    let postcodeVersion=0;
    let addressReady=Boolean(postcode?.value && addressParts.find(field=>field.dataset.addressPart==='street')?.value);
    const results = form.querySelector('[data-delivery-results]');
    let deliveryFee = null, lookupVersion = 0;
    function update() {
        let subtotal=0,count=0;
        const lines=form.querySelector('[data-menu-lines]');
        lines.replaceChildren();
        products.forEach(product => {
            const quantity=Math.max(0,Math.min(50,Number(product.querySelector('[data-quantity]').value)||0));
            let unit=Number(product.dataset.price);
            const variant=product.querySelector('[data-product-variant]')?.selectedOptions[0];
            if(variant)unit=Number(variant.dataset.variantPrice);
            const base=unit;
            product.querySelectorAll('[data-option-price]:checked').forEach(option=>unit+=Number(option.dataset.optionPrice));
            product.querySelector('.dialog-price').textContent=menuMoney(base);
            count+=quantity; subtotal+=unit*quantity;
            if(quantity){
                const row=document.createElement('div');row.className='bag-item';
                const copy=document.createElement('div');copy.textContent=quantity+' × '+product.dataset.name+(variant?' · '+variant.dataset.variantName:'');
                const amount=document.createElement('small');amount.textContent=menuMoney(unit*quantity);copy.append(amount);
                const remove=document.createElement('button');remove.type='button';remove.className='bag-remove';remove.textContent='×';remove.setAttribute('aria-label','Remover '+product.dataset.name);remove.addEventListener('click',()=>{product.querySelector('[data-quantity]').value=0;update();});
                row.append(copy,remove);lines.append(row);
            }
        });
        if(!count){const empty=document.createElement('div');empty.className='bag-empty';empty.textContent='Sua sacola está esperando seus favoritos.';lines.append(empty);}
        const delivery=mode.value==='delivery';
        address.required=delivery;
        addressParts.forEach(field=>{const active=delivery&&(field===postcode||addressReady);field.required=active;field.disabled=!active;});
        form.querySelectorAll('[data-delivery-field]').forEach(field=>field.hidden=!delivery);
        if(addressDetails)addressDetails.hidden=!delivery||!addressReady;
        const complement=form.querySelector('[name=address_complement]');if(complement)complement.disabled=!delivery||!addressReady;
        const fee=delivery ? deliveryFee : 0;
        document.querySelectorAll('[data-bag-count],[data-menu-count],[data-mobile-count]').forEach(el=>el.textContent=String(count));
        form.querySelector('[data-menu-subtotal]').textContent=menuMoney(subtotal);
        form.querySelector('[data-menu-freight]').textContent=!delivery?'Sem frete':fee===null?'Consultar endereço':menuMoney(fee);
        form.querySelector('[data-menu-total]').textContent=menuMoney(subtotal+(fee||0));
        form.querySelector('[data-mobile-total]').textContent=menuMoney(subtotal+(fee||0));
    }
    products.forEach(product=>{
        const dialog=product.querySelector('dialog');
        product.querySelector('[data-product-open]').addEventListener('click',()=>dialog.showModal());
        product.querySelector('[data-product-close]').addEventListener('click',()=>dialog.close());
        product.querySelector('[data-product-add]').addEventListener('click',()=>{
            const quantity=product.querySelector('[data-quantity]');if(Number(quantity.value)===0)quantity.value=1;
            dialog.close();update();
        });
        dialog.addEventListener('keydown',event=>{if(event.key==='Enter' && event.target.tagName!=='BUTTON'){event.preventDefault();product.querySelector('[data-product-add]').click();}});
    });
    form.querySelectorAll('[data-discover-open]').forEach(button=>button.addEventListener('click',()=>{
        document.getElementById(button.dataset.discoverOpen).closest('dialog').showModal();
    }));
    form.addEventListener('input',update);form.addEventListener('change',update);
    form.querySelector('[data-menu-search]').addEventListener('input',event=>{
        const term=event.target.value.toLocaleLowerCase('pt-BR');let visible=0;
        products.forEach(product=>{product.hidden=!product.textContent.toLocaleLowerCase('pt-BR').includes(term);if(!product.hidden)visible++;});
        form.querySelectorAll('[data-menu-category]').forEach(category=>category.hidden=![...category.querySelectorAll('[data-menu-product]')].some(product=>!product.hidden));
        form.querySelector('[data-search-empty]').hidden=visible>0;
    });
    if(token){
        const invalidateAddress=()=>{lookupVersion++;token.value='';deliveryFee=null;results.replaceChildren();update();};
        address.addEventListener('input',invalidateAddress);
        addressParts.forEach(field=>{field.addEventListener('input',invalidateAddress);field.addEventListener('change',invalidateAddress);});
        if(postcode && addressDetails){
            postcode.addEventListener('input',()=>{
                postcodeVersion++;addressReady=false;manualAddress.hidden=true;postcodeMessage.textContent='';
                addressParts.filter(field=>field!==postcode).forEach(field=>field.value='');
                form.querySelector('[name=address_complement]').value='';update();
            });
            postcode.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();form.querySelector('[data-postcode-search]').click();}});
            manualAddress.addEventListener('click',()=>{
                if(!postcode.reportValidity())return;
                postcodeVersion++;addressReady=true;postcodeMessage.textContent='Preencha o endereço completo. O frete será validado após localizar o número.';manualAddress.hidden=true;update();
                addressParts.find(field=>field.dataset.addressPart==='street').focus();
            });
            form.querySelector('[data-postcode-search]').addEventListener('click',async event=>{
                if(!postcode.reportValidity())return;
                const button=event.currentTarget,version=++postcodeVersion,submitted=postcode.value;
                invalidateAddress();addressReady=false;update();button.disabled=true;manualAddress.hidden=true;postcodeMessage.textContent='Buscando endereço…';
                const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),9000);
                try{
                    const response=await fetch(form.querySelector('[data-postcode-step]').dataset.url,{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({postcode:submitted})});
                    const data=await response.json();
                    if(version!==postcodeVersion||postcode.value!==submitted)return;
                    if(!response.ok)throw new Error(data.errors?Object.values(data.errors).flat()[0]:'Não foi possível consultar o CEP.');
                    addressParts.filter(field=>field!==postcode).forEach(field=>field.value=data.address[field.dataset.addressPart]||'');
                    addressReady=true;postcodeMessage.textContent=data.address.street&&data.address.neighborhood?'Endereço encontrado. Confira os dados e informe o número.':'Este CEP não informa todos os dados. Complete os campos abaixo.';
                    update();const missing=addressParts.find(field=>!field.value&&field!==postcode);missing?.focus();
                }catch(error){if(version===postcodeVersion){postcodeMessage.textContent=error.name==='AbortError'?'A consulta demorou. Tente novamente ou preencha manualmente.':error.message;manualAddress.hidden=false;}}
                finally{clearTimeout(timeout);button.disabled=false;}
            });
            mode.addEventListener('change',()=>{if(mode.value!=='delivery')postcodeVersion++;});
        }
        form.querySelector('[data-delivery-search]').addEventListener('click',async event=>{
            const button=event.currentTarget, version=++lookupVersion;
            token.value='';deliveryFee=null;update();button.disabled=true;results.textContent='Consultando seu endereço…';
            try {
                if(addressParts.some(field=>!field.reportValidity())){results.textContent='Preencha os campos do endereço para consultar o frete.';return;}
                const fields=Object.fromEntries(addressParts.map(field=>[field.dataset.addressPart,field.value.trim()]));
                const choices=await addressRequest(form.querySelector('[data-delivery-lookup]').dataset.url,addressParts.length?fields:address.value);
                if(version!==lookupVersion)return;
                results.replaceChildren();
                choices.forEach(choice=>{const pick=document.createElement('button');pick.type='button';pick.className='address-choice';pick.disabled=!choice.available;pick.textContent=choice.label+' — '+(choice.available?'Frete '+menuMoney(choice.fee_cents):(choice.message||'Fora da área de entrega'));pick.addEventListener('click',()=>{token.value=choice.token;deliveryFee=choice.fee_cents;results.replaceChildren();const confirmed=document.createElement('p');confirmed.className='lookup-message';confirmed.textContent='Endereço confirmado: '+choice.label;results.append(confirmed);update();});results.append(pick);});
            }catch(error){if(version===lookupVersion)results.textContent=error.name==='AbortError'?'Consulta lenta. Tente novamente.':error.message;}
            finally{button.disabled=false;}
        });
    }
    form.addEventListener('submit',event=>{
        if(event.submitter?.type!=='submit')return;
        if(mode.value==='delivery' && token && !token.value){event.preventDefault();if(!addressReady&&postcodeMessage){postcodeMessage.textContent='Busque o CEP para completar o endereço antes de continuar.';postcode.focus();}else{results.textContent='Consulte o frete e confirme seu endereço antes de continuar.';(addressParts.find(field=>field.dataset.addressPart==='number')||address).focus();}}
    });
    update();
});
document.querySelectorAll('[data-store-locate]').forEach(button=>button.addEventListener('click',async()=>{
    const form=button.closest('form'),message=form.querySelector('[data-store-location-message]');
    const address=form.querySelector('[name=address]').value;
    button.disabled=true;message.textContent='Localizando endereço da loja…';
    try {
        const choices=await addressRequest(button.dataset.url,address);
        if(form.querySelector('[name=address]').value!==address){message.textContent='O endereço mudou. Consulte novamente.';return;}
        message.replaceChildren();
        choices.forEach(place=>{const choice=document.createElement('button');choice.type='button';choice.className='outline address-choice';choice.textContent=place.label;choice.addEventListener('click',()=>{form.querySelector('[name=latitude]').value=place.latitude;form.querySelector('[name=longitude]').value=place.longitude;message.textContent='Localização preenchida. Salve a configuração para aplicar.';});message.append(choice);});
    }catch(error){message.textContent=error.name==='AbortError'?'Consulta lenta. Tente novamente.':error.message;}
    finally{button.disabled=false;}
}));
document.querySelectorAll('[data-delivery-settings]').forEach(section=>{
    const form=section.querySelector('[data-delivery-settings-form]');
    const mode=form.querySelector('[data-delivery-setting-mode]');
    const pricing=form.querySelector('[data-delivery-setting-pricing]');
    const show=(container,visible)=>{
        container.hidden=!visible;
        container.querySelectorAll('input,select,button').forEach(field=>field.disabled=!visible);
    };
    const update=()=>{
        section.querySelectorAll('[data-setting-area]').forEach(el=>show(el,el.dataset.settingArea===mode.value));
        section.querySelectorAll('[data-setting-rule]').forEach(el=>show(el,mode.value==='radius'&&el.dataset.settingRule.split(',').includes(pricing.value)));
        section.querySelectorAll('[data-area-editor]').forEach(el=>show(el,el.dataset.areaEditor===mode.value&&(mode.value==='neighborhood'||pricing.value==='bands')));
    };
    mode.addEventListener('change',update);pricing.addEventListener('change',update);update();
});
