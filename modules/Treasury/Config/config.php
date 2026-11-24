<?php

return [
    'name' => 'Treasury',
    'allow_future_dates' => false,

    /*
     * Extra non-working days (Y-m-d) for the detraction deposit deadline (5th business day).
     * Weekends are always excluded by BusinessDayCalculator. National holidays live in the tenant table
     * `treasury_holidays` (managed in Tesorería > Feriados); HolidayProvider merges this list on top of it and
     * uses it alone when the table does not exist. Leave empty unless you need a manual override.
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
     * Phase 6 - reports.
     * max_range_days: widest date range accepted by the Libro Caja y Bancos (enforced by the request and the service).
     * cash_selector_limit: most recent cash shifts returned by the cash selectors (open shifts are always included).
     */
    'reports' => [
        'max_range_days' => 366,
        'cash_selector_limit' => 200,
    ],

    /*
     * PLE TXT export of the Libro Caja y Bancos (010100 / 010200). Layout: Anexo 2 of RS 286-2009/SUNAT
     * ("Estructura del PLE", docs/plans/tesoreria-investigacion-sunat.md "PLE layout confirmado").
     */
    'ple' => [
        // UNCONFIRMED by the official layout: a trailing pipe after the last field. Default off (the layout lists
        // N fields separated by pipes). Flip it only if the official PLE validator rejects the file without it.
        'trailing_pipe' => false,
        // Record terminator. Windows style, as produced by the PLE program itself.
        'line_ending' => "\r\n",
        // true: transliterate to plain ASCII (a, e, i, o, u, n; unknown symbols dropped). ASCII bytes are identical in
        // UTF-8 and ISO-8859-1 so the file is safe whatever encoding the validator assumes. false: keep UTF-8 letters.
        'ascii_only' => true,
        // Field 4 of 1.1 (cuenta contable del efectivo, at maximum disaggregation) when the cash has no own account.
        'default_cash_account' => '101',
        // Field 11 of 1.2 when the operation has no identifiable counterparty ("varios" is allowed for multiple operations).
        'unknown_counterparty_name' => 'varios',
        // Table 3 (entidad financiera) codes offered in the bank account form. REFERENCIAL / UNCONFIRMED: the official
        // table is not published in the PLE structure workbook. The export only uses the code stored per account, and
        // refuses to run when an account with movements has none. Validate them in the official PLE validator.
        'financial_entity_codes' => [
            '01' => 'Banco Central de Reserva del Perú',
            '02' => 'Banco de Crédito del Perú',
            '03' => 'Banco Internacional del Perú (Interbank)',
            '09' => 'Scotiabank Perú',
            '11' => 'BBVA Perú (ex Banco Continental)',
            '18' => 'Banco de la Nación',
        ],
    ],

    /*
     * Phase 7 - cheques y letras (Ley 27287, Ley de Titulos Valores).
     * The legal periods live in treasury_tax_rates (cheque_presentation_days, cheque_deferred_max_days,
     * bill_protest_days); the *_fallback values are used ONLY when those rows are not loaded.
     * All of them come from a secondary source (docs/plans/tesoreria-investigacion-sunat.md): [REVISAR].
     */
    'instruments' => [
        'cheque_presentation_days_fallback' => 30,
        'cheque_deferred_max_days_fallback' => 30,
        'bill_protest_days_fallback' => 15,
        // [REVISAR] 'business' counts the protest period in business days (BusinessDayCalculator + holidays);
        // 'calendar' counts calendar days. The research document only says "15 days".
        'protest_day_type' => 'business',
        // Dashboard alert windows (days).
        'alert_cheque_days' => 7,
        'alert_bill_days' => 7,
        'alert_protest_days' => 3,
        'alert_max_items' => 10,
        // SUNAT Tabla 1 code stored on the movement created by a cheque / bill. Bills have no dedicated code in the
        // Tabla 1 loaded in this module, so 999 (otros medios de pago) is used. [REVISAR]
        'sunat_payment_codes' => [
            'cheque' => '007',
            'bill' => '999',
        ],
        // Stable codes (treasury_concepts.code, tagged by migration 000017) of the concepts used on the movements.
        'concept_income' => 'instrument_income',
        'concept_expense' => 'instrument_expense',
        // payment_method_types.id used on the legacy DocumentPayment / PurchasePayment created when an instrument
        // linked to a document or purchase is collected (D4). The catalog has no cheque / letra method, so the
        // closest bank-credit one ('04' Transferencia) is used and the instrument number goes in the reference. [REVISAR]
        'payment_method_type' => [
            'cheque' => '04',
            'bill' => '04',
        ],
        'max_per_page' => 100,
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
