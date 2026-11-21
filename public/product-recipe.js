document.querySelectorAll('[data-recipe-form]').forEach(form=>{
    const lines=form.querySelector('[data-recipe-lines]'),template=form.querySelector('[data-recipe-template]'),status=form.querySelector('[data-recipe-status]');
    let index=Math.max(-1,...[...lines.querySelectorAll('select')].map(select=>Number(select.name.match(/lines\[(\d+)\]/)?.[1]??-1)))+1;
    form.querySelector('[data-recipe-add]').addEventListener('click',()=>{
        if(lines.children.length>=50){status.textContent='Limite de 50 insumos por composição.';return;}
        const row=template.content.cloneNode(true);
        row.querySelectorAll('[name]').forEach(field=>field.name=field.name.replace('__INDEX__',String(index)));
        index++;lines.append(row);lines.lastElementChild.querySelector('select').focus();status.textContent='Linha adicionada. Escolha o insumo e informe a quantidade.';
    });
    lines.addEventListener('click',event=>{
        const button=event.target.closest('[data-recipe-remove]');if(!button)return;
        button.closest('[data-recipe-line]').remove();form.querySelector('[data-recipe-add]').focus();status.textContent='Linha removida. Salve uma nova versão para confirmar.';
    });
});
