<?php

namespace Modules\Treasury\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Modules\Treasury\Support\TreasuryExcelValueBinder;

class CashFlowExport extends TreasuryExcelValueBinder implements FromView, ShouldAutoSize, WithCustomValueBinder
{
    use Exportable;

    /** @var array<string, mixed> */
    protected array $report;

    /** @var array{ruc: string, name: string} */
    protected array $company;

    /**
     * @param  array<string, mixed>  $report
     * @param  array{ruc: string, name: string}  $company
     */
    public function __construct(array $report, array $company)
    {
        $this->report = $report;
        $this->company = $company;
    }

    public function view(): View
    {
        return view('treasury::reports.cash_flow_excel', [
            'report' => $this->report,
            'company' => $this->company,
        ]);
    }
}
