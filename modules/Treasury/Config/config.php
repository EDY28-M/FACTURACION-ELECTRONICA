<?php

return [
    'name' => 'Treasury',
    'allow_future_dates' => false,

    /*
     * Non-working days (Y-m-d) used for the detraction deposit deadline (5th business day).
     * Weekends are always excluded by BusinessDayCalculator. National holidays are published by decree
     * every year, so they are data-driven: list them here (or bind another HolidayProvider).
     */
    'holidays' => [],

    /*
     * Last-resort value used ONLY when treasury_tax_rates has no `detraction_deposit_business_day` row
     * (the regulatory value lives in treasury_tax_rates, versioned by date).
     */
    'detraction_deposit_business_day_fallback' => 5,

    /*
     * Days of grace before an ESTIMATED due date (receivables, or payables whose year has no holidays loaded)
     * is reported as overdue, so a missing holiday does not flag a detraction overdue a day early.
     */
    'estimated_due_date_grace_days' => 1,

    /*
     * Resumen alerts: minutes between lazy detraction syncs (per tenant).
     */
    'detraction_sync_throttle_minutes' => 10,

    /*
     * Max |certificate amount - computed amount| accepted when linking an issued CRE/CPE to a payment
     * (rounding differences of the issuing flow).
     */
    'withholding_link_tolerance' => 0.10,

    /*
     * SUNAT catalog identifiers (not rates): certificate types (Catalogo 01-like codes) and the
     * document types (Catalogo 01) on which retention/perception apply.
     */
    'withholding' => [
        'retention_certificate_type' => '20',
        'perception_certificate_type' => '40',
        'retention_document_types' => ['01'],
        'perception_document_types' => ['01', '03'],
    ],

    /*
     * Phase 5 - bank reconciliation.
     */
    'reconciliation' => [
        // Automatic matching: a system item is a candidate when its date is within +/- N days of the line date.
        'date_window_days' => 3,
        // When true, a system item that HAS an operation number is only auto-matched if that number appears in the
        // line reference/description. Default false: banks often reformat or truncate operation numbers.
        'require_reference_when_available' => false,
        // Minimum length (letters/digits) of an operation number to be used as a reference hint.
        'min_reference_length' => 4,
        // Import limits.
        'max_file_kb' => 5120,
        'max_rows' => 5000,
        // Max columns read from a spreadsheet (guards against a stray far-away cell exhausting memory).
        'max_columns' => 30,
        // Per converted (cross-currency) item: rounding drift tolerated between the sum of rounded items and the
        // aggregate balance when computing the in-transit total.
        'rounding_tolerance' => 0.01,
        // Max in-transit items stored inside the closing snapshot (totals always cover all of them).
        'snapshot_item_limit' => 200,
        'storage_folder' => 'bank_statements',
    ],

    /*
     * Phase 6 - cash count (arqueo de caja): PEN denominations shown in the UI grid.
     * Values >= 10 are labelled as bills, the rest as coins.
     */
    'cash_count' => [
        'denominations' => [200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1],
    ],

    /*
     * Informational text for the SPOT fund release windows (Formulario 1697).
     * Source: docs/plans/tesoreria-investigacion-sunat.md section 2. Never used for calculations.
     */
    'detraction_release_info' => [
        'Procedimiento general: se puede solicitar la liberación de fondos cuando permanecen sin agotarse 3 meses consecutivos (2 meses para Buen Contribuyente o agente de retención).',
        'Las solicitudes se presentan en los primeros 5 días hábiles de enero, abril, julio y octubre (enero, marzo, mayo, julio, setiembre y noviembre para Buen Contribuyente o agente de retención).',
        'Se tramita con el Formulario 1697 en SUNAT Operaciones en Línea. Requiere no tener deuda tributaria, no estar "no habido" y tener los libros al día.',
        'Una vez aprobada la liberación, el cobro debe realizarse dentro de los 15 días hábiles siguientes.',
        'Los fondos de la cuenta de detracciones solo pueden usarse para pagar tributos administrados por SUNAT.',
        'Esta información es referencial. Verifique siempre los plazos vigentes en SUNAT.',
    ],
];
