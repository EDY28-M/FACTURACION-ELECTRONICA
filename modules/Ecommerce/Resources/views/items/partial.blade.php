<div class="product-single-container product-single-default product-quick-view container">
    <div class="row">
        <div class="col-lg-6 col-md-6 product-single-gallery">
            <div class="product-slider-container product-item">
                <div class="product-single-carousel owl-carousel owl-theme">
                    <div class="product-item">
                        <img class="product-single-image" src="{{ asset('storage/uploads/items/'.$record->image) }}"
                            data-zoom-image="{{ asset('storage/uploads/items/'.$record->image) }}" />
                    </div>

                    @foreach($record->images as $row)

                    <div class="product-item">
                        <img class="product-single-image"
                            src="{{ asset('storage/uploads/items/'.$row->image) }}"
                            data-zoom-image="{{ asset('storage/uploads/items/'.$row->image) }}" />
                    </div>

                    @endforeach

                    <!--<div class="product-item">
                        <img class="product-single-image"
                            src="{{ asset('storage/uploads/items/'.$record->image_medium) }}"
                            data-zoom-image="{{ asset('storage/uploads/items/'.$record->image_medium) }}" />
                    </div> -->

                </div>

            </div>
            <div class="prod-thumbnail row owl-dots" id='carousel-custom-dots'>
                <div class="col-3 owl-dot">
                    <img src="{{ asset('storage/uploads/items/'.$record->image) }}" />
                </div>

                @foreach($record->images as $row)

                    <div class="col-3 owl-dot">
                        <img src="{{ asset('storage/uploads/items/'.$row->image) }}" />
                    </div>

                @endforeach

                <!--<div class="col-3 owl-dot">
                    <img src="{{ asset('porto_ecommerce/ajax/assets/images/products/zoom/product-2.html') }}" />
                </div>
                <div class="col-3 owl-dot">
                    <img src="{{ asset('porto_ecommerce/ajax/assets/images/products/zoom/product-3.html') }}" />
                </div>
                <div class="col-3 owl-dot">
                    <img src="{{ asset('porto_ecommerce/ajax/assets/images/products/zoom/product-4.html') }}" />
                </div>-->
            </div>
        </div><!-- End .col-lg-7 -->

        <div class="col-lg-6 col-md-6">
            <div class="product-single-details">
                <h1 class="product-title">{{$record->description}}</h1>

                @if(!empty($rating['count']))
                <div class="ratings-container">
                    <div class="product-ratings" title="Valoración promedio: {{ number_format($rating['average'], 1) }} de 5">
                        <span class="ratings" style="width:{{ round($rating['average'] / 5 * 100) }}%"></span>
                    </div>
                    <span class="rating-link">({{ $rating['count'] }} {{ $rating['count'] == 1 ? 'valoración' : 'valoraciones' }})</span>
                </div>
                @endif

                <div class="price-box">
                    <span class="product-price">{{ $public['currency_type_symbol'] }} {{ number_format($public['sale_unit_price'], 2) }}</span>
                </div><!-- End .price-box -->

                <div class="product-desc">
                    <p>{{ $record->name }}</p>
                </div><!-- End .product-desc -->



                <div class="product-action">
                    <!-- div class="product-single-qty">
                        <input class="horizontal-quantity form-control" type="text">
                    </div><!-- End .product-single-qty -->

                    <a href="#" data-product="{{ json_encode( $public ) }}" class="paction add-cart"
                        title="Add to Cart">
                        <span>Agregar a Carrito</span>
                    </a>

                </div><!-- End .product-action -->

            </div><!-- End .product-single-details -->
        </div><!-- End .col-lg-5 -->
    </div><!-- End .row -->
</div><!-- End .product-single-container -->

<script type="text/javascript">
    // The product JSON travels in an HTML-escaped data attribute and is read back as a string
    // (cart_add parses it as data); it is never placed inside inline JavaScript.
    jQuery('.product-quick-view .add-cart').off('click.cartAdd').on('click.cartAdd', function (event) {
        event.preventDefault();
        cart_add(jQuery(this).attr('data-product'));
    });
</script>
