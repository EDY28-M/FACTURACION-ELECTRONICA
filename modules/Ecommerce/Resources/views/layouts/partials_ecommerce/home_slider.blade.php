@if($items->isNotEmpty())
<section class="sf-hero sf-promo-slider">
    <div class="home-slider owl-carousel owl-theme">
        @foreach ($items as $item)
            <div class="home-slide">
                <div class="sf-container sf-promo-slide-inner">
                    <div class="sf-promo-copy">
                        <nav aria-label="Breadcrumb">
                            <ol class="sf-breadcrumb">
                                <li><a href="{{ route('tenant.ecommerce.index') }}">Inicio</a></li>
                                <li class="sf-breadcrumb-sep">&rsaquo;</li>
                                <li class="sf-breadcrumb-current">Catálogo</li>
                            </ol>
                        </nav>
                        <h1>{{ $item->name }}</h1>
                        <p>{{ $item->description }}</p>
                        @if($item->item_id)
                            <a href="/ecommerce/item/{{ $item->item_id }}" class="sf-promo-cta">
                                Ver producto <i class="fas fa-arrow-right"></i>
                            </a>
                        @endif
                    </div>
                    <div class="sf-promo-media">
                        <img src="{{ asset('storage/uploads/promotions/'.$item->image) }}" alt="{{ $item->name }}">
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
@else
<section class="sf-hero">
    <div class="sf-container sf-hero-inner">
        <div class="sf-hero-content">
            <nav aria-label="Breadcrumb">
                <ol class="sf-breadcrumb">
                    <li><a href="{{ route('tenant.ecommerce.index') }}">Inicio</a></li>
                    <li class="sf-breadcrumb-sep">&rsaquo;</li>
                    @if(!empty($activeCategoryName))
                        <li><a href="{{ route('tenant.ecommerce.index') }}">Catálogo</a></li>
                        <li class="sf-breadcrumb-sep">&rsaquo;</li>
                        <li class="sf-breadcrumb-current">{{ $activeCategoryName }}</li>
                    @else
                        <li class="sf-breadcrumb-current">Catálogo</li>
                    @endif
                </ol>
            </nav>

            <h1 class="sf-hero-title">
                {{ !empty($activeCategoryName) ? $activeCategoryName : 'Catálogo de productos' }}
            </h1>
            <p class="sf-hero-subtitle">
                {{ $storeName }}
                @if(!empty($information->information_contact_address))
                    &middot; {{ $information->information_contact_address }}
                @endif
                @if(!empty($totalCatalogCount))
                    &middot; {{ $totalCatalogCount }} {{ $totalCatalogCount === 1 ? 'producto disponible' : 'productos disponibles' }}
                @endif
            </p>
        </div>

        @if(isset($records) && $records->isNotEmpty())
            <div class="sf-hero-visual" aria-hidden="true">
                @foreach($records->take(2) as $preview)
                    @php
                        $previewImg = ($preview->image && $preview->image !== 'imagen-no-disponible.jpg')
                            ? asset('storage/uploads/items/'.$preview->image)
                            : asset('logo/imagen-no-disponible.jpg');
                    @endphp
                    <div class="sf-hero-preview-card">
                        <img src="{{ $previewImg }}" alt="{{ $preview->description }}">
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>
@endif
