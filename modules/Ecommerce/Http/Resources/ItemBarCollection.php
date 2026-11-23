<?php

namespace Modules\Ecommerce\Http\Resources;

use App\Services\Ecommerce\StoreItemPresenter;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Public autocomplete payload for the storefront search bar. It is served without authentication,
 * so it exposes buyer-facing fields only (see StoreItemPresenter).
 */
class ItemBarCollection extends ResourceCollection
{
    public function toArray($request)
    {
        return $this->collection->map(function ($row) {
            return StoreItemPresenter::presentForSearch($row);
        });
    }
}
