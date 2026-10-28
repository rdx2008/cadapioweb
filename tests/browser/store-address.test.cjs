const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const script=fs.readFileSync(require('node:path').join(__dirname,'../../public/store-address.js'),'utf8');
function element(value=''){
    return {value,dataset:{},validity:{valid:true},listeners:{},children:[],hidden:false,disabled:false,textContent:'',
        addEventListener(event,fn){(this.listeners[event]??=[]).push(fn);},
        async fire(event,extra={}){for(const fn of this.listeners[event]||[])await fn({preventDefault(){this.prevented=true;},...extra});},
        reportValidity(){return this.validity.valid;},focus(){this.focused=true;},
        replaceChildren(){this.children=[];},append(child){this.children.push(child);}};
}
function environment(fetcher,existing=false){
    const fields=Object.fromEntries(['postcode','street','number','neighborhood','city','state'].map(key=>[key,element()]));
    for(const [key,input] of Object.entries(fields))input.dataset.locationPart=key;
    const nodes=Object.fromEntries(['entry','details','message','confirmation','choices','address','postcode','locate','manual','change'].map(key=>[key,element()]));
    const latitude=element(existing?'-25.4':''),longitude=element(existing?'-49.2':'');
    if(existing)nodes.address.value='Existing address';
    const form=element();form.submissions=0;form.requestSubmit=()=>form.submissions++;
    const selectors={
        '[data-location-entry]':nodes.entry,'[data-location-details]':nodes.details,'[data-location-message]':nodes.message,
        '[data-location-confirmation]':nodes.confirmation,'[data-location-choices]':nodes.choices,
        '[name=address]':nodes.address,'[name=latitude]':latitude,'[name=longitude]':longitude,
        '[data-location-postcode]':nodes.postcode,'[data-location-locate]':nodes.locate,
        '[data-location-manual]':nodes.manual,'[data-location-change]':nodes.change};
    const section={dataset:{existing:existing?'1':'0',postcodeUrl:'/postcode',locateUrl:'/locate'},closest:()=>form,
        querySelector:selector=>selectors[selector],querySelectorAll:()=>Object.values(fields)};
    const timers=new Map();let timerId=0;
    vm.runInNewContext(script,{document:{querySelectorAll:selector=>selector==='[data-store-address]'?[section]:[],querySelector:()=>({content:'csrf'}),createElement:()=>element()},fetch:fetcher,AbortController,
        setTimeout(fn){timers.set(++timerId,fn);return timerId;},clearTimeout(id){timers.delete(id);}});
    return {fields,nodes,latitude,longitude,form,timers,
        async tick(){const tasks=[...timers.values()];timers.clear();for(const task of tasks)await task();},
        complete(){for(const [key,value] of Object.entries({postcode:'82510000',street:'Rua A',number:'72',neighborhood:'Centro',city:'Curitiba',state:'PR'}))fields[key].value=value;}};
}
const response=data=>({ok:true,status:200,redirected:false,json:async()=>data});
const place={label:'Rua A, 72, Curitiba',latitude:-25.4,longitude:-49.2};
test('postcode completes fields and locating after house number automatically fills coordinates',async()=>{
    const calls=[];
    const e=environment(async(url,options)=>{calls.push([url,JSON.parse(options.body)]);return response(url==='/postcode'?{address:{street:'Rua A',neighborhood:'Centro',city:'Curitiba',state:'PR'}}:{choices:[place]});});
    assert.equal(e.nodes.details.hidden,true);
    e.fields.postcode.value='82510000';await e.fields.postcode.fire('input');await e.tick();
    assert.equal(e.fields.street.value,'Rua A');assert.equal(e.nodes.details.hidden,false);assert.equal(e.fields.number.required,true);
    e.fields.number.value='72';await e.fields.number.fire('input');await e.nodes.locate.fire('click');
    assert.equal(Number(e.latitude.value),-25.4);assert.equal(Number(e.longitude.value),-49.2);
    assert.match(e.nodes.address.value,/Rua A, 72/);assert.equal(calls.length,2);
    let prevented=false;await e.form.fire('submit',{preventDefault(){prevented=true;}});assert.equal(prevented,false);
});
test('an address edit invalidates the point and ignores an outdated geocoder response',async()=>{
    let finish;
    const e=environment(()=>new Promise(resolve=>{finish=resolve;}));
    e.complete();await e.nodes.manual.fire('click');
    const lookup=e.nodes.locate.fire('click');
    e.fields.number.value='99';await e.fields.number.fire('input');
    finish(response({choices:[place]}));await lookup;
    assert.equal(e.latitude.value,'');assert.equal(e.longitude.value,'');assert.match(e.nodes.address.value,/, 99/);
});
test('multiple matches require explicit choice and old choice cannot restore stale coordinates',async()=>{
    const e=environment(async()=>response({choices:[place,{...place,label:'Other',latitude:-26}]}));
    e.complete();await e.nodes.manual.fire('click');await e.nodes.locate.fire('click');
    assert.equal(e.latitude.value,'');assert.equal(e.nodes.choices.children.length,2);
    const oldChoice=e.nodes.choices.children[0];await oldChoice.fire('click');assert.equal(Number(e.latitude.value),-25.4);
    e.fields.street.value='Rua B';await e.fields.street.fire('input');await oldChoice.fire('click');assert.equal(e.latitude.value,'');
});
test('postcode failure permits manual entry and submit waits for a successful location',async()=>{
    const e=environment(async url=>url==='/postcode'?{ok:false,status:422,json:async()=>({errors:{postcode:['CEP unavailable']}})}:response({choices:[place]}));
    e.fields.postcode.value='82510000';await e.nodes.postcode.fire('click');assert.match(e.nodes.message.textContent,/CEP unavailable/);
    e.complete();await e.nodes.manual.fire('click');
    let prevented=false;await e.form.fire('submit',{preventDefault(){prevented=true;}});
    assert.equal(prevented,true);assert.equal(e.form.submissions,1);assert.equal(Number(e.latitude.value),-25.4);
});
test('existing store coordinates survive unrelated edits and changing address invalidates them',async()=>{
    const e=environment(async()=>{throw Error('unexpected lookup');},true);
    assert.equal(e.nodes.entry.hidden,true);assert.equal(e.fields.postcode.disabled,true);
    let prevented=false;await e.form.fire('submit',{preventDefault(){prevented=true;}});assert.equal(prevented,false);
    await e.nodes.change.fire('click');assert.equal(e.nodes.entry.hidden,false);assert.equal(e.latitude.value,'');assert.equal(e.longitude.value,'');
});
test('manual coordinate adjustment cannot be overwritten by a pending automatic lookup',async()=>{
    let finish;const e=environment(()=>new Promise(resolve=>{finish=resolve;}));
    e.complete();await e.nodes.manual.fire('click');const lookup=e.nodes.locate.fire('click');
    e.latitude.value='-24';e.longitude.value='-48';await e.latitude.fire('input');
    finish(response({choices:[place]}));await lookup;assert.equal(e.latitude.value,'-24');assert.equal(e.longitude.value,'-48');
});