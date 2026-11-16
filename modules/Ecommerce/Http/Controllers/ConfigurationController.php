<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Tenant\ConfigurationEcommerce;
use App\Models\Tenant\Company;
use App\Http\Requests\Tenant\ConfigurationEcommerceRequest;
use App\Http\Resources\Tenant\ConfigurationEcommerceResource;


class ConfigurationController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Response
     */
    public function index()
    {
        return view('ecommerce::configuration.index');
    }

    public function record() {
        $configuration = ConfigurationEcommerce::first();
        $record = new ConfigurationEcommerceResource($configuration);
        return $record;
    }


    public function store_configuration(ConfigurationEcommerceRequest $request)
    {
        return $this->updateSection($request, [
            'information_contact_name',
            'information_contact_email',
            'information_contact_phone',
            'information_contact_address',
        ], 'Configuración actualizada');
    }

    public function store_configuration_culqui(Request $request)
    {
        $request->validate([
            'token_public_culqui' => 'nullable|string|max:255',
            'token_private_culqui' => 'nullable|string|max:255',
        ]);

        $configuration = ConfigurationEcommerce::firstOrFail();
        $configuration->token_public_culqui = $request->input('token_public_culqui');
        // Write-only: an empty value keeps the stored secret.
        if ($request->filled('token_private_culqui')) {
            $configuration->token_private_culqui = $request->input('token_private_culqui');
        }
        $configuration->save();

        return [
            'success' => true,
            'message' => 'Configuración Culqui actualizada'
        ];
    }

    public function store_configuration_tag(Request $request)
    {
        return $this->updateSection($request, ['tag_shipping', 'tag_dollar', 'tag_support'], 'Configuración Tags actualizada');
    }

    public function store_configuration_social(Request $request)
    {
        return $this->updateSection($request, ['link_youtube', 'link_twitter', 'link_facebook'], 'Configuración de Redes Sociales actualizada');
    }

    /**
     * Updates only the whitelisted fields of the single tenant configuration record.
     */
    private function updateSection(Request $request, array $fields, string $message)
    {
        $configuration = ConfigurationEcommerce::firstOrFail();
        $configuration->fill($request->only($fields));
        $configuration->save();

        return [
            'success' => true,
            'message' => $message
        ];
    }

    public function uploadFile(Request $request)
    {
        if ($request->hasFile('file')) {

            $config = ConfigurationEcommerce::first();
            $company = Company::first();

            $type = $request->input('type'); //logo_store

            $file = $request->file('file');
            $ext = $file->getClientOriginalExtension();
            $name = $type.'_'.$company->number.'.'.$ext;

            request()->validate(['file' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048']);

            $file->storeAs('public/uploads/logos', $name);

            $config->logo = $name;

            $config->save();

            return [
                'success' => true,
                'message' => __('app.actions.upload.success'),
                'name' => $name,
                'type' => $type
            ];
        }
        return [
            'success' => false,
            'message' =>  __('app.actions.upload.error'),
        ];
    }



}
