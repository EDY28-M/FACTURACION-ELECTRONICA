{{-- Formato moderno: datos en App\Support\PdfDocumentData::orderNote, diseño en partials/commercial_ticket. --}}
@include('pdf.moderno.partials.commercial_ticket', ['d' => \App\Support\PdfDocumentData::orderNote($document) + ['paper' => 78]])
