<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Preview of a bank statement file with a column mapping (nothing is persisted).
 */
class PreviewStatementRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $maxKb = (int) config('treasury.reconciliation.max_file_kb', 5120);

        return [
            'file' => ['required', 'file', 'max:' . $maxKb, 'mimes:csv,txt,xlsx,xls'],
            'mapping' => ['required', 'string', 'max:4000'],
        ];
    }

    public function messages()
    {
        return [
            'file.required' => 'Seleccione el archivo del extracto bancario.',
            'file.file' => 'El archivo del extracto no es válido.',
            'file.max' => 'El archivo del extracto supera el tamaño máximo permitido.',
            'file.mimes' => 'El extracto debe ser un archivo CSV, TXT, XLS o XLSX.',
            'mapping.required' => 'Indique el mapeo de columnas del extracto.',
            'mapping.max' => 'El mapeo de columnas es demasiado largo.',
        ];
    }
}
