<!DOCTYPE html>
@php
    $path = explode('/', request()->path());
    $path[1] = (array_key_exists(1, $path)> 0)?$path[1]:'';
    $path[2] = (array_key_exists(2, $path)> 0)?$path[2]:'';
    $path[0] = ($path[0] === '')?'documents':$path[0];
    $visual->sidebar_theme = property_exists($visual, 'sidebar_theme')?$visual->sidebar_theme:''
@endphp
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="no-mobile-device custom-scroll no-trans
        {{$visual->bg == 'dark' ? 'dark' : ''}}
        {{ ($path[0] === 'documents' && $path[1] === 'create'
        || $path[0] === 'quotations' && $path[1] === 'create'
        || $path[0] === 'sale-opportunities' && $path[1] === 'create'
        || $path[0] === 'order-notes' && $path[1] === 'create'
        || $path[0] === 'sale-notes' && $path[1] === 'create'
        || $path[0] === 'purchase-quotations' && $path[1] === 'create'
        || $path[0] === 'purchase-orders' && $path[1] === 'create'
        || $path[0] === 'purchases' && $path[1] === 'create') ? 'newinvoice' : ''}}
        ">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Initial rail state before first paint: narrow screens and the saved preference start as the icon rail --}}
    <script>
        window.TENANT_IGV = {reduced_regime: {{ $vc_compact_sidebar->igv_reduced_regime ? 'true' : 'false' }}};
        window.TENANT_RAIL_DEFAULT = {{ $vc_compact_sidebar->compact_sidebar == true ? 'true' : 'false' }};
        (function () {
            var d = document.documentElement, mini = innerWidth < 1200, stored = null;
            d.classList.add('no-trans');
            try { stored = localStorage.getItem('tenantRailMini'); } catch (e) {}
            if (!mini) {
                mini = stored === null ? window.TENANT_RAIL_DEFAULT : stored === '1';
            }
            if (mini) d.classList.add('rail-mini');
        })();
    </script>
    <title>Facturación Electrónica</title>

    <link async href="{{ mix('css/app.css') }}" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.gstatic.com">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,600&family=Hanken+Grotesk:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('porto-light/vendor/bootstrap/css/bootstrap.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/vendor/animate/animate.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/vendor/font-awesome/5.11/css/all.min.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/vendor/select2/css/select2.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/vendor/select2-bootstrap-theme/select2-bootstrap.min.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/vendor/datatables/media/css/dataTables.bootstrap4.css') }}" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.26.29/sweetalert2.min.css" />
    <link rel="stylesheet" href="{{asset('porto-light/vendor/bootstrap-datepicker/css/bootstrap-datepicker3.css')}}" />

    <link rel="stylesheet" href="{{asset('porto-light/vendor/jquery-ui/jquery-ui.css')}}" />
    <link rel="stylesheet" href="{{asset('porto-light/vendor/jquery-ui/jquery-ui.theme.css')}}" />
    <link rel="stylesheet" href="{{asset('porto-light/vendor/select2/css/select2.css')}}" />
    <link rel="stylesheet" href="{{asset('porto-light/vendor/select2-bootstrap-theme/select2-bootstrap.min.css')}}" />

    <link href="{{ asset('porto-light/vendor/bootstrap-timepicker/css/bootstrap-timepicker.css') }}" rel="stylesheet">
    <link href="{{ asset('porto-light/vendor/bootstrap-daterangepicker/daterangepicker.css') }}" rel="stylesheet">

    <link rel="stylesheet" href="{{asset('porto-light/vendor/bootstrap-timepicker/css/bootstrap-timepicker.css')}}" />

    <link rel="stylesheet" href="{{asset('porto-light/vendor/jquery-loading/dist/jquery.loading.css')}}" />

    <link rel="stylesheet" href="{{ asset('porto-light/css/theme.css') }}" />
    <link rel="stylesheet" href="{{ asset('porto-light/css/custom.css') }}" />

    @if (file_exists(public_path('theme/custom_styles.css')))
        <link rel="stylesheet" href="{{ asset('theme/custom_styles.css') }}" />
    @endif

    <link rel="stylesheet" href="{{ asset('css/tenant-theme.css') }}?v={{ @filemtime(public_path('css/tenant-theme.css')) }}" />

    {{-- @if (file_exists(public_path('theme/custom_styles_ecommerce.css')))
        <link rel="stylesheet" href="{{ asset('theme/custom_styles_ecommerce.css') }}" />
    @endif --}}


    @stack('styles')


    <script src="{{ asset('porto-light/vendor/modernizr/modernizr.js') }}"></script>

    <style>
        .descarga {
            color:black;
            padding:5px;
        }
        .el-checkbox__label {
            font-size: 13px;
        }
        .center-el-checkbox {
            display: flex;
            align-items: center;
        }
        .center-el-checkbox .el-checkbox {
            margin-bottom: 0
        }

    </style>

    @if ($vc_company->favicon)
    <link rel="shortcut icon" type="image/png" href="{{ asset($vc_company->favicon) }}"/>
    @endif

    {{-- Mark server-rendered head styles so tenant-shell.js only diffs these (never Vue/Element-injected styles) --}}
    <script>
        Array.prototype.forEach.call(document.head.querySelectorAll('link[rel="stylesheet"], style'), function (node) {
            node.setAttribute('data-shell-ssr', '1');
        });
    </script>

</head>
<body class="pr-0">
    @php $tnIconUrl = asset('images/system-icons.svg').'?v='.@filemtime(public_path('images/system-icons.svg')); @endphp

    <section class="body">
        <!-- start: sidebar -->
        @include('tenant.layouts.partials.sidebar')
        <!-- end: sidebar -->
        <div class="tn-content">
            <!-- start: header -->
            @include('tenant.layouts.partials.header')
            <!-- end: header -->
            <div class="inner-wrapper">
                <section role="main" class="content-body" id="main-wrapper">
                  @if (session('error'))
                      <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                          {{ session('error') }}
                          <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                      </div>
                  @endif
                  @if (session('warning'))
                      <div class="alert alert-warning alert-dismissible fade show mb-3" role="alert">
                          {{ session('warning') }}
                          <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                      </div>
                  @endif
                  @if (session('success'))
                      <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                          {{ session('success') }}
                          <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                      </div>
                  @endif
                  @yield('content')
                </section>

                @yield('package-contents')
            </div>
        </div>
    </section>
    @if($show_ws)
        @if(strlen($phone_whatsapp) > 0)
        <a class='ws-flotante' href='https://wa.me/{{$phone_whatsapp}}' target="BLANK" style="background-image: url('{{asset('logo/ws.png')}}'); background-size: 70px; background-repeat: no-repeat;" ></a>
        @endif
    @endif


    <!-- Vendor -->
    <script src="{{ asset('porto-light/vendor/jquery/jquery.js')}}"></script>
    <script src="{{ asset('porto-light/vendor/jquery-browser-mobile/jquery.browser.mobile.js')}}"></script>
    <script src="{{ asset('porto-light/vendor/jquery-cookie/jquery-cookie.js')}}"></script>
    {{-- <script src="{{ asset('porto-light/master/style-switcher/style.switcher.js')}}"></script> --}}
    <script src="{{ asset('porto-light/vendor/popper/umd/popper.min.js')}}"></script>
    <!-- <script src="{{ asset('porto-light/vendor/bootstrap/js/bootstrap.js')}}"></script> -->
    {{-- <script src="{{ asset('porto-light/vendor/common/common.js')}}"></script> --}}
    <script src="{{ asset('porto-light/vendor/nanoscroller/nanoscroller.js')}}"></script>
    <script src="{{ asset('porto-light/vendor/magnific-popup/jquery.magnific-popup.js')}}"></script>
    <script src="{{ asset('porto-light/vendor/jquery-placeholder/jquery-placeholder.js')}}"></script>
    <script src="{{ asset('porto-light/vendor/select2/js/select2.js') }}"></script>
    <script src="{{ asset('porto-light/vendor/datatables/media/js/jquery.dataTables.min.js')}}"></script>
    <script src="{{ asset('porto-light/vendor/datatables/media/js/dataTables.bootstrap4.min.js')}}"></script>

    {{-- Specific Page Vendor --}}
    <script src="{{asset('porto-light/vendor/jquery-ui/jquery-ui.js')}}"></script>
    <script src="{{asset('porto-light/vendor/jqueryui-touch-punch/jqueryui-touch-punch.js')}}"></script>
    <!--<script src="{{asset('porto-light/vendor/select2/js/select2.js')}}"></script>-->

    <script src="{{asset('porto-light/vendor/jquery-loading/dist/jquery.loading.js')}}"></script>

    <!--<script src="assets/vendor/select2/js/select2.js"></script>-->
    {{--<script src="{{asset('porto-light/vendor/bootstrap-multiselect/bootstrap-multiselect.js')}}"></script>--}}

    <!-- Moment -->
    {{--<script src="{{ asset('porto-light/vendor/moment/moment.js') }}"></script>--}}

    <!-- DatePicker -->
    {{--<script src="{{asset('porto-light/vendor/bootstrap-datepicker/js/bootstrap-datepicker.js')}}"></script>--}}

    <!-- Date range Plugin JavaScript -->
    {{--<script src="{{ asset('porto-light/vendor/bootstrap-timepicker/bootstrap-timepicker.js') }}"></script>--}}
    {{--<script src="{{ asset('porto-light/vendor/bootstrap-daterangepicker/daterangepicker.js') }}"></script>--}}

    <!-- Theme Initialization Files -->
    {{-- <script src="{{asset('porto-light/js/theme.init.js')}}"></script> --}}

    {{--<script src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>--}}
    {{--<script src="https://cdn.datatables.net/1.10.19/js/dataTables.bootstrap4.min.js"></script>--}}

    @stack('scripts')

    <script src="{{ mix('js/manifest.js') }}"></script>
    <script src="{{ mix('js/vendor.js') }}"></script>
    <!-- Theme Base, Components and Settings -->
    <script src="{{asset('porto-light/js/theme.js')}}"></script>

    <!-- Theme Custom -->
    <script src="{{asset('porto-light/js/custom.js')}}"></script>
    <script src="{{asset('porto-light/js/jquery.xml2json.js')}}"></script>
    <script>

        function parseXMLToJSON(source)
        {
            let transform = $.xml2json(source);
            return transform
        }

    </script>
    {{-- Deferred, in document order: app.js (Vue) -> tenant-shell.js -> tenant-rail.js -> tenant-tables.js. They run after theme.js, custom.js and parseXMLToJSON above, as app.js did originally. --}}
    <script defer src="{{ mix('js/app.js') }}"></script>
    <script defer src="{{ asset('js/tenant-shell.js') }}?v={{ @filemtime(public_path('js/tenant-shell.js')) }}"></script>
    <script defer src="{{ asset('js/tenant-rail.js') }}?v={{ @filemtime(public_path('js/tenant-rail.js')) }}"></script>
    <script defer src="{{ asset('js/tenant-tables.js') }}?v={{ @filemtime(public_path('js/tenant-tables.js')) }}"></script>
    <!-- <script src="//code.tidio.co/1vliqewz9v7tfosw5wxiktpkgblrws5w.js"></script> -->
</body>
</html>
