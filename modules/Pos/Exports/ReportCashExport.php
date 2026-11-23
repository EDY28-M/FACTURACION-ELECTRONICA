<?php

namespace Modules\Pos\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Concerns\Exportable;

class ReportCashExport implements FromView
{
    use Exportable;

    protected $cash;
    protected $company;
    protected $methods_payment;
    protected $treasury_summary;

    public function cash($cash)
    {
        $this->cash = $cash;
        return $this;
    }

    public function company($company)
    {
        $this->company = $company;
        return $this;
    }

    public function methods_payment($methods_payment)
    {
        $this->methods_payment = $methods_payment;
        return $this;
    }

    public function treasury_summary($treasury_summary)
    {
        $this->treasury_summary = $treasury_summary;
        return $this;
    }

    public function view(): View
    {
        return view('pos::cash.report_excel', [
            'methods_payment' => $this->methods_payment,
            'cash' => $this->cash,
            'company' => $this->company,
            'treasury_summary' => $this->treasury_summary ?? [
                'movements' => collect(),
                'income' => 0.0,
                'egress' => 0.0,
                'net' => 0.0,
            ],
        ]);
    }
}
