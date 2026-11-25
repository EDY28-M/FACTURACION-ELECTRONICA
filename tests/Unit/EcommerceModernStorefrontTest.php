<?php

namespace Tests\Unit;

use Modules\Ecommerce\Http\ViewComposers\InformationContactViewComposer;
use PHPUnit\Framework\TestCase;

class EcommerceModernStorefrontTest extends TestCase
{
    private function projectPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }

    public function test_whatsapp_number_normalization_handles_peru_and_international_formats(): void
    {
        $this->assertSame('51960268264', InformationContactViewComposer::normalizeWhatsappNumber('960 268 264'));
        $this->assertSame('51960268264', InformationContactViewComposer::normalizeWhatsappNumber('+51 960-268-264'));
        $this->assertSame('015055555', InformationContactViewComposer::normalizeWhatsappNumber('01 505-5555'));
        $this->assertNull(InformationContactViewComposer::normalizeWhatsappNumber(''));
        $this->assertNull(InformationContactViewComposer::normalizeWhatsappNumber('123'));
        $this->assertNull(InformationContactViewComposer::normalizeWhatsappNumber('javascript:alert(1)'));
    }

    public function test_modern_storefront_css_and_templates_are_wired(): void
    {
        $css = $this->projectPath('public/porto-ecommerce/assets/css/storefront-modern.css');
        $this->assertFileExists($css);
        $cssContent = file_get_contents($css);
        $this->assertStringContainsString('.sf-topbar', $cssContent);
        $this->assertStringContainsString('.sf-catalog-layout', $cssContent);
        $this->assertStringContainsString('.sf-product-grid', $cssContent);
        $this->assertStringContainsString('.sf-wa-float', $cssContent);

        foreach ([
            'modules/Ecommerce/Resources/views/layouts/master.blade.php',
            'modules/Ecommerce/Resources/views/layouts/layout_ecommerce_item/record.blade.php',
            'modules/Ecommerce/Resources/views/layouts/layout_ecommerce_cart/index.blade.php',
        ] as $layout) {
            $html = file_get_contents($this->projectPath($layout));
            $this->assertStringContainsString('storefront-modern.css', $html, "Missing storefront-modern.css in {$layout}");
            $this->assertStringContainsString('class="sf-body"', $html, "Missing sf-body in {$layout}");
        }
    }

    public function test_catalog_controller_whitelists_sort_and_supports_category_brand_filters(): void
    {
        $controller = file_get_contents($this->projectPath('modules/Ecommerce/Http/Controllers/EcommerceController.php'));
        $this->assertStringContainsString("['relevance', 'newest', 'price_asc', 'price_desc', 'name_asc']", $controller);
        $this->assertStringContainsString("'filterCategories'", $controller);
        $this->assertStringContainsString("'filterBrands'", $controller);
        $this->assertStringContainsString('->paginate(12)', $controller);
    }

    public function test_product_cards_keep_safe_escaped_data_product_contract(): void
    {
        $listView = file_get_contents($this->projectPath('modules/Ecommerce/Resources/views/layouts/partials_ecommerce/list_products.blade.php'));
        $this->assertStringContainsString('StoreItemPresenter::present($item)', $listView);
        $this->assertStringContainsString('data-product="{{ json_encode($public) }}"', $listView);
        $this->assertStringNotContainsString('{!!', $listView);
    }
}
