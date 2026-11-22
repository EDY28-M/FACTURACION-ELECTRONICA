// FEM-002: hotel checkout must await finalization and retry ONLY finalization after a
// partial failure (plain node + vm, no build step). UI-only guard: no server idempotence
// is claimed beyond what HotelRentController::finalizeRent already does.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const source = fs.readFileSync(path.join(root, 'modules/Hotel/Resources/assets/js/views/rooms/Checkout.vue'), 'utf8');

function extractBlock(src, header) {
    const start = src.indexOf(header);
    assert(start >= 0, `${header} not found`);
    const open = src.indexOf('{', src.indexOf(')', start));
    let depth = 0;
    for (let i = open; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}' && --depth === 0) return src.slice(start, i + 1);
    }
    throw new Error('unbalanced braces');
}

const onGoToInvoice = vm.runInNewContext(
    `(function(){ return { ${extractBlock(source, '    async onGoToInvoice(')} }.onGoToInvoice; })()`
);
const rentIdWatcher = vm.runInNewContext(
    `(function(){ return { ${extractBlock(source, '    "rent.id"(')} }["rent.id"]; })()`
);

// Template: the submit button is locked while loading.
assert(/:loading="loading"\s+:disabled="loading"\s+type="primary"\s+@click="onGoToInvoice"/.test(source), 'button not locked while loading');

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}
const tick = () => new Promise(r => setImmediate(r));

function makeComponent(rentId = 10) {
    const calls = [];
    const pending = [];
    const messages = [];
    const ctx = {
        loading: false, arrears: 0, resource_documents: 'documents',
        rent: { id: rentId, items: [] }, document: { payments: [{}] }, errors: {}, response: {},
        documentNewId: null, pendingFinalizationDocumentId: null, showDialogDocumentOptions: false,
        form_cash_document: { document_id: null }, canOfferLodgingExport: false,
        cashSaved: 0, closed: 0,
        $message: { error: m => messages.push(m) },
        $emit() { ctx.closed++; },
        $http: { post(url, body) { const d = deferred(); calls.push(url); pending.push(d); return d.promise; } },
        onUpdateItemsWithExtras() {}, onCalculateTotals() {},
        validatePaymentDestination: () => ({ error_by_item: 0 }),
        saveCashDocument() { ctx.cashSaved++; },
    };
    ctx.onGoToInvoice = onGoToInvoice.bind(ctx);
    return { ctx, calls, pending, messages };
}

const EMIT = '/documents';
const finalizeUrl = id => `/hotels/reception/${id}/rent/finalized`;
const emitOk = id => ({ data: { success: true, data: { id } } });

(async () => {
    // (a) success: emit once, finalize once, loading held until BOTH settle, state cleared.
    let t = makeComponent();
    t.ctx.onGoToInvoice();
    assert.strictEqual(t.ctx.loading, true);
    t.pending[0].resolve(emitOk(77));
    await tick();
    assert.deepStrictEqual(t.calls, [EMIT, finalizeUrl(10)]);
    assert.strictEqual(t.ctx.loading, true, 'loading released before finalization settled');
    assert.strictEqual(t.ctx.documentNewId, 77);
    assert.strictEqual(t.ctx.pendingFinalizationDocumentId, 77);
    t.pending[1].resolve({ data: { success: true } });
    await tick();
    assert.strictEqual(t.ctx.loading, false);
    assert.strictEqual(t.ctx.pendingFinalizationDocumentId, null);
    assert.strictEqual(t.ctx.documentNewId, 77, 'options dialog id must survive success');
    assert.strictEqual(t.ctx.showDialogDocumentOptions, true);
    assert.strictEqual(t.ctx.cashSaved, 1);
    assert.deepStrictEqual(t.messages, []);
    console.log('ok - (a) success path');

    // (b) emission fails (422, business error, transport error): no finalize, loading released, nothing kept.
    for (const fail of [
        d => d.reject({ response: { status: 422, data: { x: ['bad'] } } }),
        d => d.reject({ response: { status: 500, data: { message: 'boom' } } }),
        d => d.reject(new Error('Network Error')),
        d => d.resolve({ data: { success: false, message: 'nope' } }),
    ]) {
        t = makeComponent();
        t.ctx.onGoToInvoice();
        fail(t.pending[0]);
        await tick();
        assert.deepStrictEqual(t.calls, [EMIT], 'finalize called after failed emission');
        assert.strictEqual(t.ctx.loading, false);
        assert.strictEqual(t.ctx.pendingFinalizationDocumentId, null);
        assert.strictEqual(t.ctx.documentNewId, null);
        assert.strictEqual(t.ctx.cashSaved, 0);
    }
    console.log('ok - (b) emission failure');

    // (c) emission ok + finalize fails: id kept, partial-state message, loading released.
    t = makeComponent();
    t.ctx.onGoToInvoice();
    t.pending[0].resolve(emitOk(88));
    await tick();
    t.pending[1].reject({ response: { status: 500, data: { message: 'db down' } } });
    await tick();
    assert.strictEqual(t.ctx.loading, false);
    assert.strictEqual(t.ctx.pendingFinalizationDocumentId, 88);
    assert.strictEqual(t.ctx.documentNewId, 88);
    assert.strictEqual(t.messages.length, 1);
    assert(/YA fue emitido/.test(t.messages[0]) && /pendiente de finalizar/.test(t.messages[0]), 'partial-state message missing');
    console.log('ok - (c) finalize failure keeps id and informs');

    // (d) retry after (c): ONLY finalization; success clears pending state.
    t.ctx.onGoToInvoice();
    assert.strictEqual(t.ctx.loading, true);
    await tick();
    assert.deepStrictEqual(t.calls, [EMIT, finalizeUrl(10), finalizeUrl(10)], 'retry re-emitted or skipped finalize');
    assert.strictEqual(t.ctx.cashSaved, 1, 'cash document must NOT be saved again on retry');
    t.pending[2].resolve({ data: { success: true } });
    await tick();
    assert.strictEqual(t.ctx.loading, false);
    assert.strictEqual(t.ctx.pendingFinalizationDocumentId, null);
    // A second failing retry also stays finalize-only.
    t = makeComponent();
    t.ctx.onGoToInvoice();
    t.pending[0].resolve(emitOk(5));
    await tick();
    t.pending[1].reject(new Error('Network Error'));
    await tick();
    t.ctx.onGoToInvoice();
    await tick();
    t.pending[2].reject({ response: { status: 500, data: {} } });
    await tick();
    assert.deepStrictEqual(t.calls, [EMIT, finalizeUrl(10), finalizeUrl(10)]);
    assert.strictEqual(t.ctx.pendingFinalizationDocumentId, 5);
    assert.strictEqual(t.ctx.loading, false);
    console.log('ok - (d) retry runs finalization only');

    // (f) finalize-only retry must not be blocked by form validations that fail AFTER emission,
    // and must not rebuild the already-emitted document.
    t = makeComponent();
    t.ctx.onGoToInvoice();
    t.pending[0].resolve(emitOk(6));
    await tick();
    t.pending[1].reject(new Error('Network Error'));
    await tick();
    let rebuilt = 0;
    t.ctx.onUpdateItemsWithExtras = () => { rebuilt++; };
    t.ctx.onCalculateTotals = () => { rebuilt++; };
    t.ctx.validatePaymentDestination = () => ({ error_by_item: 1 });
    t.ctx.canOfferLodgingExport = true;
    t.ctx.lodgingExport = { enabled: true };
    t.ctx.validateLodgingExport = () => 'lodging export invalid';
    const msgsBefore = t.messages.length;
    t.ctx.onGoToInvoice();
    await tick();
    assert.deepStrictEqual(t.calls, [EMIT, finalizeUrl(10), finalizeUrl(10)], 'retry blocked by validations or re-emitted');
    assert.strictEqual(rebuilt, 0, 'retry rebuilt the emitted document');
    assert.strictEqual(t.messages.length, msgsBefore, 'validation message shown on finalize-only retry');
    t.pending[2].resolve({ data: { success: true } });
    await tick();
    assert.strictEqual(t.ctx.pendingFinalizationDocumentId, null);
    assert.strictEqual(t.ctx.loading, false);
    console.log('ok - (f) finalize-only retry skips form validations');

    // (e) after success a new checkout starts clean; a rent change drops any stale id.
    t = makeComponent();
    t.ctx.onGoToInvoice();
    t.pending[0].resolve(emitOk(1));
    await tick();
    t.pending[1].resolve({ data: { success: true } });
    await tick();
    const next = makeComponent(11); // new page/component for another stay
    next.ctx.onGoToInvoice();
    await tick();
    assert.deepStrictEqual(next.calls, [EMIT], 'new checkout did not start with a fresh emission');
    // Watcher: same component pointed at another rent while a finalization was pending.
    t = makeComponent();
    t.ctx.onGoToInvoice();
    t.pending[0].resolve(emitOk(2));
    await tick();
    t.pending[1].reject(new Error('Network Error'));
    await tick();
    assert.strictEqual(t.ctx.pendingFinalizationDocumentId, 2);
    t.ctx.rent = { id: 12, items: [] };
    rentIdWatcher.call(t.ctx);
    assert.strictEqual(t.ctx.pendingFinalizationDocumentId, null);
    assert.strictEqual(t.ctx.documentNewId, null);
    t.calls.length = 0;
    t.ctx.onGoToInvoice();
    await tick();
    assert.deepStrictEqual(t.calls, [EMIT], 'stale id leaked into another rent');
    console.log('ok - (e) clean state for a new checkout');
})().catch(e => { console.error(e); process.exit(1); });
