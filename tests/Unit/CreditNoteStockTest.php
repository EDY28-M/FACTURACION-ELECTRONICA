<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Sunat\CreditNoteStock;
use PHPUnit\Framework\TestCase;

class CreditNoteStockTest extends TestCase
{
    public function test_restores_stock_only_for_void_and_return_types()
    {
        foreach (['01', '02', '06', '07'] as $type) {
            $this->assertTrue(CreditNoteStock::restoresStock($type), $type);
        }
        foreach (['03', '04', '05', '08', '09', '10', '11', '12', '13', '99'] as $type) {
            $this->assertFalse(CreditNoteStock::restoresStock($type), $type);
        }
    }

    public function test_missing_type_never_restores_stock()
    {
        $this->assertFalse(CreditNoteStock::restoresStock(null));
        $this->assertFalse(CreditNoteStock::restoresStock(''));
    }

    public function test_debit_notes_never_move_stock()
    {
        $this->assertFalse(CreditNoteStock::movesStockFor('08'));
    }

    public function test_regular_documents_and_credit_notes_by_type()
    {
        $this->assertTrue(CreditNoteStock::movesStockFor('01'));
        $this->assertTrue(CreditNoteStock::movesStockFor('07', fn () => '06'));
        $this->assertFalse(CreditNoteStock::movesStockFor('07', fn () => '04'));
        $this->assertFalse(CreditNoteStock::movesStockFor('07'));
    }
}
