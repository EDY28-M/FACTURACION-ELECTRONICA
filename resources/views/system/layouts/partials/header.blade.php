<header class="header system-header">
    <button type="button" class="system-mobile-menu" data-system-menu aria-label="Abrir menú" aria-controls="sidebar-left" aria-expanded="false">@include('system.layouts.partials.icon', ['name' => 'panel-left-open'])</button>
    <form class="system-search" action="{{ url('clients') }}" method="GET" role="search">
        @include('system.layouts.partials.icon', ['name' => 'search'])
        <input name="search" type="search" value="{{ request('search') }}" placeholder="Buscar cliente por nombre, RUC o dominio" aria-label="Buscar cliente">
    </form>
    <time class="system-date-chip" data-system-date></time>
</header>
