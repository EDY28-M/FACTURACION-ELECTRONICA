<?php

namespace Modules\Ecommerce\Http\ViewComposers;


use App\Models\Tenant\Item;
//use App\Http\Resources\Tenant\ItemEcommerceCollection;
use App\Services\Ecommerce\StoreItemPresenter;
use Carbon\Carbon;


class FeaturedProductsViewComposer
{
    public function compose($view)
    {
        $view->items = Item::with(['currency_type', 'tags'])
            ->where([['apply_store', 1], ['active', 1], ['internal_id', '!=', null]])
            ->get()
            ->transform(function ($row) {
                $months = Carbon::now()->diffInMonths($row->created_at);
                $public = StoreItemPresenter::present($row);

                // Buyer-facing fields only; `cart` is what the add-to-cart button serializes.
                return (object) array_merge($public, [
                    'cart' => $public,
                    'tags' => $row->tags->pluck('tag_id')->toArray(),
                    'is_new' => ($months > 1) ? 0 : 1,
                ]);
            });
    }
}
