{{-- Formato moderno: datos en App\Support\PdfDocumentData::saleNote, diseño en partials/commercial_a4. --}}
@include('pdf.moderno.partials.commercial_a4', ['d' => \App\Support\PdfDocumentData::saleNote($document) + ['compact' => true]])
