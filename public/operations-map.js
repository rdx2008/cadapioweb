(() => {
    let dialog, expiry;
    document.querySelector('[data-ops-map-open]')?.addEventListener('click',()=>{
        const element=document.querySelector('[data-ops-positions]');if(!element)return;const data=JSON.parse(element.textContent);
        if(!dialog){dialog=document.createElement('dialog');dialog.className='ops-dialog';dialog.innerHTML='<div class="ops-page-title"><h2>Localização dos motoboys</h2><button type="button" class="secondary">Fechar</button></div><p data-map-description></p><div class="ops-map" data-map-body></div>';dialog.querySelector('button').addEventListener('click',()=>dialog.close());document.body.append(dialog);}
        const target=dialog.querySelector('[data-map-body]');target.replaceChildren();
        clearTimeout(expiry);
        const elapsed=(performance.now()-Number(element.dataset.receivedAt||performance.now()))/1000;const drivers=data.drivers.filter(driver=>driver.remaining_seconds>elapsed);
        dialog.querySelector('[data-map-description]').textContent=drivers.length?`${drivers.length} posição(ões) recente(s) e com precisão disponível. A posição pode mudar; confira o horário no painel.`:'Nenhuma posição recente disponível. Não exibimos pontos antigos como localização atual.';
        dialog.showModal();if(!data.store)return;
        const zoom=13,size=256*2**zoom,width=target.clientWidth,height=target.clientHeight;
        const project=p=>{const lat=Math.max(-85,Math.min(85,p.latitude))*Math.PI/180;return [(p.longitude+180)/360*size,(1-Math.log(Math.tan(lat)+1/Math.cos(lat))/Math.PI)/2*size];};
        const center=project(drivers[0]||data.store),left=center[0]-width/2,top=center[1]-height/2;
        for(let x=Math.floor(left/256);x<=Math.floor((left+width)/256);x++){for(let y=Math.floor(top/256);y<=Math.floor((top+height)/256);y++){if(x<0||y<0||x>=2**zoom||y>=2**zoom)continue;const image=new Image();image.alt='';image.referrerPolicy='strict-origin-when-cross-origin';image.src=data.tiles.replace('{z}',zoom).replace('{x}',x).replace('{y}',y);image.style.left=`${x*256-left}px`;image.style.top=`${y*256-top}px`;target.append(image);}}
        const ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox',`0 0 ${width} ${height}`);svg.setAttribute('aria-label','Posições recentes dos motoboys');
        [data.store,...drivers].forEach((point,i)=>{const p=project(point),x=p[0]-left,y=p[1]-top,circle=document.createElementNS(ns,'circle'),text=document.createElementNS(ns,'text');Object.entries({cx:x,cy:y,r:9,fill:i?'#218a46':'#ff623f',stroke:'white','stroke-width':3}).forEach(([k,v])=>circle.setAttribute(k,v));Object.entries({x:x+13,y:y-10,fill:'#172139','font-size':12,'paint-order':'stroke',stroke:'white','stroke-width':3}).forEach(([k,v])=>text.setAttribute(k,v));text.textContent=point.name;svg.append(circle,text);if(i)setTimeout(()=>{circle.remove();text.remove();},Math.max(0,point.remaining_seconds-elapsed)*1000);});target.append(svg);
        if(drivers.length)expiry=setTimeout(()=>{dialog.querySelector('[data-map-description]').textContent='As posições expiraram. Feche e abra o mapa para consultar novamente.';},Math.max(...drivers.map(driver=>driver.remaining_seconds-elapsed))*1000);
        const credit=document.createElement('a');credit.className='ops-map-credit';credit.href='https://www.openstreetmap.org/copyright';credit.target='_blank';credit.rel='noopener';credit.textContent='© OpenStreetMap contributors';target.append(credit);
    });
})();
