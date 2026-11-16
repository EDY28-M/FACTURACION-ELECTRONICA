// Stroke width is set from CSS (.sys-icon / .auth-icon) so it can be tuned per scale.
// Builds public/images/system-icons.svg from the official lucide-static icons.
// Icon names are collected from the system and tenant shell Blade/Vue sources; the script fails on unknown names.
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const sources = [
    'resources/views/system',
    'resources/views/vendor/laravel-log-viewer',
    'resources/js/views/system',
    'modules/Account/Resources/assets/js/views/system',
    'modules/Account/Resources/views/system',
    // Tenant shell (sidebar rail + top bar): @include('tenant.layouts.partials.icon', ['name' => 'x'])
    'resources/views/tenant/layouts/partials',
];
// Names that are built dynamically and cannot be detected from the source text.
const EXTRA = [];

function walk(dir, out = []) {
    const full = path.join(root, dir);
    if (!fs.existsSync(full)) return out;
    for (const entry of fs.readdirSync(full, { withFileTypes: true })) {
        const rel = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(rel, out);
        else if (/\.(vue|php)$/.test(entry.name)) out.push(rel);
    }
    return out;
}

const names = new Set(EXTRA);
const quoted = /'([a-z][a-z0-9]*(?:-[a-z0-9]+)*)'/g;
for (const file of sources.flatMap(dir => walk(dir))) {
    const text = fs.readFileSync(path.join(root, file), 'utf8');
    let match;
    // $icon('name') and $icon(cond ? 'a' : 'b')
    for (const call of text.matchAll(/\$icon\(((?:[^()]|\([^()]*\))*)\)/g)) {
        while ((match = quoted.exec(call[1]))) names.add(match[1]);
    }
    // @include('...partials.icon', ['name' => 'x'])
    for (const m of text.matchAll(/'name'\s*=>\s*'([a-z0-9-]+)'/g)) names.add(m[1]);
    // sidebar navigation rows: ['key', 'Label', 'icon-name', url]
    if (file.startsWith('resources/views/system/') && file.endsWith('partials/sidebar.blade.php')) for (const m of text.matchAll(/\['[a-z-]+',\s*'[^']+',\s*'([a-z0-9-]+)',/g)) names.add(m[1]);
    // literal sprite references: ...system-icons.svg')) }}#eye"  and JS strings like '#eye-off'
    for (const m of text.matchAll(/\)\) \}\}#([a-z][a-z0-9-]*)"/g)) names.add(m[1]);
    if (file.endsWith('auth/login.blade.php')) for (const m of text.matchAll(/'#([a-z][a-z0-9-]*)'/g)) names.add(m[1]);
    // icon: 'name' in component data
    for (const m of text.matchAll(/\bicon:\s*'([a-z0-9-]+)'/g)) names.add(m[1]);
}

const iconDir = path.join(root, 'node_modules/lucide-static/icons');
const missing = [...names].filter(name => !fs.existsSync(path.join(iconDir, name + '.svg')));
if (missing.length) {
    console.error('Unknown Lucide icons: ' + missing.join(', '));
    process.exit(1);
}

const symbols = [...names].sort().map(name => {
    const svg = fs.readFileSync(path.join(iconDir, name + '.svg'), 'utf8');
    const body = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>[\s\S]*$/, '').replace(/\s+/g, ' ').trim();
    return `<symbol id="${name}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">${body}</symbol>`;
});
const output = `<svg xmlns="http://www.w3.org/2000/svg">${symbols.join('')}</svg>\n`;
fs.writeFileSync(path.join(root, 'public/images/system-icons.svg'), output);
console.log(`system-icons.svg: ${symbols.length} Lucide icons (${[...names].sort().join(', ')})`);
