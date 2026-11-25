{{-- Formato moderno: datos en App\Support\PdfDocumentData::orderNote, diseño en partials/commercial_a4. --}}
@include('pdf.moderno.partials.commercial_a4', ['d' => \App\Support\PdfDocumentData::orderNote($document) + ['compact' => true]])
