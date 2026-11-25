<?php

namespace Modules\Document\Http\ViewComposers;

use App\Models\Tenant\Company;
use App\Models\Tenant\Document;
use Illuminate\Support\Facades\DB;

class DocumentViewComposer
{
    public function compose($view)
    {
        $company = Company::select('soap_type_id')->first();
        if ($company && $company->soap_type_id === '03') {
            $view->vc_document = 0;
            $view->vc_document_regularize_shipping = 0;
            return;
        }

        $notSent = 0;
        if (auth()->check()) {
            $notSent = Document::whereNotSent()->whereTypeUser()->count();
        }

        $view->vc_document = $notSent;
        $view->vc_document_regularize_shipping = DB::connection('tenant')
            ->table('documents')
            ->where('state_type_id', '01')
            ->where('regularize_shipping', true)
            ->count();
    }
}
