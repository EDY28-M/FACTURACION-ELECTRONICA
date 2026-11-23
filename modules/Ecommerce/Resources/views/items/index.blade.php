@extends('ecommerce::layouts.layout_ecommerce_item.index')
@section('content')

@forelse ($records as $item)
@php($public = \App\Services\Ecommerce\StoreItemPresenter::present($item))
<div class="col-6 col-md-4">
    <div class="product">
        <figure class="product-image-container">
            <a href="/ecommerce/item/{{ $item->id }}" class="product-image">
                <img src="{{ asset('storage/uploads/items/'.$item->image) }}" alt="{{ $item->description }}">
            </a>
            <a href="{{route('item_partial', ['id' => $item->id])}}" class="btn-quickview">Vista Rápida</a>
        </figure>
        <div class="product-details">
            <h2 class="product-title">
                <a href="/ecommerce/item/{{ $item->id }}">{{ $item->name }}</a>
            </h2>
            <div class="price-box">
                <span class="product-price">{{ $public['currency_type_symbol'] }} {{ number_format($public['sale_unit_price'], 2) }}</span>
            </div><!-- End .price-box -->

            <div class="product-action">
                <a href="#" data-product="{{ json_encode( $public ) }}" class="paction add-cart" title="Agregar a Carrito">
                    <span>Agregar a Carrito</span>
                </a>
            </div><!-- End .product-action -->
        </div><!-- End .product-details -->
    </div><!-- End .product -->
</div><!-- End .col-md-4 -->
@empty
<div class="col-12">
    <div class="text-center py-5">
        <h3>Aún no hay productos publicados</h3>
        <p>Vuelve pronto para ver nuestro catálogo.</p>
    </div>
</div>
@endforelse

@endsection
