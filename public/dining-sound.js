window.createDiningSound=(initialIds=[])=>{
    const button=document.querySelector('[data-dining-sound]'),status=document.querySelector('[data-dining-sound-status]');
    if(!button||!status)return null;
    const seen=new Set(initialIds);
    let audio=null,enabled=false,busy=false,blocked=false,generation=0;
    const label=text=>{button.textContent=enabled?'Desligar som das mesas':'Ativar som das mesas';button.setAttribute('aria-pressed',String(enabled));status.textContent=text;};
    const tone=()=>{
        if(!audio||audio.state!=='running')throw new Error('audio unavailable');
        const start=audio.currentTime;
        [0,.22].forEach((delay)=>{
            const oscillator=audio.createOscillator(),gain=audio.createGain();
            oscillator.type='sine';oscillator.frequency.value=740;
            gain.gain.setValueAtTime(0,start+delay);gain.gain.linearRampToValueAtTime(.14,start+delay+.02);gain.gain.exponentialRampToValueAtTime(.001,start+delay+.16);
            oscillator.connect(gain);gain.connect(audio.destination);oscillator.start(start+delay);oscillator.stop(start+delay+.18);
            oscillator.onended=()=>{oscillator.disconnect();gain.disconnect();};
        });
    };
    const stop=(permanent=false)=>{
        generation++;enabled=false;blocked=permanent;button.disabled=permanent;
        audio?.close().catch(()=>{});audio=null;
        label(permanent?'Som encerrado. Entre novamente para continuar.':'Som desligado. Ative novamente para ouvir novos chamados.');
    };
    button.addEventListener('click',async()=>{
        if(busy||blocked)return;
        if(enabled){stop();return;}
        const current=++generation;busy=true;button.disabled=true;
        try{
            const Audio=window.AudioContext||window.webkitAudioContext;
            if(!Audio)throw new Error('unsupported');
            audio=new Audio();await audio.resume();
            if(current!==generation||blocked)return;
            enabled=true;tone();label('Som ligado para novos chamados nesta tela.');
        }catch{
            if(current===generation){stop();label('Não foi possível ativar o som. Confira o áudio do aparelho e tente novamente.');}
        }finally{busy=false;button.disabled=blocked;}
    });
    return {
        observe(ids){
            if(!Array.isArray(ids)||ids.some(id=>!Number.isSafeInteger(id)||id<1))return;
            const fresh=ids.some(id=>!seen.has(id));ids.forEach(id=>seen.add(id));
            if(!fresh||!enabled||blocked||document.hidden)return;
            try{tone();label('Novo chamado de mesa. Confira a fila de solicitações.');}
            catch{stop();label('O navegador pausou o áudio. Ative o som novamente.');}
        },
        stop,
    };
};
