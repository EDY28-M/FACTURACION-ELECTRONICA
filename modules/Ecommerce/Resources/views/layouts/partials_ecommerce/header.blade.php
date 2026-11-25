@php
    $contactAddress = trim((string) ($information->information_contact_address ?? ''));
    $contactPhone = trim((string) ($information->information_contact_phone ?? ''));
    $shippingTag = trim((string) ($information->tag_shipping ?? ''));
    $isCatalogActive = request()->is('ecommerce') || request()->is('ecommerce/category/*');
@endphp

<div class="sf-topbar">
    <div class="sf-container sf-topbar-inner">
        <div class="sf-topbar-left">
            <span class="sf-topbar-badge">
                <i class="fas fa-shipping-fast"></i>
                {{ $shippingTag !== '' ? $shippingTag : 'ATENCIÓN Y DESPACHO' }}
            </span>
            @if($contactAddress !== '')
                <span class="sf-topbar-sep">|</span>
                <span class="sf-topbar-address">{{ $contactAddress }}</span>
            @endif
        </div>
        <div class="sf-topbar-right">
            @if($contactPhone !== '')
                <span class="sf-topbar-contact-label">Contáctanos &rarr;</span>
                <a href="{{ $whatsappUrl ?? ('tel:'.preg_replace('/[^0-9+]/', '', $contactPhone)) }}"
                   class="sf-topbar-phone"
                   @if(!empty($whatsappUrl)) target="_blank" rel="noopener noreferrer" @endif>
                    <i class="fab fa-whatsapp"></i>
                    <span>{{ $contactPhone }}</span>
                </a>
            @endif
        </div>
    </div>
</div>

<header class="sf-header">
    <div class="sf-container sf-navbar">
        <div class="sf-nav-left">
            <a href="{{ route('tenant.ecommerce.index') }}" class="sf-brand" aria-label="{{ $storeName }}">
                @if($storeLogoUrl)
                    <img src="{{ $storeLogoUrl }}" alt="{{ $storeName }}" />
                @else
                    <span class="sf-brand-text">{{ $storeName }}</span>
                @endif
            </a>

            <ul class="sf-nav-links">
                <li>
                    <a href="{{ route('tenant.ecommerce.index') }}" class="sf-nav-link">Inicio</a>
                </li>
                <li>
                    <a href="{{ route('tenant.ecommerce.index') }}#catalogo"
                       class="sf-nav-link {{ $isCatalogActive ? 'sf-nav-pill-active' : '' }}">
                        Catálogo
                    </a>
                </li>
                <li>
                    <a href="{{ route('tenant_detail_cart') }}"
                       class="sf-nav-link {{ request()->is('ecommerce/detail_cart') ? 'sf-nav-pill-active' : '' }}">
                        Carrito
                    </a>
                </li>
            </ul>
        </div>

        <div id="header_bar" class="sf-search-wrap">
            <div class="header-dropdown">
                <div class="sf-search-box">
                    <input placeholder="Buscar por nombre, código o marca..."
                           type="text"
                           class="search_input sf-search-input form-control"
                           v-model="value"
                           aria-label="Buscar productos"
                           v-on:keyup="autoComplete"
                           v-on:keyup.enter="goSearch" />
                    <button type="button" class="sf-search-btn" v-on:click="goSearch" aria-label="Buscar">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="sf-search-dropdown" v-if="results.length > 0" v-cloak>
                    <ul>
                        <li v-for="result in results" :key="result.id">
                            <a :href="'/ecommerce/item/' + result.id">
                                <div class="sf-search-item-info">
                                    <img :src="result.image_url_small" :alt="result.description">
                                    <span class="sf-search-title">@{{ result.description }}</span>
                                </div>
                                <span class="sf-search-price">@{{ result.sale_unit_price }}</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="sf-nav-right">
            @if(!empty($whatsappUrl))
                <a href="{{ $whatsappUrl }}"
                   class="sf-icon-btn sf-icon-btn--wa d-none d-md-inline-flex"
                   target="_blank"
                   rel="noopener noreferrer"
                   title="Consultar por WhatsApp"
                   aria-label="WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                </a>
            @endif

            @include('ecommerce::layouts.partials_ecommerce.cart_dropdown')

            @include('ecommerce::partials.headers.session')

            <button class="mobile-menu-toggler" type="button" aria-label="Abrir menú">
                <i class="icon-menu"></i>
            </button>
        </div>
    </div>
</header>

@push('scripts')
<script type="text/javascript">
    var app = new Vue({
        el: '#header_bar',
        data: {
            value: new URLSearchParams(window.location.search).get('search') || '',
            suggestions: [],
            timer: null,
            resource: 'ecommerce',
            results: [],
        },
        methods: {
            // Debounced server-side lookup (the catalogue is not downloaded in advance).
            autoComplete(event) {
                if (event && event.key === 'Enter') return
                clearTimeout(this.timer)
                const term = (this.value || '').trim()
                if (term.length < 2) {
                    this.results = []
                    return
                }
                this.timer = setTimeout(() => this.getItems(term), 250)
            },
            goSearch() {
                const term = (this.value || '').trim()
                if (term) {
                    window.location.href = '/ecommerce?search=' + encodeURIComponent(term)
                } else {
                    window.location.href = '/ecommerce'
                }
            },
            getItems(term) {
                let contex = this
                fetch(`/${this.resource}/items_bar?q=` + encodeURIComponent(term))
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (myJson) {
                        contex.results = myJson.data || []
                    })
                    .catch(function () {
                        contex.results = []
                    });
            }
        }
    })
</script>
@endpush
