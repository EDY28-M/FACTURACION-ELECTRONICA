<?php

namespace Modules\Ecommerce\Http\ViewComposers;

use App\Models\Tenant\Catalogs\Tag;
use Illuminate\Support\Facades\DB;
//use App\Http\Resources\Tenant\ItemEcommerceCollection;


class MenuViewComposer
{
    public function compose($view)
    {
        // The menu lists only categories that have at least one published product.
        $tagIds = DB::connection('tenant')->table('item_tags as it')
            ->join('items as i', 'i.id', '=', 'it.item_id')
            ->where('i.apply_store', 1)
            ->where('i.active', 1)
            ->whereNotNull('i.internal_id')
            ->distinct()
            ->pluck('it.tag_id');

        $view->items = Tag::whereIn('id', $tagIds)->get();
    }
}
