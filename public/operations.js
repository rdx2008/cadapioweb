(() => {
    let audio;
    document.querySelectorAll('[data-ops-fullscreen]').forEach(button=>button.addEventListener('click',async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else await document.documentElement.requestFullscreen();}catch{button.textContent='Tela cheia indisponível';}}));
    document.querySelectorAll('[data-ops-sound]').forEach(button=>button.addEventListener('click',async()=>{try{const Audio=window.AudioContext||window.webkitAudioContext;audio=audio||new Audio();await audio.resume();button.textContent='Som ativado';}catch{button.textContent='Som indisponível';}}));
    const beep=()=>{if(!audio||audio.state!=='running')return;const oscillator=audio.createOscillator(),gain=audio.createGain();oscillator.frequency.value=740;gain.gain.value=.04;oscillator.connect(gain);gain.connect(audio.destination);oscillator.start();oscillator.stop(audio.currentTime+.2);};
    const globalMessage=text=>document.querySelectorAll('[data-ops-global-status]').forEach(node=>node.textContent=text);
    const read=async response=>{const type=response.headers.get('Content-Type')||'';if(!type.includes('application/json'))throw new Error(response.redirected?'A sessão precisa ser renovada. Entre novamente.':'Não foi possível atualizar. Recarregue a página.');const body=await response.json();if(!response.ok){const error=new Error(Object.values(body.errors||{}).flat().join(' ')||body.message||'A operação não pôde ser concluída.');error.status=response.status;throw error;}return body;};
    document.querySelectorAll('[data-ops-root]').forEach(root=>{
        const feed=root.querySelector('[data-ops-feed]'),message=root.querySelector('[data-ops-message]');
        let signature='',timer,running=false,mutating=false,dragged=null,paused=false,lastIds=null;
        const blocked=()=>feed.querySelector('[data-ops-dirty]')||feed.querySelector('details[open]')||feed.contains(document.activeElement)&&document.activeElement.matches('input,select,textarea')||dragged||mutating;
        const dirty=form=>{const changed=[...form.elements].some(field=>field.matches('select,input:not([type=hidden])')&&(field.tagName==='SELECT'?[...field.options].some(option=>option.selected!==option.defaultSelected):field.value!==field.defaultValue));form.toggleAttribute('data-ops-dirty',changed);};
        const refresh=async(force=false)=>{
            clearTimeout(timer);if(running||paused)return;if(document.hidden){timer=setTimeout(refresh,5000);return;}
            if(blocked()&&!force){message.textContent='Atualização pausada enquanto você confere esta ação.';timer=setTimeout(refresh,5000);return;}
            running=true;
            try{const body=await read(await fetch(feed.dataset.url,{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'}));
                if(blocked()&&!force)return;
                if(body.signature!==signature){feed.innerHTML=body.html;feed.querySelectorAll('[data-ops-positions]').forEach(node=>node.dataset.receivedAt=performance.now());signature=body.signature;const ids=new Set([...feed.querySelectorAll('[data-order-id]')].map(node=>node.dataset.orderId));if(lastIds&&[...ids].some(id=>!lastIds.has(id)))beep();lastIds=ids;}
                feed.classList.remove('ops-offline');message.textContent='Atualizado às '+new Date().toLocaleTimeString('pt-BR');globalMessage('● Atualizado agora');
            }catch(error){message.textContent=error.message;globalMessage('● Sem atualização');feed.classList.add('ops-offline');if([401,403,419].includes(error.status)||error.message.includes('sessão')){paused=true;feed.replaceChildren(Object.assign(document.createElement('p'),{textContent:'Acesso indisponível. Recarregue e confira sua sessão e permissões.'}));}}
            finally{running=false;if(!paused)timer=setTimeout(refresh,5000);}
        };
        root.addEventListener('change',event=>{const form=event.target.closest('form[data-ops-action]');if(form)dirty(form);});
        root.addEventListener('input',event=>{const form=event.target.closest('form[data-ops-action]');if(form)dirty(form);});
        root.addEventListener('submit',async event=>{
            const form=event.target.closest('form[data-ops-action]');if(!form)return;event.preventDefault();if(mutating)return;
            mutating=true;clearTimeout(timer);const button=form.querySelector('button[type=submit],button:not([type])'),previous=button?.disabled;const payload=new FormData(form);if(button)button.disabled=true;
            try{const body=await read(await fetch(form.action,{method:'POST',body:payload,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'}));form.removeAttribute('data-ops-dirty');document.activeElement?.blur();mutating=false;await refresh(true);message.textContent=body.message||'Operação concluída.';}
            catch(error){message.textContent=error.message;if(error.status===409){form.removeAttribute('data-ops-dirty');document.activeElement?.blur();mutating=false;await refresh(true);message.textContent=error.message+' Confira a situação atual antes de tentar novamente.';}}
            finally{mutating=false;if(button&&button.isConnected)button.disabled=previous;clearTimeout(timer);timer=setTimeout(refresh,5000);}
        });
        root.addEventListener('dragstart',event=>{const card=event.target.closest('[draggable=true]');if(!card)return;const form=card.querySelector('[data-ops-action]');if(!form||form.querySelector('button').disabled){event.preventDefault();return;}dragged=card;card.classList.add('is-dragging');event.dataTransfer.setData('text/plain',card.dataset.orderId);event.dataTransfer.effectAllowed='move';});
        root.addEventListener('dragover',event=>{const lane=event.target.closest('[data-ops-lane]');if(dragged&&lane&&dragged.querySelector('[name=status]')?.value===lane.dataset.opsLane){event.preventDefault();lane.classList.add('is-drop-target');}});
        root.addEventListener('dragleave',event=>{const lane=event.target.closest('[data-ops-lane]');if(lane&&!lane.contains(event.relatedTarget))lane.classList.remove('is-drop-target');});
        root.addEventListener('drop',event=>{const lane=event.target.closest('[data-ops-lane]'),form=dragged?.querySelector('[data-ops-action]');event.preventDefault();if(lane&&form?.querySelector('[name=status]')?.value===lane.dataset.opsLane){form.requestSubmit();}root.querySelectorAll('.is-drop-target').forEach(node=>node.classList.remove('is-drop-target'));});
        root.addEventListener('dragend',()=>{dragged?.classList.remove('is-dragging');dragged=null;root.querySelectorAll('.is-drop-target').forEach(node=>node.classList.remove('is-drop-target'));});
        document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
        window.addEventListener('pagehide',()=>clearTimeout(timer));window.addEventListener('pageshow',event=>{if(event.persisted)refresh();});
        feed.querySelectorAll('[data-ops-positions]').forEach(node=>node.dataset.receivedAt=performance.now());
        timer=setTimeout(refresh,feed.children.length?1000:0);
    });
    const clocks=new WeakMap();setInterval(()=>{if(document.hidden)return;document.querySelectorAll('[data-ready-at]').forEach(node=>{const stamp=Number(node.dataset.readyAt);if(!stamp)return;if(!clocks.has(node))clocks.set(node,{now:Number(node.dataset.clockNow),local:performance.now()});const clock=clocks.get(node);node.textContent=`Pronto há ${Math.floor(Math.max(0,clock.now+(performance.now()-clock.local)/1000-stamp)/60)} min`;});},15000);
})();
