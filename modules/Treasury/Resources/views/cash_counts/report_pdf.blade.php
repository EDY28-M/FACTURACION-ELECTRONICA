@php
    $diff = (float) ($record['difference'] ?? 0);
    $sign = $diff > 0 ? '+' : ($diff < 0 ? '-' : '');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Arqueo de caja #{{ $record['id'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1e293b; }
        h1 { font-size: 14px; text-align: center; margin: 0 0 10px 0; }
        .meta, .grid, .summary { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .meta td, .grid th, .grid td, .summary td { padding: 5px 7px; border: 1px solid #cbd5e1; }
        .grid thead th { background: #1e3a5f; color: #ffffff; text-align: center; }
        .num { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .status-balanced { color: #166534; font-weight: bold; }
        .status-surplus { color: #1d4ed8; font-weight: bold; }
        .status-shortage { color: #b91c1c; font-weight: bold; }
        .signatures { width: 100%; margin-top: 40px; border-collapse: collapse; }
        .signatures td { width: 50%; text-align: center; padding: 0 24px; }
        .sig-line { border-top: 1px solid #334155; padding-top: 5px; margin-top: 32px; }
    </style>
</head>
<body>
    <h1>ACTA DE ARQUEO DE CAJA N.º {{ str_pad((string) ($record['id'] ?? 0), 6, '0', STR_PAD_LEFT) }}</h1>

    <table class="meta">
        <tr>
            <td><strong>Empresa:</strong> {{ $company['name'] ?? '-' }}</td>
            <td><strong>RUC:</strong> {{ $company['ruc'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Caja:</strong> {{ $record['cash_description'] ?? '-' }}</td>
            <td><strong>Sede:</strong> {{ $record['establishment_description'] ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Fecha y hora del arqueo:</strong> {{ $record['counted_at'] ?? '-' }}</td>
            <td><strong>Responsable:</strong> {{ $record['user_name'] ?? '-' }}</td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th>Denominación</th>
                <th>Tipo</th>
                <th>Valor unitario (S/)</th>
                <th>Cantidad</th>
                <th>Subtotal (S/)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($record['denominations'] ?? [] as $item)
                <tr>
                    <td>{{ $item['label'] ?? ('S/ ' . number_format((float) ($item['value'] ?? 0), 2, '.', '')) }}</td>
                    <td class="center">{{ ($item['type'] ?? 'coin') === 'bill' ? 'Billete' : 'Moneda' }}</td>
                    <td class="num">{{ number_format((float) ($item['value'] ?? 0), 2, '.', ',') }}</td>
                    <td class="center">{{ (int) ($item['qty'] ?? 0) }}</td>
                    <td class="num">{{ number_format((float) ($item['subtotal'] ?? 0), 2, '.', ',') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="center">Sin denominaciones registradas.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td><strong>Saldo según sistema (S/)</strong></td>
            <td class="num">S/ {{ number_format((float) ($record['system_balance'] ?? 0), 2, '.', ',') }}</td>
        </tr>
        <tr>
            <td><strong>Saldo contado en efectivo (S/)</strong></td>
            <td class="num">S/ {{ number_format((float) ($record['counted_balance'] ?? 0), 2, '.', ',') }}</td>
        </tr>
        <tr>
            <td><strong>Diferencia (Contado − Sistema)</strong></td>
            <td class="num status-{{ $record['status'] ?? 'balanced' }}">
                {{ $sign }}S/ {{ number_format(abs($diff), 2, '.', ',') }} ({{ $record['status_label'] ?? 'Cuadrado' }})
            </td>
        </tr>
    </table>

    @if(!empty($record['notes']))
        <table class="meta">
            <tr>
                <td><strong>Observaciones:</strong> {{ $record['notes'] }}</td>
            </tr>
        </table>
    @endif

    <table class="signatures">
        <tr>
            <td>
                <div class="sig-line">Responsable de caja<br>{{ $record['user_name'] ?? '' }}</div>
            </td>
            <td>
                <div class="sig-line">Supervisor / Auditor<br>Nombre y firma</div>
            </td>
        </tr>
    </table>
</body>
</html>
