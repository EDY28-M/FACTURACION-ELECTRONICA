<?php

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Finance\Models\PaymentFile;
use App\Models\Tenant\Cash;
use Illuminate\Support\Facades\Storage;
use Modules\Finance\Helpers\UploadFileHelper;
use Modules\Finance\Helpers\PaymentFileHelper;

class PaymentFileController extends Controller
{ 

    public function download($filename, $type) { 
        $allowedTypes = ['documents', 'sale_notes', 'quotations', 'purchases', 'expenses'];
        if (!in_array($type, $allowedTypes, true)) {
            abort(404);
        }

        $filename = basename($filename);

        return Storage::disk('tenant')->download('payment_files'.DIRECTORY_SEPARATOR.$type.DIRECTORY_SEPARATOR.$filename, null, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadAttached(Request $request)
    {
        // dd($request->all());
        $validate_upload = UploadFileHelper::validateUploadFile($request, 'file');
        
        if(!$validate_upload['success']){
            return $validate_upload;
        }

        if ($request->hasFile('file')) {
            $new_request = [
                'file' => $request->file('file'),
                'index' => $request->input('index'),
            ];

            return $this->upload_attached($new_request);
        }
        return [
            'success' => false,
            'message' =>  __('app.actions.upload.error'),
        ];
    }


    function upload_attached($request)
    {
        return PaymentFileHelper::storeTemp($request['file'], $request['index'] ?? null);
    }


}
