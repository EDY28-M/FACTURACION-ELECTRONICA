@php
    $rows = array_filter($rows ?? [], function ($v) {
        return $v !== null && $v !== '';
    });
@endphp
@if(count($rows))
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;border-top:1px solid #e4e4e7;">
        @foreach($rows as $label => $value)
            <tr>
                <td style="padding:9px 0;border-bottom:1px solid #e4e4e7;font-size:13px;color:#71717a;">{{ $label }}</td>
                <td align="right" style="padding:9px 0;border-bottom:1px solid #e4e4e7;font-size:14px;font-weight:600;color:#18181b;">{{ $value }}</td>
            </tr>
        @endforeach
    </table>
@endif
