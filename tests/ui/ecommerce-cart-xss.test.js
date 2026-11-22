// FEM-001: ecommerce cart data (localStorage / product JSON) must never be parsed as markup or
// inline JavaScript. Plain node + vm, no build step and no real browser: the real inline
// functions are extracted from the Blade views and run against a tiny hand-rolled fake DOM and
// jQuery subset. The fake jQuery THROWS if asked to parse an HTML string with data in it, so a
// regression to string-built markup fails the test. This proves the code path, not real jQuery.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const views = path.join(root, 'modules/Ecommerce/Resources/views');
const dropdownSrc = fs.readFileSync(path.join(views, 'layouts/partials_ecommerce/cart_dropdown.blade.php'), 'utf8');
const partialSrc = fs.readFileSync(path.join(views, 'items/partial.blade.php'), 'utf8');

function extractFunction(src, name) {
    const start = src.indexOf(`function ${name}(`);
    assert(start >= 0, `function ${name} not found`);
    const open = src.indexOf('{', src.indexOf(')', start));
    let depth = 0;
    for (let i = open; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}' && --depth === 0) return src.slice(start, i + 1);
    }
    throw new Error('unbalanced braces');
}

// ---------- fake DOM + jQuery subset ----------
class El {
    constructor(tag) { this.tag = tag; this.attrs = {}; this.classes = []; this.children = []; this.handlers = {}; }
}
const textNode = (t) => ({ text: String(t) });

function makeEnv() {
    const containers = { '.dropdown-cart-products': new El('div'), '.cart-count': new El('span'), '.cart-total-price': new El('span') };
    const store = new Map();
    function wrap(nodes) {
        const w = {
            nodes,
            attr(k, v) { nodes.forEach(n => { n.attrs[k] = String(v); }); return w; },
            addClass(c) { nodes.forEach(n => n.classes.push(c)); return w; },
            text(t) { nodes.forEach(n => { n.children = [textNode(t)]; }); return w; },
            empty() { nodes.forEach(n => { n.children = []; }); return w; },
            on(evt, fn) { nodes.forEach(n => { (n.handlers[evt] = n.handlers[evt] || []).push(fn); }); return w; },
            append(x) {
                nodes.forEach(n => {
                    if (x && x.nodes) x.nodes.forEach(c => n.children.push(c));
                    else if (x && x.text !== undefined) n.children.push(x);
                    else if (typeof x === 'number') n.children.push(textNode(x));
                    else if (typeof x === 'string' && !x.includes('<')) n.children.push(textNode(x));
                    else throw new Error('append() of a string would be parsed as HTML: ' + JSON.stringify(x));
                });
                return w;
            },
        };
        return w;
    }
    const $ = (arg) => {
        if (typeof arg === 'string') {
            if (/^<[a-z0-9]+>$/.test(arg)) return wrap([new El(arg.slice(1, -1))]);
            if (arg.includes('<')) throw new Error('HTML string passed to $(): ' + arg);
            assert(containers[arg], `unknown selector ${arg}`);
            return wrap([containers[arg]]);
        }
        return wrap([arg]);
    };
    const sandbox = {
        $,
        document: { createTextNode: textNode },
        localStorage: { getItem: k => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)) },
        Number, String, JSON, encodeURI, parseFloat, console,
    };
    return { sandbox, containers, store };
}

function loadDropdown() {
    const env = makeEnv();
    const code = ['remove', 'calculatetotal', 'cartImageUrl', 'buildCartDropdownItem', 'populate']
        .map(n => extractFunction(dropdownSrc, n)).join('\n');
    vm.runInNewContext(code + '\nthis.__fns = { remove, populate, calculatetotal };', env.sandbox);
    return Object.assign(env, env.sandbox.__fns && { fns: env.sandbox.__fns });
}

// Every node must be one of the expected static elements with no handler-ish attributes.
const ALLOWED_TAGS = new Set(['div', 'h4', 'a', 'span', 'figure', 'img', 'i']);
function walk(node, visit) { visit(node); (node.children || []).forEach(c => { if (c.tag) walk(c, visit); }); }
function assertStructureSafe(container) {
    walk(container, (n) => {
        if (n === container) return;
        assert(ALLOWED_TAGS.has(n.tag), `unexpected element <${n.tag}>`);
        Object.keys(n.attrs).forEach(k => assert(!/^on/i.test(k), `inline handler attribute ${k}`));
        if (n.tag === 'img' && n.attrs.src !== undefined) {
            assert(n.attrs.src.startsWith('/storage/uploads/items/'), `img src outside items path: ${n.attrs.src}`);
            assert(!/^\s*(javascript|data|vbscript):/i.test(n.attrs.src), 'unsafe img src scheme');
        }
        if (n.tag === 'a') assert(['$', '#'].includes(n.attrs.href), `unexpected href ${n.attrs.href}`);
    });
}
function collectText(node) {
    return (node.children || []).map(c => (c.tag ? collectText(c) : c.text)).join('');
}
function findAll(node, pred, acc = []) { walk(node, n => { if (n.tag && pred(n)) acc.push(n); }); return acc; }

// ---------- 1. hostile cart entries stay inert ----------
{
    const hostile = [
        { id: 1, description: '"><img src=x onerror=alert(1)>', sale_unit_price: '10.00', image_small: 'a.jpg' },
        { id: 2, description: '</script><script>alert(1)</script>', sale_unit_price: '5.00', image_small: 'b.jpg' },
        { id: 3, description: "'); alert(1); //", sale_unit_price: '1.00', image_small: 'javascript:alert(1)' },
        { id: "4); alert(1); //", description: 'bad id', sale_unit_price: '2.00', image_small: '../../etc/passwd' },
        { id: 5, description: 'quote image', sale_unit_price: '3.00', image_small: 'x" onerror="alert(1)' },
        { id: 6, description: 'no image', sale_unit_price: '4.00' },
        { id: 7, description: 'lone surrogate', sale_unit_price: '4.00', image_small: '\uD800' },
        { id: 8, description: '<b>price as html</b>', sale_unit_price: '<img src=x onerror=alert(1)>', image_small: 'c.jpg' },
    ];
    const env = loadDropdown();
    env.store.set('products_cart', JSON.stringify(hostile));
    env.fns.populate();
    const box = env.containers['.dropdown-cart-products'];

    assertStructureSafe(box);
    assert.strictEqual(box.children.length, hostile.length, 'one .product per entry');

    // Payloads appear verbatim as text only.
    const titles = findAll(box, n => n.classes.includes('product-title')).map(collectText);
    assert.deepStrictEqual(titles, hostile.map(h => h.description));
    const prices = findAll(box, n => n.classes.includes('cart-product-info')).map(collectText);
    assert.strictEqual(prices[7], '1 x <img src=x onerror=alert(1)>');

    // No script/img elements beyond the one product image per entry; no extra injected nodes.
    assert.strictEqual(findAll(box, n => n.tag === 'script').length, 0);
    assert.strictEqual(findAll(box, n => n.tag === 'img').length, hostile.length);

    // URL rules: traversal / control chars / lone surrogate / non-string / empty => no src at all.
    const imgs = findAll(box, n => n.tag === 'img');
    assert.strictEqual(imgs[0].attrs.src, '/storage/uploads/items/a.jpg');
    assert.strictEqual(imgs[2].attrs.src, '/storage/uploads/items/javascript:alert(1)'); // inert same-origin path, never a scheme
    assert.strictEqual(imgs[3].attrs.src, undefined, '.. traversal rejected');
    assert(!imgs[4].attrs.src.includes('"') && !imgs[4].attrs.src.includes(' '), 'quote/space encoded');
    assert.strictEqual(imgs[5].attrs.src, undefined, 'missing image rejected');
    assert.strictEqual(imgs[6].attrs.src, undefined, 'unencodable value rejected');

    // Non-numeric id: no remove control is rendered (nothing to inject into).
    const removes = findAll(box, n => n.classes.includes('btn-remove'));
    assert.strictEqual(removes.length, hostile.length - 1);
    assert(removes.every(n => n.attrs.onclick === undefined));
}

// ---------- 2. benign flow: render, remove, totals still work ----------
{
    const env = loadDropdown();
    env.store.set('products_cart', JSON.stringify([
        { id: 10, description: 'Laptop & "Pro" 15', sale_unit_price: '10.50', image_small: 'l p.jpg', name: 'x' },
        { id: 11, description: 'Mouse', sale_unit_price: '2.25', image_small: 'm.jpg' },
    ]));
    env.fns.populate();
    env.fns.calculatetotal();
    const box = env.containers['.dropdown-cart-products'];
    assert.strictEqual(collectText(env.containers['.cart-count']), '2');
    assert.strictEqual(collectText(env.containers['.cart-total-price']), '12.75');
    assert.strictEqual(findAll(box, n => n.classes.includes('product-title')).map(collectText)[0], 'Laptop & "Pro" 15');
    assert.strictEqual(findAll(box, n => n.tag === 'img')[0].attrs.src, '/storage/uploads/items/l%20p.jpg');

    // Click the first remove control: must call the real remove(10) -> storage updated, re-rendered.
    let prevented = false;
    findAll(box, n => n.classes.includes('btn-remove'))[0].handlers.click[0]({ preventDefault() { prevented = true; } });
    assert(prevented, 'default navigation suppressed');
    const left = JSON.parse(env.store.get('products_cart'));
    assert.deepStrictEqual(left.map(x => x.id), [11], 'storage format unchanged, item removed');
    assert.strictEqual(collectText(env.containers['.cart-count']), '1');
    assert.strictEqual(collectText(env.containers['.cart-total-price']), '2.25');
    assert.strictEqual(box.children.length, 1);
}

// ---------- 3. quick-view partial: product JSON is data, not inline code ----------
{
    assert(!/onclick\s*=/i.test(partialSrc), 'partial must not use inline onclick');
    assert(/data-product="\{\{ json_encode\( \$record \) \}\}"/.test(partialSrc), 'data-product must use escaped {{ }}');
    assert(!/\{!!/.test(partialSrc), 'no unescaped Blade output in partial');

    const scriptMatch = partialSrc.match(/<script[^>]*>([\s\S]*?)<\/script>/);
    assert(scriptMatch, 'partial listener script missing');

    // Hostile product JSON, escaped the way Blade {{ }} does, decoded the way a browser parses
    // the attribute value, then handed to cart_add exactly as the real listener would.
    const productJson = JSON.stringify({ id: 9, description: "'); alert(1); //", name: '"><img src=x onerror=alert(1)>', note: '</script><script>alert(1)</script>' });
    const bladeEscaped = productJson.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    const decodedAttr = bladeEscaped.replace(/&#039;/g, "'").replace(/&quot;/g, '"').replace(/&gt;/g, '>').replace(/&lt;/g, '<').replace(/&amp;/g, '&');
    assert.strictEqual(decodedAttr, productJson, 'attribute round-trips losslessly');

    const registered = [];
    const calls = [];
    const jQuery = (arg) => {
        if (typeof arg === 'string') {
            const sel = { off() { return sel; }, on(evt, fn) { registered.push({ selector: arg, evt, fn }); return sel; } };
            return sel;
        }
        return { attr: (k) => arg.attrs[k] };
    };
    vm.runInNewContext(scriptMatch[1], { jQuery, cart_add: (d) => calls.push(d) });
    assert.strictEqual(registered.length, 1);
    assert.strictEqual(registered[0].selector, '.product-quick-view .add-cart');
    assert(/^click/.test(registered[0].evt));
    let prevented = false;
    registered[0].fn.call({ attrs: { 'data-product': decodedAttr } }, { preventDefault() { prevented = true; } });
    assert(prevented);
    assert.deepStrictEqual(calls, [productJson], 'cart_add receives the exact JSON string (same semantics as before)');
    assert.deepStrictEqual(JSON.parse(calls[0]).description, "'); alert(1); //", 'parsed as data');
}

// ---------- 4. static guards on the dropdown view ----------
{
    assert(!/onclick\s*=/i.test(dropdownSrc), 'dropdown must not use inline onclick');
    assert(!/\.append\(\s*`/.test(dropdownSrc), 'dropdown must not append template-literal HTML');
    assert(!/\.html\(/.test(dropdownSrc), 'dropdown must not use .html()');
}

console.log('ecommerce-cart-xss: all assertions passed');
