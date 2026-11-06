document.querySelectorAll('[data-dining-live]').forEach(feed=>{
    const message=document.querySelector('[data-dining-connection]');
    let signature='',running=false,stopped=false,timer=null,controller=null;
    const editing=()=>feed.contains(document.activeElement)||Boolean(feed.querySelector('details[open]'))||[...feed.querySelectorAll('input,select,textarea')].some(field=>{
        if(field.type==='hidden'||field.type==='submit')return false;
        if(field.type==='checkbox'||field.type==='radio')return field.checked!==field.defaultChecked;
        if(field.tagName==='SELECT')return field.selectedIndex!==Math.max(0,[...field.options].findIndex(option=>option.defaultSelected));
        return field.value!==field.defaultValue;
    });
    const schedule=()=>{if(!stopped)timer=setTimeout(refresh,5000);};
    const stop=()=>{
        stopped=true;clearTimeout(timer);feed.replaceChildren();
        message.textContent='Seu acesso foi alterado ou expirou. Entre novamente para continuar.';
    };
    const refresh=async()=>{
        clearTimeout(timer);
        if(stopped||running)return;
        if(document.hidden){schedule();return;}
        running=true;controller=new AbortController();
        const timeout=setTimeout(()=>controller.abort(),10000);
        try{
            const response=await fetch(feed.dataset.url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
            if(stopped)return;
            if([401,403,404].includes(response.status)||response.redirected){stop();return;}
            if(!response.ok)throw new Error('connection');
            const data=await response.json();
            if(typeof data.html!=='string'||typeof data.signature!=='string'||typeof data.audience!=='string')throw new Error('invalid');
            if(data.audience!==feed.dataset.audience){stop();return;}
            if(editing()){message.textContent='Há novidades. Termine a edição para atualizar os pedidos.';return;}
            if(signature!==data.signature){feed.innerHTML=data.html;signature=data.signature;}
            message.textContent='Atualizado às '+new Date().toLocaleTimeString('pt-BR')+'. Novas consultas a cada 5 segundos.';
        }catch{
            if(!stopped)message.textContent='Sem atualização no momento. Vamos tentar novamente. Você pode atualizar a página.';
        }finally{clearTimeout(timeout);running=false;controller=null;schedule();}
    };
    feed.addEventListener('submit',()=>{stopped=true;clearTimeout(timer);controller?.abort();});
    window.addEventListener('pagehide',()=>{stopped=true;clearTimeout(timer);controller?.abort();});
    window.addEventListener('pageshow',event=>{if(event.persisted){stopped=false;refresh();}});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
    schedule();
});
