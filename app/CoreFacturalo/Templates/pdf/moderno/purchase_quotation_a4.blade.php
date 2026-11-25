{{-- Formato moderno: datos en App\Support\PdfDocumentData::purchaseQuotation, diseño en partials/commercial_a4. --}}
@include('pdf.moderno.partials.commercial_a4', ['d' => \App\Support\PdfDocumentData::purchaseQuotation($document)])
