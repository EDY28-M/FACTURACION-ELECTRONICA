// FEM-001 (part 2): the raw-served storefront scripts public/porto-ecommerce/assets/js/cart.js and
// main.js must never turn cart / product data into markup or inline JavaScript. Plain node + vm, no
// build and no real browser: the REAL function text is extracted from both files and run against a
// tiny hand-rolled fake DOM + jQuery subset. The fake jQuery THROWS on any HTML string that is not a
// bare tag and has no .html(), so a regression to string-built markup fails the test. This proves
// the code path, not real jQuery / browser behaviour.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const jsDir = path.join(root, 'public/porto-ecommerce/assets/js');
const cartSrc = fs.readFileSync(path.join(jsDir, 'cart.js'), 'utf8');
const mainSrc = fs.readFileSync(path.join(jsDir, 'main.js'), 'utf8');

function matchBraces(src, from) {
    const open = src.indexOf('{', from);
    let depth = 0;
    for (let i = open; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}' && --depth === 0) return i;
    }
    throw new Error('unbalanced braces');
}
function extractFunction(src, name) {
    const start = src.indexOf(`function ${name}(`);
    assert(start >= 0, `function ${name} not found`);
    return src.slice(start, matchBraces(src, src.indexOf(')', start)) + 1);
}
// Object-literal method inside main.js's IIFE: "<name>: function(...) { ... }".
function extractMethod(src, name) {
    const m = new RegExp(`\\n\\t+${name}: function\\(`).exec(src);
    assert(m, `method ${name} not found`);
    const start = m.index + 1;
    return src.slice(start, matchBraces(src, src.indexOf(')', start)) + 1);
}

// ---------- fake DOM + jQuery subset ----------
class El {
    constructor(tag) { this.tag = tag; this.attrs = {}; this.classes = []; this.children = []; this.handlers = {}; }
}
const textNode = (t) => ({ text: String(t) });

function makeEnv() {
    const selectors = ['.dropdown-cart-products', '.cart-count', '.cart-total-price', '#product_added', '#product_added_image', '.add-cart'];
    const containers = {};
    selectors.forEach(s => { containers[s] = new El('div'); });
    const modals = [];
    const store = new Map();
    const removed = [];
    function wrap(nodes) {
        const w = {
            nodes,
            attr(k, v) { nodes.forEach(n => { n.attrs[k] = String(v); }); return w; },
            addClass(c) { nodes.forEach(n => n.classes.push(c)); return w; },
            text(t) { nodes.forEach(n => { n.children = [textNode(t)]; }); return w; },
            empty() { nodes.forEach(n => { n.children = []; }); return w; },
            on(evt, fn) { nodes.forEach(n => { (n.handlers[evt] = n.handlers[evt] || []).push(fn); }); return w; },
            click(fn) { return w.on('click', fn); },
            data(k) { return nodes[0].dataStore[k]; },
            modal(action) { modals.push([nodes[0].selector, action]); return w; },
            html() { throw new Error('.html() would parse a string as markup'); },
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
            if (containers[arg]) { containers[arg].selector = arg; return wrap([containers[arg]]); }
            if (/^#(moda-succes-add-product|modal-already-product)$/.test(arg)) return wrap([{ selector: arg }]);
            throw new Error(`unknown selector ${arg}`);
        }
        return wrap([arg]);
    };
    const sandbox = {
        $, jQuery: $, e: $,
        document: { createTextNode: textNode },
        localStorage: { getItem: k => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)) },
        remove: (id) => removed.push(id),
        Number, String, JSON, encodeURI, parseFloat, console,
    };
    return { sandbox, containers, store, modals, removed };
}

const CART_FNS = ['cartItemImageUrl', 'buildCartProductNode', 'cart_add', 'productsCartDropDown', 'calculateTotalCart'];
function loadCart() {
    const env = makeEnv();
    vm.runInNewContext(CART_FNS.map(n => extractFunction(cartSrc, n)).join('\n') +
        '\nthis.__fns = { ' + CART_FNS.join(', ') + ' };', env.sandbox);
    env.fns = env.sandbox.__fns;
    return env;
}

const MAIN_METHODS = ['cartImageUrl', 'buildCartProduct', 'productsCartDropDown', 'addToCart', 'successAddProduct', 'alreadyProductCart', 'calculateTotalCart'];
function loadMain() {
    const env = makeEnv();
    vm.runInNewContext('this.__o = {\n' + MAIN_METHODS.map(n => extractMethod(mainSrc, n)).join(',\n') + '\n};', env.sandbox);
    env.o = env.sandbox.__o;
    return env;
}

const ALLOWED_TAGS = new Set(['div', 'h1', 'h4', 'a', 'p', 'span', 'figure', 'img', 'i']);
function walk(node, visit) { visit(node); (node.children || []).forEach(c => { if (c.tag) walk(c, visit); }); }
function assertStructureSafe(container) {
    walk(container, (n) => {
        if (n === container) return;
        assert(ALLOWED_TAGS.has(n.tag), `unexpected element <${n.tag}>`);
        Object.keys(n.attrs).forEach(k => assert(!/^on/i.test(k), `inline handler attribute ${k}`));
        if (n.tag === 'img' && n.attrs.src !== undefined) {
            assert(n.attrs.src.startsWith('/storage/uploads/items/'), `img src outside items path: ${n.attrs.src}`);
        }
        if (n.tag === 'a') assert(['$', '#'].includes(n.attrs.href), `unexpected href ${n.attrs.href}`);
    });
}
const collectText = (node) => (node.children || []).map(c => (c.tag ? collectText(c) : c.text)).join('');
function findAll(node, pred, acc = []) { walk(node, n => { if (n.tag && pred(n)) acc.push(n); }); return acc; }
function ser(n) {
    if (!n.tag) return n.text;
    const attrs = Object.keys(n.attrs).sort().map(k => ` ${k}="${n.attrs[k]}"`).join('');
    const cls = n.classes.length ? ` class="${n.classes.join(' ')}"` : '';
    return `<${n.tag}${cls}${attrs}>${n.children.map(ser).join('')}</${n.tag}>`;
}

const hostile = [
    { id: 1, name: '"><img src=x onerror=alert(1)>', description: '"><img src=x onerror=alert(1)>', sale_unit_price: '10.00', image_small: 'a.jpg', image_medium: 'a.jpg' },
    { id: 2, name: '</script><script>alert(1)</script>', description: '</script><script>alert(1)</script>', sale_unit_price: '5.00', image_small: 'b.jpg', image_medium: 'b.jpg' },
    { id: 3, name: "'); alert(1); //", description: "'); alert(1); //", sale_unit_price: '1.00', image_small: 'javascript:alert(1)', image_medium: 'javascript:alert(1)' },
    { id: '4); alert(1); //', name: '<svg onload=alert(1)>', description: '<svg onload=alert(1)>', sale_unit_price: '2.00', image_small: '../../x', image_medium: '../../x' },
    { id: 5, name: 'quote image', description: 'quote image', sale_unit_price: '3.00', image_small: 'x" onerror="alert(1)', image_medium: 'x" onerror="alert(1)' },
    { id: 6, name: 'no image', description: 'no image', sale_unit_price: '4.00' },
    { id: 7, name: 'lone surrogate', description: 'lone surrogate', sale_unit_price: '4.00', image_small: '\uD800', image_medium: '\uD800' },
    { id: 8, name: '<b>price as html</b>', description: '<b>price as html</b>', sale_unit_price: '<img src=x onerror=alert(1)>', image_small: 'c.jpg', image_medium: 'c.jpg' },
    { id: 9, name: 'backslash', description: 'backslash', sale_unit_price: '1', image_small: 'a\\b.jpg', image_medium: 'a\\b.jpg' },
];

function assertDropdownHostile(env, titleField, rendered) {
    const box = env.containers['.dropdown-cart-products'];
    assertStructureSafe(box);
    assert.strictEqual(box.children.length, hostile.length, 'one .product per entry');
    const titles = findAll(box, n => n.classes.includes('product-title')).map(collectText);
    assert.deepStrictEqual(titles, hostile.map(h => h[titleField]), 'payloads appear verbatim as text only');
    const prices = findAll(box, n => n.classes.includes('cart-product-info')).map(collectText);
    assert.strictEqual(prices[7], '1 x <img src=x onerror=alert(1)>');
    assert.strictEqual(findAll(box, n => n.tag === 'script').length, 0);
    const imgs = findAll(box, n => n.tag === 'img');
    assert.strictEqual(imgs.length, hostile.length, 'exactly one <img> per entry, nothing injected');
    assert.strictEqual(imgs[0].attrs.src, '/storage/uploads/items/a.jpg');
    assert.strictEqual(imgs[2].attrs.src, '/storage/uploads/items/javascript:alert(1)'); // inert same-origin path, never a scheme
    assert.strictEqual(imgs[3].attrs.src, undefined, '.. traversal rejected');
    assert(!imgs[4].attrs.src.includes('"') && !imgs[4].attrs.src.includes(' '), 'quote/space encoded');
    assert.strictEqual(imgs[5].attrs.src, undefined, 'missing image rejected');
    assert.strictEqual(imgs[6].attrs.src, undefined, 'unencodable value rejected');
    assert.strictEqual(imgs[8].attrs.src, undefined, 'backslash rejected');
    const removes = findAll(box, n => n.classes.includes('btn-remove'));
    assert.strictEqual(removes.length, hostile.length - 1, 'non-numeric id gets no remove control');
    assert(removes.every(n => n.attrs.onclick === undefined));
    assert.strictEqual(collectText(env.containers['.cart-count']), String(hostile.length));
    return rendered;
}

// ---------- 1. cart.js: dropdown with hostile cart entries ----------
{
    const env = loadCart();
    env.store.set('products_cart', JSON.stringify(hostile));
    env.fns.productsCartDropDown();
    assertDropdownHostile(env, 'description');
}

// ---------- 2. main.js: dropdown with hostile cart entries ----------
{
    const env = loadMain();
    env.store.set('products_cart', JSON.stringify(hostile));
    env.o.productsCartDropDown();
    assertDropdownHostile(env, 'name');
}

// ---------- 3. cart.js cart_add: modal content from hostile product JSON ----------
for (const item of hostile) {
    const env = loadCart();
    env.store.set('products_cart', '[]');
    env.fns.cart_add(JSON.stringify(item));
    assertStructureSafe(env.containers['#product_added']);
    assertStructureSafe(env.containers['#product_added_image']);
    const added = env.containers['#product_added'];
    assert.strictEqual(collectText(findAll(added, n => n.tag === 'h1')[0]), item.description);
    assert.strictEqual(collectText(findAll(added, n => n.tag === 'p')[0]), item.name);
    assert.strictEqual(findAll(added, n => n.tag === 'script' || n.tag === 'svg').length, 0);
    const imgs = findAll(env.containers['#product_added_image'], n => n.tag === 'img');
    assert.strictEqual(imgs.length, 1);
    if (['../../x', '\uD800', 'a\\b.jpg', undefined].includes(item.image_medium)) assert.strictEqual(imgs[0].attrs.src, undefined);
    else assert(imgs[0].attrs.src.startsWith('/storage/uploads/items/'));
    assert.deepStrictEqual(JSON.parse(env.store.get('products_cart')), [item], 'localStorage format unchanged');
    assert.deepStrictEqual(env.modals, [['#moda-succes-add-product', 'show']]);
}

// ---------- 4. main.js add-to-cart click: modal content from hostile data-product ----------
for (const item of hostile) {
    const env = loadMain();
    env.store.set('products_cart', '[]');
    env.o.addToCart();
    const handler = env.containers['.add-cart'].handlers.click[0];
    handler.call({ dataStore: { product: item } }, { preventDefault() {} });
    assertStructureSafe(env.containers['#product_added']);
    assertStructureSafe(env.containers['#product_added_image']);
    const added = env.containers['#product_added'];
    assert.strictEqual(collectText(findAll(added, n => n.tag === 'h1')[0]), item.name);
    assert.strictEqual(collectText(findAll(added, n => n.tag === 'p')[0]), item.description);
    const imgs = findAll(env.containers['#product_added_image'], n => n.tag === 'img');
    assert.strictEqual(imgs.length, 1);
    if (['../../x', '\uD800', 'a\\b.jpg', undefined].includes(item.image_medium)) assert.strictEqual(imgs[0].attrs.src, undefined);
    else assert(imgs[0].attrs.src.startsWith('/storage/uploads/items/'));
    assert.deepStrictEqual(JSON.parse(env.store.get('products_cart')), [item], 'localStorage format unchanged');
    assert.deepStrictEqual(env.modals, [['#moda-succes-add-product', 'show']]);
}

// ---------- 5. benign flow: structure, duplicates, remove wiring, totals ----------
{
    const benign = { id: 10, name: 'Laptop 15', description: 'Laptop & "Pro" 15', sale_unit_price: '10.5', image_small: 'l p.jpg', image_medium: 'l p.jpg' };
    const expectedProduct = (title) =>
        '<div class="product">' +
        `<div class="product-details"><h4 class="product-title"><a href="$">${title}</a></h4>` +
        '<span class="cart-product-info"><span class="cart-product-qty">1</span> x 10.5</span></div>' +
        '<figure class="product-image-container"><a class="product-image" href="#"><img alt="product" src="/storage/uploads/items/l%20p.jpg"></img></a>' +
        '<a class="btn-remove" href="#" title="Remove Product"><i class="icon-cancel"></i></a></figure></div>';
    const expectedAdded = (title, desc) =>
        `<h1 class="product-title">${title}</h1><div class="price-box"><span class="product-price">S/ 10.50</span></div><div class="product-desc"><p>${desc}</p></div>`;

    // cart.js
    let env = loadCart();
    env.store.set('products_cart', '[]');
    env.fns.cart_add(JSON.stringify(benign));
    env.fns.cart_add(JSON.stringify(benign)); // duplicate id -> "already" modal, no second push
    assert.strictEqual(JSON.parse(env.store.get('products_cart')).length, 1);
    assert.deepStrictEqual(env.modals, [['#moda-succes-add-product', 'show'], ['#modal-already-product', 'show']]);
    assert.strictEqual(env.containers['#product_added'].children.map(ser).join(''), expectedAdded('Laptop & "Pro" 15', 'Laptop 15'));
    assert.strictEqual(ser(env.containers['#product_added_image'].children[0]), '<img class="img" alt="product" src="/storage/uploads/items/l%20p.jpg"></img>');
    assert.strictEqual(env.containers['.dropdown-cart-products'].children.map(ser).join(''), expectedProduct('Laptop & "Pro" 15'));
    assert.strictEqual(collectText(env.containers['.cart-count']), '1');
    assert.strictEqual(collectText(env.containers['.cart-total-price']), '10.50');
    let prevented = false;
    findAll(env.containers['.dropdown-cart-products'], n => n.classes.includes('btn-remove'))[0].handlers.click[0]({ preventDefault() { prevented = true; } });
    assert(prevented, 'default navigation suppressed');
    assert.deepStrictEqual(env.removed, [10], 'remove(id) called with the numeric id');

    // main.js
    env = loadMain();
    env.store.set('products_cart', '[]');
    env.o.addToCart();
    const click = env.containers['.add-cart'].handlers.click[0];
    click.call({ dataStore: { product: benign } }, { preventDefault() {} });
    click.call({ dataStore: { product: benign } }, { preventDefault() {} });
    assert.strictEqual(JSON.parse(env.store.get('products_cart')).length, 1);
    assert.deepStrictEqual(env.modals, [['#moda-succes-add-product', 'show'], ['#modal-already-product', 'show']]);
    assert.strictEqual(env.containers['#product_added'].children.map(ser).join(''), expectedAdded('Laptop 15', 'Laptop & "Pro" 15'));
    assert.strictEqual(env.containers['.dropdown-cart-products'].children.map(ser).join(''), expectedProduct('Laptop 15'));
    assert.strictEqual(collectText(env.containers['.cart-total-price']), '10.50');
    findAll(env.containers['.dropdown-cart-products'], n => n.classes.includes('btn-remove'))[0].handlers.click[0]({ preventDefault() {} });
    assert.deepStrictEqual(env.removed, [10]);
}

// ---------- 6. static guards on the real files ----------
for (const [name, src] of [['cart.js', cartSrc], ['main.js', mainSrc]]) {
    assert(!/onclick\s*=/i.test(src), `${name} must not use inline onclick`);
    assert(!/\.html\(/.test(src), `${name} must not use .html()`);
    assert(!/\.append\(\s*`/.test(src), `${name} must not append template-literal HTML`);
    assert(!/\$\{[^}]*(description|name|image_|sale_unit_price|\.id)[^}]*\}/.test(src), `${name} must not interpolate cart data into strings`);
}

console.log('ecommerce-public-cart-xss: all assertions passed');
