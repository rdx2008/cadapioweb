const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname,'../../public/store-settings.js'),'utf8');

function environment(errors=[],origin='') {
    const events={}, frames=[];
    const sections=['dados','endereco','pagamentos'].map((id,index)=>({id,top:index*600,scrollIntoView(){this.scrolled=true;},getBoundingClientRect(){return {top:this.top};}}));
    const links=sections.map(section=>({hash:'#'+section.id,attributes:{},badge:{hidden:true},events:{},querySelector(){return this.badge;},setAttribute(key,value){this.attributes[key]=value;},removeAttribute(key){delete this.attributes[key];},addEventListener(key,fn){this.events[key]=fn;}}));
    const forms=sections.map(section=>({events:{},status:{},closest(){return section;},querySelector(){return this.status;},addEventListener(key,fn){this.events[key]=fn;}}));
    const details={tagName:'DETAILS',open:false};
    const root={dataset:{settingsErrors:JSON.stringify(errors),settingsOrigin:origin},querySelectorAll(selector){return selector==='[data-settings-section]'?sections:selector==='[data-settings-link]'?links:selector==='form'?forms:fields;}};
    details.parentElement=root;
    const fields=[{name:'hours[1][opens_at]',parentElement:details,attributes:{},setAttribute(key,value){this.attributes[key]=value;},closest(){return sections[1];}}];
    let accepted=false,confirms=0;
    const window={innerWidth:1366,addEventListener(key,fn){events[key]=fn;},confirm(){confirms++;return accepted;}};
    vm.runInNewContext(script,{document:{querySelectorAll:()=>[root]},window,requestAnimationFrame:fn=>frames.push(fn)});
    return {sections,links,forms,fields,details,events,window,frames,confirms:()=>confirms,accept:()=>accepted=true};
}

test('settings navigation follows visible sections without hiding forms',()=>{
    const r=environment();assert.equal(r.links[0].attributes['aria-current'],'location');
    r.sections[1].top=20;r.events.scroll();assert.equal(r.frames.length,1);r.frames.shift()();assert.equal(r.links[1].attributes['aria-current'],'location');
    assert.equal(r.links[0].attributes['aria-current'],undefined);
    r.links[2].events.click();assert.equal(r.links[2].attributes['aria-current'],'location');
});

test('unsaved edits survive a cancelled cross-section submit and warn on leaving',()=>{
    const r=environment();r.forms[0].events.input();assert.equal(r.links[0].badge.hidden,false);
    const submit={defaultPrevented:false,preventDefault(){this.defaultPrevented=true;}};r.forms[1].events.submit(submit);assert.equal(submit.defaultPrevented,true);assert.equal(r.confirms(),1);
    const leave={preventDefault(){this.prevented=true;}};r.events.beforeunload(leave);assert.equal(leave.prevented,true);
    r.accept();r.forms[1].events.submit({defaultPrevented:false,preventDefault(){}});
    const clean={preventDefault(){this.prevented=true;}};r.events.beforeunload(clean);assert.equal(clean.prevented,undefined);
});

test('address lookup intercepting submit does not discard unsaved edits',()=>{
    const r=environment();r.forms[1].events.change();r.forms[1].events.submit({defaultPrevented:true});
    const leave={preventDefault(){this.prevented=true;}};r.events.beforeunload(leave);assert.equal(leave.prevented,true);assert.equal(r.confirms(),0);
});

test('validation expands the relevant form and navigates to its section',()=>{
    const r=environment(['hours.1.opens_at'],'endereco');assert.equal(r.fields[0].attributes['aria-invalid'],'true');assert.equal(r.details.open,true);assert.equal(r.sections[1].scrolled,true);
    assert.equal(r.links[1].attributes['aria-current'],'location');
});
