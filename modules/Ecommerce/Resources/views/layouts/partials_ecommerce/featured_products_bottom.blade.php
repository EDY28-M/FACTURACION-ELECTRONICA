@php
    $path = explode('/', request()->path());
    $item_id = (isset($path[2])) ? $path[2] : 0;

    // Related = other published items sharing at least one tag with the current item.
    $current_item = \App\Models\Tenant\Item::with('tags')->find($item_id);
    $current_tags = $current_item ? $current_item->tags->pluck('tag_id')->toArray() : [];
    $related_items = $items->filter(function ($related) use ($item_id, $current_tags) {
        return (string) $related->id !== (string) $item_id
            && count(array_intersect($related->tags, $current_tags)) > 0;
    });
@endphp
@if($related_items->isNotEmpty())
<div class="container">
    <h2 class="carousel-title">Productos Relacionados</h2>

    <div class="featured-products owl-carousel owl-theme owl-dots-top">

        @foreach ($related_items as $item)
            <div class="product">
                <figure class="product-image-container">
                    <a href="/ecommerce/item/{{ $item->id }}" class="product-image">
                        <img src="{{ asset('storage/uploads/items/'.$item->image) }}" alt="{{ $item->description }}">
                    </a>
                    <a href="{{route('item_partial', ['id' => $item->id])}}" class="btn-quickview">Vista Rápida</a>
                </figure>
                <div class="product-details">
                    <h2 class="product-title">
                        <a href="/ecommerce/item/{{ $item->id }}">{{$item->description}}</a>
                    </h2>
                    <div class="price-box">
                        <span class="product-price">{{ $item->currency_type_symbol }} {{ number_format($item->sale_unit_price, 2) }}</span>
                    </div><!-- End .price-box -->

                    <div class="product-action">
                        <a href="#" class="paction add-cart" data-product="{{ json_encode( $item->cart ) }}" title="Add to Cart">
                            <span>Agregar a Carrito</span>
                        </a>
                    </div><!-- End .product-action -->
                </div><!-- End .product-details -->
            </div>
        @endforeach

    </div>
</div>
@endif
