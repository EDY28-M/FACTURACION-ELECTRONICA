<?php
namespace App\Http\Controllers\Tenant;

use App\CoreFacturalo\Helpers\Certificate\GenerateCertificate;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Company;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant\Configuration;


class CertificateController extends Controller
{
    public function record()
    {
        $company = Company::active();
        $configuration = Configuration::first();

        return [
            'certificate' => optional($company)->certificate,
            'config_system_env' => (bool) optional($configuration)->config_system_env
        ];
    }

    public function uploadFile(Request $request)
    {
        if (!Configuration::allowsSunatSettingsChanges()) {
            return $this->lockedResponse();
        }

        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $request->validate([
                'file' => 'required|file|max:2048',
                'password' => 'nullable|string',
            ]);

            try {
                $company = Company::active();
                if (!$company) {
                    return [
                        'success' => false,
                        'message' => 'Empresa no encontrada.',
                    ];
                }
                $password = $request->input('password');
                $file = $request->file('file');
                $pfx = file_get_contents($file->getRealPath() ?: (string) $file);
                $pem = GenerateCertificate::typePEM($pfx, $password);
                $name = 'certificate_'.$company->number.'.pem';
                $certDir = storage_path('app'.DIRECTORY_SEPARATOR.'certificates');
                File::ensureDirectoryExists($certDir, 0755, true);

                if ($company->certificate && $company->certificate !== $name) {
                    $this->deleteCertificateFile($certDir, $company->certificate);
                }

                file_put_contents($certDir.DIRECTORY_SEPARATOR.$name, $pem);
                $company->certificate = $name;
                $company->save();

                return [
                    'success' => true,
                    'message' => __('app.actions.upload.success'),
                ];
            } catch (Exception $e) {
                Log::error('Certificate upload failed', ['exception' => $e->getMessage()]);
                return [
                    'success' => false,
                    'message' => 'No se pudo procesar el certificado. Verifique el archivo y la contraseña.',
                ];
            }
        }
        return [
            'success' => false,
            'message' => __('app.actions.upload.error'),
        ];
    }

    public function destroy()
    {
        if (!Configuration::allowsSunatSettingsChanges()) {
            return $this->lockedResponse();
        }

        $company = Company::active();
        if (!$company) {
            return [
                'success' => false,
                'message' => 'Empresa no encontrada.',
            ];
        }

        if ($company->certificate) {
            $this->deleteCertificateFile(
                storage_path('app'.DIRECTORY_SEPARATOR.'certificates'),
                $company->certificate
            );
        }

        $company->certificate = null;
        $company->save();

        return [
            'success' => true,
            'message' => 'Certificado eliminado con éxito'
        ];
    }

    private function lockedResponse()
    {
        return response()->json([
            'success' => false,
            'message' => 'El administrador del sistema no permite cambiar el certificado de esta empresa.',
        ], 403);
    }

    private function deleteCertificateFile(string $certDir, string $filename): void
    {
        $realDir = realpath($certDir);
        if ($realDir === false) {
            return;
        }
        $target = realpath($realDir.DIRECTORY_SEPARATOR.basename($filename));
        if ($target !== false && strpos($target, $realDir.DIRECTORY_SEPARATOR) === 0 && is_file($target)) {
            @unlink($target);
        }
    }
}
