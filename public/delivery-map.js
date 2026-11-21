document.querySelectorAll('[data-delivery-map]').forEach(root => {
    const data = JSON.parse(root.querySelector('[data-map-data]').textContent);
    const canvas = root.querySelector('[data-map-canvas]'), tiles = root.querySelector('[data-map-tiles]'), svg = root.querySelector('svg');
    const form = root.querySelector('[data-area-form]'), status = root.querySelector('[data-map-status]');
    const field = name => form.elements.namedItem(name);
    const validPoint = p => Array.isArray(p) && p.length === 2 && p.every(v => typeof v === 'number' && Number.isFinite(v));
    const ready = data.center.every(v => v !== null && Number.isFinite(Number(v))) && Math.abs(Number(data.center[0])) <= 85;
    const store = data.center.map(Number), namespace = 'http://www.w3.org/2000/svg';
    let center = [...store], zoom = 13, width = 0, height = 0, points = [], closed = false, tool = 'pan', editing = null, dirty = false, drag = null, marker = null, scheduled = false;
    const images = new Map();
    const project = (p, z = zoom) => {
        const size = 256 * 2 ** z, latitude = Math.max(-85, Math.min(85, p[0])) * Math.PI / 180;
        return [(p[1] + 180) / 360 * size, (1 - Math.log(Math.tan(latitude) + 1 / Math.cos(latitude)) / Math.PI) / 2 * size];
    };
    const unproject = ([x,y]) => {
        const size = 256 * 2 ** zoom;
        return [Math.max(-85, Math.min(85, Math.atan(Math.sinh(Math.PI * (1 - 2 * y / size))) * 180 / Math.PI)), Math.max(-180, Math.min(180, x / size * 360 - 180))];
    };
    const screen = p => { const a = project(p), c = project(center); return [a[0] - c[0] + width / 2, a[1] - c[1] + height / 2]; };
    const position = event => { const r = canvas.getBoundingClientRect(); return [event.clientX - r.left, event.clientY - r.top]; };
    const coordinate = xy => { const c = project(center); return unproject([xy[0] + c[0] - width / 2, xy[1] + c[1] - height / 2]).map(v => Number(v.toFixed(6))); };
    const node = (tag, attrs, text) => { const element = document.createElementNS(namespace, tag); Object.entries(attrs).forEach(([key,value]) => element.setAttribute(key, String(value))); if (text) element.textContent = text; return element; };
    const announce = text => { status.textContent = text; };
    const sync = () => {
        field('polygon').value = JSON.stringify(points);
        root.querySelector('[data-map-save]').disabled = !ready || !closed || points.length < 3;
        root.querySelectorAll('[data-map-tool]').forEach(button => button.setAttribute('aria-pressed', String(tool === button.dataset.mapTool)));
        canvas.classList.toggle('is-drawing', tool === 'draw');
        root.querySelector('[data-map-instruction]').textContent = tool === 'draw' ? 'Clique nos limites e depois no primeiro ponto para fechar.' : closed ? 'Arraste os pontos para ajustar. Arraste o fundo para navegar.' : 'Clique em Nova área para começar.';
        render();
    };
    const shape = (polygon, color, opacity, extra = {}) => node('polygon', {points:polygon.map(p => screen(p).join(',')).join(' '),fill:color,'fill-opacity':opacity,stroke:color,'stroke-width':2.5,...extra});
    const render = () => {
        if (scheduled || !ready) return;
        scheduled = true;
        requestAnimationFrame(() => {
            scheduled = false; width = canvas.clientWidth; height = canvas.clientHeight;
            if (!width || !height) return;
            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            const c = project(center), left = c[0] - width / 2, top = c[1] - height / 2, used = new Set();
            for (let x = Math.floor(left / 256); x <= Math.floor((left + width) / 256); x++) {
                for (let y = Math.floor(top / 256); y <= Math.floor((top + height) / 256); y++) {
                    if (x < 0 || y < 0 || x >= 2 ** zoom || y >= 2 ** zoom) continue;
                    const key = `${zoom}/${x}/${y}`; used.add(key);
                    let image = images.get(key);
                    if (!image) {
                        image = new Image(); image.alt = ''; image.draggable = false; image.referrerPolicy = 'strict-origin-when-cross-origin';
                        image.onerror = () => announce('Não foi possível carregar parte do mapa. Confira a conexão; seus limites continuam preservados.');
                        image.src = data.tiles.replace('{z}', zoom).replace('{x}', x).replace('{y}', y);
                        images.set(key,image); tiles.append(image);
                    }
                    image.style.left = `${x * 256 - left}px`; image.style.top = `${y * 256 - top}px`;
                }
            }
            images.forEach((image,key) => { if (!used.has(key)) { image.remove(); images.delete(key); } });
            const elements = [];
            data.areas.forEach(area => { if (String(area.id) !== String(editing)) elements.push(shape(area.polygon, area.color, area.enabled ? .16 : .05, {'stroke-dasharray':area.enabled?'':'5 5','data-area':area.id})); });
            if (points.length) {
                elements.push(closed ? shape(points,field('color').value,.23) : node('polyline',{points:points.map(p=>screen(p).join(',')).join(' '),fill:'none',stroke:field('color').value,'stroke-width':3}));
                points.forEach((point,index) => { const [x,y] = screen(point); elements.push(node('circle',{cx:x,cy:y,r:9,fill:'white',stroke:field('color').value,'stroke-width':3,'data-point':index,style:'cursor:move'})); });
            }
            const [sx,sy] = screen(store); elements.push(node('circle',{cx:sx,cy:sy,r:11,fill:'#ff623f',stroke:'white','stroke-width':3}),node('text',{x:sx+16,y:sy+5,fill:'#243047','font-size':13,'font-weight':700,'paint-order':'stroke',stroke:'white','stroke-width':4},'Sua loja'));
            if (marker) { const [x,y] = screen(marker); elements.push(node('circle',{cx:x,cy:y,r:9,fill:'#137e54',stroke:'white','stroke-width':3}),node('text',{x:x+14,y:y-8,fill:'#137e54','font-size':12,'paint-order':'stroke',stroke:'white','stroke-width':4},'Endereço testado')); }
            svg.replaceChildren(...elements);
        });
    };
    const abandon = () => !dirty || window.confirm('Descartar as alterações desta área que ainda não foram salvas?');
    const fit = polygon => {
        if (!polygon.length) {center=[...store];zoom=13;render();return;}
        zoom=17;
        while (zoom>3) { const p=polygon.map(v=>project(v)); if (Math.max(...p.map(v=>v[0]))-Math.min(...p.map(v=>v[0]))<canvas.clientWidth*.7 && Math.max(...p.map(v=>v[1]))-Math.min(...p.map(v=>v[1]))<canvas.clientHeight*.7) break; zoom--; }
        const p=polygon.map(v=>project(v));center=unproject([(Math.min(...p.map(v=>v[0]))+Math.max(...p.map(v=>v[0])))/2,(Math.min(...p.map(v=>v[1]))+Math.max(...p.map(v=>v[1])))/2]);render();
    };
    const load = area => {
        if (!abandon()) return;
        form.reset();editing=area?.id??null;points=Array.isArray(area?.polygon)?area.polygon.filter(validPoint).map(p=>[...p]):[];closed=points.length>=3;tool=area?'edit':'draw';dirty=false;
        field('area_id').value=editing??'';field('revision').value=area?.revision??0;field('name').value=area?.name??'';
        field('fee').value=area?.fee??(area?Number(area.fee_cents/100).toFixed(2).replace('.',','):'5,00');field('enabled').value=area?.enabled===false||area?.enabled==='0'?'0':'1';field('color').value=area?.color??'#ff623f';
        root.querySelector('[data-map-form-title]').textContent=editing?'Editar área':'Configurar nova área';
        fit(points);sync();announce(area?'Confira o contorno e salve as alterações.':'Clique no mapa para adicionar os pontos da nova área.');
    };
    const finish = () => { if (points.length<3) {announce('Marque pelo menos três pontos para fechar a área.');return;}closed=true;tool='edit';dirty=true;sync();announce('Contorno fechado. Informe nome e frete e salve a área.'); };
    root.querySelector('[data-map-new]').addEventListener('click',()=>load(null));
    root.querySelector('[data-map-cancel]').addEventListener('click',()=>{if(!abandon())return;dirty=false;editing=null;points=[];closed=false;tool='pan';form.reset();sync();announce('Edição cancelada. As áreas salvas permanecem no mapa.');});
    root.querySelectorAll('[data-map-edit]').forEach(button=>button.addEventListener('click',()=>{load(data.areas.find(a=>String(a.id)===button.dataset.mapEdit));canvas.scrollIntoView({block:'center',behavior:'smooth'});}));
    root.querySelectorAll('[data-map-tool]').forEach(button=>button.addEventListener('click',()=>{if(button.dataset.mapTool==='draw'&&closed){announce('Use Nova área para criar outro contorno, ou Editar pontos para ajustar este.');return;}tool=button.dataset.mapTool;sync();}));
    root.querySelector('[data-map-close]').addEventListener('click',finish);
    root.querySelector('[data-map-undo]').addEventListener('click',()=>{if(!points.length)return;points.pop();closed=false;tool='draw';dirty=true;sync();announce('Último ponto removido.');});
    root.querySelectorAll('[data-map-zoom]').forEach(button=>button.addEventListener('click',()=>{zoom=Math.max(3,Math.min(18,zoom+Number(button.dataset.mapZoom)));render();}));
    root.querySelector('[data-map-home]').addEventListener('click',()=>{center=[...store];zoom=13;render();});
    canvas.addEventListener('pointerdown',event=>{
        if (!ready || event.target.closest('button,a') || event.button!==0) return;
        const index=event.target.getAttribute('data-point');drag={start:position(event),center:project(center),index:index===null?null:Number(index),area:event.target.getAttribute('data-area'),moved:false};canvas.setPointerCapture(event.pointerId);
    });
    canvas.addEventListener('pointermove',event=>{
        if (!drag) return;const xy=position(event),dx=xy[0]-drag.start[0],dy=xy[1]-drag.start[1];if(Math.abs(dx)+Math.abs(dy)>5)drag.moved=true;
        if(!drag.moved)return;
        if(drag.index!==null&&tool==='edit'){points[drag.index]=coordinate(xy);dirty=true;sync();}
        else {center=unproject([drag.center[0]-dx,drag.center[1]-dy]);render();}
    });
    canvas.addEventListener('pointerup',event=>{
        if(!drag)return;const previous=drag;drag=null;
        if(canvas.hasPointerCapture(event.pointerId))canvas.releasePointerCapture(event.pointerId);
        if(previous.moved)return;
        if(tool==='draw'&&!closed){if(previous.index===0&&points.length>=3){finish();return;}if(points.length>=64){announce('Limite de 64 pontos. Feche o contorno ou desfaça o último ponto.');return;}points.push(coordinate(position(event)));dirty=true;sync();announce(`${points.length} pontos marcados. Clique no primeiro ponto para fechar.`);}
        else if(previous.area){load(data.areas.find(a=>String(a.id)===previous.area));}
    });
    canvas.addEventListener('pointercancel',()=>{drag=null;});
    canvas.addEventListener('keydown',event=>{
        if(event.target!==canvas)return;const delta={ArrowLeft:[-80,0],ArrowRight:[80,0],ArrowUp:[0,-80],ArrowDown:[0,80]}[event.key];
        if(delta){event.preventDefault();const c=project(center);center=unproject([c[0]+delta[0],c[1]+delta[1]]);render();}
        if(event.key==='Enter'&&tool==='draw'){event.preventDefault();finish();}
        if(event.key===' '&&tool==='draw'&&!closed){event.preventDefault();if(points.length<64){points.push(coordinate([width/2,height/2]));dirty=true;sync();announce(`${points.length} pontos marcados. Use setas para mover o mapa ou Enter para fechar.`);}}
        if(event.key==='+'||event.key==='-'){event.preventDefault();zoom=Math.max(3,Math.min(18,zoom+(event.key==='+'?1:-1)));render();}
    });
    form.addEventListener('input',()=>{dirty=true;sync();});form.addEventListener('change',()=>{dirty=true;sync();});
    form.addEventListener('submit',event=>{if(!closed||points.length<3){event.preventDefault();announce('Feche o contorno antes de salvar.');}else{dirty=false;field('polygon').value=JSON.stringify(points);}});
    root.querySelectorAll('form:not([data-area-form]):not([data-map-test])').forEach(other=>other.addEventListener('submit',event=>{if(!abandon())event.preventDefault();else dirty=false;}));
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
    const test=root.querySelector('[data-map-test]'), output=root.querySelector('[data-map-test-result]');let testVersion=0;
    test.addEventListener('input',()=>{testVersion++;output.replaceChildren();marker=null;render();});
    test.addEventListener('submit',async event=>{
        event.preventDefault();const version=++testVersion,button=test.querySelector('button');button.disabled=true;output.textContent='Consultando endereço…';
        try {const response=await fetch(test.action,{method:'POST',body:new FormData(test),headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});const body=await response.json();if(version!==testVersion)return;
            if(!response.ok)throw new Error(Object.values(body.errors??{}).flat().join(' ')||'Não foi possível consultar o endereço.');
            output.replaceChildren();body.results.forEach(result=>{const p=document.createElement('p');p.className='map-test-result'+(result.available?'':' is-outside');p.textContent=result.label+' — '+(result.available?`${result.area} · Frete ${(result.fee_cents/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}`:'Fora das áreas ativas salvas');output.append(p);});
            if(body.results.length===1){marker=[body.results[0].latitude,body.results[0].longitude];center=[...marker];render();}
        }catch(error){if(version===testVersion)output.textContent=error.message;}finally{button.disabled=false;}
    });
    if(!ready){announce('Salve o endereço e a localização da loja nas configurações antes de desenhar as áreas.');root.querySelectorAll('.map-tools button,.map-zoom button').forEach(button=>button.disabled=true);return;}
    new ResizeObserver(render).observe(canvas);
    if(data.old){load(data.old);dirty=true;}else{render();announce('Mapa pronto. Clique em Nova área para desenhar os limites.');}
});
