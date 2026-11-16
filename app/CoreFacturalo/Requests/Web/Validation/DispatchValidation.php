<?php

namespace App\CoreFacturalo\Requests\Web\Validation;

use App\Models\Tenant\Item;
use Exception;

class DispatchValidation
{

    public static function validation($inputs)
    {
        $series = Functions::findSeries($inputs);
        if (!$series) throw new Exception("La serie no fue encontrada.");
        if (isset($inputs['document_type_id']) && $series->document_type_id !== $inputs['document_type_id']) {
            throw new Exception("La serie {$series->number} no corresponde al tipo de guía seleccionado.");
        }
        if (isset($inputs['establishment_id']) && (int) $series->establishment_id !== (int) $inputs['establishment_id']) {
            throw new Exception("La serie {$series->number} no pertenece al establecimiento seleccionado.");
        }
        $inputs['series'] = $series->number;
        unset($inputs['series_id']);

        return $inputs;
    }
    
    // public static function validation($inputs) {
    //     $inputs['establishment_id'] ?? Functions::establishment($inputs['establishment']);
    //     unset($inputs['establishment']);
        
    //     Functions::validateSeries($inputs);
        
    //     $inputs['customer_id'] ?? (Functions::person($inputs['customer'], 'customer'));
    //     unset($inputs['customer']);
        
    //     $inputs['items'] = self::items($inputs['items']);
        
    //     return $inputs;
    // }
    
    // private static function items($inputs) {
    //     $items = [];
        
    //     foreach ($inputs as $row) {

    //         // $item = Item::where('internal_id', $row['internal_id'])->first();
            
    //         // if (!$item) throw new Exception("El código interno {$row['internal_id']} no fue encontrado.");
            
    //         $items[] = [
    //             'item_id' => $row['id'],
    //             'quantity' => $row['quantity']
    //         ];
    //     }
        
    //     return $items;
    // }
}
