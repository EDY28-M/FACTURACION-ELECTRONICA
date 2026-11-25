@extends('ecommerce::layouts.layout_ecommerce_item.record')

@section('content')
@php
    $mainImg = ($record->image && $record->image !== 'imagen-no-disponible.jpg')
        ? asset('storage/uploads/items/'.$record->image)
        : asset('logo/imagen-no-disponible.jpg');
    $waText = 'Hola '.($storeName ?? '').', quisiera consultar disponibilidad y precio de: '.$record->description.(!empty($record->internal_id) ? ' (Código: '.$record->internal_id.')' : '');
@endphp

<nav aria-label="Breadcrumb">
    <ol class="sf-breadcrumb">
        <li><a href="{{ route('tenant.ecommerce.index') }}">Inicio</a></li>
        <li class="sf-breadcrumb-sep">&rsaquo;</li>
        <li><a href="{{ route('tenant.ecommerce.index') }}#catalogo">Catálogo</a></li>
        @if(!empty($record->category_name))
            <li class="sf-breadcrumb-sep">&rsaquo;</li>
            <li><a href="{{ route('tenant.ecommerce.index', ['cat' => $record->category_id]) }}">{{ $record->category_name }}</a></li>
        @endif
        <li class="sf-breadcrumb-sep">&rsaquo;</li>
        <li class="sf-breadcrumb-current">{{ $record->description }}</li>
    </ol>
</nav>

<div class="product-single-container product-single-default">
    <div class="row align-items-start">
        <div class="col-lg-6 col-md-6 product-single-gallery">
            <div class="sf-detail-gallery">
                <div class="product-slider-container product-item mb-0">
                    <div class="product-single-carousel owl-carousel owl-theme">
                        <div class="product-item">
                            <img class="product-single-image" src="{{ $mainImg }}" data-zoom-image="{{ $mainImg }}" alt="{{ $record->description }}" />
                        </div>
                        @foreach($record->images as $row)
                            <div class="product-item">
                                <img class="product-single-image" src="{{ asset('storage/uploads/items/'.$row->image) }}"
                                    data-zoom-image="{{ asset('storage/uploads/items/'.$row->image) }}" alt="{{ $record->description }}" />
                            </div>
                        @endforeach
                    </div>
                    <span class="prod-full-screen">
                        <i class="icon-plus"></i>
                    </span>
                </div>
            </div>

            @if(count($record->images) > 0)
                <div class="prod-thumbnail row owl-dots mt-3" id="carousel-custom-dots">
                    <div class="col-3 owl-dot">
                        <img src="{{ $mainImg }}" alt="{{ $record->description }}" />
                    </div>
                    @foreach($record->images as $row)
                        <div class="col-3 owl-dot">
                            <img src="{{ asset('storage/uploads/items/'.$row->image) }}" alt="{{ $record->description }}" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="col-lg-6 col-md-6">
            <div class="product-single-details sf-detail-info">
                <div class="sf-detail-chips">
                    @if(!empty($record->internal_id))
                        <span class="sf-chip sf-chip--sku">SKU: {{ $record->internal_id }}</span>
                    @endif
                    @if(!empty($record->category_name))
                        <span class="sf-chip">{{ $record->category_name }}</span>
                    @endif
                    @if(!empty($record->brand_name))
                        <span class="sf-chip">{{ $record->brand_name }}</span>
                    @endif
                    @if(!empty($record->out_of_stock))
                        <span class="sf-chip" style="background:#fee2e2;color:#b91c1c;">Agotado</span>
                    @endif
                </div>

                <h1 class="product-title sf-detail-title">{{ $record->description }}</h1>

                @if(!empty($rating['count']))
                    <div class="ratings-container mb-3">
                        <div class="product-ratings" title="Valoración promedio: {{ number_format($rating['average'], 1) }} de 5">
                            <span class="ratings" style="width:{{ round($rating['average'] / 5 * 100) }}%"></span>
                        </div>
                        <span class="rating-link">({{ $rating['count'] }} {{ $rating['count'] == 1 ? 'valoración' : 'valoraciones' }})</span>
                    </div>
                @endif

                <div class="sf-detail-price">
                    @if((float) ($record->sale_unit_price ?? 0) > 0)
                        {{ $record->price_label }}
                    @else
                        <span style="font-size:20px;color:#64748b;font-weight:600;">Consultar precio</span>
                    @endif
                </div>

                @if(!empty($record->name) && $record->name !== $record->description)
                    <div class="product-desc mb-3">
                        <p style="font-size: 14.5px; color: #475569;">{{ $record->name }}</p>
                    </div>
                @endif

                @if(!empty($record->attributes))
                    <div class="mb-3" style="font-size: 13.5px; color: #475569;">
                        @foreach($record->attributes as $at)
                            <div><strong>{{ $at->description }}:</strong> {{ $at->value }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="sf-detail-actions">
                    @if(empty($record->out_of_stock))
                        <a href="#" class="paction add-cart sf-detail-add-btn" data-product="{{ json_encode($record->cart) }}" title="Agregar al carrito">
                            <i class="fas fa-shopping-bag"></i>
                            <span>Agregar al carrito</span>
                        </a>
                    @endif

                    @if(!empty($whatsappUrl))
                        <a href="{{ $whatsappUrl }}?text={{ rawurlencode($waText) }}"
                           class="sf-detail-wa-btn"
                           target="_blank"
                           rel="noopener noreferrer">
                            <i class="fab fa-whatsapp"></i>
                            <span>Consultar por WhatsApp</span>
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<div class="product-single-tabs sf-detail-tabs">
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="product-tab-desc" data-toggle="tab" href="#product-desc-content" role="tab"
                aria-controls="product-desc-content" aria-selected="true">Descripción</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="product-tab-especTecn" data-toggle="tab" href="#product-especTecn-content" role="tab"
                aria-controls="product-especTecn-content" aria-selected="false">Especificaciones Técnicas</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" onclick="getRating('{{ $record->id }}')" id="product-tab-reviews" data-toggle="tab" href="#product-reviews-content" role="tab"
                aria-controls="product-reviews-content" aria-selected="false">Valoraciones</a>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade show active" id="product-desc-content" role="tabpanel" aria-labelledby="product-tab-desc">
            <div class="product-desc-content">
                <p>{{ $record->description }}</p>
                @if(!empty($record->name) && $record->name !== $record->description)
                    <p>{{ $record->name }}</p>
                @endif
            </div>
        </div>

        <div class="tab-pane fade" id="product-especTecn-content" role="tabpanel" aria-labelledby="product-tab-especTecn">
            <div class="product-especTecn-content">
                <p>{{ $record->technical_specifications ?: 'Sin especificaciones técnicas adicionales.' }}</p>
            </div>
        </div>

        <div class="tab-pane fade" id="product-reviews-content" role="tabpanel" aria-labelledby="product-tab-reviews">
            <div class="product-reviews-content">
                <div class="collateral-box">
                    <div class="page">
                        <div class="page__demo">
                            <div class="page__group">
                                <div class="rating">
                                    <input type="radio" name="rating-star2" class="rating__control" id="rc6" onclick="sendRating(1,{{ $record->id }})">
                                    <input type="radio" name="rating-star2" class="rating__control" id="rc7" onclick="sendRating(2,{{ $record->id }})">
                                    <input type="radio" name="rating-star2" class="rating__control" id="rc8" onclick="sendRating(3,{{ $record->id }})">
                                    <input type="radio" name="rating-star2" class="rating__control" id="rc9" onclick="sendRating(4,{{ $record->id }})">
                                    <input type="radio" name="rating-star2" class="rating__control" id="rc10" onclick="sendRating(5,{{ $record->id }})">
                                    <label for="rc6" class="rating__item"><svg class="rating__star"><use xlink:href="#star"></use></svg><span class="rating__label">1</span></label>
                                    <label for="rc7" class="rating__item"><svg class="rating__star"><use xlink:href="#star"></use></svg><span class="rating__label">2</span></label>
                                    <label for="rc8" class="rating__item"><svg class="rating__star"><use xlink:href="#star"></use></svg><span class="rating__label">3</span></label>
                                    <label for="rc9" class="rating__item"><svg class="rating__star"><use xlink:href="#star"></use></svg><span class="rating__label">4</span></label>
                                    <label for="rc10" class="rating__item"><svg class="rating__star"><use xlink:href="#star"></use></svg><span class="rating__label">5</span></label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <svg xmlns="http://www.w3.org/2000/svg" style="display: none">
                        <symbol id="star" viewBox="0 0 26 28">
                            <path d="M26 10.109c0 .281-.203.547-.406.75l-5.672 5.531 1.344 7.812c.016.109.016.203.016.313 0 .406-.187.781-.641.781a1.27 1.27 0 0 1-.625-.187L13 21.422l-7.016 3.687c-.203.109-.406.187-.625.187-.453 0-.656-.375-.656-.781 0-.109.016-.203.031-.313l1.344-7.812L.39 10.859c-.187-.203-.391-.469-.391-.75 0-.469.484-.656.875-.719l7.844-1.141 3.516-7.109c.141-.297.406-.641.766-.641s.625.344.766.641l3.516 7.109 7.844 1.141c.375.063.875.25.875.719z" />
                        </symbol>
                    </svg>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
