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
    ],
    resolve: {
        alias: {
            '@components': path.resolve(__dirname, 'resources/js/components'),
            '@views': path.resolve(__dirname, 'resources/js/views/tenant'),
            '@helpers': path.resolve(__dirname, 'resources/js/helpers'),
            '@mixins': path.resolve(__dirname, 'resources/js/mixins'),

            '@viewsModuleSale': path.resolve(__dirname, 'modules/Sale/Resources/assets/js/views'),
            '@viewsModuleFinance': path.resolve(__dirname, 'modules/Finance/Resources/assets/js/views'),
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
