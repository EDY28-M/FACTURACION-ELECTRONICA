<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <title>{{ $storeName ?? 'Tienda virtual' }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ ($storeName ?? 'Tienda virtual').' - catálogo y tienda virtual' }}">

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
    <link rel="stylesheet" href="{{ asset('porto-ecommerce/assets/css/rating.css') }}">
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

        <main class="main">
            @include('ecommerce::layouts.partials_ecommerce.home_slider')

            @php
                $hasActiveFilters = !empty($activeCategoryId) || !empty($activeTagId) || !empty($activeBrands) || !empty($activeSearch);
                $introHeadline = !empty($activeCategoryName)
                    ? $activeCategoryName
                    : ($storeName ?? 'Nuestro Catálogo');
                $introText = trim((string) ($information->tag_support ?? ''));
                if ($introText === '') {
                    $introText = 'Explora nuestro catálogo actualizado, agrega tus productos al carrito o consulta disponibilidad directa por WhatsApp.';
                }
            @endphp

            <section class="sf-intro" id="catalogo">
                <h2 class="sf-intro-title">{{ $introHeadline }}</h2>
                <p class="sf-intro-desc">{{ $introText }}</p>
            </section>

            <section class="sf-catalog-section">
                <div class="sf-container">
                    <div class="sf-catalog-layout">
                        <!-- Left Faceted Filter Sidebar -->
                        <aside class="sf-sidebar" id="sf-filter-sidebar" aria-label="Filtros de catálogo">
                            <form id="sf-filters-form" method="GET" action="{{ route('tenant.ecommerce.index') }}">
                                @if(!empty($activeSearch))
                                    <input type="hidden" name="search" value="{{ $activeSearch }}">
                                @endif
                                @if(!empty($activeSort) && $activeSort !== 'relevance')
                                    <input type="hidden" name="sort" value="{{ $activeSort }}">
                                @endif
                                @if(!empty($activeCategoryId))
                                    <input type="hidden" name="cat" id="sf-cat-input" value="{{ $activeCategoryId }}">
                                @else
                                    <input type="hidden" name="cat" id="sf-cat-input" value="">
                                @endif

                                <div class="sf-sidebar-head">
                                    <h3 class="sf-sidebar-title">Filtros</h3>
                                    @if($hasActiveFilters)
                                        <a href="{{ route('tenant.ecommerce.index') }}" class="sf-clear-filters">Limpiar</a>
                                    @endif
                                </div>

                                @php
                                    $useCategories = isset($filterCategories) && $filterCategories->isNotEmpty();
                                    $useTags = isset($filterTags) && $filterTags->isNotEmpty();
                                @endphp

                                <!-- CATEGORÍA (radio style) -->
                                <div class="sf-filter-group">
                                    <button type="button" class="sf-filter-toggle" data-sf-collapse>
                                        <span>Categoría</span>
                                        <i class="fas fa-chevron-up"></i>
                                    </button>
                                    <ul class="sf-filter-list">
                                        @php
                                            $allCatParams = [];
                                            if (!empty($activeSearch)) $allCatParams['search'] = $activeSearch;
                                            if (!empty($activeBrands)) $allCatParams['brand'] = $activeBrands;
                                            if (!empty($activeSort) && $activeSort !== 'relevance') $allCatParams['sort'] = $activeSort;
                                            $allCatUrl = route('tenant.ecommerce.index', $allCatParams);
                                        @endphp
                                        <li>
                                            <a href="{{ $allCatUrl }}"
                                               class="sf-filter-item {{ (empty($activeCategoryId) && empty($activeTagId)) ? 'is-active' : '' }}">
                                                <span class="sf-radio-dot"></span>
                                                <span>Todas las categorías</span>
                                                @if(isset($totalCatalogCount))
                                                    <span class="sf-filter-count">({{ $totalCatalogCount }})</span>
                                                @endif
                                            </a>
                                        </li>

                                        @if($useCategories)
                                            @foreach($filterCategories as $cat)
                                                @php
                                                    $catParams = array_merge($allCatParams, ['cat' => $cat->id]);
                                                @endphp
                                                <li>
                                                    <a href="{{ route('tenant.ecommerce.index', $catParams) }}"
                                                       class="sf-filter-item {{ ((int) ($activeCategoryId ?? 0) === (int) $cat->id) ? 'is-active' : '' }}">
                                                        <span class="sf-radio-dot"></span>
                                                        <span>{{ $cat->name }}</span>
                                                        <span class="sf-filter-count">({{ $cat->products_count }})</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        @elseif($useTags)
                                            @foreach($filterTags as $tag)
                                                <li>
                                                    <a href="{{ route('tenant.ecommerce.category', ['category' => $tag->id]) }}"
                                                       class="sf-filter-item {{ ((int) ($activeTagId ?? 0) === (int) $tag->id) ? 'is-active' : '' }}">
                                                        <span class="sf-radio-dot"></span>
                                                        <span>{{ $tag->name }}</span>
                                                        <span class="sf-filter-count">({{ $tag->products_count }})</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        @endif
                                    </ul>
                                </div>

                                <!-- MARCA (multi-select checkboxes) -->
                                @if(isset($filterBrands) && $filterBrands->isNotEmpty())
                                    <div class="sf-filter-group">
                                        <button type="button" class="sf-filter-toggle" data-sf-collapse>
                                            <span>Marca</span>
                                            <i class="fas fa-chevron-up"></i>
                                        </button>
                                        <ul class="sf-filter-list">
                                            @foreach($filterBrands as $brand)
                                                @php
                                                    $checked = in_array((int) $brand->id, $activeBrands ?? [], true);
                                                @endphp
                                                <li>
                                                    <label class="sf-filter-item {{ $checked ? 'is-active' : '' }}">
                                                        <input type="checkbox"
                                                               name="brand[]"
                                                               value="{{ $brand->id }}"
                                                               class="sf-check-input"
                                                               {{ $checked ? 'checked' : '' }}
                                                               onchange="this.form.submit()">
                                                        <span>{{ $brand->name }}</span>
                                                        <span class="sf-filter-count">({{ $brand->products_count }})</span>
                                                    </label>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif

                                <!-- ETIQUETAS (if tenant uses both categories & tags) -->
                                @if($useCategories && $useTags)
                                    <div class="sf-filter-group">
                                        <button type="button" class="sf-filter-toggle" data-sf-collapse>
                                            <span>Colecciones</span>
                                            <i class="fas fa-chevron-up"></i>
                                        </button>
                                        <ul class="sf-filter-list">
                                            @foreach($filterTags as $tag)
                                                <li>
                                                    <a href="{{ route('tenant.ecommerce.category', ['category' => $tag->id]) }}"
                                                       class="sf-filter-item {{ ((int) ($activeTagId ?? 0) === (int) $tag->id) ? 'is-active' : '' }}">
                                                        <span class="sf-radio-dot"></span>
                                                        <span>{{ $tag->name }}</span>
                                                        <span class="sf-filter-count">({{ $tag->products_count }})</span>
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </form>

                            <!-- Sidebar WhatsApp Help Card -->
                            <div class="sf-help-card">
                                <h4 class="sf-help-card-title">¿No encuentras lo que buscas?</h4>
                                <p class="sf-help-card-text">
                                    Envíanos el código, nombre o una foto del producto y lo buscamos en el almacén.
                                </p>
                                @if(!empty($whatsappUrl))
                                    <a href="{{ $whatsappUrl }}?text={{ rawurlencode('Hola '.$storeName.', quisiera consultar por un producto del catálogo.') }}"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="sf-help-card-link">
                                        Consultar por WhatsApp &rarr;
                                    </a>
                                @else
                                    <a href="#contacto-tienda" class="sf-help-card-link">
                                        Ver datos de contacto &rarr;
                                    </a>
                                @endif
                            </div>
                        </aside>

                        <!-- Right Catalog Column -->
                        <div class="sf-catalog-main">
                            <div class="sf-toolbar">
                                <div class="sf-toolbar-left">
                                    <button type="button" class="sf-mobile-filter-btn" id="sf-mobile-filter-toggle">
                                        <i class="fas fa-sliders-h"></i>
                                        <span>Filtros</span>
                                    </button>

                                    <span class="sf-results-count">
                                        <strong>{{ $dataPaginate->total() }}</strong>
                                        {{ $dataPaginate->total() === 1 ? 'producto' : 'productos' }}
                                    </span>

                                    @if(!empty($activeSearch))
                                        <a href="{{ route('tenant.ecommerce.index', array_filter(['cat' => $activeCategoryId ?? null, 'brand' => $activeBrands ?? [], 'sort' => ($activeSort ?? 'relevance') !== 'relevance' ? $activeSort : null])) }}"
                                           class="sf-active-chip" title="Quitar búsqueda">
                                            <span>“{{ $activeSearch }}”</span>
                                            <i class="fas fa-times"></i>
                                        </a>
                                    @endif

                                    @if(!empty($activeCategoryName))
                                        <a href="{{ route('tenant.ecommerce.index', array_filter(['search' => $activeSearch ?? null, 'brand' => $activeBrands ?? [], 'sort' => ($activeSort ?? 'relevance') !== 'relevance' ? $activeSort : null])) }}"
                                           class="sf-active-chip" title="Quitar categoría">
                                            <span>{{ $activeCategoryName }}</span>
                                            <i class="fas fa-times"></i>
                                        </a>
                                    @endif
                                </div>

                                <form method="GET" action="{{ request()->url() }}" class="sf-sort-wrap">
                                    @if(!empty($activeSearch))
                                        <input type="hidden" name="search" value="{{ $activeSearch }}">
                                    @endif
                                    @if(!empty($activeCategoryId))
                                        <input type="hidden" name="cat" value="{{ $activeCategoryId }}">
                                    @endif
                                    @foreach(($activeBrands ?? []) as $bid)
                                        <input type="hidden" name="brand[]" value="{{ $bid }}">
                                    @endforeach
                                    <label for="sf-sort-select" class="sf-sort-label">Ordenar por</label>
                                    <select id="sf-sort-select" name="sort" class="sf-sort-select" onchange="this.form.submit()">
                                        <option value="relevance" {{ ($activeSort ?? 'relevance') === 'relevance' ? 'selected' : '' }}>Relevancia</option>
                                        <option value="newest" {{ ($activeSort ?? '') === 'newest' ? 'selected' : '' }}>Más recientes</option>
                                        <option value="price_asc" {{ ($activeSort ?? '') === 'price_asc' ? 'selected' : '' }}>Menor precio</option>
                                        <option value="price_desc" {{ ($activeSort ?? '') === 'price_desc' ? 'selected' : '' }}>Mayor precio</option>
                                        <option value="name_asc" {{ ($activeSort ?? '') === 'name_asc' ? 'selected' : '' }}>Nombre A–Z</option>
                                    </select>
                                </form>
                            </div>

                            <div class="sf-product-grid">
                                @include('ecommerce::layouts.partials_ecommerce.list_products')
                            </div>

                            @if($dataPaginate->total() > 0)
                                <div class="sf-pagination-wrap">
                                    <div class="sf-pagination-info">
                                        Mostrando {{ $dataPaginate->firstItem() }}–{{ $dataPaginate->lastItem() }} de {{ $dataPaginate->total() }} productos.
                                    </div>
                                    @if($dataPaginate->hasPages())
                                        <div class="sf-pagination-links">
                                            {{ $dataPaginate->links() }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>
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

    <!-- Main JS File -->
    <script src="{{ asset('porto-ecommerce/assets/js/main.min.js') }}"></script>
    <script src="{{ asset('porto-ecommerce/assets/js/vue.min.js') }}"></script>

    <script>
        (function () {
            document.querySelectorAll('[data-sf-collapse]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var group = btn.closest('.sf-filter-group');
                    if (group) group.classList.toggle('is-collapsed');
                });
            });
            var mobileBtn = document.getElementById('sf-mobile-filter-toggle');
            var sidebar = document.getElementById('sf-filter-sidebar');
            if (mobileBtn && sidebar) {
                mobileBtn.addEventListener('click', function () {
                    sidebar.classList.toggle('is-open');
                });
            }
        })();
    </script>

    @stack('scripts')
</body>

</html>
