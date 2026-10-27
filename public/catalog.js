document.querySelectorAll('[data-catalog-filters]').forEach(form=>{
    form.querySelector('[name=store_id]').addEventListener('change',()=>{form.querySelector('[name=category_id]').value='';});
});
document.querySelectorAll('[data-catalog-create]').forEach(form=>{
    const pricing=form.querySelector('[data-catalog-pricing]'), variants=form.querySelector('[data-catalog-variants]');
    const rows=form.querySelector('[data-variant-rows]');
    let nextIndex=Math.max(-1,...Array.from(rows.querySelectorAll('input[name$="[name]"]')).map(field=>Number(field.name.match(/\[(\d+)\]/)[1])))+1;
    const update=()=>{
        const enabled=pricing.value==='1';
        variants.hidden=!enabled;
        variants.querySelectorAll('input,button').forEach(field=>field.disabled=!enabled);
        const price=form.querySelector('[name=price]');price.disabled=enabled;price.required=!enabled;
        form.querySelector('[data-single-price]').hidden=enabled;
    };
    form.querySelector('[data-add-variant]').addEventListener('click',()=>{
        if(rows.querySelectorAll('[data-variant-row]').length>=20)return;
        const template=document.getElementById('catalog-variant-row');
        rows.insertAdjacentHTML('beforeend',template.innerHTML.replaceAll('__INDEX__',String(nextIndex++)));update();
    });
    rows.addEventListener('click',event=>{if(event.target.matches('[data-remove-variant]'))event.target.closest('[data-variant-row]').remove();});
    pricing.addEventListener('change',update);update();
    let photoUrl;
    form.querySelector('[data-catalog-photo]').addEventListener('change',event=>{
        if(photoUrl)URL.revokeObjectURL(photoUrl);
        const preview=form.querySelector('[data-catalog-photo-preview]'),file=event.target.files[0];preview.hidden=true;
        if(file&&['image/jpeg','image/png','image/webp'].includes(file.type)&&file.size<=2097152){photoUrl=URL.createObjectURL(file);preview.src=photoUrl;preview.hidden=false;}
    });
});
