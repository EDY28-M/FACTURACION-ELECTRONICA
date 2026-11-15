<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Sunat\ProductCode;
use App\CoreFacturalo\Helpers\Sunat\ProductCodeSearch;
use PHPUnit\Framework\TestCase;

class ProductCodeTest extends TestCase
{
    private $calls = 0;

    protected function setUp(): void
    {
        $this->calls = 0;
        ProductCode::setLookup(function (array $codes) {
            $this->calls++;
            return array_values(array_intersect($codes, ['10101500', '10101501'])); // class + product
        });
    }

    protected function tearDown(): void
    {
        ProductCode::setLookup(null);
    }

    public function test_format(): void
    {
        $this->assertTrue(ProductCode::isValidFormat('10101501'));
        $this->assertFalse(ProductCode::isValidFormat('1010150'));
        $this->assertFalse(ProductCode::isValidFormat('1010150a'));
        $this->assertFalse(ProductCode::isValidFormat(null));
    }

    public function test_exists_class_and_product(): void
    {
        $this->assertTrue(ProductCode::exists('10101500'));
        $this->assertTrue(ProductCode::exists('10101501'));
        $this->assertFalse(ProductCode::exists('99999999'));
        $this->assertFalse(ProductCode::exists('abc'));
    }

    public function test_date_rule(): void
    {
        $this->assertFalse(ProductCode::isRequired('01', '2026-12-31'));
        $this->assertTrue(ProductCode::isRequired('01', '2027-01-01'));
        $this->assertTrue(ProductCode::isRequired('03', '2027-06-01'));
        $this->assertTrue(ProductCode::isRequired('07', '2027-06-01'));
        $this->assertFalse(ProductCode::isRequired('09', '2027-06-01'));
    }

    public function test_assert_items_does_nothing_before_deadline(): void
    {
        ProductCode::assertItems(['document_type_id' => '01', 'date_of_issue' => '2026-11-14',
            'items' => [['item' => ['description' => 'X', 'item_code' => '']]]]);
        $this->assertTrue(true);
    }

    public function test_assert_items_blocks_from_deadline(): void
    {
        $this->expectException(\Exception::class);
        ProductCode::assertItems(['document_type_id' => '01', 'date_of_issue' => '2027-01-02',
            'items' => [['item' => ['description' => 'X', 'item_code' => '99999999']]]]);
    }

    public function test_assert_items_passes_with_valid_code(): void
    {
        ProductCode::assertItems(['document_type_id' => '03', 'date_of_issue' => '2027-01-02',
            'items' => [['item' => ['description' => 'X', 'item_code' => '10101501']]]]);
        $this->assertTrue(true);
    }

    public function test_search_parse_and_escape(): void
    {
        $this->assertSame(['type' => 'code', 'code' => '10101501'], ProductCodeSearch::parse(' 10101501 '));
        $this->assertSame(['type' => 'prefix', 'prefix' => '101015'], ProductCodeSearch::parse('101015'));
        $this->assertNull(ProductCodeSearch::parse('ga'));
        $this->assertSame(['type' => 'text', 'terms' => ['gatos', 'vivos']], ProductCodeSearch::parse("gatos  vivos"));
        $this->assertSame('50\\%\\_a\\\\', ProductCodeSearch::escapeLike('50%_a\\'));
    }

    public function test_legacy_code_is_only_validated_when_new_or_changed(): void
    {
        $this->assertFalse(ProductCode::needsValidation('LEGACY-1', ' LEGACY-1 '));
        $this->assertTrue(ProductCode::needsValidation('LEGACY-1', 'ZZZ'));
        $this->assertTrue(ProductCode::needsValidation(null, 'ZZZ'));
        $this->assertFalse(ProductCode::needsValidation('LEGACY-1', ''));

        $fails = [];
        $fail = function ($m) use (&$fails) { $fails[] = $m; };
        ProductCode::itemCodeRule('LEGACY-1')('item_code', 'LEGACY-1', $fail);
        $this->assertSame([], $fails);
        ProductCode::itemCodeRule('LEGACY-1')('item_code', '99999999', $fail);
        $this->assertCount(1, $fails);
        ProductCode::itemCodeRule('LEGACY-1')('item_code', '10101501', $fail);
        $this->assertCount(1, $fails);
    }

    public function test_multi_item_check_is_batched(): void
    {
        $items = [];
        foreach (['10101501', '10101501', '10101500'] as $c) {
            $items[] = ['item' => ['description' => 'X', 'item_code' => $c]];
        }
        ProductCode::assertItems(['document_type_id' => '01', 'date_of_issue' => '2027-02-01', 'items' => $items]);
        $this->assertSame(1, $this->calls);
        $this->assertSame(['10101501'], ProductCode::existing(['10101501', 'bad', '10101501', '99999999']));
        $this->assertFalse(ProductCode::isRequired('04', '2027-06-01'));
    }
}
