<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--    <title>{{ config('app.name', 'Facturación Electrónica') }}</title>--}}
    <title>Facturación Electrónica</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,600&family=Hanken+Grotesk:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('porto-light/vendor/bootstrap/css/bootstrap.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/vendor/animate/animate.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/vendor/font-awesome/css/fontawesome-all.min.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/css/theme.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.26.29/sweetalert2.min.css" />
    @if (file_exists(public_path('theme/custom_styles.css')))
        <link rel="stylesheet" href="{{ asset('theme/custom_styles.css') }}" />
    @endif
    <link rel="stylesheet" href="{{ asset('css/tenant-auth.css') }}?v={{ @filemtime(public_path('css/tenant-auth.css')) }}" />
    {{-- Motion starts only once frames are painting; the static state is already complete --}}
    <script>if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) requestAnimationFrame(function () { document.documentElement.classList.add('anim'); });</script>

    {{-- @if (file_exists(public_path('theme/background/bkg_store.jpeg')))
        <style>
            .app{
                background: url('{{ asset('theme/background/bkg_store.jpeg') }}') center center / cover;
            }
        </style>
    @else
        <style>
            .app{
                background: url('{{ asset('porto-light/background/bkg_store.jpeg') }}') center center / cover;
            }
        </style>
    @endif --}}

    @include('tenant.layouts.partials.favicon')
</head>

<body class="tn-auth">

    <div class="app">
        @yield('content')
    </div>

    @stack('scripts')
    <!-- <script src="//code.tidio.co/1vliqewz9v7tfosw5wxiktpkgblrws5w.js"></script> -->
</body>

</html>
