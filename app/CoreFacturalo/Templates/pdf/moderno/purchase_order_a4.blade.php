{{-- Formato moderno: datos en App\Support\PdfDocumentData::purchaseOrder, diseño en partials/commercial_a4. --}}
@include('pdf.moderno.partials.commercial_a4', ['d' => \App\Support\PdfDocumentData::purchaseOrder($document)])
