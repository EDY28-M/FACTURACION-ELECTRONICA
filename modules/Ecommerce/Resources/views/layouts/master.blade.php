<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <title>{{ $storeName ?? 'Tienda virtual' }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ ($storeName ?? 'Tienda virtual').' - tienda virtual' }}">

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('porto-ecommerce/assets/images/icons/favicon.ico') }}">

    <!-- Plugins CSS File -->
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/bootstrap.min.css') }}">

    <!-- Main CSS File -->
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/style.min.css') }}">
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/custom.css') }}">

    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/rating.css') }}">

    <!-- Fontawesome -->
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/font-awesome/css/fontawesome-all.min.css') }}">


    @if (file_exists(public_path('theme/custom_styles_ecommerce.css')))
        <link rel="stylesheet" href="{{ asset('theme/custom_styles_ecommerce.css') }}" />
    @endif
</head>
<style>


</style>
<body>
    <div class="page-wrapper">

        @include('ecommerce::layouts.partials_ecommerce.header')
        <main class="main">
            <br> <!-- layout-  info_boxez-->

            <div class="container">
                <div class="row">
                    <div class="col-lg-9">

                    @php
                        $tagid = Request::segment(3);
                    @endphp

                        @if(!$tagid)
                            @include('ecommerce::layouts.partials_ecommerce.home_slider')
                        @endif

                        <div class="mb-3"></div><!-- margin -->

                        <div class="row row-sm">
                            @include('ecommerce::layouts.partials_ecommerce.list_products')
                        </div>

                        @if($dataPaginate->hasPages())
                            <div class="row float-right">
                              <div class="col-md-12 col-lg-12">
                                {{ $dataPaginate->links() }}
                              </div>
                            </div>
                        @endif

                        <div class="mb-6"></div><!-- margin -->

                    </div><!-- End .col-lg-9 -->

                    <aside class="sidebar-home col-lg-3 order-lg-first">
                        <div class="side-menu-container">
                            <h2>CATEGORIAS</h2>
                            @include('ecommerce::layouts.partials_ecommerce.sidemenu')
                        </div><!-- End .side-menu-container -->

                        @if($records->isNotEmpty())
                            <div class="box-carousel">
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
                                </div><!-- End .banner-slider -->
                            </div>
                        @endif
                    </aside><!-- End .col-lg-3 -->
                </div><!-- End .row -->
            </div><!-- End .container -->

            <div class="mb-4"></div><!-- margin -->
        </main><!-- End .main -->

        <footer class="footer">
            @include('ecommerce::layouts.partials_ecommerce.footer')
        </footer><!-- End .footer -->
    </div><!-- End .page-wrapper -->

    <div class="mobile-menu-overlay"></div><!-- End .mobil-menu-overlay -->

    <div class="mobile-menu-container">

        @include('ecommerce::layouts.partials_ecommerce.mobile_menu')

    </div><!-- End .mobile-menu-container -->

    <a id="scroll-top" href="#top" title="Top" role="button"><i class="icon-angle-up"></i></a>

    <!-- Plugins JS File -->
    <script src="{{ asset('porto-ecommerce/assets/js/jquery.min.js') }}"></script>
    <script src="{{ asset('porto-ecommerce/assets/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('porto-ecommerce/assets/js/plugins.min.js') }}"></script>

    <!-- Main JS File -->
    <script src="{{ asset('porto-ecommerce/assets/js/main.min.js') }}"></script>

    <script src="{{ asset('porto-ecommerce/assets/js/vue.min.js') }}"></script>



    @stack('scripts')
</body>

</html>

