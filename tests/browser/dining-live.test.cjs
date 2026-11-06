const {test}=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const script=fs.readFileSync(path.join(__dirname,'../../public/dining-live.js'),'utf8');
function environment(){
    let next=0,calls=0,replacements=0,opened=false,fields=[],focused=false,reply={ok:true,status:200,redirected:false,json:async()=>({html:'ready',signature:'one',audience:'waiter'})};
    const timers=new Map(),events={},windows={},feedEvents={},message={textContent:''};
    const feed={dataset:{url:'/dining/tabs/1/live',audience:'waiter'},contains:()=>focused,querySelector:()=>opened?{}:null,querySelectorAll:()=>fields,addEventListener:(name,fn)=>feedEvents[name]=fn,replaceChildren:()=>{feed.html='';replacements++;},set innerHTML(value){this.html=value;replacements++;}};
    const document={hidden:false,activeElement:{},querySelectorAll:()=>[feed],querySelector:()=>message,addEventListener:(name,fn)=>events[name]=fn};
    vm.runInNewContext(script,{document,window:{addEventListener:(name,fn)=>windows[name]=fn},fetch:async()=>{calls++;return typeof reply==='function'?reply():reply;},AbortController,Date,setTimeout:(fn,delay)=>{timers.set(++next,{fn,delay});return next;},clearTimeout:id=>timers.delete(id)});
    return {feed,message,document,events,windows,feedEvents,setReply:value=>reply=value,setOpen:value=>opened=value,setFields:value=>fields=value,setFocus:value=>focused=value,calls:()=>calls,replacements:()=>replacements,timers,
        tick(){const entry=[...timers].find(([,timer])=>timer.delay===5000);assert.ok(entry,'refresh scheduled');timers.delete(entry[0]);return entry[1].fn();}};
}
test('polls every five seconds while visible and keeps identical content untouched',async()=>{
    const r=environment();r.document.hidden=true;await r.tick();assert.equal(r.calls(),0);
    r.document.hidden=false;await r.tick();assert.equal(r.feed.html,'ready');assert.equal(r.replacements(),1);
    await r.tick();assert.equal(r.replacements(),1);assert.match(r.message.textContent,/Atualizado/);
    r.setReply({ok:true,status:200,json:async()=>({html:'served',signature:'two',audience:'waiter'})});await r.tick();assert.equal(r.feed.html,'served');
});
test('preserves focused, expanded or dirty forms even if an update arrives in flight',async()=>{
    const r=environment();r.setOpen(true);await r.tick();assert.equal(r.replacements(),0);assert.match(r.message.textContent,/Termine a edição/);
    r.setOpen(false);r.setFields([{type:'text',value:'Ana',defaultValue:'',tagName:'INPUT'}]);await r.tick();assert.equal(r.replacements(),0);
    r.setFields([{type:'select-one',tagName:'SELECT',selectedIndex:1,options:[{defaultSelected:false},{defaultSelected:false}]}]);await r.tick();assert.equal(r.replacements(),0);
    r.setFields([]);let resolve;r.setReply(()=>new Promise(done=>resolve=done));const pending=r.tick();r.setFocus(true);resolve({ok:true,status:200,json:async()=>({html:'new',signature:'new',audience:'waiter'})});await pending;assert.equal(r.replacements(),0);
});
test('permission failure clears content and stops polling even during editing',async()=>{
    const r=environment();r.setOpen(true);r.setReply({status:403,ok:false});await r.tick();assert.equal(r.replacements(),1);assert.match(r.message.textContent,/acesso/);assert.equal(r.timers.size,0);
});
test('changed profile stops polling and connection failures retain existing screen',async()=>{
    const r=environment();await r.tick();r.setReply({ok:false,status:500});await r.tick();assert.equal(r.feed.html,'ready');assert.match(r.message.textContent,/tentar novamente/);
    r.setReply({ok:true,status:200,json:async()=>({html:'private',signature:'new',audience:'operator'})});await r.tick();assert.equal(r.feed.html,'');assert.equal(r.timers.size,0);
});
test('pagehide and form submission stop refresh and persisted navigation resumes',async()=>{
    const r=environment();r.windows.pagehide();assert.equal(r.timers.size,0);r.windows.pageshow({persisted:true});await new Promise(resolve=>setImmediate(resolve));assert.equal(r.calls(),1);
    r.feedEvents.submit();assert.equal(r.timers.size,0);
});
