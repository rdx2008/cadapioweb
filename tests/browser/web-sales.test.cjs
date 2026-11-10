const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../../public/app.js'), 'utf8');

test('staff feed preserves open actions, pauses when hidden and clears data on revoked access', async () => {
    const status = {};
    let editing = false, revoked = false, calls = 0;
    const feed = {dataset: {url: '/operation/live'}, innerHTML: 'Initial', contains: () => false,
        querySelector: () => editing ? {} : null, replaceChildren() {this.innerHTML = '';}};
    const runtime = environment({'[data-staff-feed]': [feed]}, {'[data-staff-connection]': status}, async () => {
        calls++;
        return {ok: !revoked, status: revoked ? 403 : 200, redirected: false, json: async () => ({html: 'Updated', signature: 'v1'})};
    });
    await runtime.tick();
    assert.equal(feed.innerHTML, 'Updated');
    editing = true; feed.innerHTML = 'Open action';
    await runtime.tick();
    assert.equal(feed.innerHTML, 'Open action');
    runtime.document.hidden = true;
    const before = calls;
    await runtime.tick();
    assert.equal(calls, before);
    runtime.document.hidden = false; revoked = true;
    await runtime.tick();
    assert.equal(feed.innerHTML, '');
    assert.match(status.textContent, /acesso foi alterado/);
});

function environment(lists = {}, singles = {}, fetcher = async () => { throw new Error('Unexpected fetch'); }) {
    const timers = new Map();
    const events = {};
    let timerId = 0;
    const document = {
        hidden: false,
        activeElement: null,
        querySelectorAll: (selector) => lists[selector] || [],
        querySelector: (selector) => singles[selector] || null,
        addEventListener: (event, callback) => { events[event] = callback; },
    };
    vm.runInNewContext(script, {
        document, window: {}, navigator: {}, AbortController,
        fetch: fetcher,
        setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, {callback, delay}); return id; },
        clearTimeout: (id) => timers.delete(id),
    });
    return {
        document, events,
        async tick() {
            const next = [...timers].find(([, value]) => value.delay === 5000);
            assert.ok(next, 'A próxima atualização deve estar agendada');
            timers.delete(next[0]);
            await next[1].callback();
        },
    };
}

function jsonResponse(data) {
    return {ok: true, status: 200, headers: {get: () => 'application/json'}, json: async () => data};
}

test('cart prices selected addons per unit and only charges freight for delivery', () => {
    const listeners = {};
    const quantity = {value: '2'};
    const fields = {
        '[data-mode]': {value: 'pickup'},
        '[data-zone]': {selectedOptions: [{dataset: {fee: '600'}}]},
        '[data-delivery-address]': {},
        '[data-cart-total]': {},
    };
    const product = {
        dataset: {price: '1000'},
        querySelector: () => quantity,
        querySelectorAll: () => [{dataset: {optionPrice: '250'}}],
    };
    const form = {
        querySelectorAll: () => [product],
        querySelector: (selector) => fields[selector],
        addEventListener: (event, callback) => {listeners[event] = callback;},
    };
    environment({'[data-cart]': [form]});
    assert.match(fields['[data-cart-total]'].textContent, /25,00/);
    assert.equal(fields['[data-zone]'].disabled, true);
    fields['[data-mode]'].value = 'delivery';
    listeners.change();
    assert.match(fields['[data-cart-total]'].textContent, /31,00/);
    assert.equal(fields['[data-zone]'].required, true);
    assert.equal(fields['[data-delivery-address]'].required, true);
});

test('board polling updates new orders, preserves an open edit and survives network failure', async () => {
    const fields = {'[data-board-connection]': {}, '[data-order-alert]': {}};
    let editing = false;
    let failure = false;
    let version = 2;
    const metric = {dataset: {summary: 'paid_cents', format: 'money'}};
    const board = {
        dataset: {url: '/orders/live', latestId: '1'}, innerHTML: 'Initial board',
        contains: () => false,
        querySelector: () => editing ? {} : null,
    };
    const runtime = environment({'[data-order-board]': [board], '[data-summary]': [metric]}, fields, async (url, options) => {
        assert.equal(url, '/orders/live');
        assert.equal(options.cache, 'no-store');
        if (failure) {throw new Error('Sem rede');}
        return jsonResponse({html: 'Board ' + version, signature: 'v' + version, latest_id: version, summary: {paid_cents: version * 1000}});
    });
    await runtime.tick();
    assert.equal(board.innerHTML, 'Board 2');
    assert.match(metric.textContent, /20,00/);
    assert.match(fields['[data-order-alert]'].textContent, /Novo pedido/);
    version = 3; editing = true;
    await runtime.tick();
    assert.equal(board.innerHTML, 'Board 2');
    assert.match(fields['[data-board-connection]'].textContent, /edição/);
    editing = false; failure = true;
    await runtime.tick();
    assert.equal(board.innerHTML, 'Board 2');
    failure = false;
    await runtime.tick();
    assert.equal(board.innerHTML, 'Board 3');
    assert.match(metric.textContent, /30,00/);
});

test('hidden page pauses requests and customer tracking writes messages as text', async () => {
    const fields = Object.fromEntries(['[data-tracking-status]', '[data-tracking-payment]', '[data-tracking-ride]', '[data-cancel-reason]', '[data-tracking-connection]'].map((selector) => [selector, {}]));
    let calls = 0;
    const panel = {dataset: {trackingUrl: '/pedido/public-id/status'}, querySelector: (selector) => fields[selector]};
    const runtime = environment({'[data-tracking-url]': [panel]}, {}, async () => {
        calls++;
        return jsonResponse({status: 'cancelled', payment_status: 'pending', ride_status: null, cancellation_reason: '<script>texto</script>'});
    });
    runtime.document.hidden = true;
    await runtime.tick();
    assert.equal(calls, 0);
    runtime.document.hidden = false;
    await runtime.tick();
    assert.equal(calls, 1);
    assert.equal(fields['[data-tracking-status]'].textContent, 'Cancelado');
    assert.equal(fields['[data-cancel-reason]'].textContent, '<script>texto</script>');
    assert.equal(fields['[data-cancel-reason]'].innerHTML, undefined);
});

test('online payment tracking reflects verified state and hides payment actions after confirmation', async () => {
    const fields=Object.fromEntries(['[data-tracking-status]','[data-tracking-payment]','[data-tracking-ride]','[data-cancel-reason]','[data-tracking-connection]','[data-online-actions]','[data-online-message]','[data-online-pay]'].map(key=>[key,{}]));
    let status='pending';
    const panel={dataset:{trackingUrl:'/pedido/test/status'},querySelector:key=>fields[key]};
    const runtime=environment({'[data-tracking-url]':[panel]}, {}, async()=>jsonResponse({status:'pending',payment_status:status,payment_online:true}));
    await runtime.tick();
    assert.equal(fields['[data-tracking-payment]'].textContent,'Aguardando pagamento online');
    assert.equal(fields['[data-online-actions]'].hidden,false);
    status='paid';await runtime.tick();
    assert.equal(fields['[data-tracking-payment]'].textContent,'Recebido');
    assert.equal(fields['[data-online-actions]'].hidden,true);
    status='review';await runtime.tick();
    assert.equal(fields['[data-tracking-payment]'].textContent,'Em conferência');
    assert.equal(fields['[data-online-pay]'].hidden,true);
});

test('ifood list pauses in hidden pages and preserves the last view on network failure', async () => {
    const message = {};
    const feed = {dataset: {url: '/integrations/ifood/orders/live'}, contains: () => false, querySelector: () => null, innerHTML: 'Initial'};
    let calls = 0;
    let failed = false;
    const runtime = environment({'[data-ifood-feed]': [feed]}, {'[data-ifood-connection]': message}, async () => {
        calls++;
        if (failed) { throw new Error('Offline'); }
        return jsonResponse({html: 'Imported test orders', signature: 'one'});
    });
    await runtime.tick();
    assert.equal(feed.innerHTML, 'Imported test orders');
    runtime.document.hidden = true;
    await runtime.tick();
    assert.equal(calls, 1);
    runtime.document.hidden = false;
    failed = true;
    await runtime.tick();
    assert.equal(feed.innerHTML, 'Imported test orders');
    assert.match(message.textContent, /indisponível/);
});

test('delivery code appears after collection and is cleared on return or failed refresh', async () => {
    const fields=Object.fromEntries(['[data-tracking-status]','[data-tracking-payment]','[data-tracking-ride]','[data-cancel-reason]','[data-tracking-connection]','[data-delivery-code-panel]','[data-delivery-code]'].map(key=>[key,{}]));
    let code='012345',failed=false;
    const panel={dataset:{trackingUrl:'/pedido/test/status'},querySelector:key=>fields[key]};
    const runtime=environment({'[data-tracking-url]':[panel]}, {}, async()=>{if(failed)throw Error('offline');return jsonResponse({status:'ready',payment_status:'pending',ride_status:'collected',delivery_code:code});});
    await runtime.tick();assert.equal(fields['[data-delivery-code]'].textContent,'012345');assert.equal(fields['[data-delivery-code-panel]'].hidden,false);
    code=null;await runtime.tick();assert.equal(fields['[data-delivery-code]'].textContent,'');assert.equal(fields['[data-delivery-code-panel]'].hidden,true);
    code='123456';await runtime.tick();failed=true;await runtime.tick();assert.equal(fields['[data-delivery-code]'].textContent,'');assert.equal(fields['[data-delivery-code-panel]'].hidden,true);
});
