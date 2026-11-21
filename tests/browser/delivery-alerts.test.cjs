const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../../public/delivery-alerts.js'), 'utf8');

function environment() {
    const timers = new Map(), events = {}, windowEvents = {}, buttonEvents = {};
    let id = 0, requests = 0, beeps = 0, data = {latest_id:1,open_count:1,items:[{id:1,ride_id:2,label:'<script>unsafe</script>',url:'/deliveries/2'}]}, status = 200;
    const node = () => ({children:[], append(child){this.children.push(child);},replaceChildren(){this.children=[];}});
    const count = node(), list = node(), message = node(), sound = {...node(),addEventListener:(name,fn)=>buttonEvents[name]=fn};
    const fields = {'[data-delivery-alert-count]':count,'[data-delivery-alert-list]':list,'[data-delivery-alert-message]':message,'[data-delivery-alert-sound]':sound};
    const panel = {dataset:{url:'/delivery-alerts/live'},querySelector:key=>fields[key]};
    const document = {hidden:false,querySelectorAll:()=>[panel],createElement:node,addEventListener:(name,fn)=>events[name]=fn};
    class Audio {
        state='running';currentTime=0;destination={};
        async resume(){} async close(){}
        createOscillator(){return {frequency:{},connect(){},start(){beeps++;},stop(){}};}
        createGain(){return {connect(){},gain:{setValueAtTime(){},exponentialRampToValueAtTime(){}}};}
    }
    vm.runInNewContext(script,{document,window:{AudioContext:Audio,addEventListener:(name,fn)=>windowEvents[name]=fn},fetch:async()=>{requests++;if(status===0)throw Error('offline');return {ok:status===200,status,json:async()=>data};},setTimeout:fn=>{timers.set(++id,fn);return id;},clearTimeout:id=>timers.delete(id)});
    const flush = async()=>{await new Promise(resolve=>setImmediate(resolve));};
    return {document,count,list,message,sound,events,windowEvents,buttonEvents,timers,flush,setData:value=>data=value,setStatus:value=>status=value,requests:()=>requests,beeps:()=>beeps,async tick(){const next=timers.entries().next().value;assert.ok(next);timers.delete(next[0]);await next[1]();await flush();}};
}

test('alerts start silently, render text safely, sound only for new incidents and reflect resolution',async()=>{
    const r=environment();await r.flush();assert.equal(r.count.textContent,'1');assert.equal(r.beeps(),0);
    assert.equal(r.list.children[0].children[0].textContent,'Entrega #2 · <script>unsafe</script>');assert.equal(r.list.children[0].children[0].innerHTML,undefined);
    await r.buttonEvents.click();r.setData({latest_id:2,open_count:2,items:[]});await r.tick();assert.equal(r.beeps(),1);
    await r.tick();assert.equal(r.beeps(),1);
    r.setData({latest_id:2,open_count:0,items:[]});await r.tick();assert.equal(r.count.textContent,'0');assert.equal(r.list.children.length,0);
});

test('alerts preserve previous data offline, pause hidden and clear private data on revoked access',async()=>{
    const r=environment();await r.flush();r.setStatus(0);await r.tick();assert.equal(r.count.textContent,'1');assert.equal(r.list.children.length,1);
    r.document.hidden=true;r.events.visibilitychange();assert.equal(r.timers.size,0);const before=r.requests();assert.equal(r.requests(),before);
    r.document.hidden=false;r.setStatus(403);r.events.visibilitychange();await r.flush();assert.equal(r.list.children.length,0);assert.equal(r.sound.disabled,true);assert.equal(r.timers.size,0);
});

test('back navigation restarts visual updates with sound disabled',async()=>{
    const r=environment();await r.flush();await r.buttonEvents.click();r.windowEvents.pagehide();assert.equal(r.timers.size,0);
    r.windowEvents.pageshow({persisted:true});await r.flush();assert.equal(r.sound.textContent,'Ativar som');assert.equal(r.timers.size,1);
    r.setData({latest_id:2,open_count:2,items:[]});await r.tick();assert.equal(r.beeps(),0);
});
