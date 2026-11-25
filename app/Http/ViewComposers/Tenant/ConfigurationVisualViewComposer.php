<?php

namespace App\Http\ViewComposers\Tenant;

use App\Http\Resources\Tenant\ConfigurationResource;
use App\Models\Tenant\Configuration;
use Illuminate\Support\Facades\DB;

class ConfigurationVisualViewComposer
{
    public function compose($view)
    {
        $key = 'vc_visual_memo_'.DB::connection('tenant')->getDatabaseName();
        $app = app();

        if (!$app->bound($key)) {
            $configuration = Configuration::first();
            if ($configuration && is_null($configuration->visual)) {
                $configuration->visual = [
                    'bg' => 'light',
                    'header' => 'light',
                    'sidebars' => 'light',
                ];
                $configuration->save();
            }
            $record = $configuration ? new ConfigurationResource($configuration) : null;
            $app->instance($key, $record ? $record->visual : null);
        }

        $view->visual = $app->make($key);
    }
}
