const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

function publish(output, publicPath) {
    const manifestPath = path.join(publicPath, 'mix-manifest.json');
    const previous = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    const manifest = JSON.parse(fs.readFileSync(path.join(output, 'mix-manifest.json'), 'utf8'));
    const files = Object.keys(manifest);
    for (const file of files) {
        if (!/^\/js\/[\w.-]+$/.test(file)) throw new Error(`Unexpected JS build output: ${file}`);
        const bytes = fs.readFileSync(path.join(output, file));
        const hash = crypto.createHash('md5').update(bytes).digest('hex').slice(0, 20);
        if (!manifest[file].endsWith(hash)) throw new Error(`Invalid output hash: ${file}`);
    }
    const targets = files.concat('/mix-manifest.json');
    const backup = path.join(output, 'previous');
    const existing = new Set();
    for (const file of targets) {
        if (!fs.existsSync(path.join(publicPath, file))) continue;
        existing.add(file);
        fs.mkdirSync(path.dirname(path.join(backup, file)), { recursive: true });
        fs.copyFileSync(path.join(publicPath, file), path.join(backup, file));
    }
    const published = [];
    try {
        for (const file of files) fs.copyFileSync(path.join(output, file), path.join(publicPath, file) + '.next');
        fs.writeFileSync(manifestPath + '.next', JSON.stringify({ ...previous, ...manifest }, null, 4) + '\n');
        for (const file of targets) {
            fs.renameSync(path.join(publicPath, file) + '.next', path.join(publicPath, file));
            published.push(file);
        }
    } catch (error) {
        const failures = [];
        for (const file of published.reverse()) {
            try {
                if (existing.has(file)) fs.copyFileSync(path.join(backup, file), path.join(publicPath, file));
                else fs.unlinkSync(path.join(publicPath, file));
            } catch (rollbackError) { failures.push(rollbackError.message); }
        }
        for (const file of targets) {
            try { fs.rmSync(path.join(publicPath, file) + '.next', { force: true }); }
            catch (cleanupError) { failures.push(cleanupError.message); }
        }
        if (failures.length) throw new Error(`${error.message}; recovery errors: ${failures.join('; ')}. Previous assets: ${backup}`);
        throw error;
    }
}

function build() {
    const webpack = require('webpack');
    const root = path.resolve(__dirname, '..');
    process.chdir(root);
    const output = '.local/build-js';
    fs.rmSync(path.join(root, output), { recursive: true, force: true });
    process.env.MIX_JS_ONLY = '1';
    process.env.MIX_OUTPUT_PATH = output;
    const config = require('laravel-mix/setup/webpack.config');
    webpack(config, (error, stats) => {
        try {
            if (error || stats.hasErrors()) throw error || new Error(stats.toString({ colors: true, chunks: false, modules: false }));
            publish(output, path.join(root, 'public'));
            console.log(stats.toString({ colors: true, chunks: false, modules: false }));
            console.log('JavaScript assets published; existing stylesheet manifest entries preserved.');
        } catch (buildError) {
            console.error(buildError);
            process.exit(1);
        }
        // Webpack 3's legacy loader workers keep handles open after the completed build callback.
        process.exit(0);
    });
}

module.exports = publish;
if (require.main === module) build();
