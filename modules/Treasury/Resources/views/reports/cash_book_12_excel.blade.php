@php
    $meta = $report['meta'] ?? [];
    $rows = $report['rows'] ?? [];
    $totals = $report['totals'] ?? [];
    $cnAdjustments = $report['credit_note_adjustments'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Formato 1.2 - Libro Caja y Bancos (Cuenta corriente)</title>
</head>
<body>
    <table>
        <tr>
            <td colspan="10"><strong>{{ $meta['title'] ?? 'FORMATO 1.2: LIBRO CAJA Y BANCOS - DETALLE DE LOS MOVIMIENTOS DE LA CUENTA CORRIENTE' }}</strong></td>
        </tr>
        <tr>
            <td><strong>PERÍODO:</strong></td>
            <td colspan="9">{{ $meta['period_label'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>RUC:</strong></td>
            <td colspan="9">{{ $meta['company_ruc'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>RAZÓN SOCIAL:</strong></td>
            <td colspan="9">{{ $meta['company_name'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>ENTIDAD FINANCIERA:</strong></td>
            <td colspan="4">{{ $meta['bank_name'] ?? '-' }}</td>
            <td><strong>CÓDIGO CUENTA CORRIENTE:</strong></td>
            <td colspan="4">{{ $meta['account_number'] ?? '-' }} ({{ $meta['currency_type_id'] ?? 'PEN' }})</td>
        </tr>
    </table>

    <br>

    <table>
        <thead>
            <tr>
                <th>N.º correlativo / CUO</th>
                <th>Fecha de la operación</th>
                <th>Medio de pago (Tabla 1)</th>
                <th>Descripción de la operación</th>
                <th>Apellidos y nombres / Razón social</th>
                <th>N.º transacción / documento</th>
                <th>Código cuenta contable</th>
                <th>Denominación cuenta contable</th>
                <th>Deudor (Debe)</th>
                <th>Acreedor (Haber)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td></td>
                <td>{{ $meta['date_start'] ?? '' }}</td>
                <td></td>
                <td><strong>SALDO INICIAL</strong></td>
                <td></td>
                <td></td>
                <td>{{ $meta['pcge_account'] ?? '1041' }}</td>
                <td>{{ $meta['account_description'] ?? 'Cuentas corrientes operativas' }}</td>
                <td>{{ (float) ($report['opening_balance'] ?? 0) >= 0 ? number_format((float) ($report['opening_balance'] ?? 0), 2, '.', '') : '0.00' }}</td>
                <td>{{ (float) ($report['opening_balance'] ?? 0) < 0 ? number_format(abs((float) ($report['opening_balance'] ?? 0)), 2, '.', '') : '0.00' }}</td>
            </tr>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['correlative'] }}</td>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['sunat_payment_code'] !== null ? $row['sunat_payment_code'] . ' - ' : '' }}{{ $row['sunat_payment_label'] }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td>{{ $row['counterparty'] }}</td>
                    <td>{{ $row['transaction_number'] }}</td>
                    <td>{{ $row['pcge_code'] }}</td>
                    <td>{{ $row['pcge_name'] }}</td>
                    <td>{{ number_format((float) $row['debit'], 2, '.', '') }}</td>
                    <td>{{ number_format((float) $row['credit'], 2, '.', '') }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="8"><strong>TOTALES DEL PERÍODO</strong></td>
                <td><strong>{{ number_format((float) ($totals['debit'] ?? 0), 2, '.', '') }}</strong></td>
                <td><strong>{{ number_format((float) ($totals['credit'] ?? 0), 2, '.', '') }}</strong></td>
            </tr>
            <tr>
                <td colspan="8"><strong>SALDO FINAL SEGÚN LIBRO</strong></td>
                <td colspan="2"><strong>{{ number_format((float) ($totals['closing_balance'] ?? 0), 2, '.', '') }}</strong></td>
            </tr>
            @if((int) ($totals['missing_payment_code_count'] ?? 0) > 0)
                <tr>
                    <td colspan="10"><strong>ADVERTENCIA: {{ (int) $totals['missing_payment_code_count'] }} operación(es) sin código de medio de pago (Tabla 1). Complete la evidencia de pago antes de presentar el libro.</strong></td>
                </tr>
            @endif
            @if((float) ($totals['rounding_drift'] ?? 0) != 0.0)
                <tr>
                    <td colspan="8"><strong>DIFERENCIA POR REDONDEO (conversión de moneda)</strong></td>
                    <td colspan="2"><strong>{{ number_format((float) $totals['rounding_drift'], 2, '.', '') }}</strong></td>
                </tr>
            @endif
            @if(!empty($cnAdjustments))
                <tr>
                    <td colspan="8"><strong>AJUSTES POR NOTAS DE CRÉDITO ({{ count($cnAdjustments) }})</strong></td>
                    <td colspan="2"><strong>{{ number_format((float) ($totals['credit_note_adjustments'] ?? 0), 2, '.', '') }}</strong></td>
                </tr>
                <tr>
                    <td colspan="8"><strong>SALDO FINAL SEGÚN SISTEMA</strong></td>
                    <td colspan="2"><strong>{{ number_format((float) ($totals['system_closing_balance'] ?? 0), 2, '.', '') }}</strong></td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
