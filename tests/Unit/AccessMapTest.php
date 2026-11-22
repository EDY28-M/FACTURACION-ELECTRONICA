<?php

namespace Tests\Unit;

use App\Support\Access\AccessMap;
use PHPUnit\Framework\TestCase;

class AccessMapTest extends TestCase
{
    private function map(): AccessMap
    {
        return new AccessMap(require __DIR__.'/../../config/permissions.php');
    }

    public function test_paths_resolve_to_the_expected_module_and_level()
    {
        $map = $this->map();

        $cases = [
            'dashboard'                      => ['dashboard', null],
            'documents/create'               => ['documents', 'new_document'],
            'documents'                      => ['documents', 'list_document'],
            'documents/records'              => ['documents', 'list_document'],
            'documents/not-sent'             => ['documents', 'document_not_sent'],
            'documents/regularize-shipping'  => ['documents', 'regularize_shipping'],
            'contracts/recurrent'            => ['documents', 'contracts'],
            'contracts/recurrent/history'    => ['documents', 'contracts'],
            'reports/sire/rvie'              => ['reports', null],
            'reports/kardex'                 => ['inventory', null],
            'reports/kardex_lots/records'    => ['inventory', null],
            'reports/kardex_series/records'  => ['inventory', null],
            'reports/validate-documents'     => ['reports', null],
            'purchases/validate-sunat'       => ['purchases', null],
            'persons/suppliers'              => ['purchases', null],
            'persons/customers'              => ['persons', null],
            'items/sunat-product-codes'      => ['items', null],
            'dispatches/send/12'             => ['advanced', null],
            'pos_full'                       => ['pos', null],
            'documents/create_tensu'         => ['documents', 'new_document'],
            'ecommerce/configuration_culqui' => ['ecommerce', null],
            'ecommerce/record'               => ['ecommerce', null],
            'statusOrder/records'            => ['ecommerce', null],
            'options/delete_documents'       => ['configuration', null],
            'certificates/record'            => ['configuration', null],
            'companies/create'               => ['configuration', null],
            'list-settings'                  => ['configuration', null],
            'list-reports'                   => ['reports', null],
            'hotels/reception'               => ['hotels', null],
        ];

        foreach ($cases as $path => [$module, $level]) {
            $rule = $map->resolve($path, 'GET');
            $this->assertNotNull($rule, $path);
            $this->assertSame($module, $rule['module'], $path);
            $this->assertSame($level, $rule['level'], $path);
        }
    }

    public function test_owner_is_always_allowed()
    {
        $decision = $this->map()->decide('users', true, [], [], false);

        $this->assertTrue($decision['allowed']);
        $this->assertSame('owner', $decision['reason']);
    }

    public function test_granted_module_is_allowed()
    {
        $decision = $this->map()->decide('purchases', false, ['purchases'], [], false);

        $this->assertTrue($decision['allowed']);
    }

    public function test_missing_module_is_denied()
    {
        $decision = $this->map()->decide('reports/sire/rvie', false, ['dashboard', 'purchases'], [], false);

        $this->assertFalse($decision['allowed']);
        $this->assertSame('module', $decision['reason']);
    }

    public function test_missing_level_is_denied_even_with_the_module()
    {
        $map = $this->map();

        $denied = $map->decide('contracts/recurrent', false, ['documents'], ['new_document'], false);
        $this->assertFalse($denied['allowed']);
        $this->assertSame('level', $denied['reason']);

        $allowed = $map->decide('contracts/recurrent', false, ['documents'], ['contracts'], false);
        $this->assertTrue($allowed['allowed']);
    }

    public function test_regularize_shipping_level_is_enforced_with_many_levels()
    {
        $levels = ['new_document', 'list_document', 'document_not_sent', 'document_contingengy', 'catalogs', 'summary_voided',
            'quotations', 'sale_notes', 'incentives', 'sale-opportunity', 'contracts', 'order-note', 'technical-service'];

        $decision = $this->map()->decide('documents/regularize-shipping', false, ['documents'], $levels, false);

        $this->assertFalse($decision['allowed']);
    }

    public function test_no_rows_means_no_access()
    {
        $this->assertFalse($this->map()->decide('dashboard', false, [], [], false)['allowed']);
        $this->assertFalse($this->map()->decide('documents/create', false, [], [], false)['allowed']);
    }

    public function test_unmapped_path_is_allowed()
    {
        $decision = $this->map()->decide('some/unknown/route', false, [], [], false);

        $this->assertTrue($decision['allowed']);
        $this->assertSame('unmapped', $decision['reason']);
        $this->assertTrue($this->map()->decide('/', false, [], [], false)['allowed']);
    }

    public function test_ajax_is_enforced_only_where_flagged()
    {
        $map = $this->map();

        $this->assertFalse($map->decide('documents/not-sent/records', false, ['purchases'], [], true)['allowed']);
        $this->assertFalse($map->decide('reports/sales/records', false, ['purchases'], [], true)['allowed']);
        $this->assertFalse($map->decide('reports/kardex_lots/records', false, ['purchases'], [], true)['allowed']);
        $this->assertFalse($map->decide('reports/kardex_series/records', false, ['purchases'], [], true)['allowed']);
        $this->assertTrue($map->decide('reports/kardex_lots/records', false, ['inventory'], [], true)['allowed']);
        $this->assertTrue($map->decide('reports/kardex_series/records', false, ['inventory'], [], true)['allowed']);
        $this->assertFalse($map->decide('ecommerce/configuration_culqui', false, ['purchases'], [], true)['allowed']);
        $this->assertFalse($map->decide('ecommerce/record', false, ['purchases'], [], true)['allowed']);
        $this->assertFalse($map->decide('statusOrder/records', false, ['purchases'], [], true)['allowed']);
        $this->assertFalse($map->decide('options/delete_documents', false, ['purchases'], [], true)['allowed']);
        $this->assertFalse($map->decide('certificates/record', false, ['purchases'], [], true)['allowed']);
        // Shared lookup endpoints stay open for AJAX and cross-module helpers.
        $this->assertTrue($map->decide('items/search', false, ['purchases'], [], true)['allowed']);
        $this->assertTrue($map->decide('cash/cash_document', false, ['purchases'], [], true)['allowed']);
        $this->assertTrue($map->decide('cash/cash_document', false, ['documents'], ['new_document'], false)['allowed']);
        $this->assertTrue($map->decide('companies/record', false, [], [], false)['allowed']);
        $this->assertTrue($map->decide('reports/data-table/items', false, ['dashboard'], [], true)['allowed']);
        $this->assertTrue($map->decide('reports/data-table/persons/customers', false, ['purchases'], [], true)['allowed']);
        $this->assertTrue($map->decide('finances/payment-file/upload', false, ['documents'], ['list_document'], true)['allowed']);
        $this->assertTrue($map->decide('finances/payment-file/download-file/abc.pdf/documents', false, ['purchases'], [], false)['allowed']);
        $this->assertTrue($map->decide('services/ruc/20100070970', false, ['persons'], [], true)['allowed']);
        $this->assertTrue($map->decide('services/dni/12345678', false, ['pos'], [], true)['allowed']);
        $this->assertTrue($map->decide('services/exchange_rate', false, ['purchases'], [], true)['allowed']);
        $this->assertTrue($map->decide('services/search_exchange_rate', false, ['documents'], ['new_document'], true)['allowed']);
        $this->assertFalse($map->decide('services', false, ['persons'], [], false)['allowed']);
        $this->assertTrue($map->decide('services', false, ['items'], [], false)['allowed']);
        $this->assertTrue($map->decide('configurations/record', false, ['dashboard'], [], true)['allowed']);
        $this->assertTrue($map->decide('configurations/change-mode', false, ['pos'], [], false)['allowed']);
        $this->assertTrue($map->decide('documents/search/externalId/ext-1', false, ['ecommerce'], [], true)['allowed']);
        $this->assertTrue($map->decide('voided/status_masive', false, ['configuration'], [], true)['allowed']);
    }

    public function test_ajax_does_not_bypass_module_check()
    {
        $map = $this->map();

        $denied = $map->decide('documents/delete_document', false, [], [], true);
        $this->assertFalse($denied['allowed']);
        $this->assertSame('module', $denied['reason']);

        $this->assertFalse($map->decide('documents/send/1', false, ['purchases'], [], true)['allowed']);
        $this->assertTrue($map->decide('documents/delete_document', false, ['documents'], [], true)['allowed']);
        $this->assertFalse($map->decide('document_payments', false, [], [], true)['allowed']);
        $this->assertTrue($map->decide('document_payments', false, ['documents'], [], true)['allowed']);
    }

    public function test_unmapped_mutation_is_denied()
    {
        $map = $this->map();

        $denied = $map->decide('some/unknown/route', false, [], [], false, 'POST');
        $this->assertFalse($denied['allowed']);
        $this->assertSame('unmapped-mutation', $denied['reason']);

        $this->assertTrue($map->decide('some/unknown/route', false, [], [], false, 'GET')['allowed']);
    }

    public function test_pos_profile_can_emit_without_listing_all_documents()
    {
        $map = $this->map();

        $this->assertTrue($map->decide('documents', false, ['pos'], [], true, 'POST')['allowed']);
        $this->assertTrue($map->decide('sale-notes', false, ['pos'], [], true, 'POST')['allowed']);
        $this->assertTrue($map->decide('document_payments', false, ['pos'], [], true, 'POST')['allowed']);
        $this->assertFalse($map->decide('documents/records', false, ['pos'], [], true, 'GET')['allowed']);
        $this->assertFalse($map->decide('documents', false, ['purchases'], [], true, 'POST')['allowed']);
    }

    public function test_document_levels_are_method_specific()
    {
        $map = $this->map();

        $this->assertTrue($map->decide('documents', false, ['documents'], ['new_document'], true, 'POST')['allowed']);
        $this->assertFalse($map->decide('documents', false, ['documents'], ['list_document'], true, 'POST')['allowed']);
        $this->assertTrue($map->decide('sale-notes', false, ['documents'], ['sale_notes'], true, 'POST')['allowed']);
        $this->assertFalse($map->decide('sale-notes', false, ['documents'], ['new_document'], true, 'POST')['allowed']);
        $this->assertTrue($map->decide('sale-notes', false, ['pos'], [], true, 'POST')['allowed']);
        $this->assertFalse($map->decide('documents/records', false, ['documents'], ['new_document'], true, 'GET')['allowed']);
        $this->assertTrue($map->decide('documents/records', false, ['documents'], ['list_document'], true, 'GET')['allowed']);
    }

    public function test_first_allowed_path_follows_sidebar_order()
    {
        $map = $this->map();

        $this->assertSame('dashboard', $map->firstAllowedPath(['purchases', 'dashboard'], []));
        $this->assertSame('purchases', $map->firstAllowedPath(['purchases', 'reports'], []));
        $this->assertSame('documents', $map->firstAllowedPath(['documents'], ['list_document']));
        $this->assertSame('contracts', $map->firstAllowedPath(['documents'], ['contracts']));
        $this->assertNull($map->firstAllowedPath([], []));
        $this->assertNull($map->firstAllowedPath(['documents'], []));
    }

    public function test_every_menu_entry_is_mapped()
    {
        $config = require __DIR__.'/../../config/permissions.php';
        $map = new AccessMap($config);

        foreach ($config['menu'] as $path) {
            $this->assertNotNull($map->resolve($path), $path);
        }
    }
}
