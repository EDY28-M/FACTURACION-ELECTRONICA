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
    <title>Formato 1.1 - Libro Caja y Bancos (Efectivo)</title>
</head>
<body>
    <table>
        <tr>
            <td colspan="7"><strong>{{ $meta['title'] ?? 'FORMATO 1.1: LIBRO CAJA Y BANCOS - DETALLE DE LOS MOVIMIENTOS DEL EFECTIVO' }}</strong></td>
        </tr>
        <tr>
            <td><strong>PERÍODO:</strong></td>
            <td colspan="6">{{ $meta['period_label'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>RUC:</strong></td>
            <td colspan="6">{{ $meta['company_ruc'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>APELLIDOS Y NOMBRES, DENOMINACIÓN O RAZÓN SOCIAL:</strong></td>
            <td colspan="6">{{ $meta['company_name'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>SEDE / CAJA:</strong></td>
            <td colspan="6">{{ $meta['establishment_label'] ?? '-' }} — {{ $meta['cash_label'] ?? '-' }} (Moneda: {{ $meta['currency_type_id'] ?? 'PEN' }})</td>
        </tr>
    </table>

    <br>

    <table>
        <thead>
            <tr>
                <th>N.º correlativo / CUO</th>
                <th>Fecha de la operación</th>
                <th>Descripción de la operación</th>
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
                <td><strong>SALDO INICIAL</strong></td>
                <td>101</td>
                <td>Caja</td>
                <td>{{ (float) ($report['opening_balance'] ?? 0) >= 0 ? number_format((float) ($report['opening_balance'] ?? 0), 2, '.', '') : '0.00' }}</td>
                <td>{{ (float) ($report['opening_balance'] ?? 0) < 0 ? number_format(abs((float) ($report['opening_balance'] ?? 0)), 2, '.', '') : '0.00' }}</td>
            </tr>
            @foreach($rows as $row)
                <tr>
                    <td>{{ $row['correlative'] }}</td>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td>{{ $row['pcge_code'] }}</td>
                    <td>{{ $row['pcge_name'] }}</td>
                    <td>{{ number_format((float) $row['debit'], 2, '.', '') }}</td>
                    <td>{{ number_format((float) $row['credit'], 2, '.', '') }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="5"><strong>TOTALES DEL PERÍODO</strong></td>
                <td><strong>{{ number_format((float) ($totals['debit'] ?? 0), 2, '.', '') }}</strong></td>
                <td><strong>{{ number_format((float) ($totals['credit'] ?? 0), 2, '.', '') }}</strong></td>
            </tr>
            <tr>
                <td colspan="5"><strong>SALDO FINAL SEGÚN LIBRO</strong></td>
                <td colspan="2"><strong>{{ number_format((float) ($totals['closing_balance'] ?? 0), 2, '.', '') }}</strong></td>
            </tr>
            @if(!empty($cnAdjustments))
                <tr>
                    <td colspan="5"><strong>AJUSTES POR NOTAS DE CRÉDITO ({{ count($cnAdjustments) }})</strong></td>
                    <td colspan="2"><strong>{{ number_format((float) ($totals['credit_note_adjustments'] ?? 0), 2, '.', '') }}</strong></td>
                </tr>
                <tr>
                    <td colspan="5"><strong>SALDO FINAL SEGÚN SISTEMA</strong></td>
                    <td colspan="2"><strong>{{ number_format((float) ($totals['system_closing_balance'] ?? 0), 2, '.', '') }}</strong></td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>
