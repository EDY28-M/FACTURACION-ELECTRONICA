const mix = require('laravel-mix');
const path = require('path');
const webpack = require('webpack');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel application. By default, we are compiling the Sass
 | file for the application as well as bundling up all the JS files.
 |
 */

const outputPath = process.env.MIX_OUTPUT_PATH || 'public';

mix.setPublicPath(outputPath)
   .js('resources/js/app.js', `${outputPath}/js`)
   .extract(['vue'])
   .version();

// shortcut: legacy CSS parser rejects Dart Sass output; upgrade loaders before changing Sass sources.
if (!process.env.MIX_JS_ONLY) {
    mix.sass('resources/sass/style.scss', `${outputPath}/css/app.css`)
       .sass('resources/sass/auth.scss', `${outputPath}/css/auth.css`);
}

mix.webpackConfig({
    output: {
        // Async page chunks are emitted next to the entries as content-hashed files and
        // requested from /js/ (published by scripts/build-js.js).
        publicPath: '/',
        chunkFilename: 'js/chunk-[name].[chunkhash:12].js',
    },
    plugins: [
        // Stable module ids keep unchanged chunks cacheable across builds.
        new webpack.HashedModuleIdsPlugin(),
        // Stable chunk ids: webpack 3 numbers chunks by order and [chunkhash] ignores that number,
        // so adding a page renumbered chunks while their file names stayed the same. Browsers
        // then reused a cached chunk carrying the old id and the page rendered blank
        // ("Loading chunk N failed"). Naming chunks ties the id to the file content.
        new webpack.NamedChunksPlugin(chunk => {
            if (chunk.name) return chunk.name;
            const ids = chunk.mapModules(m => m.id).sort().join('_');
            return require('crypto').createHash('md5').update(ids).digest('hex').slice(0, 8);
        }),
        // Mix's webpack-chunk-hash hashes module sources only. Fold the chunk id into the
        // hash so a chunk whose id changes always gets a new file name.
        {
            apply(compiler) {
                compiler.plugin('compilation', compilation => {
                    compilation.plugin('after-hash', () => {
                        const length = compilation.outputOptions.hashDigestLength;
                        compilation.chunks.forEach(chunk => {
                            if (chunk.hasRuntime() || chunk.isInitial()) return;
                            chunk.hash = require('crypto').createHash('md5')
                                .update(`${chunk.hash}|${chunk.id}`).digest('hex');
                            chunk.renderedHash = chunk.hash.substr(0, length);
                        });
                    });
                });
            },
        },
    ],
    resolve: {
        alias: {
            '@components': path.resolve(__dirname, 'resources/js/components'),
            '@views': path.resolve(__dirname, 'resources/js/views/tenant'),
            '@helpers': path.resolve(__dirname, 'resources/js/helpers'),
            '@mixins': path.resolve(__dirname, 'resources/js/mixins'),

            '@viewsModuleSale': path.resolve(__dirname, 'modules/Sale/Resources/assets/js/views'),
            '@viewsModuleFinance': path.resolve(__dirname, 'modules/Finance/Resources/assets/js/views'),
            '@viewsModuleTreasury': path.resolve(__dirname, 'modules/Treasury/Resources/assets/js/views'),
            '@viewsModulePurchase': path.resolve(__dirname, 'modules/Purchase/Resources/assets/js/views'),
            '@viewsModuleExpense': path.resolve(__dirname, 'modules/Expense/Resources/assets/js/views'),
            '@viewsModuleOrder': path.resolve(__dirname, 'modules/Order/Resources/assets/js/views'),
            '@viewsModuleAccount': path.resolve(__dirname, 'modules/Account/Resources/assets/js/views'),
            '@viewsModuleItem': path.resolve(__dirname, 'modules/Item/Resources/assets/js/views'),
            '@viewsModuleHotel': path.resolve(__dirname, 'modules/Hotel/Resources/assets/js/views'),
            '@viewsModuleDocumentary': path.resolve(__dirname, 'modules/DocumentaryProcedure/Resources/assets/js/views'),
        }
    }
}).sourceMaps()

mix.disableNotifications();
