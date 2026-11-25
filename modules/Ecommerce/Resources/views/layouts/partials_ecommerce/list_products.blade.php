<?php

    // Guarded: a global function declared in a view would be fatal if the view is rendered twice.
    if (! function_exists('stock')) {
        function stock($item, $config)
        {
            if($config) {
                $stock=0;
                foreach ($item->warehouses as $key => $value) {
                    $stock += $value->stock;
                }
                return ($stock > 0) ? false : true;
            }
        }
    }
?>
@forelse ($dataPaginate as $item)
@php($public = \App\Services\Ecommerce\StoreItemPresenter::present($item))

    <div class="col-6 col-md-4">
        <div class="product product-style {{ stock($item, $configuration) ? 'productdisabled' : '' }}">
            <figure class="product-image-container">
                <a href="/ecommerce/item/{{ $item->id }}" class="product-image product-image-list">
                    <img src="{{ asset('storage/uploads/items/'.$item->image) }}" class="image" alt="product">
                </a>
                <a href="{{route('item_partial', ['id' => $item->id])}}" class="btn-quickview">Vista Rápida</a>
                {{-- <span class="product-label label-sale">-20%</span> --}}
                @if(json_encode($item->is_new) == 1)
                    <span class="product-label label-hot">New</span>
                @endif
                @if(stock($item, $configuration))
                    <span class="product-label product-danger">AGOTADO</span>
                @endif
            </figure>
            <div class="product-details">
                {{ $item->internal_id }}
                <h2 class="product-title">
                    <a href="/ecommerce/item/{{ $item->id }}">{{ $item->description }}</a>
                </h2>
                <div class="price-box">
                    <!-- <span class="old-price">S/ {{ number_format( ($item->sale_unit_price * 1.2 ) , 2 )}}</span> -->
                    <span class="product-price">{{ $public['currency_type_symbol'] }} {{ number_format($public['sale_unit_price'], 2) }}</span>
                </div>
                <div class="product-action">
                    <a href="#" class="paction add-cart" data-product="{{ json_encode( $public ) }}" title="Add to Cart">
                        <span>Agregar a Carrito</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

@empty
    <div class="col-12">
        <div class="store-empty text-center">
            @if(request()->filled('search'))
                <h3 class="store-empty-title">Sin resultados</h3>
                <p class="store-empty-text">No encontramos productos para &ldquo;{{ request('search') }}&rdquo;.</p>
                <a href="{{ route('tenant.ecommerce.index') }}" class="btn btn-dark">Ver todo el catálogo</a>
            @elseif(request()->segment(2) === 'category')
                <h3 class="store-empty-title">No encontramos productos</h3>
                <p class="store-empty-text">Esta categoría aún no tiene productos disponibles.</p>
                <a href="{{ route('tenant.ecommerce.index') }}" class="btn btn-dark">Ver todo el catálogo</a>
            @else
                <h3 class="store-empty-title">Aún no hay productos publicados</h3>
                <p class="store-empty-text">Vuelve pronto para ver nuestro catálogo.</p>
            @endif
        </div>
    </div>
@endforelse



<style>
    .store-empty {
        padding: 6rem 1.5rem;
        border: 1px dashed #ddd;
        background-color: #fafafa;
    }
    .store-empty-title {
        margin-bottom: 1rem;
        font-size: 2rem;
        font-weight: 600;
        color: #222529;
    }
    .store-empty-text {
        margin-bottom: 1.5rem;
        font-size: 1.4rem;
        color: #777;
    }
    .store-empty .store-empty-text:last-child {
        margin-bottom: 0;
    }
    /* .product-style {
        border-style: solid;
        border-width: 1px;
        border-color: "#ddd";
        margin: 10px 1px;
    } */
    .product-image-list {
        max-height: 210px;
        min-height: 210px;
    }
    .image {
        max-height: 210px;
    }
    .product-danger {
        float: right;
        color: #fff;
        background-color: #dc3545;
        border-color: #dc3545;
    }
    .productdisabled
    {
        pointer-events: none;
        /* opacity: 0.7; */
    }
</style>
