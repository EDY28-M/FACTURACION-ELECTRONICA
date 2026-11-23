<?php

namespace Modules\Treasury\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Modules\Treasury\Support\TreasuryExcelValueBinder;

class CashBookFormat12Export extends TreasuryExcelValueBinder implements FromView, ShouldAutoSize, WithCustomValueBinder
{
    use Exportable;

    /** @var array<string, mixed> */
    protected array $report;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(array $report)
    {
        $this->report = $report;
    }

    public function view(): View
    {
        return view('treasury::reports.cash_book_12_excel', [
            'report' => $this->report,
        ]);
    }
}
