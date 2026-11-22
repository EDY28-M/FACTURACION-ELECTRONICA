<?php
namespace App\Http\Controllers\Tenant;

use App\Models\Tenant\Company;
use App\Models\Tenant\Configuration;
use App\Models\Tenant\SoapType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\CompanyRequest;
use App\Http\Resources\Tenant\CompanyResource;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CompanyController extends Controller
{
    /**
     * Fields the company can only change when the system admin enabled config_system_env.
     */
    private const LOCKED_SUNAT_FIELDS = [
        'soap_type_id',
        'soap_send_id',
        'soap_username',
        'soap_password',
        'soap_url',
    ];

    public function create()
    {
        return view('tenant.companies.form');
    }

    public function tables()
    {
        $soap_sends = config('tables.system.soap_sends');
        $soap_types = SoapType::all();


        return compact('soap_types', 'soap_sends');
    }

    public function record()
    {
        $company = Company::active();
        $record = new CompanyResource($company);

        return $record;
    }

    public function store(CompanyRequest $request)
    {
        $id = $request->input('id');
        $company = Company::findOrFail($id);

        // The certificate is only set through CertificateController::uploadFile; accepting it here
        // would let a tenant point to any .pem stored in the shared certificates directory.
        $data = Arr::except($request->all(), ['certificate']);

        if (!Configuration::allowsSunatSettingsChanges()) {
            $data = Arr::except($data, self::LOCKED_SUNAT_FIELDS);
        }

        $company->fill($data);

        // Write-only: an empty value keeps the stored (encrypted) secret.
        if (filled($request->input('gre_client_secret'))) {
            $company->setGreClientSecret($request->input('gre_client_secret'));
        }

        if (filled($request->input('sire_client_secret'))) {
            $company->setSireClientSecret($request->input('sire_client_secret'));
        }

        if (filled($request->input('cpe_client_secret'))) {
            $company->setCpeClientSecret($request->input('cpe_client_secret'));
        }

        $company->save();

        return [
            'success' => true,
            'message' => 'Empresa actualizada'
        ];
    }

    public function uploadFile(Request $request)
    {
        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return [
                'success' => false,
                'message' => __('app.actions.upload.error'),
            ];
        }

        $company = Company::active();
        if (!$company) {
            abort(404, 'Empresa no encontrada.');
        }

        $uploadSpecs = [
            'logo' => [
                'rules' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'dir' => 'public/uploads/logos',
            ],
            'logo_store' => [
                'rules' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'dir' => 'public/uploads/logos',
            ],
            'favicon' => [
                'rules' => 'required|image|mimes:png|max:1024',
                'dir' => 'public/uploads/favicons',
            ],
            'img_firm' => [
                'rules' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'dir' => 'public/uploads/firms',
            ],
        ];

        $type = $request->input('type');
        abort_unless(is_string($type) && array_key_exists($type, $uploadSpecs), 422, 'Tipo de archivo inválido.');

        $spec = $uploadSpecs[$type];
        $request->validate(['file' => $spec['rules']]);

        $file = $request->file('file');
        $ext = $file->extension();

        if ($type === 'favicon') {
            $filename = time() . '.' . $ext;
            $file->storeAs($spec['dir'], $filename);
            $name = 'storage/uploads/favicons/' . $filename;
        } else {
            $name = $type . '_' . $company->number . '.' . $ext;
            $file->storeAs($spec['dir'], $name);
        }

        $company->{$type} = $name;
        $company->save();

        return [
            'success' => true,
            'message' => __('app.actions.upload.success'),
            'name' => $name,
            'type' => $type,
        ];
    }
}
