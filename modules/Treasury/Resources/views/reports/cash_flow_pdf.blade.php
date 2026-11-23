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
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 8px 0; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .meta td { padding: 3px 6px; border: 1px solid #cbd5e1; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { padding: 4px 5px; border: 1px solid #cbd5e1; }
        .grid thead th { background: #1e3a5f; color: #ffffff; text-align: center; }
        .num { text-align: right; white-space: nowrap; }
        .section td { background: #e2e8f0; font-weight: bold; }
        .subtotal td { background: #f8fafc; font-weight: bold; }
        .total td { background: #dbeafe; font-weight: bold; }
        .neg { color: #b91c1c; font-weight: bold; }
        .alert { border: 1px solid #dc2626; background: #fef2f2; color: #991b1b; padding: 6px 8px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <h1>{{ $isProjected ? 'FLUJO DE CAJA PROYECTADO' : 'FLUJO DE CAJA REAL' }}</h1>

    <table class="meta">
        <tr>
            <td><strong>Empresa:</strong> {{ $company['name'] ?? '-' }}</td>
            <td><strong>RUC:</strong> {{ $company['ruc'] ?? '-' }}</td>
            <td><strong>Fecha emisión:</strong> {{ date('Y-m-d') }}</td>
        </tr>
        <tr>
            <td><strong>Período:</strong> {{ $report['date_start'] ?? '' }} al {{ $report['date_end'] ?? '' }}</td>
            <td><strong>Granularidad:</strong> {{ ($report['granularity'] ?? 'month') === 'week' ? 'Semanal' : 'Mensual' }}</td>
            <td><strong>Moneda:</strong> {{ $currency }}{{ !empty($report['is_consolidated']) ? ' (Consolidado)' : '' }}</td>
        </tr>
    </table>

    @if(!empty($report['has_negative_projection']))
        <div class="alert">
            <strong>Alerta de liquidez negativa proyectada:</strong>
            Se proyecta saldo negativo en {{ count($report['negative_periods'] ?? []) }} período(s).
        </div>
    @endif

    <table class="grid">
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
            <tr class="subtotal">
                <td>Saldo inicial</td>
                @foreach($periodSummary as $ps)
                    <td class="num {{ (float) $ps['opening_balance'] < 0 ? 'neg' : '' }}">{{ $symbol }} {{ number_format((float) $ps['opening_balance'], 2, '.', ',') }}</td>
                @endforeach
                <td class="num">{{ $symbol }} {{ number_format((float) ($totals['opening_balance'] ?? 0), 2, '.', ',') }}</td>
            </tr>

            <tr class="section">
                <td colspan="{{ count($periods) + 2 }}">ENTRADAS</td>
            </tr>
            @forelse($report['inflow_rows'] ?? [] as $row)
                <tr>
                    <td>{{ $row['category'] }}</td>
                    @foreach($periods as $p)
                        <td class="num">{{ number_format((float) ($row['values'][$p['key']] ?? 0), 2, '.', ',') }}</td>
                    @endforeach
                    <td class="num">{{ number_format((float) $row['total'], 2, '.', ',') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($periods) + 2 }}">Sin entradas en el período.</td>
                </tr>
            @endforelse
            <tr class="subtotal">
                <td>Total entradas</td>
                @foreach($periodSummary as $ps)
                    <td class="num">{{ $symbol }} {{ number_format((float) $ps['inflows'], 2, '.', ',') }}</td>
                @endforeach
                <td class="num">{{ $symbol }} {{ number_format((float) ($totals['inflows'] ?? 0), 2, '.', ',') }}</td>
            </tr>

            <tr class="section">
                <td colspan="{{ count($periods) + 2 }}">SALIDAS</td>
            </tr>
            @forelse($report['outflow_rows'] ?? [] as $row)
                <tr>
                    <td>{{ $row['category'] }}</td>
                    @foreach($periods as $p)
                        <td class="num">{{ number_format((float) ($row['values'][$p['key']] ?? 0), 2, '.', ',') }}</td>
                    @endforeach
                    <td class="num">{{ number_format((float) $row['total'], 2, '.', ',') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($periods) + 2 }}">Sin salidas en el período.</td>
                </tr>
            @endforelse
            <tr class="subtotal">
                <td>Total salidas</td>
                @foreach($periodSummary as $ps)
                    <td class="num">{{ $symbol }} {{ number_format((float) $ps['outflows'], 2, '.', ',') }}</td>
                @endforeach
                <td class="num">{{ $symbol }} {{ number_format((float) ($totals['outflows'] ?? 0), 2, '.', ',') }}</td>
            </tr>

            @if(!empty($report['transfer_rows']))
                <tr class="section">
                    <td colspan="{{ count($periods) + 2 }}">TRANSFERENCIAS INTERNAS (EXCLUIDAS DEL FLUJO OPERATIVO)</td>
                </tr>
                @foreach($report['transfer_rows'] as $row)
                    <tr>
                        <td>{{ $row['category'] }}</td>
                        @foreach($periods as $p)
                            <td class="num">{{ number_format((float) ($row['values'][$p['key']] ?? 0), 2, '.', ',') }}</td>
                        @endforeach
                        <td class="num">{{ number_format((float) $row['total'], 2, '.', ',') }}</td>
                    </tr>
                @endforeach
            @endif

            @if($hasNonZeroTransfersNet)
                <tr class="subtotal">
                    <td>Neto de transferencias (dif. cambio / otra moneda)</td>
                    @foreach($periodSummary as $ps)
                        <td class="num {{ (float) ($ps['transfers_net'] ?? 0) < 0 ? 'neg' : '' }}">{{ $symbol }} {{ number_format((float) ($ps['transfers_net'] ?? 0), 2, '.', ',') }}</td>
                    @endforeach
                    <td class="num {{ (float) ($totals['transfers_net'] ?? 0) < 0 ? 'neg' : '' }}">{{ $symbol }} {{ number_format((float) ($totals['transfers_net'] ?? 0), 2, '.', ',') }}</td>
                </tr>
            @endif

            <tr class="subtotal">
                <td>Flujo neto del período</td>
                @foreach($periodSummary as $ps)
                    <td class="num {{ (float) $ps['net'] < 0 ? 'neg' : '' }}">{{ $symbol }} {{ number_format((float) $ps['net'], 2, '.', ',') }}</td>
                @endforeach
                <td class="num {{ (float) ($totals['net'] ?? 0) < 0 ? 'neg' : '' }}">{{ $symbol }} {{ number_format((float) ($totals['net'] ?? 0), 2, '.', ',') }}</td>
            </tr>
            <tr class="total">
                <td>Saldo final</td>
                @foreach($periodSummary as $ps)
                    <td class="num {{ (float) $ps['closing_balance'] < 0 ? 'neg' : '' }}">{{ $symbol }} {{ number_format((float) $ps['closing_balance'], 2, '.', ',') }}</td>
                @endforeach
                <td class="num {{ (float) ($totals['closing_balance'] ?? 0) < 0 ? 'neg' : '' }}">{{ $symbol }} {{ number_format((float) ($totals['closing_balance'] ?? 0), 2, '.', ',') }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
