@php
    $isProjected = ($report['mode'] ?? 'real') === 'projected';
    $currency = $report['currency_type_id'] ?? 'PEN';
    $symbol = $currency === 'USD' ? 'US$' : 'S/';
    $periods = $report['periods'] ?? [];
    $periodSummary = $report['period_summary'] ?? [];
    $totals = $report['totals'] ?? [];
    $hasNonZeroTransfersNet = !$isProjected && (
        abs((float) ($totals['transfers_net'] ?? 0)) > 0.0001
        || collect($periodSummary)->contains(fn ($ps) => abs((float) ($ps['transfers_net'] ?? 0)) > 0.0001)
    );
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Flujo de caja {{ $isProjected ? 'proyectado' : 'real' }}</title>
</head>
<body>
    <table>
        <tr>
            <td colspan="{{ count($periods) + 2 }}">
                <strong>{{ $isProjected ? 'FLUJO DE CAJA PROYECTADO' : 'FLUJO DE CAJA REAL' }}</strong>
            </td>
        </tr>
        <tr>
            <td><strong>Empresa:</strong></td>
            <td colspan="{{ count($periods) + 1 }}">{{ $company['name'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>RUC:</strong></td>
            <td colspan="{{ count($periods) + 1 }}">{{ $company['ruc'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Período:</strong></td>
            <td colspan="{{ count($periods) + 1 }}">{{ $report['date_start'] ?? '' }} al {{ $report['date_end'] ?? '' }}</td>
        </tr>
        <tr>
            <td><strong>Moneda:</strong></td>
            <td colspan="{{ count($periods) + 1 }}">{{ $currency }}{{ !empty($report['is_consolidated']) ? ' (Consolidado)' : '' }}</td>
        </tr>
    </table>

    <br>

    <table>
        <thead>
            <tr>
                <th>Concepto</th>
                @foreach($periods as $p)
                    <th>{{ $p['label'] }}</th>
                @endforeach
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Saldo inicial</strong></td>
                @foreach($periodSummary as $ps)
                    <td>{{ number_format((float) $ps['opening_balance'], 2, '.', '') }}</td>
                @endforeach
                <td>{{ number_format((float) ($totals['opening_balance'] ?? 0), 2, '.', '') }}</td>
            </tr>

            <tr>
                <td colspan="{{ count($periods) + 2 }}"><strong>ENTRADAS ({{ $symbol }})</strong></td>
            </tr>
            @forelse($report['inflow_rows'] ?? [] as $row)
                <tr>
                    <td>{{ $row['category'] }}</td>
                    @foreach($periods as $p)
                        <td>{{ number_format((float) ($row['values'][$p['key']] ?? 0), 2, '.', '') }}</td>
                    @endforeach
                    <td>{{ number_format((float) $row['total'], 2, '.', '') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($periods) + 2 }}">Sin entradas registradas en el período.</td>
                </tr>
            @endforelse
            <tr>
                <td><strong>Total entradas</strong></td>
                @foreach($periodSummary as $ps)
                    <td><strong>{{ number_format((float) $ps['inflows'], 2, '.', '') }}</strong></td>
                @endforeach
                <td><strong>{{ number_format((float) ($totals['inflows'] ?? 0), 2, '.', '') }}</strong></td>
            </tr>

            <tr>
                <td colspan="{{ count($periods) + 2 }}"><strong>SALIDAS ({{ $symbol }})</strong></td>
            </tr>
            @forelse($report['outflow_rows'] ?? [] as $row)
                <tr>
                    <td>{{ $row['category'] }}</td>
                    @foreach($periods as $p)
                        <td>{{ number_format((float) ($row['values'][$p['key']] ?? 0), 2, '.', '') }}</td>
                    @endforeach
                    <td>{{ number_format((float) $row['total'], 2, '.', '') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($periods) + 2 }}">Sin salidas registradas en el período.</td>
                </tr>
            @endforelse
            <tr>
                <td><strong>Total salidas</strong></td>
                @foreach($periodSummary as $ps)
                    <td><strong>{{ number_format((float) $ps['outflows'], 2, '.', '') }}</strong></td>
                @endforeach
                <td><strong>{{ number_format((float) ($totals['outflows'] ?? 0), 2, '.', '') }}</strong></td>
            </tr>

            @if(!empty($report['transfer_rows']))
                <tr>
                    <td colspan="{{ count($periods) + 2 }}"><strong>TRANSFERENCIAS INTERNAS (EXCLUIDAS DEL FLUJO OPERATIVO)</strong></td>
                </tr>
                @foreach($report['transfer_rows'] as $row)
                    <tr>
                        <td>{{ $row['category'] }}</td>
                        @foreach($periods as $p)
                            <td>{{ number_format((float) ($row['values'][$p['key']] ?? 0), 2, '.', '') }}</td>
                        @endforeach
                        <td>{{ number_format((float) $row['total'], 2, '.', '') }}</td>
                    </tr>
                @endforeach
            @endif

            @if($hasNonZeroTransfersNet)
                <tr>
                    <td><strong>Neto de transferencias (dif. cambio / otra moneda)</strong></td>
                    @foreach($periodSummary as $ps)
                        <td><strong>{{ number_format((float) ($ps['transfers_net'] ?? 0), 2, '.', '') }}</strong></td>
                    @endforeach
                    <td><strong>{{ number_format((float) ($totals['transfers_net'] ?? 0), 2, '.', '') }}</strong></td>
                </tr>
            @endif

            <tr>
                <td><strong>Flujo neto del período</strong></td>
                @foreach($periodSummary as $ps)
                    <td><strong>{{ number_format((float) $ps['net'], 2, '.', '') }}</strong></td>
                @endforeach
                <td><strong>{{ number_format((float) ($totals['net'] ?? 0), 2, '.', '') }}</strong></td>
            </tr>
            <tr>
                <td><strong>Saldo final</strong></td>
                @foreach($periodSummary as $ps)
                    <td><strong>{{ number_format((float) $ps['closing_balance'], 2, '.', '') }}</strong></td>
                @endforeach
                <td><strong>{{ number_format((float) ($totals['closing_balance'] ?? 0), 2, '.', '') }}</strong></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
