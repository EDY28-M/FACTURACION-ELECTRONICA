{{-- Formato moderno: datos en App\Support\PdfDocumentData::saleNote, diseño en partials/commercial_ticket. --}}
@include('pdf.moderno.partials.commercial_ticket', ['d' => \App\Support\PdfDocumentData::saleNote($document) + ['paper' => 56]])
