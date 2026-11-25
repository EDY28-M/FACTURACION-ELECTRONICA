@php
    $items = collect($items ?? [])->map(function ($item) {
        return [
            'name' => data_get($item, 'description') ?: data_get($item, 'name'),
            'qty' => data_get($item, 'cantidad') ?? data_get($item, 'quantity'),
        ];
    })->filter(function ($i) {
        return $i['name'];
    });
@endphp
@if($items->count())
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;border-top:1px solid #e4e4e7;">
        <tr>
            <td style="padding:8px 0;border-bottom:1px solid #e4e4e7;font-size:12px;color:#71717a;">Producto</td>
            <td align="right" style="padding:8px 0;border-bottom:1px solid #e4e4e7;font-size:12px;color:#71717a;">Cant.</td>
        </tr>
        @foreach($items as $item)
            <tr>
                <td style="padding:8px 0;border-bottom:1px solid #e4e4e7;font-size:14px;color:#18181b;">{{ $item['name'] }}</td>
                <td align="right" style="padding:8px 0;border-bottom:1px solid #e4e4e7;font-size:14px;color:#18181b;">{{ $item['qty'] }}</td>
            </tr>
        @endforeach
    </table>
@endif
