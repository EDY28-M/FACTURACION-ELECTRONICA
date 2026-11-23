<aside class="sidebar-product col-lg-3 padding-left-lg mobile-sidebar">
    <div class="sidebar-wrapper">
        @if($information->tag_shipping || $information->tag_dollar || $information->tag_support)
        <div class="widget widget-info">
            <ul>
                @if($information->tag_shipping)
                    <li>
                        <i class="icon-shipping"></i>
                        <h4>{{ $information->tag_shipping }}</h4>
                    </li>
                @endif
                @if($information->tag_dollar)
                <li>
                    <i class="icon-us-dollar"></i>
                    <h4>{{ $information->tag_dollar }}</h4>
                </li>
                @endif
                @if($information->tag_support)
                <li>
                    <i class="icon-online-support"></i>
                    <h4>{{ $information->tag_support }}</h4>
                </li>
                @endif
            </ul>
        </div><!-- End .widget -->
        @endif

        @if($records->isNotEmpty())
            <div class="widget widget-banners box-carousel">
                <div class="widget-banners-slider owl-carousel owl-theme">
                    @foreach($records as $data)
                        <figure class="product-image-container boxing">
                            <a href="/ecommerce/item/{{ $data->id }}" class="product-image">
                                <img src="{{ asset('storage/uploads/items/'.$data->image) }}" alt="{{ $data->description }}" class="image">
                            </a>
                            <a href="{{route('item_partial', ['id' => $data->id])}}" class="btn-quickview">Vista Rápida</a>
                            <span class="product-label label-hot">Novedad</span>
                            <span class="product-label">{{ $data->description }}</span>
                        </figure>
                    @endforeach
                </div><!-- End .banner-->
            </div>
        @endif

        @include('ecommerce::layouts.partials_ecommerce.widget_products')
    </div>
</aside><!-- End .col-md-3 -->
