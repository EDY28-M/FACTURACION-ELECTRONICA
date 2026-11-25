<?php

namespace App\CoreFacturalo\Helpers\Sunat;

use Exception;

/**
 * Single source of truth for SUNAT / OSE environment readiness before emitting,
 * signing or sending electronic documents (CPE / GRE / summaries / voided /
 * retentions / perceptions).
 *
 * Environments (soap_types):
 *  - 01: Demo (SUNAT Beta uses bundled test certificate; Demo + OSE requires OSE config & certificate)
 *  - 02: Producción (requires SOL user, SOL password, readable certificate file, and OSE URL if OSE)
 *  - 03: Interno (no electronic SUNAT documents; only Notas de Venta and non-electronic records)
 *
 * Keep POS UI rules in sync with resources/js/helpers/pos_environment.js
 */
class SoapEnvironmentGuard
{
    public const SOAP_DEMO = '01';
    public const SOAP_PRODUCTION = '02';
    public const SOAP_INTERNAL = '03';

    public const SEND_SUNAT = '01';
    public const SEND_OSE = '02';

    public const INTERNAL_MODE_MESSAGE = 'La empresa está en modo SUNAT · Interno. Solo puede emitir Notas de Venta; para emitir comprobantes electrónicos cambie el entorno a Demo o Producción en Configuración → Empresa → SUNAT y certificado.';

    public const INVALID_ENVIRONMENT_MESSAGE = 'El entorno SUNAT de la empresa no es válido. Revise Configuración → Empresa → SUNAT y certificado.';

    public static function isDemo($company): bool
    {
        return (string) ($company->soap_type_id ?? '') === self::SOAP_DEMO;
    }

    public static function isProduction($company): bool
    {
        return (string) ($company->soap_type_id ?? '') === self::SOAP_PRODUCTION;
    }

    public static function isInternal($company): bool
    {
        return (string) ($company->soap_type_id ?? '') === self::SOAP_INTERNAL;
    }

    public static function isOse($company): bool
    {
        return (string) ($company->soap_send_id ?? self::SEND_SUNAT) === self::SEND_OSE;
    }

    /**
     * Physical dispatches (series not starting with T or V) are not signed or sent to SUNAT.
     * Every other Facturalo type (invoice, credit, debit, summary, voided, retention, perception)
     * and electronic dispatches (T/V) require a valid electronic environment.
     */
    public static function isElectronicEmission(string $type, ?string $series = null): bool
    {
        if ($type === 'dispatch' && $series !== null && trim($series) !== '') {
            $prefix = strtoupper(substr(trim($series), 0, 1));
            return in_array($prefix, ['T', 'V'], true);
        }

        return true;
    }

    /**
     * Resolves the filesystem path to the PEM certificate to sign XMLs.
     * Never returns a bare directory path when company->certificate is empty.
     *
     * @throws Exception
     */
    public static function certificatePath($company): string
    {
        if (!$company) {
            throw new Exception(self::INVALID_ENVIRONMENT_MESSAGE);
        }

        if (self::isInternal($company)) {
            throw new Exception(self::INTERNAL_MODE_MESSAGE);
        }

        if (!self::isDemo($company) && !self::isProduction($company)) {
            throw new Exception(self::INVALID_ENVIRONMENT_MESSAGE);
        }

        if (self::isDemo($company) && !self::isOse($company)) {
            return self::demoCertificatePath();
        }

        $certificate = trim((string) ($company->certificate ?? ''));
        if ($certificate === '') {
            $envLabel = self::isProduction($company) ? 'Producción' : 'Demo (OSE)';
            throw new Exception(
                "No se puede firmar el comprobante en {$envLabel} porque falta configurar el certificado digital. "
                .'Súbalo en Configuración → Empresa → SUNAT y certificado.'
            );
        }

        return self::tenantCertificatePath($certificate);
    }

    /**
     * Returns the list of missing configuration fields (Spanish labels) required
     * to emit/sign/send electronic documents in the company's current environment.
     *
     * @param  object|null    $company
     * @param  callable|null  $fileChecker  fn(string $path): bool
     * @return string[]
     */
    public static function missingForEmission($company, ?callable $fileChecker = null): array
    {
        if (!$company || self::isInternal($company)) {
            return [];
        }

        $isReadableFile = $fileChecker ?: static function (string $path): bool {
            return is_file($path) && is_readable($path);
        };

        $missing = [];
        $isOse = self::isOse($company);

        if (self::isProduction($company)) {
            if (!self::isFilled($company->soap_username ?? null)) {
                $missing[] = 'usuario SOL';
            }
            if (!self::isFilled($company->soap_password ?? null)) {
                $missing[] = 'clave SOL';
            }

            $certificate = trim((string) ($company->certificate ?? ''));
            if ($certificate === '' || !$isReadableFile(self::tenantCertificatePath($certificate))) {
                $missing[] = 'certificado digital';
            }

            if ($isOse && !self::isFilled($company->soap_url ?? null)) {
                $missing[] = 'URL del OSE';
            }

            return $missing;
        }

        if (self::isDemo($company)) {
            if ($isOse) {
                if (!self::isFilled($company->soap_username ?? null)) {
                    $missing[] = 'usuario SOL/OSE';
                }
                if (!self::isFilled($company->soap_password ?? null)) {
                    $missing[] = 'clave SOL/OSE';
                }

                $certificate = trim((string) ($company->certificate ?? ''));
                if ($certificate === '' || !$isReadableFile(self::tenantCertificatePath($certificate))) {
                    $missing[] = 'certificado digital';
                }

                if (!self::isFilled($company->soap_url ?? null)) {
                    $missing[] = 'URL del OSE';
                }
            } elseif (!$isReadableFile(self::demoCertificatePath())) {
                $missing[] = 'certificado digital de pruebas';
            }

            return $missing;
        }

        return [];
    }

    /**
     * Validates that the company's environment allows emitting a document of the given type/series.
     *
     * @throws Exception
     */
    public static function assertCanEmit($company, string $type = 'invoice', ?string $series = null, ?callable $fileChecker = null): void
    {
        if (!self::isElectronicEmission($type, $series)) {
            return;
        }

        self::assertElectronicReady($company, 'emitir comprobantes', $fileChecker);
    }

    /**
     * Validates that the company's environment allows signing XML.
     *
     * @throws Exception
     */
    public static function assertCanSign($company, ?callable $fileChecker = null): void
    {
        self::assertElectronicReady($company, 'firmar comprobantes', $fileChecker);
    }

    /**
     * Validates that the company's environment allows sending or querying SUNAT/OSE.
     *
     * @throws Exception
     */
    public static function assertCanSend($company, ?callable $fileChecker = null): void
    {
        self::assertElectronicReady($company, 'enviar comprobantes a SUNAT', $fileChecker);
    }

    /**
     * @throws Exception
     */
    private static function assertElectronicReady($company, string $action, ?callable $fileChecker = null): void
    {
        if (!$company) {
            throw new Exception(self::INVALID_ENVIRONMENT_MESSAGE);
        }

        if (self::isInternal($company)) {
            throw new Exception(self::INTERNAL_MODE_MESSAGE);
        }

        if (!self::isDemo($company) && !self::isProduction($company)) {
            throw new Exception(self::INVALID_ENVIRONMENT_MESSAGE);
        }

        $missing = self::missingForEmission($company, $fileChecker);
        if (!empty($missing)) {
            $envLabel = self::isProduction($company)
                ? 'Producción'
                : (self::isOse($company) ? 'Demo (OSE)' : 'Demo');

            throw new Exception(
                "No se puede {$action} en {$envLabel} porque falta configurar: "
                .implode(', ', $missing)
                .'. Complételo en Configuración → Empresa → SUNAT y certificado.'
            );
        }
    }

    public static function demoCertificatePath(): string
    {
        if (function_exists('app_path')) {
            return app_path(
                'CoreFacturalo'.DIRECTORY_SEPARATOR
                .'WS'.DIRECTORY_SEPARATOR
                .'Signed'.DIRECTORY_SEPARATOR
                .'Resources'.DIRECTORY_SEPARATOR
                .'certificate.pem'
            );
        }

        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR
            .'WS'.DIRECTORY_SEPARATOR
            .'Signed'.DIRECTORY_SEPARATOR
            .'Resources'.DIRECTORY_SEPARATOR
            .'certificate.pem';
    }

    public static function tenantCertificatePath(string $certificate): string
    {
        $filename = basename(trim($certificate));

        if (function_exists('storage_path')) {
            return storage_path('app'.DIRECTORY_SEPARATOR.'certificates'.DIRECTORY_SEPARATOR.$filename);
        }

        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR
            .'storage'.DIRECTORY_SEPARATOR
            .'app'.DIRECTORY_SEPARATOR
            .'certificates'.DIRECTORY_SEPARATOR
            .$filename;
    }

    private static function isFilled($value): bool
    {
        if ($value === null) {
            return false;
        }

        return trim((string) $value) !== '';
    }
}
