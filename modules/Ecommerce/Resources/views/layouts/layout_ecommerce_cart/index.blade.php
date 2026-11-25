<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $storeName ?? 'Tienda virtual' }} — Carrito</title>

    <meta name="description" content="{{ ($storeName ?? 'Tienda virtual').' - carrito de compras' }}">

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="{{ asset('porto-ecommerce/assets/images/icons/favicon.ico') }}">

    <!-- Clean Modern Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Plugins CSS File -->
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/bootstrap.min.css') }}">

    <!-- Main CSS File -->
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/style.min.css') }}">
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/custom.css') }}">
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/storefront-modern.css') }}?v=20261125">

    <!-- Fontawesome -->
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/font-awesome/css/fontawesome-all.min.css') }}">

    @if (file_exists(public_path('theme/custom_styles_ecommerce.css')))
        <link rel="stylesheet" href="{{ asset('theme/custom_styles_ecommerce.css') }}" />
    @endif
</head>
<body class="sf-body">
    <div class="page-wrapper">
        @include('ecommerce::layouts.partials_ecommerce.header')

        <main class="main sf-cart-shell">
            <div class="sf-container">
                <nav aria-label="Breadcrumb">
                    <ol class="sf-breadcrumb">
                        <li><a href="{{ route('tenant.ecommerce.index') }}">Inicio</a></li>
                        <li class="sf-breadcrumb-sep">&rsaquo;</li>
                        <li class="sf-breadcrumb-current">Carrito de compras</li>
                    </ol>
                </nav>

                @yield('content')
            </div>
        </main>

        <footer class="footer sf-footer" id="contacto-tienda">
            @include('ecommerce::layouts.partials_ecommerce.footer')
        </footer>
    </div>

    <div class="mobile-menu-overlay"></div>
    <div class="mobile-menu-container">
        @include('ecommerce::layouts.partials_ecommerce.mobile_menu')
    </div>

    <a id="scroll-top" href="#top" title="Subir" role="button"><i class="icon-angle-up"></i></a>

     <!-- Plugins JS File -->
    <script src="{{ asset('porto-ecommerce/assets/js/jquery.min.js') }}"></script>
    <script src="{{ asset('porto-ecommerce/assets/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('porto-ecommerce/assets/js/plugins.min.js') }}"></script>
    @if(!empty($cardEnabled))
    <script src="https://js.culqi.com/checkout-js"></script>
    <script src="https://3ds.culqi.com" defer></script>
    <script src="{{ asset('payments/culqi-checkout.js') }}"></script>
    @endif
    <script src="{{ asset('porto-ecommerce/assets/js/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('porto-ecommerce/assets/js/moment.min.js') }}"></script>

    <!-- Main JS File -->
    <script src="{{ asset('porto-ecommerce/assets/js/main.min.js') }}"></script>
    <script src="{{ asset('porto-ecommerce/assets/js/vue.js') }}"></script>
    <script src="{{ asset('porto-ecommerce/assets/js/axios.min.js') }}"></script>

    @stack('scripts')
</body>

</html>
