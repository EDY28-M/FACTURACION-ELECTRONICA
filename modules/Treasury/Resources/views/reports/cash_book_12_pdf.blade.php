@php
    $meta = $report['meta'] ?? [];
    $rows = $report['rows'] ?? [];
    $totals = $report['totals'] ?? [];
    $cnAdjustments = $report['credit_note_adjustments'] ?? [];
    $currency = $meta['currency_type_id'] ?? 'PEN';
    $symbol = $currency === 'USD' ? 'US$' : 'S/';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Formato 1.2 - Libro Caja y Bancos (Cuenta corriente)</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1e293b; }
        h1 { font-size: 11px; text-align: center; margin: 0 0 8px 0; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .meta td { padding: 3px 6px; border: 1px solid #cbd5e1; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { padding: 3px 4px; border: 1px solid #cbd5e1; }
        .grid thead th { background: #1e3a5f; color: #ffffff; text-align: center; }
        .num { text-align: right; white-space: nowrap; }
        .center { text-align: center; white-space: nowrap; }
        .subtotal td { background: #f1f5f9; font-weight: bold; }
        .total td { background: #dbeafe; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $meta['title'] ?? 'FORMATO 1.2: LIBRO CAJA Y BANCOS - DETALLE DE LOS MOVIMIENTOS DE LA CUENTA CORRIENTE' }}</h1>

    <table class="meta">
        <tr>
            <td><strong>PERÍODO:</strong> {{ $meta['period_label'] ?? '-' }}</td>
            <td><strong>RUC:</strong> {{ $meta['company_ruc'] ?? '-' }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>RAZÓN SOCIAL:</strong> {{ $meta['company_name'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>ENTIDAD FINANCIERA:</strong> {{ $meta['bank_name'] ?? '-' }}</td>
            <td><strong>CUENTA CORRIENTE:</strong> {{ $meta['account_number'] ?? '-' }} — {{ $meta['account_description'] ?? '-' }} ({{ $currency }})</td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th rowspan="2">N.º Correlativo / CUO</th>
                <th rowspan="2">Fecha</th>
                <th colspan="4">Operaciones bancarias</th>
                <th colspan="2">Cuenta contable asociada</th>
                <th colspan="2">Saldos y movimientos ({{ $symbol }})</th>
            </tr>
            <tr>
                <th>Medio pago (T.1)</th>
                <th>Descripción</th>
                <th>Girador / Beneficiario</th>
                <th>N.º transacción / doc.</th>
                <th>Código</th>
                <th>Denominación</th>
                <th>Deudor</th>
                <th>Acreedor</th>
            </tr>
        </thead>
        <tbody>
            <tr class="subtotal">
                <td class="center">-</td>
                <td class="center">{{ $meta['date_start'] ?? '' }}</td>
                <td class="center">-</td>
                <td>SALDO INICIAL</td>
                <td>-</td>
                <td>-</td>
                <td class="center">{{ $meta['pcge_account'] ?? '1041' }}</td>
                <td>{{ $meta['account_description'] ?? 'Cuentas corrientes operativas' }}</td>
                <td class="num">{{ (float) ($report['opening_balance'] ?? 0) >= 0 ? number_format((float) ($report['opening_balance'] ?? 0), 2, '.', ',') : '0.00' }}</td>
                <td class="num">{{ (float) ($report['opening_balance'] ?? 0) < 0 ? number_format(abs((float) ($report['opening_balance'] ?? 0)), 2, '.', ',') : '0.00' }}</td>
            </tr>
            @forelse($rows as $row)
                <tr>
                    <td class="center">{{ $row['correlative'] }}</td>
                    <td class="center">{{ $row['date'] }}</td>
                    <td class="center">{{ $row['sunat_payment_code'] }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td>{{ $row['counterparty'] }}</td>
                    <td>{{ $row['transaction_number'] }}</td>
                    <td class="center">{{ $row['pcge_code'] }}</td>
                    <td>{{ $row['pcge_name'] }}</td>
                    <td class="num">{{ number_format((float) $row['debit'], 2, '.', ',') }}</td>
                    <td class="num">{{ number_format((float) $row['credit'], 2, '.', ',') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="center">No se registraron movimientos bancarios en el período.</td>
                </tr>
            @endforelse
            <tr class="subtotal">
                <td colspan="8">TOTALES DEL PERÍODO</td>
                <td class="num">{{ number_format((float) ($totals['debit'] ?? 0), 2, '.', ',') }}</td>
                <td class="num">{{ number_format((float) ($totals['credit'] ?? 0), 2, '.', ',') }}</td>
            </tr>
            <tr class="total">
                <td colspan="8">SALDO FINAL SEGÚN LIBRO</td>
                <td colspan="2" class="num">{{ $symbol }} {{ number_format((float) ($totals['closing_balance'] ?? 0), 2, '.', ',') }}</td>
            </tr>
            @if(!empty($cnAdjustments))
                <tr class="subtotal">
                    <td colspan="8">AJUSTES POR NOTAS DE CRÉDITO DEL PERÍODO ({{ count($cnAdjustments) }})</td>
                    <td colspan="2" class="num">{{ $symbol }} {{ number_format((float) ($totals['credit_note_adjustments'] ?? 0), 2, '.', ',') }}</td>
                </tr>
                <tr class="total">
                    <td colspan="8">SALDO FINAL SEGÚN SISTEMA</td>
                    <td colspan="2" class="num">{{ $symbol }} {{ number_format((float) ($totals['system_closing_balance'] ?? 0), 2, '.', ',') }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
