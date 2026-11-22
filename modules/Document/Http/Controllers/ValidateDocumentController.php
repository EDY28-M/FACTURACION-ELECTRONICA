<?php

namespace Modules\Document\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use App\Models\Tenant\Document;
use Modules\Document\Http\Resources\ValidateDocumentsCollection;
use App\Models\Tenant\Catalogs\DocumentType;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\Series;
use App\Models\Tenant\StateType;
use Modules\Document\Http\Requests\ValidateDocumentsRequest;
use App\CoreFacturalo\Services\Extras\ValidateCpe2;
use App\Models\Tenant\Company;
use Illuminate\Support\Facades\DB;

class ValidateDocumentController extends Controller
{
    
    public function index()
    {
        return view('document::validate_documents.index');
    }

    public function records(ValidateDocumentsRequest $request)
    {
        
        $records = $this->getRecords($request);
        $validate_documents = $this->validateDocuments($records);

        return new ValidateDocumentsCollection($validate_documents);

    }

    public function validateDocuments($records)
    {
        $records_paginate = $records->paginate(config('tenant.items_per_page'));

        foreach ($records_paginate->getCollection() as $document) {
            $response = $this->lookupDocumentWithRetries($document);
            if ($response === null) {
                $document->message = $document->number_full.'|Mensaje: No se pudo consultar el estado del comprobante.';
                $document->state_type_sunat_description = 'NO DISPONIBLE';
                $document->code = null;
                continue;
            }

            $response_code = $response['data']['comprobante_estado_codigo'];
            $response_description = $response['data']['comprobante_estado_descripcion'];
            $document->message = $document->number_full.'|Código: '.$response_code.'|Mensaje: '.$response_description;
            $document->state_type_sunat_description = $response_description;
            $document->code = $response_code;
        }

        return $records_paginate;
    }

    private function lookupDocumentWithRetries($document)
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->lookupDocument($document);
            } catch (\Throwable $exception) {
                continue;
            }

            if (is_array($response) && ($response['success'] ?? false) === true
                && isset($response['data']) && is_array($response['data'])
                && isset($response['data']['comprobante_estado_codigo'], $response['data']['comprobante_estado_descripcion'])
                && (is_string($response['data']['comprobante_estado_codigo']) || is_int($response['data']['comprobante_estado_codigo']))
                && (string) $response['data']['comprobante_estado_codigo'] !== ''
                && is_string($response['data']['comprobante_estado_descripcion'])
                && trim($response['data']['comprobante_estado_descripcion']) !== '') {
                return $response;
            }
        }

        return null;
    }

    protected function lookupDocument($document)
    {
        return (new ValidateCpe2())->search(
            $document->company->number,
            $document->document_type_id,
            $document->series,
            $document->number,
            $document->date_of_issue,
            $document->total
        );
    }


    public function getRecords($request){


        $start_number = $request->start_number;
        $end_number = $request->end_number;
        $document_type_id = $request->document_type_id;
        $series = $request->series;
        
        // dd($request->all());

        if($end_number){

            $records = Document::where('document_type_id',$document_type_id)
                            ->where('series',$series)
                            ->whereBetween('number', [$start_number , $end_number])
                            ->latest();

        }else{

            $records = Document::where('document_type_id',$document_type_id)
                            ->where('series',$series)
                            ->where('number',$start_number)
                            ->latest();
        }        

        return $records;

    }

    public function data_table()
    {
        
        $document_types = DocumentType::whereIn('id', ['01', '03','07', '08'])->get();
        $series = Series::whereIn('document_type_id', ['01', '03','07', '08'])->get();
                       
        return compact('document_types','series');

    }
    
    public function regularize(ValidateDocumentsRequest $request)
    {
        
        $document_state = [
            'ACEPTADO' => '05',
            'ENVIADO' => '03',
            'OBSERVADO' => '07',
            'RECHAZADO' => '09',
            'ANULADO' => '11',
            'POR ANULAR' => '13',
        ];

        $records = $this->getRecords($request)->get();

        $updates = [];
        foreach ($records as $document) {
            $response = $this->lookupDocumentWithRetries($document);
            if ($response === null) {
                return ['success' => false, 'message' => 'No se pudieron regularizar los estados. Intente nuevamente.'];
            }

            $response_description = mb_strtoupper($response['data']['comprobante_estado_descripcion']);
            if (isset($document_state[$response_description])) {
                $updates[] = [
                    'id' => $document->id,
                    'original_state' => $document->state_type_id,
                    'state' => $document_state[$response_description],
                ];
            }
        }

        try {
            DB::connection('tenant')->transaction(function () use ($updates) {
                $documents = Document::whereIn('id', array_column($updates, 'id'))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($updates as $update) {
                    $document = $documents->get($update['id']);
                    if (!$document || (string) $document->state_type_id !== (string) $update['original_state']) {
                        throw new \RuntimeException('Document state changed during lookup.');
                    }
                }
                foreach ($updates as $update) {
                    $document = $documents->get($update['id']);
                    $document->state_type_id = $update['state'];
                    if (!$document->save()) {
                        throw new \RuntimeException('Unable to persist document state.');
                    }
                }
            });
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => 'No se pudieron regularizar los estados. Intente nuevamente.'];
        }

        return [
            'success' => true,
            'message' => 'Estados regularizados correctamente'
        ];

    }
}
