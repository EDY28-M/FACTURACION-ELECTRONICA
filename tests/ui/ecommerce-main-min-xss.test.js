// FEM-001 (part 3): public/porto-ecommerce/assets/js/main.min.js is the file the storefront layouts
// actually load. It must never turn cart / product data into markup or inline JavaScript.
// Plain node + vm, no build, no real browser: the WHOLE minified IIFE is executed against a
// hand-rolled fake DOM + jQuery. Document-ready is fired, so the real `init()` registers the real
// `.add-cart` click handler; the dropdown is driven through that handler (the module object is not
// exposed). The fake jQuery THROWS on .html() and on any HTML string that is not a bare tag, so a
// regression to string-built markup fails the test. Anything the cart code does not touch is a
// permissive stub. This proves the code path, not real jQuery / browser behaviour.
// MIN_JS_PATH can point to another copy of the file (used for mutation checks).
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..', '..');
const minPath = process.env.MIN_JS_PATH || path.join(root, 'public/porto-ecommerce/assets/js/main.min.js');
const minSrc = fs.readFileSync(minPath, 'utf8');

// ---------- permissive stub for everything unrelated to the cart ----------
function loose(overrides = {}) {
    const target = function () {};
    return new Proxy(target, {
        get(_, key) {
            if (key in overrides) return overrides[key];
            if (key === Symbol.toPrimitive) return () => 0;
            if (key === 'then') return undefined;
            return loose();
        },
        apply() { return loose(); },
        construct() { return loose(); },
    });
}

// ---------- fake DOM + jQuery subset (the cart containers) ----------
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
    const readyFns = [];
    const state = { strict: false };

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

    const windowStub = loose();
    const documentStub = loose({
        createTextNode: textNode,
        ready(fn) { readyFns.push(fn); return documentStub; },
    });

    const $ = (arg) => {
        if (typeof arg === 'string') {
            if (/^<[a-z0-9]+>$/.test(arg)) return wrap([new El(arg.slice(1, -1))]);
            if (arg.includes('<')) {
                if (state.strict) throw new Error('HTML string passed to $(): ' + arg);
                return loose();
            }
            if (containers[arg]) { containers[arg].selector = arg; return wrap([containers[arg]]); }
            if (/^#(moda-succes-add-product|modal-already-product)$/.test(arg)) return wrap([{ selector: arg }]);
            return loose();
        }
        if (arg === documentStub || arg === windowStub) return arg === documentStub ? documentStub : loose();
        if (arg && typeof arg === 'object' && arg.dataStore) return wrap([arg]);
        return loose();
    };

    $.fn = {}; // plugin probes (t.fn.owlCarousel etc.) must see "plugin missing"
    const sandbox = {
        $, jQuery: $,
        Waypoint: loose(),
        window: windowStub,
        document: documentStub,
        navigator: loose(),
        localStorage: { getItem: k => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)) },
        remove: (id) => removed.push(id),
        console,
    };
    return { sandbox, containers, store, modals, removed, readyFns, state };
}

// Runs the real minified file, fires document-ready (real init()), and returns a driver.
function loadMin(initialCart = []) {
    const env = makeEnv();
    env.store.set('products_cart', JSON.stringify(initialCart));
    vm.runInNewContext(minSrc, env.sandbox, { filename: 'main.min.js' });
    assert(env.readyFns.length > 0, 'document-ready callback was registered');
    env.readyFns.forEach(fn => fn());
    env.state.strict = true;
    const handlers = env.containers['.add-cart'].handlers.click;
    assert(handlers && handlers.length === 1, 'init() registered the real .add-cart click handler');
    env.click = (product) => handlers[0].call({ dataStore: { product } }, { preventDefault() {} });
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
const NO_IMAGE = ['../../x', '\uD800', 'a\\b.jpg', undefined];
const benign = { id: 10, name: 'Laptop 15', description: 'Laptop & "Pro" 15', sale_unit_price: '10.5', image_small: 'l p.jpg', image_medium: 'l p.jpg' };

// ---------- 1. dropdown (productsCartDropDown) with hostile cart entries ----------
{
    // Cart pre-seeded with hostile entries (as if localStorage was tampered with); a benign add
    // triggers the real productsCartDropDown, which re-renders every entry.
    const env = loadMin(hostile);
    env.click(benign);
    const box = env.containers['.dropdown-cart-products'];
    assertStructureSafe(box);
    assert.strictEqual(box.children.length, hostile.length + 1, 'one .product per entry');
    const titles = findAll(box, n => n.classes.includes('product-title')).map(collectText);
    assert.deepStrictEqual(titles, hostile.map(h => h.name).concat(benign.name), 'payloads appear verbatim as text only');
    const infos = findAll(box, n => n.classes.includes('cart-product-info')).map(collectText);
    assert.strictEqual(infos[7], '1 x <img src=x onerror=alert(1)>', 'HTML in price stays text');
    assert.strictEqual(findAll(box, n => n.tag === 'script' || n.tag === 'svg').length, 0);
    const imgs = findAll(box, n => n.tag === 'img');
    assert.strictEqual(imgs.length, hostile.length + 1, 'exactly one img element per entry, nothing injected');
    assert.strictEqual(imgs[0].attrs.src, '/storage/uploads/items/a.jpg');
    assert.strictEqual(imgs[2].attrs.src, '/storage/uploads/items/javascript:alert(1)', 'inert same-origin path, never a scheme');
    assert.strictEqual(imgs[3].attrs.src, undefined, '.. traversal rejected');
    assert(!imgs[4].attrs.src.includes('"') && !imgs[4].attrs.src.includes(' '), 'quote/space encoded');
    assert.strictEqual(imgs[5].attrs.src, undefined, 'missing image rejected');
    assert.strictEqual(imgs[6].attrs.src, undefined, 'unencodable value rejected');
    assert.strictEqual(imgs[8].attrs.src, undefined, 'backslash rejected');
    assert.strictEqual(imgs[9].attrs.src, '/storage/uploads/items/l%20p.jpg');
    const removes = findAll(box, n => n.classes.includes('btn-remove'));
    assert.strictEqual(removes.length, hostile.length, 'non-numeric id gets no remove control (9 numeric + benign = 10 entries, 1 non-numeric)');
    assert(removes.every(n => n.attrs.onclick === undefined), 'no onclick attribute');
    assert.strictEqual(collectText(env.containers['.cart-count']), String(hostile.length + 1));
}

// ---------- 2. add-to-cart modal content from hostile data-product ----------
for (const item of hostile) {
    const env = loadMin([]);
    env.click(item);
    assertStructureSafe(env.containers['#product_added']);
    assertStructureSafe(env.containers['#product_added_image']);
    assertStructureSafe(env.containers['.dropdown-cart-products']);
    const added = env.containers['#product_added'];
    assert.strictEqual(collectText(findAll(added, n => n.tag === 'h1')[0]), item.name);
    assert.strictEqual(collectText(findAll(added, n => n.tag === 'p')[0]), item.description);
    assert.strictEqual(findAll(added, n => n.tag === 'script' || n.tag === 'svg').length, 0);
    const imgs = findAll(env.containers['#product_added_image'], n => n.tag === 'img');
    assert.strictEqual(imgs.length, 1);
    if (NO_IMAGE.includes(item.image_medium)) assert.strictEqual(imgs[0].attrs.src, undefined);
    else assert(imgs[0].attrs.src.startsWith('/storage/uploads/items/'));
    assert.deepStrictEqual(JSON.parse(env.store.get('products_cart')), [item], 'localStorage format unchanged');
    assert.deepStrictEqual(env.modals, [['#moda-succes-add-product', 'show']]);
}

// ---------- 3. benign flow: structure, duplicates, remove wiring, totals ----------
{
    const expectedProduct =
        '<div class="product">' +
        '<div class="product-details"><h4 class="product-title"><a href="$">Laptop 15</a></h4>' +
        '<span class="cart-product-info"><span class="cart-product-qty">1</span> x 10.5</span></div>' +
        '<figure class="product-image-container"><a class="product-image" href="#"><img alt="product" src="/storage/uploads/items/l%20p.jpg"></img></a>' +
        '<a class="btn-remove" href="#" title="Remove Product"><i class="icon-cancel"></i></a></figure></div>';
    const expectedAdded =
        '<h1 class="product-title">Laptop 15</h1><div class="price-box"><span class="product-price">S/ 10.50</span></div>' +
        '<div class="product-desc"><p>Laptop & "Pro" 15</p></div>';

    const env = loadMin([]);
    env.click(benign);
    env.click(benign); // duplicate id -> "already" modal, no second push
    assert.strictEqual(JSON.parse(env.store.get('products_cart')).length, 1);
    assert.deepStrictEqual(env.modals, [['#moda-succes-add-product', 'show'], ['#modal-already-product', 'show']]);
    assert.strictEqual(env.containers['#product_added'].children.map(ser).join(''), expectedAdded);
    assert.strictEqual(ser(env.containers['#product_added_image'].children[0]), '<img class="img" alt="product" src="/storage/uploads/items/l%20p.jpg"></img>');
    assert.strictEqual(env.containers['.dropdown-cart-products'].children.map(ser).join(''), expectedProduct);
    assert.strictEqual(collectText(env.containers['.cart-count']), '1');
    assert.strictEqual(collectText(env.containers['.cart-total-price']), '10.50');
    let prevented = false;
    findAll(env.containers['.dropdown-cart-products'], n => n.classes.includes('btn-remove'))[0]
        .handlers.click[0]({ preventDefault() { prevented = true; } });
    assert(prevented, 'default navigation suppressed');
    assert.deepStrictEqual(env.removed, [10], 'remove(id) called with the numeric id');
    assert.deepStrictEqual(JSON.parse(env.store.get('products_cart')), [benign], 'localStorage format unchanged');
}

// ---------- 4. static guards on the real minified file ----------
assert(!/\.html\(/.test(minSrc), 'main.min.js must not use .html()');
assert(!/onclick\s*=/i.test(minSrc), 'main.min.js must not use inline onclick');
assert(!/\.append\(\s*`/.test(minSrc), 'main.min.js must not append template-literal HTML');
assert(!/\$\{[^}]*(description|name|image_|sale_unit_price|\.id)[^}]*\}/.test(minSrc), 'main.min.js must not interpolate cart data into strings');

console.log('ecommerce-main-min-xss: all assertions passed');
