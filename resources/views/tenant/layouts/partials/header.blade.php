@php
    $tnEnvHref = in_array('configuration', $vc_modules) ? route('tenant.companies.create') : null;
    if ($vc_company->soap_type_id == '01') {
        $tnEnv = ['key' => 'demo', 'label' => 'SUNAT · Demo', 'title' => 'SUNAT: entorno de demostración'];
    } elseif ($vc_company->soap_type_id == '02') {
        $tnMissing = \App\Models\Tenant\Company::missingForProduction($vc_company);
        $tnEnv = $tnMissing
            ? ['key' => 'warn', 'label' => 'SUNAT · Producción incompleta', 'title' => 'Para emitir en producción falta: '.implode(', ', $tnMissing)]
            : ['key' => 'prod', 'label' => 'SUNAT · Producción', 'title' => 'SUNAT: entorno de producción'];
    } else {
        $tnEnv = ['key' => 'int', 'label' => 'SUNAT · Interno', 'title' => 'Entorno interno'];
    }
    if ($tnEnvHref) {
        $tnEnv['title'] .= ', pulse para ir a configuración';
    }
    $tnUserName = trim((string) $vc_user->name);
    $tnUserParts = preg_split('/\s+/', $tnUserName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tnInitials = mb_strtoupper(mb_substr($tnUserParts[0] ?? ($vc_user->email ?? '?'), 0, 1).(isset($tnUserParts[1]) ? mb_substr($tnUserParts[1], 0, 1) : ''));
    $tnRoles = ['admin' => 'Administrador', 'seller' => 'Vendedor', 'integrator' => 'Integrador'];
    $tnRole = $tnRoles[$vc_user->type] ?? ucfirst((string) $vc_user->type);
    $tnLevels = $vc_module_levels ?? [];
    $tnIsIntegrator = $vc_user->type == 'integrator';
    $tnNotInternal = $vc_company->soap_type_id != '03';
    $tnCanOrders = in_array('ecommerce', $vc_modules);
    $tnCanNotSent = $tnNotInternal && in_array('documents', $vc_modules) && in_array('document_not_sent', $tnLevels);
    $tnCanRegularize = $tnNotInternal && in_array('documents', $vc_modules) && in_array('regularize_shipping', $tnLevels);
    $tnCanNewDoc = !$tnIsIntegrator && $tnNotInternal && in_array('documents', $vc_modules) && in_array('new_document', $tnLevels);
    $tnCanListDoc = $tnNotInternal && in_array('documents', $vc_modules) && in_array('list_document', $tnLevels);
    $tnCanPos = !$tnIsIntegrator && in_array('pos', $vc_modules);
    $tnCanEstablishments = in_array('establishments', $vc_modules);
    $tnCanConfig = in_array('configuration', $vc_modules);
    $tnHasQuickMenu = $tnCanNewDoc || $tnCanListDoc || $tnCanPos || $tnCanEstablishments || $tnCanConfig;
@endphp
<header class="header tn-top">
    <div class="tn-search-wrap" data-tn-module-search>
        <div class="tn-search" role="search">
            @include('tenant.layouts.partials.icon', ['name' => 'search'])
            <input class="tn-search-input" type="search" placeholder="Buscar módulo o sección"
                   autocomplete="off" spellcheck="false" aria-label="Buscar módulos y secciones del sistema"
                   aria-haspopup="listbox" aria-expanded="false">
        </div>
        <div class="tn-search-panel" hidden>
            <div class="tn-search-panel-head">
                <span class="tn-search-status" aria-live="polite"></span>
                <button type="button" class="tn-search-close" aria-label="Cerrar buscador">&times;</button>
            </div>
            <div class="tn-search-body" role="listbox" aria-label="Resultados de búsqueda"></div>
            <div class="tn-search-foot">
                <span><kbd>↑</kbd><kbd>↓</kbd> navegar · <kbd>Enter</kbd> abrir · <kbd>Esc</kbd> cerrar</span>
                <span class="tn-search-brand">KeraAI</span>
            </div>
        </div>
    </div>

    <div class="header-right">
        {{-- Stable wrapper so tenant-shell.js can resync the environment pill and counts when badges appear or disappear --}}
        <div class="header-notifications" data-shell-notifications>
            @if($tnEnvHref)
                <a href="{{ $tnEnvHref }}" class="tn-env tn-env--{{ $tnEnv['key'] }}" title="{{ $tnEnv['title'] }}">{{ $tnEnv['label'] }}</a>
            @else
                <span class="tn-env tn-env--{{ $tnEnv['key'] }}" title="{{ $tnEnv['title'] }}">{{ $tnEnv['label'] }}</span>
            @endif

            <ul class="notifications">
                @if($tnCanOrders)
                <li>
                    <a href="{{ route('tenant_orders_index') }}" class="tn-ib notification-icon" title="Pedidos pendientes" aria-label="Pedidos pendientes">
                        @include('tenant.layouts.partials.icon', ['name' => 'shopping-cart'])
                        <em class="num cart-item-count">{{ $vc_orders }}</em>
                    </a>
                </li>
                @endif
                @if($vc_document > 0 && $tnCanNotSent)
                <li>
                    <a href="{{ route('tenant.documents.not_sent') }}" class="tn-ib notification-icon" title="Comprobantes pendientes de envío" aria-label="Comprobantes pendientes de envío">
                        @include('tenant.layouts.partials.icon', ['name' => 'bell'])
                        <em class="num cart-item-count tn-warn">{{ $vc_document }}</em>
                    </a>
                </li>
                @endif
                @if($vc_document_regularize_shipping > 0 && $tnCanRegularize)
                <li>
                    <a href="{{ route('tenant.documents.regularize_shipping') }}" class="tn-ib notification-icon" title="Comprobantes pendientes de rectificación" aria-label="Comprobantes pendientes de rectificación">
                        @include('tenant.layouts.partials.icon', ['name' => 'triangle-alert'])
                        <em class="num cart-item-count tn-warn">{{ $vc_document_regularize_shipping }}</em>
                    </a>
                </li>
                @endif
            </ul>
        </div>

        <div class="tn-spacer"></div>

        @if($tnHasQuickMenu)
        @php
            // Accesos rápidos siempre visibles junto al botón principal (en celular vuelven al menú desplegable).
            // Mismo orden de prioridad que el botón principal de abajo: no se repite en la fila.
            $tnPrimary = $tnCanNewDoc ? 'doc' : ($tnCanPos ? 'pos' : ($tnCanListDoc ? 'list' : ($tnCanEstablishments ? 'est' : 'company')));
            $tnQuick = [];
            if ($tnCanNewDoc) $tnQuick[] = ['href' => route('tenant.documents.create'), 'icon' => 'receipt', 'label' => 'Boleta', 'title' => 'Nueva boleta'];
            if ($tnCanListDoc) $tnQuick[] = ['href' => route('tenant.documents.index'), 'icon' => 'file-minus-2', 'label' => 'Nota de crédito', 'title' => 'Nota de crédito (se emite desde el listado de comprobantes)'];
            if ($tnCanPos && $tnPrimary !== 'pos') $tnQuick[] = ['href' => route('tenant.pos.index'), 'icon' => 'monitor', 'label' => 'Punto de venta', 'title' => 'Punto de venta'];
            if ($tnCanEstablishments && $tnPrimary !== 'est') $tnQuick[] = ['href' => route('tenant.establishments.index'), 'icon' => 'building-2', 'label' => 'Establecimiento', 'title' => 'Nuevo establecimiento', 'minor' => true];
            if ($tnCanConfig && $tnPrimary !== 'company') $tnQuick[] = ['href' => route('tenant.companies.create'), 'icon' => 'briefcase', 'label' => 'Mi empresa', 'title' => 'Mi empresa', 'minor' => true];
        @endphp
        <div class="tn-new {{ count($tnQuick) ? 'has-quick' : '' }}">
            @if($tnCanNewDoc)
                <a class="tn-btn tn-btn--p" href="{{ route('tenant.documents.create') }}" aria-label="Nueva factura">
                    @include('tenant.layouts.partials.icon', ['name' => 'plus'])
                    <span>Nueva factura</span>
                </a>
            @elseif($tnCanPos)
                <a class="tn-btn tn-btn--p" href="{{ route('tenant.pos.index') }}" aria-label="Punto de venta">
                    @include('tenant.layouts.partials.icon', ['name' => 'monitor'])
                    <span>Punto de venta</span>
                </a>
            @elseif($tnCanListDoc)
                <a class="tn-btn tn-btn--p" href="{{ route('tenant.documents.index') }}" aria-label="Comprobantes">
                    @include('tenant.layouts.partials.icon', ['name' => 'receipt'])
                    <span>Comprobantes</span>
                </a>
            @elseif($tnCanEstablishments)
                <a class="tn-btn tn-btn--p" href="{{ route('tenant.establishments.index') }}" aria-label="Establecimientos">
                    @include('tenant.layouts.partials.icon', ['name' => 'building-2'])
                    <span>Establecimientos</span>
                </a>
            @else
                <a class="tn-btn tn-btn--p" href="{{ route('tenant.companies.create') }}" aria-label="Mi empresa">
                    @include('tenant.layouts.partials.icon', ['name' => 'briefcase'])
                    <span>Mi empresa</span>
                </a>
            @endif
            @if(count($tnQuick))
                <nav class="tn-quick" aria-label="Accesos rápidos">
                    @foreach($tnQuick as $q)
                        <a class="tn-qa{{ ($q['minor'] ?? false) ? ' tn-qa--minor' : '' }}" href="{{ $q['href'] }}" title="{{ $q['title'] }}" aria-label="{{ $q['title'] }}">
                            @include('tenant.layouts.partials.icon', ['name' => $q['icon']])
                            <span>{{ $q['label'] }}</span>
                        </a>
                    @endforeach
                </nav>
            @endif
            <button class="tn-btn tn-btn--p tn-caret" type="button" aria-haspopup="menu" aria-expanded="false" aria-label="Más accesos rápidos">
                @include('tenant.layouts.partials.icon', ['name' => 'chevron-down'])
            </button>
            <div class="tn-menu" role="menu">
                @if($tnCanNewDoc)
                    <a role="menuitem" href="{{ route('tenant.documents.create') }}">
                        @include('tenant.layouts.partials.icon', ['name' => 'receipt'])Boleta
                    </a>
                @endif
                @if($tnCanListDoc)
                    <a role="menuitem" href="{{ route('tenant.documents.index') }}" title="Las notas de crédito se emiten desde el listado de comprobantes">
                        @include('tenant.layouts.partials.icon', ['name' => 'file-minus-2'])Nota de crédito
                    </a>
                @endif
                @if($tnCanPos)
                    <a role="menuitem" href="{{ route('tenant.pos.index') }}">
                        @include('tenant.layouts.partials.icon', ['name' => 'monitor'])Punto de venta
                    </a>
                @endif
                @if($tnCanEstablishments)
                    <a role="menuitem" href="{{ route('tenant.establishments.index') }}">
                        @include('tenant.layouts.partials.icon', ['name' => 'building-2'])Nuevo establecimiento
                    </a>
                @endif
                @if($tnCanConfig)
                    <a role="menuitem" href="{{ route('tenant.companies.create') }}">
                        @include('tenant.layouts.partials.icon', ['name' => 'briefcase'])Mi empresa
                    </a>
                @endif
            </div>
        </div>
        @endif

        <a href="{{ route('settings.change_mode') }}" data-no-shell class="tn-ib tn-moon" title="Modo noche" aria-label="Modo noche">
            @include('tenant.layouts.partials.icon', ['name' => 'moon', 'class' => 'i-moon'])
            @include('tenant.layouts.partials.icon', ['name' => 'sun', 'class' => 'i-sun'])
        </a>

        <div id="userbox" class="tn-user">
            <button class="tn-user-btn" type="button" aria-haspopup="menu" aria-expanded="false">
                <span class="tn-av" aria-hidden="true">{{ $tnInitials }}</span>
                <span class="tn-who"><b>{{ $tnUserName !== '' ? $tnUserName : $vc_user->email }}</b><small>{{ $tnRole }}</small></span>
                @include('tenant.layouts.partials.icon', ['name' => 'chevron-down'])
            </button>
            <div class="tn-menu" role="menu">
                @if(in_array('configuration', $vc_modules))
                    <a role="menuitem" href="{{ url('list-settings') }}">
                        @include('tenant.layouts.partials.icon', ['name' => 'settings-2'])Configuración
                    </a>
                @endif
                <a role="menuitem" class="danger" href="{{ route('logout') }}" data-no-shell onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                    @include('tenant.layouts.partials.icon', ['name' => 'log-out'])@lang('app.buttons.logout')
                </a>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                    @csrf
                </form>
            </div>
        </div>
    </div>
</header>
<script defer src="{{ asset('js/tenant-env-sync.js') }}?v={{ @filemtime(public_path('js/tenant-env-sync.js')) }}" data-url="{{ url('companies/environment') }}"></script>
<script defer src="{{ asset('js/tenant-module-search.js') }}?v={{ @filemtime(public_path('js/tenant-module-search.js')) }}"></script>
