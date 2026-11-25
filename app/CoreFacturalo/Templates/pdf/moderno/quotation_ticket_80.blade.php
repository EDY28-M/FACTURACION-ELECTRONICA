{{-- Formato moderno: datos en App\Support\PdfDocumentData::quotation, diseño en partials/commercial_ticket. --}}
@include('pdf.moderno.partials.commercial_ticket', ['d' => \App\Support\PdfDocumentData::quotation($document) + ['paper' => 78]])
