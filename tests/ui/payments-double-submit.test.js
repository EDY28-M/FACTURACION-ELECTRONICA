// FEC-001: UI-only re-entry guard for payment rows (no server idempotence is claimed).
// Loads the real clickSubmit method text from each SFC (no build step) and runs it
// against a stub component context with a controllable $http.post.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const files = [
    ['documents', 'resources/js/views/tenant/documents/partials/payments.vue', 'document'],
    ['sale_notes', 'resources/js/views/tenant/sale_notes/partials/payments.vue', 'document'],
    ['purchase', 'modules/Purchase/Resources/assets/js/views/purchase_payments/payments.vue', 'purchase'],
    ['expense', 'modules/Expense/Resources/assets/js/views/expense_payments/payments.vue', 'expense'],
];

function extractMethod(source, name) {
    const start = source.indexOf(`            ${name}(`);
    assert(start >= 0, `${name} not found`);
    const open = source.indexOf('{', source.indexOf(')', start));
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}' && --depth === 0) return source.slice(start, i + 1);
    }
    throw new Error('unbalanced braces');
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

function makeContext(clickSubmit, totalKey) {
    const calls = [];
    const pending = [];
    const messages = [];
    const ctx = {
        records: [{ id: null, payment: 10, errors: {}, loading: false }],
        showAddButton: false,
        resource: 'payments',
        documentId: 1, purchaseId: 1, expenseId: 1,
        [totalKey]: { total_difference: '100' },
        $message: { error: m => messages.push(['error', m]), success: m => messages.push(['success', m]) },
        $eventHub: { $emit() {} },
        $http: { post(url, form) { const d = deferred(); calls.push([url, form]); pending.push(d); return d.promise; } },
        getData() {},
    };
    ctx.clickSubmit = clickSubmit.bind(ctx);
    return { ctx, calls, pending, messages };
}

const tick = () => new Promise(r => setImmediate(r));

(async () => {
    for (const [label, file, totalKey] of files) {
        const source = fs.readFileSync(path.join(root, file), 'utf8');

        // Template: both row buttons are disabled while the row is submitting.
        assert(/btn-info"\s+:disabled="row\.loading"\s+@click\.prevent="clickSubmit\(index\)"/.test(source), `${label}: save button not disabled`);
        assert(/btn-danger"\s+:disabled="row\.loading"\s+@click\.prevent="clickCancel\(index\)"/.test(source), `${label}: cancel button not disabled`);

        const clickSubmit = vm.runInNewContext(`(function(){ return { ${extractMethod(source, 'clickSubmit')} }.clickSubmit; })()`);

        // 1) Two rapid clicks while pending -> exactly one request, row locked.
        let t = makeContext(clickSubmit, totalKey);
        t.ctx.clickSubmit(0);
        t.ctx.clickSubmit(0);
        assert.strictEqual(t.calls.length, 1, `${label}: double click sent ${t.calls.length} requests`);
        assert.strictEqual(t.ctx.records[0].loading, true, `${label}: row not locked`);

        // 2) Success releases the flag.
        t.pending[0].resolve({ data: { success: true, message: 'ok' } });
        await tick();
        assert.strictEqual(t.ctx.records[0].loading, false, `${label}: flag not released on success`);

        // 3) Error (422) releases the flag, shows errors, and a legitimate re-submit works.
        t = makeContext(clickSubmit, totalKey);
        t.ctx.clickSubmit(0);
        t.pending[0].reject({ response: { status: 422, data: { payment: ['bad'] } } });
        await tick();
        assert.strictEqual(t.ctx.records[0].loading, false, `${label}: flag not released on error`);
        assert.deepStrictEqual(t.ctx.records[0].errors, { payment: ['bad'] }, `${label}: errors not shown`);
        t.ctx.clickSubmit(0);
        assert.strictEqual(t.calls.length, 2, `${label}: re-submit after error blocked`);
        t.pending[1].resolve({ data: { success: false, message: 'nope' } });
        await tick();
        assert.strictEqual(t.ctx.records[0].loading, false, `${label}: flag not released on success:false`);

        // 4) Existing validation still short-circuits without locking or posting.
        t = makeContext(clickSubmit, totalKey);
        t.ctx.records[0].payment = 500;
        t.ctx.clickSubmit(0);
        assert.strictEqual(t.calls.length, 0, `${label}: validation bypassed`);
        assert.strictEqual(t.ctx.records[0].loading, false, `${label}: validation left row locked`);

        console.log(`ok - ${label}`);
    }
})().catch(e => { console.error(e); process.exit(1); });
