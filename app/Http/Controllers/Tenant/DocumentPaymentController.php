<?php
namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\DocumentPaymentRequest;
use App\Http\Requests\Tenant\DocumentRequest;
use App\Http\Resources\Tenant\DocumentPaymentCollection;
use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PaymentMethodType;
use App\Exports\DocumentPaymentExport;
use Exception, Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Modules\Finance\Traits\FinanceTrait;
use Modules\Finance\Traits\FilePaymentTrait;
use Carbon\Carbon;

class DocumentPaymentController extends Controller
{

    use FinanceTrait, FilePaymentTrait;

    public function records($document_id)
    {
        Document::whereTypeUser()->findOrFail($document_id);
        $records = DocumentPayment::where('document_id', $document_id)->get();

        return new DocumentPaymentCollection($records);
    }

    public function tables()
    {
        return [
            'payment_method_types' => PaymentMethodType::all(),
            'payment_destinations' => $this->getPaymentDestinations()
        ];
    }

    public function document($document_id)
    {
        $document = Document::whereTypeUser()->findOrFail($document_id);

        $total_paid = collect($document->payments)->sum('payment');
        $total = $document->total;
        $total_difference = round($total - $total_paid, 2);

        return [
            'number_full' => $document->number_full,
            'total_paid' => $total_paid,
            'total' => $total,
            'total_difference' => $total_difference
        ];

    }

    public function store(DocumentPaymentRequest $request)
    {
        $id = $request->input('id');

        return DB::connection('tenant')->transaction(function () use ($id, $request) {

            if ($id) {
                $record = DocumentPayment::lockForUpdate()->findOrFail($id);
                $document = Document::whereTypeUser()->lockForUpdate()->findOrFail($record->document_id);
            } else {
                $document = Document::whereTypeUser()
                    ->lockForUpdate()
                    ->findOrFail($request->input('document_id'));
                $record = new DocumentPayment();
                $record->document_id = $document->id;
            }

            if ((string) $document->state_type_id === '11') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pueden registrar pagos de un comprobante anulado.',
                ], 422);
            }

            $record->fill($request->except(['id', 'document_id']));
            $record->document_id = $document->id;
            $record->save();

            $this->createGlobalPayment($record, $request->all());
            $this->saveFiles($record, $request, 'documents');

            return [
                'success' => true,
                'message' => ($id) ? 'Pago editado con éxito' : 'Pago registrado con éxito',
            ];
        });
    }

    public function destroy($id)
    {
        return DB::connection('tenant')->transaction(function () use ($id) {
            $item = DocumentPayment::lockForUpdate()->findOrFail($id);
            $document = Document::whereTypeUser()->lockForUpdate()->findOrFail($item->document_id);

            if ((string) $document->state_type_id === '11') {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pueden eliminar pagos de un comprobante anulado.',
                ], 422);
            }

            $item->delete();

            return [
                'success' => true,
                'message' => 'Pago eliminado con éxito',
            ];
        });
    }

    public function initialize_balance()
    {
        if ((auth()->user()->type ?? null) !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permiso para realizar esta acción.',
            ], 403);
        }

        DB::connection('tenant')->transaction(function (){

            $documents = Document::get();

            foreach ($documents as $document) {

                $total_payments = $document->payments->sum('payment');

                $balance = $document->total - $total_payments;

                if($balance <= 0){

                    $document->total_canceled = true;
                    $document->update();

                }else{

                    $document->total_canceled = false;
                    $document->update();
                }

            }

        });

        return [
            'success' => true,
            'message' => 'Acción realizada con éxito'
        ];
    }

    public function  report($start, $end, $type = 'pdf')
    {
        $documents = DocumentPayment::whereBetween('date_of_payment', [$start , $end])->get();

        $records = collect($documents)->transform(function($row){
            return [
                'id' => $row->id,
                'date_of_payment' => $row->date_of_payment->format('d/m/Y'),
                'payment_method_type_description' => $row->payment_method_type->description,
                'destination_description' => ($row->global_payment) ? $row->global_payment->destination_description:null,
                'change' => $row->change,
                'payment' => $row->payment,
                'reference' => $row->reference,
                'customer' => $row->document->customer->name,
                'number'=>  $row->document->number_full,
                'total' => $row->document->total,
            ];
        });

        if ($type == 'pdf') {
            $pdf = PDF::loadView('tenant.document_payments.report', compact("records"));

            $filename = "Reporte_Pagos";

            return $pdf->stream($filename.'.pdf');
        } elseif ($type == 'excel') {
            $filename = "Reporte_Pagos";

            // $pdf = PDF::loadView('tenant.document_payments.report', compact("records"))->download($filename.'.xlsx');

            // return $pdf->stream($filename.'.xlsx');

            return (new DocumentPaymentExport)
                ->records($records)
                ->download($filename.Carbon::now().'.xlsx');
        }

    }

}
