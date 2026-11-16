const assert = require('assert');
const fs = require('fs');
const path = require('path');
const os = require('os');
const crypto = require('crypto');
const publish = require('../../scripts/build-js');

function fixture(includeNewChunk) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'sunat-publish-'));
    const output = path.join(root, 'stage');
    const live = path.join(root, 'public');
    fs.mkdirSync(path.join(output, 'js'), { recursive: true });
    fs.mkdirSync(path.join(live, 'js'), { recursive: true });
    const files = ['/js/app.js', '/js/vendor.js', '/js/manifest.js'];
    if (includeNewChunk) files.splice(1, 0, '/js/new-chunk.js');
    const manifest = {};
    for (const file of files) {
        const bytes = 'new ' + file;
        fs.writeFileSync(path.join(output, file), bytes);
        manifest[file] = file + '?id=' + crypto.createHash('md5').update(bytes).digest('hex').slice(0, 20);
        if (file !== '/js/new-chunk.js') fs.writeFileSync(path.join(live, file), 'old ' + file);
    }
    fs.writeFileSync(path.join(output, 'mix-manifest.json'), JSON.stringify(manifest));
    fs.writeFileSync(path.join(live, 'mix-manifest.json'), '{"/css/app.css":"/css/app.css?id=unchanged"}');
    const before = Object.fromEntries(fs.readdirSync(path.join(live, 'js')).map(file => ['/js/' + file, fs.readFileSync(path.join(live, 'js', file), 'utf8')]));
    before['/mix-manifest.json'] = fs.readFileSync(path.join(live, 'mix-manifest.json'), 'utf8');
    return { root, output, live, before, files };
}

for (const scenario of ['second rename', 'manifest staging', 'manifest rename', 'new chunk rollback', 'staged copy']) {
    const f = fixture(scenario === 'new chunk rollback');
    const method = scenario === 'manifest staging' ? 'writeFileSync' : scenario === 'staged copy' ? 'copyFileSync' : 'renameSync';
    const original = fs[method];
    let calls = 0;
    let failed = false;
    fs[method] = function(...args) {
        const relevant = method === 'copyFileSync' ? String(args[1]).endsWith('.next') : true;
        if (relevant) calls++;
        const shouldFail = scenario === 'second rename' ? calls === 2 : scenario === 'new chunk rollback' ? calls === 3 : scenario === 'manifest rename' ? String(args[0]).endsWith('mix-manifest.json.next') : true;
        if (relevant && shouldFail && !failed) { failed = true; throw new Error('Injected ' + scenario); }
        return original.apply(fs, args);
    };
    try { assert.throws(() => publish(f.output, f.live), /Injected/); }
    finally { fs[method] = original; }
    for (const [file, bytes] of Object.entries(f.before)) assert.strictEqual(fs.readFileSync(path.join(f.live, file), 'utf8'), bytes, scenario + ': ' + file);
    assert.strictEqual(fs.existsSync(path.join(f.live, 'js/new-chunk.js')), false);
    assert(!fs.readdirSync(path.join(f.live, 'js')).some(file => file.endsWith('.next')));
    assert.strictEqual(fs.existsSync(path.join(f.live, 'mix-manifest.json.next')), false);
    assert(fs.existsSync(path.join(f.output, 'previous/mix-manifest.json')), 'Keep durable recovery backup');
    fs.rmSync(f.root, { recursive: true, force: true });
}
const success = fixture(true);
publish(success.output, success.live);
for (const file of success.files) assert.strictEqual(fs.readFileSync(path.join(success.live, file), 'utf8'), 'new ' + file);
assert.strictEqual(JSON.parse(fs.readFileSync(path.join(success.live, 'mix-manifest.json')))['/css/app.css'], '/css/app.css?id=unchanged');
fs.rmSync(success.root, { recursive: true, force: true });
console.log('JS publishing: success and five I/O-failure rollback scenarios passed.');
