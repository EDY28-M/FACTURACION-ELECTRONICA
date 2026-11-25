<?php
    // Guarded: a global function declared in a view would be fatal if the view is rendered twice.
    if (! function_exists('stock')) {
        function stock($item, $config)
        {
            if ($config) {
                $stock = 0;
                foreach ($item->warehouses as $value) {
                    $stock += $value->stock;
                }
                return ($stock > 0) ? false : true;
            }
            return false;
        }
    }
?>
@forelse ($dataPaginate as $item)
@php
    $public = \App\Services\Ecommerce\StoreItemPresenter::present($item);
    $isOutOfStock = stock($item, $configuration);
    $imgSrc = ($item->image && $item->image !== 'imagen-no-disponible.jpg')
        ? asset('storage/uploads/items/'.$item->image)
        : asset('logo/imagen-no-disponible.jpg');
    $brandLabel = optional($item->brand)->name ?: optional($item->category)->name;
    $waText = 'Hola, deseo consultar por: '.$item->description.($item->internal_id ? ' (Código: '.$item->internal_id.')' : '');
@endphp

    <article class="product sf-card {{ $isOutOfStock ? 'productdisabled' : '' }}">
        <figure class="sf-card-media">
            @if($brandLabel)
                <span class="sf-card-tag">{{ $brandLabel }}</span>
            @else
                <span class="sf-card-tag">Imagen referencial</span>
            @endif

            @if($isOutOfStock)
                <span class="sf-card-badge sf-card-badge--out">Agotado</span>
            @elseif((int) ($item->is_new ?? 0) === 1)
                <span class="sf-card-badge sf-card-badge--new">Nuevo</span>
            @endif

            <a href="/ecommerce/item/{{ $item->id }}" class="sf-card-media-link">
                <img src="{{ $imgSrc }}" alt="{{ $item->description }}" loading="lazy">
            </a>

            <div class="sf-card-hover-bar">
                <a href="{{ route('item_partial', ['id' => $item->id]) }}" class="btn-quickview sf-card-quickview">
                    <i class="far fa-eye"></i>
                    <span>Vista rápida</span>
                </a>
            </div>
        </figure>

        <div class="sf-card-body">
            <h3 class="sf-card-title">
                <a href="/ecommerce/item/{{ $item->id }}">{{ $item->description }}</a>
            </h3>

            <div class="sf-card-meta">
                @if(!empty($item->internal_id))
                    <span class="sf-card-sku">{{ $item->internal_id }}</span>
                @endif

                @if((float) $public['sale_unit_price'] > 0)
                    <span class="sf-card-price">{{ $public['currency_type_symbol'] }} {{ number_format($public['sale_unit_price'], 2) }}</span>
                @else
                    <span class="sf-card-price--consult">Consultar precio</span>
                @endif
            </div>

            <div class="sf-card-actions">
                <a href="#"
                   class="paction add-cart sf-card-add"
                   data-product="{{ json_encode($public) }}"
                   title="Agregar al carrito">
                    <i class="fas fa-shopping-bag"></i>
                    <span>Agregar</span>
                </a>

                @if(!empty($whatsappUrl))
                    <a href="{{ $whatsappUrl }}?text={{ rawurlencode($waText) }}"
                       class="sf-card-wa"
                       target="_blank"
                       rel="noopener noreferrer"
                       title="Consultar por WhatsApp"
                       aria-label="Consultar por WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                @endif
            </div>
        </div>
    </article>

@empty
    <div class="sf-empty">
        <div class="sf-empty-icon"><i class="fas fa-search"></i></div>
        @if(!empty($activeSearch))
            <h3 class="sf-empty-title">Sin resultados</h3>
            <p class="sf-empty-text">No encontramos productos para “{{ $activeSearch }}”.</p>
            <a href="{{ route('tenant.ecommerce.index') }}" class="sf-btn-primary">Ver todo el catálogo</a>
        @elseif(!empty($activeCategoryId) || !empty($activeTagId) || !empty($activeBrands))
            <h3 class="sf-empty-title">No encontramos productos con esos filtros</h3>
            <p class="sf-empty-text">Prueba quitando alguna marca o seleccionando todas las categorías.</p>
            <a href="{{ route('tenant.ecommerce.index') }}" class="sf-btn-primary">Limpiar filtros</a>
        @else
            <h3 class="sf-empty-title">Aún no hay productos publicados</h3>
            <p class="sf-empty-text">Vuelve pronto para explorar nuestro catálogo.</p>
        @endif
    </div>
@endforelse
