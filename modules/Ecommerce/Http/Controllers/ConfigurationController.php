<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\Tenant\Item;
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
        abort_unless($configuration, 404, 'La configuración de la tienda no existe.');

        $data = $this->payload($configuration);
        // Summary for the settings screen (not part of the save responses).
        $data['published_products'] = Item::where('apply_store', 1)->where('active', 1)->whereNotNull('internal_id')->count();
        $data['store_url'] = '/ecommerce';
        $company = Company::first();
        $companyLogo = trim((string) optional($company)->logo);
        $data['company_logo_url'] = $companyLogo !== '' ? '/storage/uploads/logos/'.basename($companyLogo) : null;
        $data['store_name'] = trim((string) (optional($company)->trade_name ?: optional($company)->name));

        return ['data' => $data];
    }

    /**
     * Safe representation of the configuration (never includes the private Culqi key).
     */
    private function payload(ConfigurationEcommerce $configuration): array
    {
        return (new ConfigurationEcommerceResource($configuration))->resolve(new Request());
    }

    public function store_configuration(ConfigurationEcommerceRequest $request)
    {
        return $this->updateSection($request, [
            'information_contact_name',
            'information_contact_email',
            'information_contact_phone',
            'information_contact_address',
        ], 'Datos de contacto guardados');
    }

    public function store_configuration_culqui(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token_public_culqui' => ['nullable', 'string', 'max:255', 'regex:/^pk_(test|live)_[A-Za-z0-9_]+$/'],
            'token_private_culqui' => ['nullable', 'string', 'max:255', 'regex:/^sk_(test|live)_[A-Za-z0-9_]+$/'],
            'clear_token_private_culqui' => ['nullable', 'boolean'],
        ], [
            'token_public_culqui.regex' => 'La clave pública debe empezar con pk_test_ (pruebas) o pk_live_ (producción).',
            'token_private_culqui.regex' => 'La clave privada debe empezar con sk_test_ (pruebas) o sk_live_ (producción).',
            'max' => 'La clave no debe superar 255 caracteres.',
            'string' => 'La clave no es válida.',
        ]);

        $configuration = ConfigurationEcommerce::firstOrFail();

        $validator->after(function ($validator) use ($request, $configuration) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            // Test and live keys must not be mixed: the charge would be rejected by Culqi.
            $public = $request->has('token_public_culqui') ? $request->input('token_public_culqui') : $configuration->token_public_culqui;
            $private = $request->filled('token_private_culqui') ? $request->input('token_private_culqui') : $configuration->token_private_culqui;
            $publicMode = ConfigurationEcommerceResource::culqiMode($public);
            $privateMode = ConfigurationEcommerceResource::culqiMode($private);
            if ($publicMode && $privateMode && $publicMode !== $privateMode && ! $request->boolean('clear_token_private_culqui')) {
                $validator->errors()->add('token_private_culqui', 'La clave privada y la pública deben ser del mismo modo (ambas de pruebas o ambas de producción).');
            }
        });
        $validator->validate();

        // Only touch the public token when the field was sent (a partial save must not erase it).
        if ($request->has('token_public_culqui')) {
            $configuration->token_public_culqui = $request->input('token_public_culqui') ?: null;
        }
        // Write-only: an empty value keeps the stored secret; it is removed only on explicit request.
        if ($request->boolean('clear_token_private_culqui')) {
            $configuration->token_private_culqui = null;
        } elseif ($request->filled('token_private_culqui')) {
            $configuration->token_private_culqui = $request->input('token_private_culqui');
        }
        $configuration->save();

        return [
            'success' => true,
            'message' => 'Configuración de pagos con tarjeta guardada',
            'record' => $this->payload($configuration),
        ];
    }

    public function store_configuration_tag(Request $request)
    {
        $request->validate([
            'tag_shipping' => 'nullable|string|max:255',
            'tag_dollar' => 'nullable|string|max:255',
            'tag_support' => 'nullable|string|max:255',
        ], [
            'max' => 'El texto no debe superar 255 caracteres.',
            'string' => 'El texto no es válido.',
        ]);

        return $this->updateSection($request, ['tag_shipping', 'tag_dollar', 'tag_support'], 'Mensajes de la ficha del producto guardados');
    }

    public function store_configuration_social(Request $request)
    {
        // Links are rendered as hrefs on the public storefront: only http(s) URLs are accepted.
        $rule = ['nullable', 'string', 'max:255', 'url', 'starts_with:http://,https://'];
        $request->validate([
            'link_youtube' => $rule,
            'link_twitter' => $rule,
            'link_facebook' => $rule,
        ], [
            'url' => 'El enlace debe ser una URL válida (https://...).',
            'starts_with' => 'El enlace debe comenzar con http:// o https://.',
            'max' => 'El enlace no debe superar 255 caracteres.',
            'string' => 'El enlace no es válido.',
        ]);

        return $this->updateSection($request, ['link_youtube', 'link_twitter', 'link_facebook'], 'Redes sociales guardadas');
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
            'message' => $message,
            'record' => $this->payload($configuration),
        ];
    }

    public function uploadFile(Request $request)
    {
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'type' => ['required', Rule::in(['logo_store'])],
            ], [
                'file.image' => 'El archivo debe ser una imagen JPG, PNG o GIF.',
                'file.mimes' => 'El logo debe ser JPG, PNG o GIF.',
                'file.max' => 'El logo no debe superar 2 MB.',
                'file.uploaded' => 'No se pudo subir el archivo. Verifique que no supere 2 MB.',
                'type.required' => 'Falta el tipo de archivo.',
                'type.in' => 'El tipo de archivo no es válido.',
            ]);

            $config = ConfigurationEcommerce::firstOrFail();
            $company = Company::firstOrFail();

            $type = $request->input('type'); // logo_store

            $file = $request->file('file');
            $ext = $file->extension() ?: 'png';
            // A new name per upload avoids stale copies in browsers and the CDN cache.
            $name = $type . '_' . $company->number . '_' . time() . '.' . $ext;

            $file->storeAs('public/uploads/logos', $name);
            $this->deleteStoredLogo($config->logo);

            $config->logo = $name;

            $config->save();

            return [
                'success' => true,
                'message' => 'Logo actualizado',
                'name' => $name,
                'type' => $type,
                'logo_url' => '/storage/uploads/logos/'.$name,
            ];
        }
        return [
            'success' => false,
            'message' => 'Seleccione una imagen para subir.',
        ];
    }

    /**
     * Removes the store logo (the storefront then falls back to the company logo or the store name).
     */
    public function removeLogo()
    {
        $config = ConfigurationEcommerce::firstOrFail();
        $this->deleteStoredLogo($config->logo);
        $config->logo = null;
        $config->save();

        return [
            'success' => true,
            'message' => 'Logo eliminado',
            'record' => $this->payload($config),
        ];
    }

    private function deleteStoredLogo($logo): void
    {
        $logo = trim((string) $logo);
        // Only files this screen created (logo_store_*) are removed, never the company logo.
        if ($logo !== '' && strpos(basename($logo), 'logo_store_') === 0) {
            Storage::delete('public/uploads/logos/'.basename($logo));
        }
    }
}
