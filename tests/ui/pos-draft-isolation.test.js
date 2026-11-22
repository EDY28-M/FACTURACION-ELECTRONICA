// FEC-002: POS draft isolation by tenant + user (plain node + vm, no build step).
// Loads the real helper source, strips ES `export`, runs it against a fake localStorage.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const helperPath = path.join(root, 'resources/js/helpers/pos_draft.js');

function fakeStorage(initial = {}) {
    const data = { ...initial };
    return {
        data,
        getItem: k => (k in data ? data[k] : null),
        setItem: (k, v) => { data[k] = String(v); },
        removeItem: k => { delete data[k]; },
    };
}

// One "browser tab": shared storage, own helper instance (module state) and host.
function loadHelper(storage, host) {
    const src = fs.readFileSync(helperPath, 'utf8').replace(/^export /gm, '');
    const sandbox = { window: { localStorage: storage, location: { host } } };
    vm.createContext(sandbox);
    vm.runInContext(
        `${src}\nthis.api = { setPosDraftContext, readPosDraft, writePosDraft, removePosDraft };`,
        sandbox
    );
    return sandbox.api;
}

// Objects parsed inside the vm realm have a different prototype: compare by value.
const same = (actual, expected, msg) =>
    assert.strictEqual(JSON.stringify(actual), JSON.stringify(expected), msg);

const GENERIC = ['form_pos', 'customer', 'amount', 'enter_amount', 'difference'];
const draft = { items: [{ id: 1 }], payments: [{ payment: 10 }] };

// (a) user A writes, user B (same tenant, same browser) reads nothing
{
    const storage = fakeStorage({ unrelated: 'keep' });
    const a = loadHelper(storage, 'tenant1.example.com');
    a.setPosDraftContext(1);
    a.writePosDraft('form_pos', draft);
    a.writePosDraft('customer', { id: 7 });
    a.writePosDraft('enter_amount', 55);

    const b = loadHelper(storage, 'tenant1.example.com');
    b.setPosDraftContext(2);
    assert.strictEqual(b.readPosDraft('form_pos'), null, 'B must not see A form_pos');
    assert.strictEqual(b.readPosDraft('customer'), null, 'B must not see A customer');
    assert.strictEqual(b.readPosDraft('enter_amount', 0), 0, 'B must get default for A enter_amount');

    // (b) A reads own draft back (also after a fresh page load)
    const a2 = loadHelper(storage, 'tenant1.example.com');
    a2.setPosDraftContext(1);
    same(a2.readPosDraft('form_pos'), draft, 'A must recover own draft');
    same(a2.readPosDraft('customer'), { id: 7 });
    assert.strictEqual(a2.readPosDraft('enter_amount', 0), 55);
    console.log('ok - (a) other user isolated, (b) same user restores');
}

// (c) same user id on a different tenant host reads nothing
{
    const storage = fakeStorage();
    const t1 = loadHelper(storage, 'tenant1.example.com');
    t1.setPosDraftContext(1);
    t1.writePosDraft('form_pos', draft);
    const t2 = loadHelper(storage, 'tenant2.example.com');
    t2.setPosDraftContext(1);
    assert.strictEqual(t2.readPosDraft('form_pos'), null, 'other tenant must not see draft');
    console.log('ok - (c) other tenant isolated');
}

// (d) legacy generic keys are discarded and never adopted
{
    const legacy = {};
    GENERIC.forEach(k => { legacy[k] = JSON.stringify({ legacy: k }); });
    const storage = fakeStorage({ ...legacy, unrelated: 'keep', items: '[1]', client: '{}' });
    const h = loadHelper(storage, 'tenant1.example.com');
    // before identity is known nothing is readable or writable
    assert.strictEqual(h.readPosDraft('form_pos'), null);
    h.writePosDraft('form_pos', draft);
    assert.ok(!Object.keys(storage.data).some(k => k.startsWith('pos_draft')), 'no write without identity');

    h.setPosDraftContext(1);
    GENERIC.forEach(k => {
        assert.ok(!(k in storage.data), `legacy key ${k} must be removed`);
        assert.strictEqual(h.readPosDraft(k), null, `legacy ${k} must not be adopted`);
    });
    assert.strictEqual(storage.data.unrelated, 'keep');
    assert.strictEqual(storage.data.items, '[1]', 'dispatch->invoice key untouched');
    assert.strictEqual(storage.data.client, '{}', 'dispatch->invoice key untouched');

    // missing identity fails closed
    const nobody = loadHelper(fakeStorage(), 'tenant1.example.com');
    nobody.setPosDraftContext(null);
    nobody.writePosDraft('form_pos', draft);
    assert.strictEqual(nobody.readPosDraft('form_pos'), null);
    console.log('ok - (d) legacy discarded, fail closed without identity');
}

// (e) remove clears only the POS namespaced key (and only for that user)
{
    const storage = fakeStorage({ unrelated: 'keep' });
    const a = loadHelper(storage, 'tenant1.example.com');
    a.setPosDraftContext(1);
    a.writePosDraft('form_pos', draft);
    a.writePosDraft('amount', 5);
    const b = loadHelper(storage, 'tenant1.example.com');
    b.setPosDraftContext(2);
    b.writePosDraft('form_pos', { other: true });

    a.removePosDraft('form_pos');
    a.removePosDraft('amount');
    assert.strictEqual(a.readPosDraft('form_pos'), null);
    assert.strictEqual(a.readPosDraft('amount', 0), 0);
    same(b.readPosDraft('form_pos'), { other: true }, 'B draft untouched');
    assert.strictEqual(storage.data.unrelated, 'keep', 'unrelated key untouched');
    console.log('ok - (e) clear removes only own POS keys');
}

// Static: no generic POS key literal is read/written directly in the POS files.
{
    const files = [
        'resources/js/views/tenant/pos/index.vue',
        'resources/js/views/tenant/pos/partials/payment.vue',
        'resources/js/views/tenant/pos/partials/multiple_payment.vue',
    ];
    files.forEach(f => {
        const src = fs.readFileSync(path.join(root, f), 'utf8');
        assert.ok(!/localStorage/.test(src), `${f} must not touch localStorage directly`);
        assert.ok(/helpers\/pos_draft/.test(src), `${f} must use the pos_draft helper`);
    });
    console.log('ok - POS files use only the namespaced helper');
}
