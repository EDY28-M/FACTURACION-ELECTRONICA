<?php
namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use App\Models\System\Configuration;



class ConfigurationController extends Controller
{

    public function index()
    {
        $config = Configuration::first();
        // 'false' is the legacy "internal lookup" marker, not a real token.
        $primaryToken = $config->token_apiruc === 'false' ? null : $config->token_apiruc;
        $configuration = [
            'login' => $config->login,
            'use_login_global' => $config->use_login_global,
            'status' => [
                'certificate' => (bool) $config->certificate,
                'soap_user' => (bool) $config->soap_username,
                'culqi_public' => (bool) $config->token_public_culqui,
                'culqi_private' => (bool) $config->token_private_culqui,
                'ruc' => ($config->url_apiruc && $primaryToken) || $config->secondary_token_apiruc,
                'ruc_partial' => $config->url_apiruc || $primaryToken || $config->secondary_url_apiruc || $config->secondary_token_apiruc,
            ],
        ];
        return view('system.configuration.index', compact('configuration'));
    }

    public function record()
    {

        $configuration = Configuration::first();

        return [
            'token_public_culqui' => $configuration->token_public_culqui,
            'has_token_private_culqui' => (bool) $configuration->token_private_culqui,
        ];
    }


    public function store(Request $request)
    {
        $request->validate([
            'apiruc_primary' => 'nullable|in:primary,secondary',
        ]);

        $configuration = Configuration::first();

        if($request->token_public_culqui)
        {
            $configuration->token_public_culqui = $request->token_public_culqui;
        }

        if($request->token_private_culqui)
        {
            $configuration->token_private_culqui = $request->token_private_culqui;
        }

        if($request->url_apiruc)
        {
            $configuration->url_apiruc = $request->url_apiruc;
        }

        // The internal (scraper) lookup was removed: drop its legacy 'false' marker.
        if ($configuration->token_apiruc === 'false') {
            $configuration->token_apiruc = null;
        }

        if($request->token_apiruc && $request->token_apiruc !== 'false')
        {
            $configuration->token_apiruc = $request->token_apiruc;
        }

        if($request->secondary_url_apiruc)
        {
            $configuration->secondary_url_apiruc = $request->secondary_url_apiruc;
        }

        if($request->secondary_token_apiruc)
        {
            $configuration->secondary_token_apiruc = $request->secondary_token_apiruc;
        }

        if($request->apiruc_primary)
        {
            $configuration->apiruc_primary = $request->apiruc_primary;
        }

        $configuration->save();

        return [
            'success' => true,
            'message' => 'Datos guardados con exito'
        ];
    }

    public function apiruc()
    {

        $configuration = Configuration::first();

        return [
            'url_apiruc' => $configuration->url_apiruc,
            'token_apiruc' => $configuration->token_apiruc,
            'secondary_url_apiruc' => $configuration->secondary_url_apiruc,
            'secondary_token_apiruc' => $configuration->secondary_token_apiruc,
            'apiruc_primary' => $configuration->apiruc_primary ?: 'primary',
        ];
    }

    public function storeLoginSettings()
    {
        request()->validate([
            'position_form' => 'required|in:left,right',
            'show_logo_in_form' => 'required|boolean',
            'position_logo' => 'required|in:top-left,top-right,bottom-left,bottom-right,none',
            'show_socials' => 'required|boolean',
            'use_login_global' => 'required|boolean',
        ]);

        $config = Configuration::first();
        $loginConfig = $config->login;
        foreach(request()->all() as $key => $option) {
            $loginConfig->$key = $option;
        }

        $config->login = $loginConfig;
        $config->use_login_global = request('use_login_global');
        $config->save();

        return response()->json([
            'success' => true,
            'message' => 'Información actualizada.',
        ], 200);
    }

    public function storeBgLogin()
    {
        request()->validate([
            'image' => 'required|mimes:webp,jpeg,png,jpg,gif,svg|max:2048'
        ]);

        $config = Configuration::first();
        if (request()->hasFile('image') && request()->file('image')->isValid()) {
            $file = request()->file('image');
            $ext = $file->getClientOriginalExtension();
            $name = time() . '.' . $ext;
            $path = 'public/uploads/login';
            $file->storeAs($path, $name);

            $loginConfig = $config->login;
            $basePathStorage = 'storage/uploads/login/';
			if (request('type') === 'bg') {
                $loginConfig->type = 'image';
				$loginConfig->image = asset($basePathStorage . $name);
			} else {
                $loginConfig->logo = asset($basePathStorage . $name);
            }
            $config->login = $loginConfig;
            $config->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Información actualizada.',
        ], 200);
    }
}
