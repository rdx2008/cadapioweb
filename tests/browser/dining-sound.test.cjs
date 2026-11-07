const {test}=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const script=fs.readFileSync(path.join(__dirname,'../../public/dining-sound.js'),'utf8');
function environment({unsupported=false,failed=false,delayed=false}={}){
    let click,tones=0,closed=0,resume;
    const button={disabled:false,textContent:'',attributes:{},setAttribute(name,value){this.attributes[name]=value;},addEventListener(name,fn){click=fn;}},status={textContent:''};
    const audio={state:'running',currentTime:0,destination:{},resume:()=>failed?Promise.reject(Error('blocked')):delayed?new Promise(done=>resume=done):Promise.resolve(),close:()=>{closed++;return Promise.resolve();},createOscillator:()=>({frequency:{},connect(){},start(){tones++;},stop(){},disconnect(){}}),createGain:()=>({gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){}})};
    const document={hidden:false,querySelector:selector=>selector==='[data-dining-sound]'?button:status},window={AudioContext:unsupported?undefined:function(){return audio;}};
    vm.runInNewContext(script,{document,window});
    const sound=window.createDiningSound([1]);
    return {sound,button,status,audio,document,click:()=>click(),tones:()=>tones,closed:()=>closed,resume:()=>resume()};
}
test('silent until explicit activation, tests audio and only alerts once for new calls',async()=>{
    const r=environment();r.sound.observe([1,2]);assert.equal(r.tones(),0);
    await r.click();assert.equal(r.button.attributes['aria-pressed'],'true');assert.equal(r.tones(),2);
    r.sound.observe([1,2]);assert.equal(r.tones(),2);
    r.sound.observe([1,2,3,4]);assert.equal(r.tones(),4);
    r.sound.observe([]);r.sound.observe([3]);assert.equal(r.tones(),4);
    await r.click();r.sound.observe([5]);assert.equal(r.tones(),4);assert.equal(r.closed(),1);
});
test('does not announce hidden pages or malformed metadata',async()=>{
    const r=environment();await r.click();r.document.hidden=true;r.sound.observe([2]);r.document.hidden=false;r.sound.observe([2]);
    for(const ids of [null,{},['3'],[-1],[NaN]])r.sound.observe(ids);
    assert.equal(r.tones(),2);r.sound.observe([3]);assert.equal(r.tones(),4);
});
test('unsupported or rejected audio remains disabled with a retry message',async()=>{
    for(const options of [{unsupported:true},{failed:true}]){
        const r=environment(options);await r.click();assert.equal(r.button.disabled,false);assert.equal(r.button.attributes['aria-pressed'],'false');assert.match(r.status.textContent,/tente novamente/);assert.equal(r.tones(),0);
    }
});
test('audio paused by browser requires another gesture and does not replay backlog',async()=>{
    const r=environment();await r.click();r.audio.state='suspended';r.sound.observe([2]);assert.equal(r.button.attributes['aria-pressed'],'false');assert.match(r.status.textContent,/pausou/);
    r.audio.state='running';await r.click();r.sound.observe([2]);assert.equal(r.tones(),4);
});
test('revocation stops audio and pending activation cannot reenable it',async()=>{
    const r=environment({delayed:true});const activation=r.click();r.sound.stop(true);r.resume();await activation;r.sound.observe([2]);await r.click();
    assert.equal(r.tones(),0);assert.equal(r.button.disabled,true);assert.equal(r.button.attributes['aria-pressed'],'false');assert.equal(r.closed(),1);
});
