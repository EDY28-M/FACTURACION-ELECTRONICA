<?php

namespace Modules\Dashboard\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class DashboardStockCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     * Supports both lightweight SQL view rows (v_low_stock_products) and Eloquent ItemWarehouse models.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function toArray($request)
    {
        return $this->collection->transform(function ($row) {
            $product = isset($row->product) ? $row->product : $row->item->description;
            $warehouse = (isset($row->warehouse) && is_string($row->warehouse))
                ? $row->warehouse
                : $row->warehouse->description;

            return [
                'id' => $row->id,
                'product' => $product,
                'stock' => number_format($row->stock, 2, '.', ''),
                'warehouse' => $warehouse,
                'state' => ($row->stock <= 0) ? '01' : (($row->stock > 0 && $row->stock <= 20) ? '02' : '03'),
            ];
        });
    }
}
