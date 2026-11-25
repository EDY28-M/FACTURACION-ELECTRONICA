// POS environment rules (Demo '01', Producción '02', Interno '03') and API error extraction.
// Loads the real helper source directly via node + vm, plus static checks on POS/Invoice views.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const helperPath = path.join(root, 'resources/js/helpers/pos_environment.js');

function loadHelper() {
    const src = fs.readFileSync(helperPath, 'utf8').replace(/^export /gm, '');
    const sandbox = {};
    vm.createContext(sandbox);
    vm.runInContext(
        `${src}\nthis.api = { isInternalSoapMode, allowedPosDocumentTypes, normalizePosDocumentType, defaultPosDocumentType, nextPosDocumentType, productionMissingMessage, extractApiErrorMessage };`,
        sandbox
    );
    return sandbox.api;
}

const same = (actual, expected, msg) =>
    assert.strictEqual(JSON.stringify(actual), JSON.stringify(expected), msg);

const api = loadHelper();

// (1) Environment '03' (SUNAT · Interno): only Nota de Venta ('80') allowed everywhere
{
    assert.strictEqual(api.isInternalSoapMode('03'), true);
    assert.strictEqual(api.isInternalSoapMode('01'), false);
    assert.strictEqual(api.isInternalSoapMode('02'), false);
    assert.strictEqual(api.isInternalSoapMode(null), false);

    same(api.allowedPosDocumentTypes('03'), ['80'], 'Internal mode must only allow Nota de Venta');
    assert.strictEqual(api.normalizePosDocumentType('01', '03'), '80', 'Factura draft coerces to 80 in internal mode');
    assert.strictEqual(api.normalizePosDocumentType('03', '03'), '80', 'Boleta draft coerces to 80 in internal mode');
    assert.strictEqual(api.normalizePosDocumentType('80', '03'), '80');
    assert.strictEqual(api.defaultPosDocumentType('03', { identity_document_type_id: '6' }, false), '80');
    assert.strictEqual(api.defaultPosDocumentType('03', { identity_document_type_id: '1' }, true), '80');
    assert.strictEqual(api.nextPosDocumentType('80', '03'), '80', 'F2 stays on 80 in internal mode');
    assert.strictEqual(api.nextPosDocumentType('03', '03'), '80');
    console.log('ok - (1) SUNAT Interno restricts POS to Nota de Venta');
}

// (2) Environments '01' (Demo) and '02' (Producción): Factura, Boleta, Nota de Venta
{
    for (const env of ['01', '02']) {
        same(api.allowedPosDocumentTypes(env), ['01', '03', '80']);
        assert.strictEqual(api.normalizePosDocumentType('01', env), '01');
        assert.strictEqual(api.normalizePosDocumentType('03', env), '03');
        assert.strictEqual(api.normalizePosDocumentType('80', env), '80');
        assert.strictEqual(api.normalizePosDocumentType('99', env), '03');

        assert.strictEqual(api.defaultPosDocumentType(env, { identity_document_type_id: '6' }, false), '01');
        assert.strictEqual(api.defaultPosDocumentType(env, { identity_document_type_id: '1' }, false), '03');
        assert.strictEqual(api.defaultPosDocumentType(env, { identity_document_type_id: '6' }, true), '03');
        assert.strictEqual(api.defaultPosDocumentType(env, null, false), '03');

        assert.strictEqual(api.nextPosDocumentType('01', env), '03');
        assert.strictEqual(api.nextPosDocumentType('03', env), '80');
        assert.strictEqual(api.nextPosDocumentType('80', env), '01');
    }
    console.log('ok - (2) Demo and Producción support 01/03/80 defaults and F2 cycling');
}

// (3) Incomplete production warning message
{
    assert.strictEqual(api.productionMissingMessage('01', ['usuario SOL']), null);
    assert.strictEqual(api.productionMissingMessage('03', ['usuario SOL']), null);
    assert.strictEqual(api.productionMissingMessage('02', []), null);
    assert.strictEqual(api.productionMissingMessage('02', null), null);

    const msg = api.productionMissingMessage('02', ['usuario SOL', 'certificado digital']);
    assert.ok(msg.includes('usuario SOL') && msg.includes('certificado digital'));
    assert.ok(msg.includes('Nota de Venta'));
    console.log('ok - (3) productionMissingMessage warns only for incomplete Producción');
}

// (4) API error extraction covers 422 nested/flat, 500 JSON, HTML, 403, 419, and network errors
{
    // Nested Laravel 422
    assert.strictEqual(
        api.extractApiErrorMessage({
            response: {
                status: 422,
                data: {
                    message: 'The given data was invalid.',
                    errors: { series_id: ['La serie es obligatoria.'] },
                },
            },
        }),
        'La serie es obligatoria.'
    );

    // Flat Laravel 422
    assert.strictEqual(
        api.extractApiErrorMessage({
            response: {
                status: 422,
                data: { customer_id: ['Debe seleccionar un cliente.'] },
            },
        }),
        'Debe seleccionar un cliente.'
    );

    // Backend JSON 500 business message
    assert.strictEqual(
        api.extractApiErrorMessage({
            response: {
                status: 500,
                data: {
                    success: false,
                    message: 'La empresa está en modo SUNAT · Interno.',
                },
            },
        }),
        'La empresa está en modo SUNAT · Interno.'
    );

    // Raw HTML 500 must NOT leak HTML into the toast
    const htmlMsg = api.extractApiErrorMessage({
        response: { status: 500, data: '<html><body>500 Server Error</body></html>' },
    });
    assert.ok(!htmlMsg.includes('<html>'), 'Must not leak raw HTML');
    assert.ok(htmlMsg.length > 5);

    // 403, 419, and offline/network error (no error.response)
    assert.ok(api.extractApiErrorMessage({ response: { status: 403, data: {} } }).includes('entorno'));
    assert.ok(api.extractApiErrorMessage({ response: { status: 419, data: {} } }).includes('sesión'));
    assert.ok(api.extractApiErrorMessage(new Error('Network Error')).includes('conectar'));
    assert.ok(api.extractApiErrorMessage(null).includes('conectar'));
    console.log('ok - (4) extractApiErrorMessage handles every HTTP/network failure safely');
}

// (5) Static checks on POS & Invoice components
{
    const paymentSrc = fs.readFileSync(path.join(root, 'resources/js/views/tenant/pos/partials/payment.vue'), 'utf8');
    assert.ok(/helpers\/pos_environment/.test(paymentSrc), 'payment.vue must use pos_environment helper');
    assert.ok(/v-if="!isInternalMode"\s+label="01"/.test(paymentSrc), 'payment.vue must hide FACTURA in internal mode');
    assert.ok(/v-if="!isInternalMode"\s+label="03"/.test(paymentSrc), 'payment.vue must hide BOLETA in internal mode');
    assert.ok(/productionWarning/.test(paymentSrc), 'payment.vue must surface productionWarning');
    assert.ok(/extractApiErrorMessage/.test(paymentSrc), 'payment.vue must extract error messages on catch');
    assert.ok(/loading_submit \|\| locked_submit/.test(paymentSrc), 'payment.vue must disable pay button while submitting');

    const posIndexSrc = fs.readFileSync(path.join(root, 'resources/js/views/tenant/pos/index.vue'), 'utf8');
    assert.ok(/helpers\/pos_environment/.test(posIndexSrc), 'pos/index.vue must use pos_environment helper');
    assert.ok(/defaultPosDocumentType/.test(posIndexSrc), 'pos/index.vue must use defaultPosDocumentType');
    assert.ok(/normalizePosDocumentType/.test(posIndexSrc), 'pos/index.vue must normalize restored drafts');

    const optionsSrc = fs.readFileSync(path.join(root, 'resources/js/views/tenant/pos/partials/options.vue'), 'utf8');
    assert.ok(/v-if="resource !== 'sale-notes'"/.test(optionsSrc), 'options.vue must hide SUNAT status for sale notes');
    assert.ok(!/downloads\/Document\/\$\{type\}/.test(optionsSrc), 'options.vue must not reference undefined ${type}');

    const invoiceSrc = fs.readFileSync(path.join(root, 'resources/js/views/tenant/documents/invoice.vue'), 'utf8');
    assert.ok(/extractApiErrorMessage/.test(invoiceSrc), 'invoice.vue must use extractApiErrorMessage');

    console.log('ok - (5) static checks on POS and invoice Vue components passed');
}
