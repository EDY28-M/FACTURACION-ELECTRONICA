<?php
namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller; 
use Illuminate\Http\Request;
use App\Models\System\Client;
use App\Models\System\Plan;
use App\Models\Tenant\Company;
use App\Models\Tenant\Configuration;
use App\Models\Tenant\AccountPayment;
use App\Models\System\ClientPayment;



use App\Http\Resources\Tenant\AccountPaymentCollection;
use App\Services\Payments\PlatformPayment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\Tenant\CulqiEmail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use stdClass;
use App\Models\System\Configuration as ConfigurationAdmin;



use Exception;


class AccountController extends Controller
{
    public function index()
    {
        return view('tenant.account.configuration' );
    }

    public function tables()
    {
        $plans = Plan::all();
        $configuration = Configuration::first();


        return compact('plans', 'configuration');
    }

    public function paymentIndex()
    {
        $configuration = ConfigurationAdmin::first();
        $token_public_culqui = optional($configuration)->token_public_culqui;

        return view('tenant.account.payment_index', compact('token_public_culqui'));
    }

    public function paymentRecords()
    {
        $records = AccountPayment::all();
        return new AccountPaymentCollection($records);
        
    }

    public function updatePlan(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|integer',
        ]);

        try{
            $plan = Plan::findOrFail($request->plan_id);
            $company = Company::active();
            $client = $company ? Client::where('number', $company->number)->first() : null;
            if (!$client) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró el cliente asociado en el sistema.',
                ], 404);
            }
            $configuration = Configuration::firstOrFail();

            $client->plan_id = $plan->id;
            $client->save();

            $configuration->plan = $plan;
            $configuration->save();

            return [
                'success' => true,
                'message' => 'Cliente Actualizado satisfactoriamente'
            ];

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'El plan solicitado no existe.',
            ], 404);
        } catch (Exception $e) {
            Log::error('Error updating tenant plan', ['exception' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar el plan.',
            ], 500);
        }

    }

    public function paymentCulqui(Request $request, PlatformPayment $platform)
    {
        $validator = Validator::make($request->all(), [
            'id_payment_account' => 'required|integer',
            'token' => 'required|string',
            'email' => 'required|email',
            'installments' => 'nullable|integer',
            'device_finger_print_id' => 'nullable|string|max:100',
            'authentication_3DS' => 'nullable|array',
            'authentication_3DS.eci' => 'nullable|string|max:255',
            'authentication_3DS.xid' => 'nullable|string|max:255',
            'authentication_3DS.cavv' => 'nullable|string|max:255',
            'authentication_3DS.protocolVersion' => 'nullable|string|max:255',
            'authentication_3DS.directoryServerTransactionId' => 'nullable|string|max:255',
        ]);
        if ($validator->fails()) {
            return ['success' => false, 'message' => 'Los datos del pago no son válidos.'];
        }

        $secret = ConfigurationAdmin::first()->token_private_culqui;
        if (! $secret) {
            return ['success' => false, 'message' => 'La pasarela de pagos no está configurada.'];
        }

        $model = new AccountPayment();
        $result = $model->getConnection()->transaction(function () use ($request, $platform, $secret) {
            // Row lock: concurrent submissions for the same payment are serialized.
            $payment = AccountPayment::lockForUpdate()->find($request->id_payment_account);
            if (! $payment) {
                return ['success' => false, 'message' => 'El pago solicitado no existe.', 'charge_id' => null, 'payment' => null];
            }

            $result = $platform->pay($payment, $secret, $request->token, $request->email, $request->installments, [
            'antifraud' => ['device_finger_print_id' => $request->input('device_finger_print_id')],
            'authentication_3DS' => (array) $request->input('authentication_3DS', []),
        ]);
            $result['payment'] = $payment;

            return $result;
        });

        if (! $result['success']) {
            return ['success' => false, 'needs_3ds' => ! empty($result['needs_3ds']), 'message' => $result['message']];
        }

        // Side effects after a verified payment must never turn it into an error.
        try {
            $system_client_payment = ClientPayment::find($result['payment']->reference_id);
            if ($system_client_payment) {
                $system_client_payment->state = 1;
                $system_client_payment->save();
            }
        } catch (Exception $e) {
            Log::error('Platform payment paid but system record not updated', ['charge_id' => $result['charge_id'], 'account_payment_id' => $result['payment']->id]);
        }

        try {
            $document = new stdClass;
            $document->client = optional(auth()->user())->name;
            $document->product = 'Pago Programado Cuenta Facturador Pro';
            $document->total = number_format((float) $result['payment']->payment, 2, '.', '');
            $document->items = [['description' => 'Pago programado facturador', 'cantidad' => '1', 'unit_type_id' => 'NIU']];

            Mail::to($request->email)->send(new CulqiEmail($document));
        } catch (Exception $e) {
            Log::warning('Platform payment confirmation email failed', ['charge_id' => $result['charge_id']]);
        }

        return ['success' => true, 'message' => $result['message']];
    }
}
