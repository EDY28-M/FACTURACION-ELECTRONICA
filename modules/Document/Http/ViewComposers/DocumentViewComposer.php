<?php

namespace Modules\Document\Http\ViewComposers;
use App\Models\Tenant\Document;

class DocumentViewComposer
{
    public function compose($view)
    {
        // Same filters as the not-sent list it links to: sellers only count their own documents.
        $view->vc_document = auth()->check() ? Document::whereNotSent()->whereTypeUser()->count() : 0;

        $view->vc_document_regularize_shipping = Document::whereRegularizeShipping()->count();

    }
}