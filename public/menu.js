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
    const cart=new MenuCart();
    let editingId=null;
    const cartMessage=form.querySelector('[data-cart-message]');
    const pizzaInfo=(product,ids,variantId)=>{
        const names=[],prices=[];
        product.querySelectorAll('[data-pizza-flavor]').forEach(flavor=>{
            if(!ids.map(Number).includes(Number(flavor.value)))return;
            const price=JSON.parse(flavor.dataset.flavorPrices)[variantId];
            if(Number.isInteger(price)&&price>0){prices.push(price);names.push('1/'+ids.length+' '+flavor.dataset.flavorName);}
        });
        return {names,price:prices.length===ids.length?MenuCart.pizzaPrice(prices,product.dataset.pizzaPricing):null};
    };
    const readLine=(product)=>{
        const selected=product.querySelector('[data-product-variant]')?.selectedOptions[0];
        const choices={},optionNames=[];
        const comboChoices={},comboNames=[];
        product.querySelectorAll('[data-combo-option]:checked').forEach(option=>{
            (comboChoices[option.dataset.comboGroupId]??=[]).push(Number(option.value));comboNames.push(option.dataset.comboName);
        });
        product.querySelectorAll('[data-option-price]:checked').forEach(option=>{
            (choices[option.dataset.optionGroupId]??=[]).push(Number(option.value));optionNames.push(option.dataset.optionName);
        });
        let unit=selected?Number(selected.dataset.variantPrice):Number(product.dataset.price);
        const flavors=Array.from(product.querySelectorAll('[data-pizza-flavor]:checked')).map(flavor=>Number(flavor.value));
        const pizza=pizzaInfo(product,flavors,selected?.value);
        if(product.dataset.pizza==='1'&&pizza.price!==null)unit=pizza.price;
        product.querySelectorAll('[data-combo-option]:checked').forEach(option=>unit+=Number(option.dataset.comboPrice));
        product.querySelectorAll('[data-option-price]:checked').forEach(option=>unit+=Number(option.dataset.optionPrice));
        return {productId:Number(product.dataset.productId),quantity:Number(product.querySelector('[data-quantity]').value),variantId:selected?Number(selected.value):null,
            choices,flavors,comboChoices,name:product.dataset.name,variantName:selected?.dataset.variantName??'',optionNames:[...pizza.names,...comboNames,...optionNames],unit};
    };
    const openProduct=(product,line=null)=>{
        editingId=line?.id??null;
        product.querySelector('[data-quantity]').value=line?.quantity??1;
        const variant=product.querySelector('[data-product-variant]');
        if(variant)variant.value=line?.variantId??variant.options[0]?.value??'';
        product.querySelectorAll('[data-pizza-flavor]').forEach(flavor=>{flavor.checked=Boolean(line?.flavors?.map(Number).includes(Number(flavor.value)));});
        product.querySelectorAll('[data-combo-option]').forEach(option=>{option.checked=Boolean(line?.comboChoices?.[option.dataset.comboGroupId]?.map(Number).includes(Number(option.value)));});
        product.querySelectorAll('[data-option-price]').forEach(option=>{option.checked=Boolean(line?.choices?.[option.dataset.optionGroupId]?.map(Number).includes(Number(option.value)));});
        product.querySelector('[data-product-add]').textContent=line?'Salvar item':'Adicionar à sacola';
        product.querySelector('[data-product-message]').textContent='';
        const dialog=product.querySelector('dialog');if(!dialog.open)dialog.showModal();update();
    };
    const appendInput=(name,value)=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.querySelector('[data-cart-payload]').append(input);};
    function update() {
        let subtotal=0,count=0;
        const lines=form.querySelector('[data-menu-lines]');
        lines.replaceChildren();form.querySelector('[data-cart-payload]').replaceChildren();
        products.forEach(product=>{
            const variant=product.querySelector('[data-product-variant]')?.selectedOptions[0];
            product.querySelectorAll('[data-pizza-flavor]').forEach(flavor=>{
                const price=JSON.parse(flavor.dataset.flavorPrices)[variant?.value],available=Number.isInteger(price)&&price>0;
                flavor.disabled=!available;
                if(!available&&flavor.checked){flavor.checked=false;product.querySelector('[data-product-message]').textContent='Sabores indisponíveis neste tamanho foram desmarcados.';}
                const label=flavor.closest('[data-flavor-choice]');label.querySelector('[data-flavor-price]').textContent=available?'Pizza inteira: '+menuMoney(price):'Indisponível neste tamanho';
            });
            const limit=product.querySelector('[data-flavor-limit]');if(limit)limit.textContent='Escolha de 1 a '+(variant?.dataset.maxFlavors??1)+' sabores';
            const ids=Array.from(product.querySelectorAll('[data-pizza-flavor]:checked')).map(flavor=>Number(flavor.value));
            const pizza=pizzaInfo(product,ids,variant?.value);
            let displayed=product.dataset.pizza==='1'&&pizza.price!==null?pizza.price:variant?Number(variant.dataset.variantPrice):Number(product.dataset.price);
            product.querySelectorAll('[data-combo-option]:checked').forEach(option=>displayed+=Number(option.dataset.comboPrice));
            product.querySelector('.dialog-price').textContent=menuMoney(displayed);
        });
        cart.lines.forEach((line,index)=>{
            count+=line.quantity;subtotal+=line.unit*line.quantity;
            const row=document.createElement('div');row.className='bag-item';
            const copy=document.createElement('div');copy.textContent=line.quantity+' × '+line.name;
            const details=document.createElement('small');details.textContent=[line.variantName,...line.optionNames].filter(Boolean).join(' · ');copy.append(details);
            const amount=document.createElement('small');amount.textContent=menuMoney(line.unit*line.quantity);copy.append(amount);
            const actions=document.createElement('div');actions.className='bag-item-actions';
            const edit=document.createElement('button');edit.type='button';edit.className='bag-edit';edit.textContent='Editar';edit.setAttribute('aria-label','Editar '+line.name+' '+line.variantName);
            const product=products.find(product=>Number(product.dataset.productId)===line.productId);edit.disabled=!product;
            edit.addEventListener('click',()=>openProduct(product,line));
            const remove=document.createElement('button');remove.type='button';remove.className='bag-remove';remove.textContent='×';remove.setAttribute('aria-label','Remover '+line.name+' '+line.variantName);remove.addEventListener('click',()=>{cart.remove(line.id);update();});
            actions.append(edit,remove);row.append(copy,actions);lines.append(row);
            appendInput(`cart_lines[${index}][product_id]`,line.productId);appendInput(`cart_lines[${index}][quantity]`,line.quantity);
            if(line.variantId)appendInput(`cart_lines[${index}][variant_id]`,line.variantId);
            (line.flavors??[]).forEach(id=>appendInput(`cart_lines[${index}][flavors][]`,id));
            Object.entries(line.choices).forEach(([group,ids])=>ids.forEach(id=>appendInput(`cart_lines[${index}][choices][${group}][]`,id)));
            Object.entries(line.comboChoices??{}).forEach(([group,ids])=>ids.forEach(id=>appendInput(`cart_lines[${index}][combo_choices][${group}][]`,id)));
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
        product.querySelector('[data-product-open]').addEventListener('click',()=>openProduct(product));
        product.querySelector('[data-product-close]').addEventListener('click',()=>dialog.close());
        product.querySelector('[data-product-add]').addEventListener('click',()=>{
            const message=product.querySelector('[data-product-message]');
            try {
                const variant=product.querySelector('[data-product-variant]');
                if(variant&&!variant.value)throw new Error('Escolha um tamanho disponível.');
                if(product.dataset.pizza==='1'){
                    const count=product.querySelectorAll('[data-pizza-flavor]:checked').length,max=Number(variant.selectedOptions[0]?.dataset.maxFlavors??1);
                    if(count<1||count>max)throw new Error('Escolha de 1 a '+max+' sabores para este tamanho.');
                }
                product.querySelectorAll('[data-option-group]').forEach(group=>{
                    const count=group.querySelectorAll('[data-option-price]:checked').length;
                    if(count<Number(group.dataset.min)||count>Number(group.dataset.max))throw new Error(group.querySelector('legend').textContent.trim()+': confira a quantidade de escolhas.');
                });
                product.querySelectorAll('[data-combo-group]').forEach(group=>{
                    const count=group.querySelectorAll('[data-combo-option]:checked').length;
                    if(count<Number(group.dataset.min)||count>Number(group.dataset.max))throw new Error(group.querySelector('legend').textContent.trim()+': confira as escolhas do combo.');
                });
                cart.save(readLine(product),editingId);editingId=null;cartMessage.textContent='Sacola atualizada.';dialog.close();update();
            }catch(error){message.textContent=error.message;}
        });
        dialog.addEventListener('keydown',event=>{if(event.key==='Enter' && event.target.tagName!=='BUTTON'){event.preventDefault();product.querySelector('[data-product-add]').click();}});
    });
    form.querySelectorAll('[data-discover-open]').forEach(button=>button.addEventListener('click',()=>{
        openProduct(document.getElementById(button.dataset.discoverOpen).closest('[data-menu-product]'));
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
        if(!cart.lines.length){event.preventDefault();cartMessage.textContent='Adicione pelo menos um item à sacola.';cartMessage.scrollIntoView({block:'center'});return;}
        if(mode.value==='delivery' && token && !token.value){event.preventDefault();if(!addressReady&&postcodeMessage){postcodeMessage.textContent='Busque o CEP para completar o endereço antes de continuar.';postcode.focus();}else{results.textContent='Consulte o frete e confirme seu endereço antes de continuar.';(addressParts.find(field=>field.dataset.addressPart==='number')||address).focus();}}
    });
    let seed={};
    try{seed=JSON.parse(form.querySelector('[data-cart-seed]').textContent);}catch{}
    const seeded=seed.cart_lines&&typeof seed.cart_lines==='object'?Object.values(seed.cart_lines):Object.entries(seed.items??{}).filter(([,quantity])=>Number(quantity)>0).map(([id,quantity])=>({product_id:id,quantity,variant_id:seed.variants?.[id],choices:seed.choices?.[id]??{}}));
    seeded.forEach(entry=>{
        if(!entry||typeof entry!=='object')return;
        const product=products.find(product=>Number(product.dataset.productId)===Number(entry.product_id));
        const choices=Object.fromEntries(Object.entries(entry.choices&&typeof entry.choices==='object'?entry.choices:{}).filter(([,ids])=>Array.isArray(ids)));
        const comboChoices=Object.fromEntries(Object.entries(entry.combo_choices&&typeof entry.combo_choices==='object'?entry.combo_choices:{}).filter(([,ids])=>Array.isArray(ids)));
        const variant=product?Array.from(product.querySelector('[data-product-variant]')?.options??[]).find(option=>Number(option.value)===Number(entry.variant_id)):null;
        let unit=variant?Number(variant.dataset.variantPrice):Number(product?.dataset.price??0);const optionNames=[];
        const flavors=Array.isArray(entry.flavors)?entry.flavors.map(Number):[];
        const pizza=product?pizzaInfo(product,flavors,entry.variant_id):{names:[],price:null};
        if(product?.dataset.pizza==='1'&&pizza.price!==null)unit=pizza.price;
        product?.querySelectorAll('[data-combo-option]').forEach(option=>{if(comboChoices[option.dataset.comboGroupId]?.map(Number).includes(Number(option.value))){unit+=Number(option.dataset.comboPrice);optionNames.push(option.dataset.comboName);}});
        product?.querySelectorAll('[data-option-price]').forEach(option=>{if(choices[option.dataset.optionGroupId]?.map(Number).includes(Number(option.value))){unit+=Number(option.dataset.optionPrice);optionNames.push(option.dataset.optionName);}});
        try{cart.save({productId:Number(entry.product_id),quantity:Number(entry.quantity),variantId:entry.variant_id?Number(entry.variant_id):null,choices,flavors,comboChoices,name:product?.dataset.name??'Produto indisponível · remova este item',variantName:variant?.dataset.variantName??'',optionNames:[...pizza.names,...optionNames],unit});}catch(error){cartMessage.textContent=error.message;}
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
