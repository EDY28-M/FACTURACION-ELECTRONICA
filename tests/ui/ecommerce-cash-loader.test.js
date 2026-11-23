// FEM-003: the ecommerce cash payment must never leave the loading modal open. A normal HTTP 200
// with success !== true, an HTTP error and a network error must all replace the loader with an
// error message and keep the cart intact. Plain node + vm, no build step: the REAL paymentCash
// method is extracted from the Blade view by brace matching. swal/axios/localStorage are stubs,
// so no browser, server or payment is involved.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const viewPath = process.env.CASH_VIEW || path.join(root, 'modules/Ecommerce/Resources/views/cart/detail.blade.php');
const source = fs.readFileSync(viewPath, 'utf8');

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

// Static template/data assertions (the button is locked while pending, flag is reactive data).
assert(/<button @click="paymentCash" :disabled="payment_cash\.processing"/.test(source), 'OK! button not disabled while processing');
assert(/payment_cash:\s*\{[^}]*processing:\s*false/.test(source), 'payment_cash.processing not declared in Vue data');

let methodSrc = extractBlock(source, '            async paymentCash(');
methodSrc = methodSrc.replace(/\{\{\s*route\("tenant_ecommerce_payment_cash"\)\s*\}\}/, '/ecommerce/payment_cash');
assert(!methodSrc.includes('{{'), 'unresolved Blade expression left in extracted method');
const methodWrapper = `(function(){ return { ${methodSrc} }.paymentCash; })()`;

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}
const tick = () => new Promise(r => setImmediate(r));

function makeComponent({ products = [{ id: 1 }] } = {}) {
    const swals = [];      // every swal(...) call, in order
    const pending = [];    // controllable axios requests
    const posts = [];
    const state = { cleared: 0, contactSaved: 0, logs: [] };
    // The buyer closing a dialog runs its .then callbacks (accept or dismiss).
    const dialogs = [];
    const swal = (...args) => {
        const dialog = { callbacks: [] };
        dialogs.push(dialog);
        swals.push(args);
        return { then(onOk, onDismiss) { dialog.callbacks.push(onOk, onDismiss); return this; } };
    };
    const closeLastDialog = () => { const d = dialogs[dialogs.length - 1]; d.callbacks.slice(0, 1).forEach(cb => cb && cb()); };
    swal.close = () => {};
    const ctx = {
        payment_cash: { amount: '10.00', clicked: true, processing: false },
        errors: {}, response_order_total: null,
        getHeaderConfig: () => ({ headers: {} }),
        getFormPaymentCash: async () => ({ producto: 'p' }),
        saveContactDataUser() { state.contactSaved++; },
        clearShoppingCart() { state.cleared++; },
    };
    // paymentCash resolves swal/Swal/axios/localStorage/app_cart/console as globals of its own context.
    const sandbox = vm.createContext({
        swal,
        Swal: { showLoading() {} },
        axios: { post(url, body) { const d = deferred(); posts.push(url); pending.push(d); return d.promise; } },
        localStorage: { getItem: () => JSON.stringify(products) },
        app_cart: {},
        console: { log: (...a) => state.logs.push(a) },
    });
    ctx.paymentCash = vm.runInContext(methodWrapper, sandbox).bind(ctx);
    const run = () => ctx.paymentCash();
    return { ctx, swals, pending, posts, state, run, closeLastDialog };
}

const SUCCESS_TITLE = 'Gracias por su pago!';
const FAIL_TITLE = 'Pago No realizado';
const titleOf = a => (typeof a[0] === 'string' ? a[0] : a[0].title);
const textOf = a => (typeof a[0] === 'string' ? a[1] : a[0].text);
const typeOf = a => (typeof a[0] === 'string' ? a[2] : a[0].type);

(async () => {
    // (a) success: unchanged behavior (contact saved, cart cleared, success swal replaces loader).
    let t = makeComponent();
    let p = t.run();
    await tick();
    assert.strictEqual(t.ctx.payment_cash.processing, true);
    assert.strictEqual(t.swals.length, 1, 'loader should be the only modal while pending');
    t.pending[0].resolve({ data: { success: true, order: { total: 50 } } });
    await p;
    assert.strictEqual(t.state.contactSaved, 1);
    // QA-09: the cart (and the page reload it triggers) waits until the buyer has seen the dialog.
    assert.strictEqual(t.state.cleared, 0, 'cart must not be cleared before the confirmation is closed');
    assert.strictEqual(t.ctx.response_order_total, 50);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(t.ctx.order_generated)), { total: 50 });
    assert.strictEqual(t.swals.length, 2);
    assert.strictEqual(titleOf(t.swals[1]), SUCCESS_TITLE);
    assert.strictEqual(typeOf(t.swals[1]), 'success');
    t.closeLastDialog();
    assert.strictEqual(t.state.cleared, 1, 'cart cleared once the dialog is closed');
    assert.strictEqual(t.ctx.payment_cash.processing, false);

    // (b) HTTP 200 + success:false + message: loader replaced by error, message as TEXT, cart kept.
    t = makeComponent();
    p = t.run();
    await tick();
    t.pending[0].resolve({ data: { success: false, message: 'Stock insuficiente <img src=x onerror=alert(1)>' } });
    await p;
    assert.strictEqual(t.swals.length, 2, 'loader must be replaced by a rejection modal');
    assert.strictEqual(titleOf(t.swals[1]), FAIL_TITLE);
    assert.strictEqual(textOf(t.swals[1]), 'Stock insuficiente <img src=x onerror=alert(1)>');
    assert.strictEqual(typeOf(t.swals[1]), 'error');
    assert.strictEqual(t.swals[1][0].html, undefined, 'server message must never be passed as html');
    assert(!t.swals[1][0].title.includes('Stock'), 'server message must not be placed in the (HTML) title');
    assert.strictEqual(t.state.cleared, 0, 'cart must NOT be cleared on rejection');
    assert.strictEqual(t.state.contactSaved, 0);
    assert(!t.swals.some(a => titleOf(a) === SUCCESS_TITLE), 'success must not be shown for a rejected order');
    assert.strictEqual(t.ctx.payment_cash.processing, false);

    // (c) HTTP 200 + success:false without (or with non-string) message: generic message.
    for (const data of [{ success: false }, { success: false, message: '   ' }, { success: false, message: { a: 1 } }, { success: 'yes' }, {}]) {
        t = makeComponent();
        p = t.run();
        await tick();
        t.pending[0].resolve({ data });
        await p;
        assert.strictEqual(t.swals.length, 2, JSON.stringify(data));
        assert.strictEqual(titleOf(t.swals[1]), FAIL_TITLE);
        assert.strictEqual(textOf(t.swals[1]), 'No se pudo registrar el pedido. Intente nuevamente.');
        assert.strictEqual(t.state.cleared, 0);
        assert.strictEqual(t.ctx.payment_cash.processing, false);
    }

    // (d) HTTP 422 / 500: loader replaced, cart kept, 422 payload exposed as form errors.
    t = makeComponent();
    p = t.run();
    await tick();
    t.pending[0].reject({ response: { status: 422, data: { numero_documento: ['x'] } } });
    await p;
    assert.strictEqual(t.swals.length, 2);
    assert.strictEqual(titleOf(t.swals[1]), FAIL_TITLE);
    assert.deepStrictEqual(JSON.parse(JSON.stringify(t.ctx.errors)), { numero_documento: ['x'] });
    assert.strictEqual(t.state.cleared, 0);
    assert.strictEqual(t.ctx.payment_cash.processing, false);
    t = makeComponent();
    p = t.run();
    await tick();
    t.pending[0].reject({ response: { status: 500, data: {} } });
    await p;
    assert.strictEqual(t.swals.length, 2);
    assert.strictEqual(typeOf(t.swals[1]), 'error');
    assert.strictEqual(t.state.cleared, 0);
    assert.strictEqual(t.ctx.payment_cash.processing, false);

    // (e) network error without `response`: no TypeError, loader replaced.
    t = makeComponent();
    p = t.run();
    await tick();
    t.pending[0].reject(new Error('Network Error'));
    await p; // would reject with TypeError if error.response were dereferenced unguarded
    assert.strictEqual(t.swals.length, 2);
    assert.strictEqual(titleOf(t.swals[1]), FAIL_TITLE);
    assert.strictEqual(t.state.cleared, 0);
    assert.strictEqual(t.ctx.payment_cash.processing, false);

    // (e2) failure building the payload (before the request) also replaces the loader.
    t = makeComponent();
    t.ctx.getFormPaymentCash = async () => { throw new Error('boom'); };
    await t.run();
    assert.strictEqual(t.posts.length, 0);
    assert.strictEqual(t.swals.length, 2);
    assert.strictEqual(titleOf(t.swals[1]), FAIL_TITLE);
    assert.strictEqual(t.ctx.payment_cash.processing, false);

    // (f) retry after a rejection issues a brand-new request and can succeed.
    t = makeComponent();
    p = t.run();
    await tick();
    t.pending[0].resolve({ data: { success: false, message: 'Intente de nuevo' } });
    await p;
    p = t.run();
    await tick();
    assert.strictEqual(t.posts.length, 2, 'retry must issue a new request');
    t.pending[1].resolve({ data: { success: true, order: { total: 9 } } });
    await p;
    t.closeLastDialog();
    assert.strictEqual(t.state.cleared, 1);
    assert.strictEqual(titleOf(t.swals[t.swals.length - 1]), SUCCESS_TITLE);

    // (g) double click while pending: a single request and a single loader.
    t = makeComponent();
    const p1 = t.run();
    const p2 = t.run();
    await tick();
    assert.strictEqual(t.posts.length, 1, 'second click must not send a second request');
    assert.strictEqual(t.swals.length, 1);
    t.pending[0].resolve({ data: { success: true, order: { total: 1 } } });
    await Promise.all([p1, p2]);
    t.closeLastDialog();
    assert.strictEqual(t.posts.length, 1);
    assert.strictEqual(t.state.cleared, 1, 'cart cleared exactly once');
    assert.strictEqual(t.ctx.payment_cash.processing, false);

    // (h) empty cart: early validation error, no loader, no request, no lock left behind.
    t = makeComponent({ products: [] });
    await t.run();
    assert.strictEqual(t.posts.length, 0);
    assert.strictEqual(t.swals.length, 1);
    assert.strictEqual(t.ctx.payment_cash.processing, false);

    console.log('ecommerce-cash-loader: ok');
})().catch(e => { console.error(e); process.exit(1); });
