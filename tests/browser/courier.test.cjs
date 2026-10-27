const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../../public/courier.js'), 'utf8');

function environment() {
    const now = Date.now(), intervals = new Map(), timers = new Map(), events = {}, calls = [];
    const text = {}, elements = {};
    let positionCallback, errorCallback, locations = 0, timerId = 0;
    let responder = async url => ({ok:true, status:200, json:async () => url.includes('heartbeat')
        ? {presence:{renew:true,label:'Na fila',queue_position:2,queue_total:3}}
        : {html:'Updated',signature:'v1',server_time:now,presence:{renew:true,label:'Na fila',queue_position:2,queue_total:3}}});
    for (const name of ['connection','gps','alert','presence','queue','sound','offline']) {
        elements[`[data-courier-${name}]`] = {textContent:'',addEventListener:(event, callback) => {events[name+':'+event]=callback;}};
    }
    const button = {disabled:false};
    const item = {dataset:{offerId:'1',offerExpires:String(now+30000)},textContent:'',closest:()=>({querySelectorAll:()=>[button]})};
    const board = {innerHTML:'Initial',contains:()=>false,querySelector:()=>null,querySelectorAll:()=>[item],replaceChildren(){this.innerHTML='';}};
    elements['[data-courier-board]'] = board;
    const root = {dataset:{renew:'1',serverTime:String(now),liveUrl:'/courier/live',heartbeatUrl:'/courier/heartbeat'},
        querySelector:selector=>elements[selector],addEventListener:(event,callback)=>{events['root:'+event]=callback;}};
    const document = {hidden:false,activeElement:null,querySelectorAll:()=>[root],querySelector:()=>({content:'csrf'}),addEventListener:(event,callback)=>{events[event]=callback;}};
    vm.runInNewContext(script,{document, window:{addEventListener:(event,callback)=>{events[event]=callback;}},
        navigator:{geolocation:{getCurrentPosition:(success,error)=>{locations++;positionCallback=success;errorCallback=error;}}},
        fetch:async(url,options)=>{calls.push({url,options});return responder(url,options);},AbortController,Date,
        setTimeout:(callback,delay)=>{const id=++timerId;timers.set(id,{callback,delay});return id;},clearTimeout:id=>timers.delete(id),
        setInterval:(callback,delay)=>{intervals.set(delay,callback);}});
    return {document,calls,events,board,button,item,intervals,text:elements,get locations(){return locations;},
        respond(callback){responder=callback;},
        async locate(overrides={}){await positionCallback({timestamp:Date.now(),coords:{latitude:-23.55,longitude:-46.63,accuracy:10},...overrides});},
        deny(){errorCallback({code:1});},
        async poll(){const entry=[...timers].find(([,timer])=>timer.delay===5000);assert.ok(entry);timers.delete(entry[0]);await entry[1].callback();}};
}

test('courier heartbeat pauses when hidden, validates GPS and preserves server queue', async()=>{
    const runtime=environment();
    await runtime.locate();
    assert.equal(runtime.calls.length,1);
    const payload=JSON.parse(runtime.calls[0].options.body);
    assert.equal(payload.accuracy,10);
    assert.ok(payload.captured_at>0);
    assert.equal(payload.store_id,undefined);
    assert.match(runtime.text['[data-courier-queue]'].textContent,/2º de 3/);
    runtime.document.hidden=true;
    await runtime.intervals.get(30000)();await runtime.poll();
    assert.equal(runtime.calls.length,1);assert.equal(runtime.locations,1);
    runtime.document.hidden=false;
    await runtime.intervals.get(30000)();await runtime.locate({coords:{latitude:0,longitude:0,accuracy:80}});
    assert.equal(runtime.calls.length,1);
    assert.match(runtime.text['[data-courier-gps]'].textContent,/imprecisa/);
    await runtime.intervals.get(30000)();runtime.deny();
    assert.match(runtime.text['[data-courier-gps]'].textContent,/negada/);
});

test('expired availability cannot auto renew and session revocation clears courier board', async()=>{
    const runtime=environment();
    runtime.respond(async()=>({ok:false,status:409,json:async()=>({presence:{renew:false,label:'Offline',queue_position:null,queue_total:null}})}));
    await runtime.locate();
    await runtime.intervals.get(30000)();
    assert.equal(runtime.locations,1);
    assert.match(runtime.text['[data-courier-gps]'].textContent,/suspensa/);
    runtime.respond(async()=>({ok:false,status:403}));
    await runtime.poll();
    assert.equal(runtime.board.innerHTML,'');
    assert.match(runtime.text['[data-courier-connection]'].textContent,/acesso expirou/);
});

test('offer countdown disables expired actions and polling recovers after connection failure', async()=>{
    const runtime=environment();await runtime.locate();
    runtime.item.dataset.offerExpires=String(Date.now()-1000);
    runtime.intervals.get(1000)();
    assert.equal(runtime.button.disabled,true);assert.equal(runtime.item.textContent,'Oferta expirada');
    runtime.respond(async()=>{throw Error('offline');});
    await runtime.poll();
    assert.equal(runtime.board.innerHTML,'Initial');
    assert.match(runtime.text['[data-courier-connection]'].textContent,/Sem conexão/);
    runtime.respond(async()=>({ok:true,status:200,json:async()=>({html:'Recovered',signature:'v2',server_time:Date.now(),presence:{renew:false,label:'Expirada',queue_position:null,queue_total:null}})}));
    await runtime.poll();assert.equal(runtime.board.innerHTML,'Recovered');
    await runtime.intervals.get(30000)();assert.equal(runtime.locations,1);
});
