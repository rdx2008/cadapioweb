const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const MenuCart=vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../public/menu-cart.js'),'utf8')+';MenuCart',{structuredClone});
const line=(variant,quantity=1)=>({productId:1,quantity,variantId:variant,choices:{7:[variant+10]},unit:variant*1000,name:'Pizza',optionNames:['Borda']});
test('separate combinations survive editing, cancellation and removal without sharing choices',()=>{
    const cart=new MenuCart(),small=line(1),large=line(2,2);
    const first=cart.save(small),second=cart.save(large);
    small.choices[7].push(99);assert.deepEqual(cart.lines[0].choices[7],[11]);
    const draft=structuredClone(cart.lines[1]);draft.quantity=3;draft.choices[7]=[55];
    assert.equal(cart.lines[1].quantity,2);
    cart.save(draft,second);assert.equal(cart.lines.length,2);assert.equal(cart.lines[1].quantity,3);
    assert.deepEqual(cart.lines[0].choices[7],[11]);
    const payload=cart.payload();payload[1].choices[7].push(66);assert.deepEqual(cart.lines[1].choices[7],[55]);
    cart.remove(first);assert.equal(cart.lines[0].id,second);assert.equal(cart.lines[0].variantId,2);
});
test('cart limits apply across combinations and failed edits preserve previous contents',()=>{
    const cart=new MenuCart();cart.save(line(1,25));const second=cart.save(line(2,25));
    assert.throws(()=>cart.save(line(2,26),second),/50 unidades/);assert.equal(cart.lines[1].quantity,25);
    assert.throws(()=>cart.save(line(1,0)),/inteira/);assert.throws(()=>cart.save(line(1,1.5)),/inteira/);
    assert.throws(()=>cart.save(line(1),999),/não está/);
    const many=new MenuCart();for(let i=1;i<=50;i++)many.save({...line(1),productId:i});
    assert.throws(()=>many.save({...line(1),productId:51}),/50 itens/);assert.equal(many.lines.length,50);
});
test('pizza prices use highest or equal shares rounded in cents and flavors survive item edits',()=>{
    assert.equal(MenuCart.pizzaPrice([5000,7000],'highest'),7000);
    assert.equal(MenuCart.pizzaPrice([5000,7000],'average'),6000);
    assert.equal(MenuCart.pizzaPrice([5001,7000],'average'),6001);
    assert.equal(MenuCart.pizzaPrice([5000,6000,7000],'average'),6000);
    assert.equal(MenuCart.pizzaPrice([],'highest'),null);
    const cart=new MenuCart(),id=cart.save({...line(1),flavors:[10,20]});
    const payload=cart.payload();assert.deepEqual(Array.from(payload[0].flavors),[10,20]);
    payload[0].flavors.push(30);assert.deepEqual(cart.lines[0].flavors,[10,20]);
    cart.save({...cart.lines[0],flavors:[20]},id);assert.deepEqual(Array.from(cart.payload()[0].flavors),[20]);
});
