{{-- QR of the SUNAT GRE 2.0 validation link. Rendered only once the guide has a QR. --}}
@if(!empty($document->qr))
<table class="full-width" style="margin-top: 10px;">
    <tr>
        <td style="text-align: right;">
            <img src="data:image/png;base64, {{ $document->qr }}" style="width: 100px; height: 100px;" />
        </td>
    </tr>
</table>
@endif
