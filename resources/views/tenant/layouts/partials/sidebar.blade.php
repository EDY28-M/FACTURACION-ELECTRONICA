@php
    // Group headings only render when at least one item of the group can render (same module gates as the items).
    $tnIsIntegrator = auth()->user()->type == 'integrator';
    $tnShowSell = in_array('documents', $vc_modules)
        || (! $tnIsIntegrator && in_array('pos', $vc_modules))
        || in_array('ecommerce', $vc_modules)
        || in_array('persons', $vc_modules);
    $tnShowManage = in_array('items', $vc_modules)
        || (! $tnIsIntegrator && (in_array('purchases', $vc_modules) || in_array('inventory', $vc_modules)))
        || in_array('establishments', $vc_modules)
        || (in_array('advanced', $vc_modules) && $vc_company->soap_type_id != '03');
    $tnShowAnalyze = in_array('reports', $vc_modules) || in_array('accounting', $vc_modules) || in_array('finance', $vc_modules) || in_array('treasury', $vc_modules);
    $tnShowAccount = in_array('configuration', $vc_modules) || in_array('cuenta', $vc_modules);
    $tnBrand = trim((string) ($vc_company->trade_name ?: $vc_company->name)) ?: 'Facturación';
    $tnInitial = mb_strtoupper(mb_substr($tnBrand, 0, 1));
@endphp
<aside id="sidebar-left" class="rail" aria-label="Menú principal">
    <a class="rail-brand" href="{{ route('tenant.dashboard.index') }}" data-tip="{{ $tnBrand }}">
        @if($vc_company->logo)
            <span class="mark mark-logo"><img src="{{ asset('storage/uploads/logos/'.$vc_company->logo) }}" alt=""></span>
        @else
            <span class="mark" aria-hidden="true">{{ $tnInitial }}</span>
        @endif
        <b>{{ $tnBrand }}</b>
    </a>
    <div class="nano-content">
        <nav id="menu" class="rail-nav" role="navigation">
            <ul class="nav-main">
                    @if(in_array('dashboard', $vc_modules))
                    <li class="{{ ($path[0] === 'dashboard')?'nav-active':'' }}">
                        <a class="nav-link" data-tip="Dashboard" href="{{ route('tenant.dashboard.index') }}">
                            @include('tenant.layouts.partials.icon', ['name' => 'layout-dashboard'])
                            <span class="rail-label">Dashboard</span>
                        </a>
                    </li>
                    @endif
@if($tnShowSell)
                    <li class="nav-description">Vender</li>
@endif
                    @if(in_array('documents', $vc_modules))
                    <li class="
                        nav-parent
                        {{ ($path[0] === 'documents')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'summaries')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'voided')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'quotations')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'sale-notes')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'contingencies')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'incentives')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'order-notes')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'sale-opportunities')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'contracts')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'production-orders')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'technical-services')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'user-commissions')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'regularize-shipping')?'nav-active nav-expanded':'' }}
                        ">
                        <a class="nav-link" data-tip="Ventas" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'receipt-text'])
                            <span class="rail-label">Ventas</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Ventas">
                            @if(auth()->user()->type != 'integrator' && $vc_company->soap_type_id != '03')
                                @if(in_array('documents', $vc_modules))
                                    @if(in_array('new_document', $vc_module_levels))
                                        <li class="{{ ($path[0] === 'documents' && $path[1] === 'create')?'nav-active':'' }}">
                                            <a class="nav-link" href="{{route('tenant.documents.create')}}">Comprobante electrónico</a>
                                        </li>
                                    @endif
                                @endif
                            @endif

                            @if(in_array('documents', $vc_modules) && $vc_company->soap_type_id != '03')
                                @if(in_array('list_document', $vc_module_levels))
                                    <li class="{{ ($path[0] === 'documents' && $path[1] != 'create' && $path[1] != 'not-sent'&& $path[1] != 'regularize-shipping')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.documents.index')}}">Listado de comprobantes</a>
                                    </li>
                                @endif
                            @endif

                            @if(in_array('sale_notes', $vc_module_levels))
                                    <li class="{{ ($path[0] === 'sale-notes')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.sale_notes.index')}}">Notas de Venta</a>
                                    </li>
                                @endif

                            @if(in_array('documents', $vc_modules) && $vc_company->soap_type_id != '03')

                                @if(in_array('document_not_sent', $vc_module_levels))
                                    <li class="{{ ($path[0] === 'documents' && $path[1] === 'not-sent')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.documents.not_sent')}}">
                                            Comprobantes no enviados
                                        </a>
                                    </li>
                                @endif
                                @if(in_array('regularize_shipping', $vc_module_levels))
                                <li class="{{ ($path[0] === 'documents' && $path[1] === 'regularize-shipping')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('tenant.documents.regularize_shipping')}}">
                                        CPE pendientes de rectificación
                                    </a>
                                </li>
                                @endif
                            @endif

                            @if(auth()->user()->type != 'integrator' && in_array('documents', $vc_modules) )

                                @if(auth()->user()->type != 'integrator' && in_array('document_contingengy', $vc_module_levels) && $vc_company->soap_type_id != '03')
                                <li class="{{ ($path[0] === 'contingencies' )?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('tenant.contingencies.index')}}">
                                        Documentos de contingencia
                                    </a>
                                </li>
                                @endif

                                @if(in_array('summary_voided', $vc_module_levels) && $vc_company->soap_type_id != '03')

                                    <li class="nav-parent
                                        {{ ($path[0] === 'summaries')?'nav-active nav-expanded':'' }}
                                        {{ ($path[0] === 'voided')?'nav-active nav-expanded':'' }}
                                        ">
                                        <a class="nav-link" href="#">
                                                <span class="rail-label">Resúmenes y Anulaciones</span>
                                                @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                                            </a>
                                        <ul class="nav-children">
                                            <li class="{{ ($path[0] === 'summaries')?'nav-active':'' }}">
                                                <a class="nav-link" href="{{route('tenant.summaries.index')}}">
                                                    Resúmenes
                                                </a>
                                            </li>
                                            <li class="{{ ($path[0] === 'voided')?'nav-active':'' }}">
                                                <a class="nav-link" href="{{route('tenant.voided.index')}}">
                                                    Anulaciones
                                                </a>
                                            </li>
                                        </ul>
                                    </li>
                                @endif

                                @if(in_array('sale-opportunity', $vc_module_levels))
                                    <li class="{{ ($path[0] === 'sale-opportunities')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.sale_opportunities.index')}}">
                                            Oportunidad de venta
                                        </a>
                                    </li>
                                @endif

                                @if(in_array('quotations', $vc_module_levels))

                                    <li class="{{ ($path[0] === 'quotations')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.quotations.index')}}">
                                            Cotizaciones
                                        </a>
                                    </li>
                                @endif

                                @if(in_array('contracts', $vc_module_levels))
                                    <li class="nav-parent
                                        {{ ($path[0] === 'contracts')?'nav-active nav-expanded':'' }}
                                        {{ ($path[0] === 'production-orders')?'nav-active nav-expanded':'' }}
                                        ">
                                        <a class="nav-link" href="#">
                                                <span class="rail-label">Contratos</span>
                                                @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                                            </a>
                                        <ul class="nav-children">
                                            <li class="{{ ($path[0] === 'contracts' && ($path[1] ?? '') !== 'recurrent')?'nav-active':'' }}">
                                                <a class="nav-link" href="{{route('tenant.contracts.index')}}">
                                                    Listado
                                                </a>
                                            </li>
                                            <li class="{{ ($path[0] === 'contracts' && ($path[1] ?? '') === 'recurrent')?'nav-active':'' }}">
                                                <a class="nav-link" href="{{route('tenant.contracts.recurrent')}}">
                                                    Facturación recurrente
                                                </a>
                                            </li>
                                            <li class="{{ ($path[0] === 'production-orders')?'nav-active':'' }}">
                                                <a class="nav-link" href="{{route('tenant.production_orders.index')}}">
                                                    Ordenes de Producción
                                                </a>
                                            </li>
                                        </ul>
                                    </li>
                                @endif

                                @if(in_array('order-note', $vc_module_levels))
                                    <li class="{{ ($path[0] === 'order-notes')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.order_notes.index')}}">
                                            Pedidos
                                        </a>
                                    </li>
                                @endif

                                @if(in_array('technical-service', $vc_module_levels))
                                    <li class="{{ ($path[0] === 'technical-services')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.technical_services.index')}}">
                                            Servicio de soporte técnico
                                        </a>
                                    </li>
                                @endif

                                @if(in_array('incentives', $vc_module_levels))

                                    <li class="nav-parent
                                        {{ ($path[0] === 'incentives')?'nav-active nav-expanded':'' }}
                                        {{ ($path[0] === 'user-commissions')?'nav-active nav-expanded':'' }}
                                        ">
                                        <a class="nav-link" href="#">
                                                <span class="rail-label">Comisiones</span>
                                                @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                                            </a>
                                        <ul class="nav-children">
                                            <li class="{{ ($path[0] === 'user-commissions')?'nav-active':'' }}">
                                                <a class="nav-link" href="{{route('tenant.user_commissions.index')}}">
                                                    Vendedores
                                                </a>
                                            </li>
                                            <li class="{{ ($path[0] === 'incentives')?'nav-active':'' }}">
                                                <a class="nav-link" href="{{route('tenant.incentives.index')}}">Productos</a>
                                            </li>
                                        </ul>
                                    </li>

                                @endif



                            @endif

                        </ul>
                    </li>
                    @endif
                    @if(auth()->user()->type != 'integrator')
                        @if(in_array('pos', $vc_modules))
                        <li class="nav-parent
                        {{ ($path[0] === 'pos')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'cash')?'nav-active nav-expanded':'' }}
                        ">
                            <a class="nav-link" data-tip="POS" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'monitor'])
                            <span class="rail-label">POS</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                            <ul class="nav-children" data-title="POS">
                                <li class="{{ ($path[0] === 'pos'  )?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('tenant.pos.index')}}">Punto de venta</a>
                                </li>
                                <li class="{{ ($path[0] === 'cash')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('tenant.cash.index')}}">Caja chica POS</a>
                                </li>
                            </ul>
                        </li>
                        @endif
                    @endif
                    @if(in_array('ecommerce', $vc_modules))
                    <li class="nav-parent
                        {{ in_array($path[0], ['ecommerce','items_ecommerce', 'tags', 'promotions', 'orders', 'configuration'])?'nav-active nav-expanded':'' }}"
                    >
                        <a class="nav-link" data-tip="Tienda virtual" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'store'])
                            <span class="rail-label">Tienda virtual</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Tienda virtual">
                            <li class="">
                                <a class="nav-link" onclick="window.open('{{ route("tenant.ecommerce.index") }}', '_blank')">Ir a Tienda</a>
                            </li>
                            <li class="{{ ($path[0] === 'orders')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant_orders_index')}}">Pedidos</a>
                            </li>
                            <li class="{{ ($path[0] === 'items_ecommerce')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.items_ecommerce.index')}}">Productos Tienda Virtual</a>
                            </li>
                            <li class="{{ ($path[0] === 'tags')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.tags.index')}}">Categorías</a>
                            </li>
                            <li class="{{ ($path[0] === 'promotions')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.promotion.index')}}">Promociones y banners</a>
                            </li>
                            <li class="{{ (($path[1] ?? null) === 'configuration')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant_ecommerce_configuration')}}">Configuración</a>
                            </li>
                        </ul>
                    </li>
                    @endif
                    @if(in_array('persons', $vc_modules))
                    <li class="nav-parent
                        {{ ($path[0] === 'persons' && $path[1] === 'customers')?'nav-active nav-expanded':'' }}
                        {{ $path[0] === 'person-types' ? 'nav-active nav-expanded' : '' }}
                        ">
                        <a class="nav-link" data-tip="Clientes" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'users'])
                            <span class="rail-label">Clientes</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Clientes">
                            <li class="{{ ($path[0] === 'persons' && $path[1] === 'customers')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.persons.index', ['type' => 'customers'])}}">Clientes</a>
                            </li>
                            <li class="{{ ($path[0] === 'person-types')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.person_types.index')}}">Tipos de clientes</a>
                            </li>
                        </ul>
                    </li>
                    @endif
@if($tnShowManage)
                    <li class="nav-description">Gestionar</li>
@endif
                    @if(in_array('items', $vc_modules))
                    <li class="nav-parent
                        {{ ($path[0] === 'items')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'services')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'categories')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'brands')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'item-lots')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'item-sets')?'nav-active nav-expanded':'' }}
                        ">
                        <a class="nav-link" data-tip="Productos y servicios" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'package'])
                            <span class="rail-label">Productos y servicios</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Productos y servicios">
                            <li class="{{ ($path[0] === 'items')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.items.index')}}">Productos</a>
                            </li>
                            <li class="{{ ($path[0] === 'item-sets'  )?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.item_sets.index')}}">Conjuntos/Packs/Promociones</a>
                            </li>
                            <li class="{{ ($path[0] === 'services')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.services')}}">Servicios</a>
                            </li>
                            <li class="{{ ($path[0] === 'categories')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.categories.index')}}">Categorías</a>
                            </li>
                            <li class="{{ ($path[0] === 'brands')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.brands.index')}}">Marcas</a>
                            </li>
                            <li class="{{ ($path[0] === 'item-lots')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.item-lots.index')}}">Series</a>
                            </li>
                        </ul>
                    </li>
                    @endif
@if(auth()->user()->type != 'integrator')
                        @if(in_array('purchases', $vc_modules))
                        <li class="
                            nav-parent
                            {{ ($path[0] === 'purchases')?'nav-active nav-expanded':'' }}
                            {{ ($path[0] === 'persons' && $path[1] === 'suppliers')?'nav-active nav-expanded':'' }}
                            {{ ($path[0] === 'expenses')?'nav-active nav-expanded':'' }}
                            {{ ($path[0] === 'purchase-quotations')?'nav-active nav-expanded':'' }}
                            {{ ($path[0] === 'purchase-orders')?'nav-active nav-expanded':'' }}
                            {{ ($path[0] === 'fixed-asset')?'nav-active nav-expanded':'' }}
                            ">
                            <a class="nav-link" data-tip="Compras" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'shopping-bag'])
                            <span class="rail-label">Compras</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                            <ul class="nav-children" data-title="Compras">



                                <li class="{{ ($path[0] === 'purchases' && $path[1] === 'create')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('tenant.purchases.create')}}">
                                        Nuevo
                                    </a>
                                </li>

                                <li class="{{ ($path[0] === 'purchases' && $path[1] != 'create')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('tenant.purchases.index')}}">
                                        Listado
                                    </a>
                                </li>

                                <li class="{{ ($path[0] === 'purchase-orders')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('tenant.purchase-orders.index')}}">
                                        Ordenes de compra
                                    </a>
                                </li>
                                <li class="{{ ($path[0] === 'expenses' )?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('tenant.expenses.index')}}">
                                        Gastos diversos
                                    </a>
                                </li>

                                <li class="nav-parent
                                    {{ ($path[0] === 'persons' && $path[1] === 'suppliers')?'nav-active nav-expanded':'' }}
                                    {{ ($path[0] === 'purchase-quotations')?'nav-active nav-expanded':'' }}
                                    ">
                                    <a class="nav-link" href="#">
                                            <span class="rail-label">Proveedores</span>
                                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                                        </a>
                                    <ul class="nav-children">

                                        <li class="{{ ($path[0] === 'persons' && $path[1] === 'suppliers')?'nav-active':'' }}">
                                            <a class="nav-link" href="{{route('tenant.persons.index', ['type' => 'suppliers'])}}">
                                                Listado
                                            </a>
                                        </li>
                                        <li class="{{ ($path[0] === 'purchase-quotations')?'nav-active':'' }}">
                                            <a class="nav-link" href="{{route('tenant.purchase-quotations.index')}}">
                                                Solicitar cotización
                                            </a>
                                        </li>
                                    </ul>
                                </li>

                                <li class="nav-parent
                                    {{ ($path[0] === 'fixed-asset' )?'nav-active nav-expanded':'' }}
                                    ">
                                    <a class="nav-link" href="#">
                                            <span class="rail-label">Activos fijos</span>
                                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                                        </a>
                                    <ul class="nav-children">

                                        <li class="{{ ($path[0] === 'fixed-asset' && $path[1] === 'items')?'nav-active':'' }}">
                                            <a class="nav-link" href="{{route('tenant.fixed_asset_items.index')}}">
                                                Ítems
                                            </a>
                                        </li>
                                        <li class="{{ ($path[0] === 'fixed-asset' && $path[1] === 'purchases')?'nav-active':'' }}">
                                            <a class="nav-link" href="{{route('tenant.fixed_asset_purchases.index')}}">
                                                Compras
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                            </ul>
                        </li>
                        @endif
@endif
@if(auth()->user()->type != 'integrator')
                        @if(in_array('inventory', $vc_modules))
                        <li class="nav-parent
                            {{ (in_array($path[0], ['inventory', 'moves', 'transfers', 'devolutions']) |($path[0] === 'reports' && in_array($path[1], ['kardex', 'inventory', 'valued-kardex'])))?'nav-active nav-expanded':'' }}
                        ">
                            <a class="nav-link" data-tip="Inventario" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'warehouse'])
                            <span class="rail-label">Inventario</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                            <ul class="nav-children" data-title="Inventario">
                                <li class="{{ ($path[0] === 'inventory')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('inventory.index')}}">Movimientos</a>
                                </li>
                                <li class="{{ ($path[0] === 'transfers')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('transfers.index')}}">Traslados</a>
                                </li>
                                <li class="{{ ($path[0] === 'devolutions')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('devolutions.index')}}">Devoluciones</a>
                                </li>
                                <li class="{{(($path[0] === 'reports') && ($path[1] === 'kardex')) ? 'nav-active' : ''}}">
                                    <a class="nav-link" href="{{route('reports.kardex.index')}}">Reporte Kardex</a>
                                </li>
                                <li class="{{(($path[0] === 'reports') && ($path[1] == 'inventory')) ? 'nav-active' : ''}}">
                                    <a class="nav-link" href="{{route('reports.inventory.index')}}">Reporte Inventario</a>
                                </li>
                                {{-- <li class="{{ ($path[0] === 'warehouses')?'nav-active':'' }}">
                                    <a class="nav-link" href="{{route('warehouses.index')}}">Almacenes</a>
                                </li> --}}
                                <li class="{{(($path[0] === 'reports') && ($path[1] === 'valued-kardex')) ? 'nav-active' : ''}}">
                                    <a class="nav-link" href="{{route('reports.valued_kardex.index')}}">Kardex valorizado</a>
                                </li>
                            </ul>
                        </li>
                        @endif
@endif
                    @if(in_array('establishments', $vc_modules))
                    <li class="nav-parent {{ in_array($path[0], ['users', 'establishments'])?'nav-active nav-expanded':'' }}">
                        <a class="nav-link" data-tip="Usuarios, locales y series" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'user-cog'])
                            <span class="rail-label">Usuarios, locales y series</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Usuarios, locales y series">
                            <li class="{{ ($path[0] === 'users')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.users.index')}}">Usuarios</a>
                            </li>
                            <li class="{{ ($path[0] === 'establishments')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.establishments.index')}}">Establecimientos</a>
                            </li>
                        </ul>
                    </li>
                    @endif
                    @if(in_array('advanced', $vc_modules) && $vc_company->soap_type_id != '03')
                    <li class="
                        nav-parent
                        {{ ($path[0] === 'retentions')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'dispatches')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'perceptions')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'drivers')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'dispatchers')?'nav-active nav-expanded':'' }}
                        {{ ($path[0] === 'order-forms')?'nav-active nav-expanded':'' }}

                        ">
                        <a class="nav-link" data-tip="Comprobantes avanzados" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'files'])
                            <span class="rail-label">Comprobantes avanzados</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Comprobantes avanzados">
                            <li class="{{ ($path[0] === 'retentions')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.retentions.index')}}">
                                    Retenciones
                                </a>
                            </li>
                            <li class="{{ ($path[0] === 'dispatches')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.dispatches.index')}}">
                                    Guías de remisión
                                </a>
                            </li>
                            <li class="{{ ($path[0] === 'perceptions')?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.perceptions.index')}}">
                                Percepciones
                                </a>
                            </li>


                            <li class="nav-parent
                                {{ ($path[0] === 'order-forms')?'nav-active nav-expanded':'' }}
                                {{ ($path[0] === 'drivers')?'nav-active nav-expanded':'' }}
                                {{ ($path[0] === 'dispatchers')?'nav-active nav-expanded':'' }}
                                ">
                                <a class="nav-link" href="#">
                                        <span class="rail-label">Ordenes de pedido</span>
                                        @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                                    </a>
                                <ul class="nav-children">

                                    <li class="{{ ($path[0] === 'order-forms')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.order_forms.index')}}">
                                            Listado
                                        </a>
                                    </li>
                                    <li class="{{ ($path[0] === 'drivers')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.order_forms.drivers.index')}}">
                                            Conductores
                                        </a>
                                    </li>
                                    <li class="{{ ($path[0] === 'dispatchers')?'nav-active':'' }}">
                                        <a class="nav-link" href="{{route('tenant.order_forms.dispatchers.index')}}">
                                            Transportistas
                                        </a>
                                    </li>

                                </ul>
                            </li>


                        </ul>
                    </li>
                    @endif
@if($tnShowAnalyze)
                    <li class="nav-description">Analizar</li>
@endif
                    @if(in_array('reports', $vc_modules))
                    <li class="{{  ($path[0] === 'reports' && in_array($path[1], ['purchases', 'search','sales','customers','items', 'general-items','consistency-documents', 'quotations', 'sale-notes','cash','commissions','document-hotels', 'validate-documents', 'document-detractions','commercial-analysis', 'order-notes-consolidated', 'order-notes-general', 'sales-consolidated', 'user-commissions', 'fixed-asset-purchases', 'massive-downloads', 'sire'])) ? 'nav-active' : ''}} {{ $path[0] === 'list-reports' ? 'nav-active' : '' }}">
                        <a class="nav-link" data-tip="Reportes" href="{{ url('list-reports') }}">
                            @include('tenant.layouts.partials.icon', ['name' => 'chart-pie'])
                            <span class="rail-label">Reportes</span>
                        </a>
                    </li>
                    @endif
                    @if(in_array('accounting', $vc_modules))
                    <li class="
                        nav-parent
                        {{ ($path[0] === 'account')?'nav-active nav-expanded':'' }}
                        ">
                        <a class="nav-link" data-tip="Contabilidad" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'calculator'])
                            <span class="rail-label">Contabilidad</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Contabilidad">
                            <li class="{{(($path[0] === 'account') && ($path[1] === 'format')) ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{ route('tenant.account_format.index') }}">Exportar reporte</a>
                            </li>
                            <li class="{{(($path[0] === 'account') && ($path[1] == ''))   ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{ route('tenant.account.index') }}">Exportar formatos - Sis. Contable</a>
                            </li>
                            <li class="{{(($path[0] === 'account') && ($path[1] == 'summary-report'))   ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{ route('tenant.account_summary_report.index') }}">Reporte resumido - Ventas</a>
                            </li>
                        </ul>
                    </li>
                    @endif
                    @if(in_array('finance', $vc_modules))

                    <li class="nav-parent
                        {{ $path[0] === 'finances' && in_array($path[1], ['global-payments', 'balance','payment-method-types', 'unpaid', 'to-pay', 'income', 'movements']) ? 'nav-active nav-expanded' : ''}}"
                    >
                        <a class="nav-link" data-tip="Finanzas" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'wallet'])
                            <span class="rail-label">Finanzas</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Finanzas">
                            <li class="{{(($path[0] === 'finances') && ($path[1] == 'movements')) ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{route('tenant.finances.movements.index')}}">Movimientos</a>
                            </li>
                            <li class="{{(($path[0] === 'finances') && ($path[1] == 'income')) ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{route('tenant.finances.income.index')}}">Ingresos</a>
                            </li>
                            <li class="{{(($path[0] === 'finances') && ($path[1] == 'unpaid')) ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{route('tenant.finances.unpaid.index')}}">Cuentas por cobrar</a>
                            </li>
                            <li class="{{(($path[0] === 'finances') && ($path[1] == 'to-pay')) ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{route('tenant.finances.to_pay.index')}}">Cuentas por pagar</a>
                            </li>
                            <li class="{{(($path[0] === 'finances') && ($path[1] == 'global-payments')) ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{route('tenant.finances.global_payments.index')}}">Pagos</a>
                            </li>
                            <li class="{{(($path[0] === 'finances') && ($path[1] == 'balance')) ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{route('tenant.finances.balance.index')}}">Balance</a>
                            </li>
                            <li class="{{(($path[0] === 'finances') && ($path[1] == 'payment-method-types')) ? 'nav-active' : ''}}">
                                <a class="nav-link" href="{{route('tenant.finances.payment_method_types.index')}}">Ingresos y Egresos - M. Pago</a>
                            </li>
                        </ul>
                    </li>
                    @endif
                    @if(in_array('treasury', $vc_modules))
                    <li class="nav-parent
                        {{ $path[0] === 'treasury' ? 'nav-active nav-expanded' : '' }}"
                    >
                        <a class="nav-link" data-tip="Tesorería" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'building-2'])
                            <span class="rail-label">Tesorería</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Tesorería">
                            <li class="{{ ($path[0] === 'treasury' && (($path[1] ?? '') === '' || ($path[1] ?? '') === 'summary')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ route('tenant.treasury.dashboard.index') }}">Resumen</a>
                            </li>
                            <li class="{{ ($path[0] === 'treasury' && ($path[1] ?? '') === 'accounts') ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ route('tenant.treasury.accounts.index') }}">Cuentas</a>
                            </li>
                        </ul>
                    </li>
                    @endif
@if($tnShowAccount)
                    <li class="nav-description">Cuenta</li>
@endif
                    @if(in_array('configuration', $vc_modules))
                    <li class="{{in_array($path[0], ['list-platforms', 'list-cards', 'list-currencies', 'list-bank-accounts', 'list-banks', 'list-attributes', 'list-detractions', 'list-units', 'list-payment-methods', 'list-incomes', 'list-payments', 'company_accounts', 'list-vouchers-type',     'companies', 'advanced', 'tasks', 'inventories','bussiness_turns','offline-configurations','series-configurations','configurations', 'login-page', 'list-settings']) ? 'nav-active' : ''}}">
                        <a class="nav-link" data-tip="Configuración" href="{{ url('list-settings') }}">
                            @include('tenant.layouts.partials.icon', ['name' => 'settings-2'])
                            <span class="rail-label">Configuración</span>
                        </a>
                    </li>
                    @endif
                    @if(in_array('cuenta', $vc_modules))
                    <li class=" nav-parent
                        {{ ($path[0] === 'cuenta')?'nav-active nav-expanded':'' }}">
                        <a class="nav-link" data-tip="Mis pagos" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'credit-card'])
                            <span class="rail-label">Mis pagos</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Mis pagos">
                            <li class="{{ (($path[0] === 'cuenta') && ($path[1] === 'configuration')) ?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.configuration.index')}}">
                                    Configuracion
                                </a>
                            </li>
                            <li class="{{ (($path[0] === 'cuenta') && ($path[1] === 'payment_index')) ?'nav-active':'' }}">
                                <a class="nav-link" href="{{route('tenant.payment.index')}}">
                                    Lista de Pagos
                                </a>
                            </li>

                        </ul>
                    </li>
                    @endif
@if(in_array('hotels', $vc_modules) || in_array('documentary-procedure', $vc_modules))
                    <li class="nav-description">Módulos extras</li>
@endif
                    @if(in_array('hotels', $vc_modules))
                    <li class=" nav-parent
                        {{ ($path[0] === 'hotels') ? 'nav-active nav-expanded' : '' }}">
                        <a class="nav-link" data-tip="Hoteles" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'bed-double'])
                            <span class="rail-label">Hoteles</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Hoteles">
                            <li class="{{ (($path[0] === 'hotels') && ($path[1] === 'reception')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ url('hotels/reception') }}">Recepción</a>
                            </li>
                            <li class="{{ (($path[0] === 'hotels') && ($path[1] === 'rates')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ url('hotels/rates') }}">Tarifas</a>
                            </li>
                            <li class="{{ (($path[0] === 'hotels') && ($path[1] === 'floors')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ url('hotels/floors') }}">Pisos</a>
                            </li>
                            <li class="{{ (($path[0] === 'hotels') && ($path[1] === 'categories')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ url('hotels/categories') }}">Categorías</a>
                            </li>
                            <li class="{{ (($path[0] === 'hotels') && ($path[1] === 'rooms')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ url('hotels/rooms') }}">Habitaciones</a>
                            </li>
                        </ul>
                    </li>
                    @endif
                    @if(in_array('documentary-procedure', $vc_modules))
                    <li class=" nav-parent {{ ($path[0] === 'documentary-procedure') ? 'nav-active nav-expanded' : '' }}">
                        <a class="nav-link" data-tip="Trámite documentario" href="#">
                            @include('tenant.layouts.partials.icon', ['name' => 'folder-open'])
                            <span class="rail-label">Trámite documentario</span>
                            @include('tenant.layouts.partials.icon', ['name' => 'chevron-right', 'class' => 'chev'])
                        </a>
                        <ul class="nav-children" data-title="Trámite documentario">
                            <li class="{{ (($path[0] === 'documentary-procedure') && ($path[1] === 'offices')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ route('documentary.offices') }}">Oficinas</a>
                            </li>
                            <li class="{{ (($path[0] === 'documentary-procedure') && ($path[1] === 'processes')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ route('documentary.processes') }}">Procesos</a>
                            </li>
                            <li class="{{ (($path[0] === 'documentary-procedure') && ($path[1] === 'documents')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ route('documentary.documents') }}">Tipos de Documento</a>
                            </li>
                            <li class="{{ (($path[0] === 'documentary-procedure') && ($path[1] === 'actions')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ route('documentary.actions') }}">Acciones</a>
                            </li>
                            <li class="{{ (($path[0] === 'documentary-procedure') && ($path[1] === 'files')) ? 'nav-active' : '' }}">
                                <a class="nav-link" href="{{ route('documentary.files') }}">Expedientes</a>
                            </li>
                        </ul>
                    </li>
                    @endif
            </ul>
        </nav>
    </div>
    <button class="rail-toggle" id="railToggle" type="button" data-tip="Contraer menú" aria-label="Contraer menú">
        @include('tenant.layouts.partials.icon', ['name' => 'panel-left-close', 'class' => 'i-close'])
        @include('tenant.layouts.partials.icon', ['name' => 'panel-left-open', 'class' => 'i-open'])
        <span class="rail-label">Contraer menú</span>
    </button>
</aside>
<div class="rail-scrim" id="railScrim"></div>
<div class="rail-tip" id="railTip" role="tooltip"></div>
<script>
    // Restore the sidebar scroll position before first paint.
    (function () {
        try {
            var pos = localStorage.getItem('sidebar-left-position');
            var box = document.querySelector('#sidebar-left .nano-content');
            if (pos !== null && box) { box.scrollTop = pos; }
        } catch (e) {}
    })();
</script>
