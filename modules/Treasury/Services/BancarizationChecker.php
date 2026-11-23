<?php

namespace Modules\Treasury\Services;

use Modules\Treasury\Support\Money;

/**
 * Evaluates Ley 28194 bancarization obligations and art. 6 exceptions using TaxRateRepository.
 */
class BancarizationChecker
{
    public const LOW_COMPLIANCE_MULTIPLIER = 0.30;

    public const ALLOWED_EXCEPTIONS = [
        'financial_entity',
        'tax_administration',
        'court',
        'no_bank_district',
    ];

    public const EXCEPTION_LABELS = [
        'financial_entity' => 'Pago a entidad del sistema financiero (Art. 6 inc. a)',
        'tax_administration' => 'Pago a administración tributaria (Art. 6 inc. b)',
        'court' => 'Consignación o mandato judicial (Art. 6 inc. c)',
        'no_bank_district' => 'Distrito sin agencia del sistema financiero (Art. 6 inc. d)',
    ];

    /**
     * SUNAT Tabla 1 / SIRE Tabla 10 (RS 112-2021 Anexo 1) payment method codes.
     */
    public const SUNAT_PAYMENT_CODES = [
        '001' => 'Depósito en cuenta',
        '002' => 'Giro',
        '003' => 'Transferencia de fondos',
        '004' => 'Orden de pago',
        '005' => 'Tarjeta de débito',
        '006' => 'Tarjeta de crédito emitida en el país por una empresa del sistema financiero',
        '007' => 'Cheques con cláusula "no negociable", "intransferibles", "no a la orden" u otra equivalente',
        '008' => 'Efectivo, por operaciones en las que no existe obligación de utilizar medio de pago',
        '009' => 'Efectivo, en los demás casos',
        '010' => 'Medios de pago usados en comercio exterior',
        '011' => 'Documentos emitidos por EDPYMES y cooperativas de ahorro y crédito no autorizadas a captar depósitos del público',
        '012' => 'Tarjeta de crédito emitida en el país o en el exterior por una empresa no perteneciente al sistema financiero',
        '013' => 'Tarjetas de crédito emitidas en el exterior por empresas bancarias o financieras no domiciliadas',
        '101' => 'Transferencias - comercio exterior',
        '102' => 'Cheques bancarios - comercio exterior',
        '103' => 'Orden de pago simple - comercio exterior',
        '104' => 'Orden de pago documentario - comercio exterior',
        '105' => 'Remesa simple - comercio exterior',
        '106' => 'Remesa documentaria - comercio exterior',
        '107' => 'Carta de crédito simple - comercio exterior',
        '108' => 'Carta de crédito documentario - comercio exterior',
        '999' => 'Otros medios de pago',
    ];

    private $taxRateRepository;

    public function __construct(TaxRateRepository $taxRateRepository)
    {
        $this->taxRateRepository = $taxRateRepository;
    }

    /**
     * @return array{
     *     exceeds_threshold: bool,
     *     threshold: ?float,
     *     is_cash: bool,
     *     requires_exception_or_bank_method: bool,
     *     warning: ?string,
     *     suggested_sunat_code: ?string
     * }
     */
    public function evaluate(
        float $amount,
        string $currencyTypeId,
        string $date,
        bool $isCashMethod,
        ?string $sunatCode = null,
        bool $lowComplianceCounterparty = false,
        ?float $exchangeRateSale = null
    ): array {
        $baseThreshold = $this->taxRateRepository->getBancarizationThreshold(
            $currencyTypeId,
            $date,
            $exchangeRateSale
        );

        $threshold = $baseThreshold !== null
            ? ($lowComplianceCounterparty
                ? Money::round($baseThreshold * self::LOW_COMPLIANCE_MULTIPLIER)
                : $baseThreshold)
            : null;

        // Ley 28194 art. 4: obligation applies starting from ("a partir de") the threshold amount.
        $exceedsThreshold = ($threshold !== null && Money::round($amount) >= $threshold);
        $isCash = $isCashMethod || in_array($sunatCode, ['008', '009'], true);
        $requiresExceptionOrBankMethod = $exceedsThreshold && $isCash;

        $warning = null;
        if ($requiresExceptionOrBankMethod) {
            $currencySymbol = strtoupper($currencyTypeId) === 'USD'
                ? 'US$'
                : (strtoupper($currencyTypeId) === 'PEN' ? 'S/' : strtoupper($currencyTypeId));
            $formattedThreshold = number_format($threshold, 2, '.', ',');
            $complianceNote = $lowComplianceCounterparty ? ' (umbral reducido al 30% por perfil de cumplimiento)' : '';

            $warning = "El importe alcanza o supera el umbral de bancarización vigente ({$currencySymbol} {$formattedThreshold}{$complianceNote}). "
                . 'Debe utilizar un medio de pago bancarizado o indicar una excepción válida del art. 6 de la Ley 28194.';
        }

        $suggestedSunatCode = $sunatCode;
        if ($isCash) {
            $suggestedSunatCode = $exceedsThreshold ? '009' : '008';
        }

        return [
            'exceeds_threshold' => $exceedsThreshold,
            'threshold' => $threshold,
            'is_cash' => $isCash,
            'requires_exception_or_bank_method' => $requiresExceptionOrBankMethod,
            'warning' => $warning,
            'suggested_sunat_code' => $suggestedSunatCode,
        ];
    }

    public static function sunatPaymentCodeOptions(): array
    {
        $options = [];
        foreach (self::SUNAT_PAYMENT_CODES as $code => $description) {
            $options[] = [
                'code' => (string) $code,
                'description' => $description,
                'label' => "{$code} — {$description}",
            ];
        }

        return $options;
    }

    public static function exceptionOptions(): array
    {
        $options = [];
        foreach (self::EXCEPTION_LABELS as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }
}
