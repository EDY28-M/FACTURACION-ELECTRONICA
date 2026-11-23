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

    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/rating.css') }}">

    @if (file_exists(public_path('theme/custom_styles_ecommerce.css')))
        <link rel="stylesheet" href="{{ asset('theme/custom_styles_ecommerce.css') }}" />
    @endif

</head>
<body>
    <div class="page-wrapper">

        @include('ecommerce::layouts.partials_ecommerce.header')
        @include('ecommerce::layouts.partials_ecommerce.header_bottom_sticky')

        <main class="main">
            <div class="container">
                <div class="row">
                    <div class="col-lg-9">
                        <div class="row row-sm">

                                @yield('content')

                        </div><!-- End .row -->

                    </div><!-- End .col-lg-9 -->

                    <aside class="sidebar-shop col-lg-3 order-lg-first">
                        <div class="sidebar-wrapper">

                            <div class="widget widget-featured">

                                @include('ecommerce::layouts.partials_ecommerce.widget_products')

                            </div><!-- End .widget -->

                        </div><!-- End .sidebar-wrapper -->
                    </aside><!-- End .col-lg-3 -->
                </div><!-- End .row -->
            </div><!-- End .container -->

            <div class="mb-5"></div><!-- margin -->
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
    <script src="{{ asset( 'porto-ecommerce/assets/js/plugins.min.js') }}"></script>
    <script src="{{ asset( 'porto-ecommerce/assets/js/nouislider.min.js' ) }}"></script>

     <!-- Main JS File -->
     <script src="{{ asset('porto-ecommerce/assets/js/main.min.js') }}"></script>
     <script src="{{ asset('porto-ecommerce/assets/js/vue.min.js') }}"></script>
     <script src="{{ asset('porto-ecommerce/assets/js/rating.js') }}"></script>
    @stack('scripts')
</body>

</html>
