@php
    $path = request()->segment(1);
    $clientCount = \App\Models\System\Client::count();
    $certificate = optional(\App\Models\System\Configuration::first())->certificate;
    $adminName = trim(Auth::user()->name);
    $initials = collect(preg_split('/\s+/', $adminName))->filter()->take(2)->map(function ($part) { return mb_strtoupper(mb_substr($part, 0, 1)); })->implode('');
    $navigation = [
        ['dashboard', 'Dashboard', 'layout-dashboard', route('system.dashboard')],
        ['clients', 'Clientes', 'building-2', url('clients')],
        ['plans', 'Planes', 'package', route('system.plans.index')],
        ['accounting', 'Contabilidad', 'calculator', route('system.accounting.index')],
        ['backup', 'Backup', 'database-backup', route('system.backup')],
        ['logs', 'Logs', 'scroll-text', url('logs')],
        ['auto-update', 'Actualizaciones', 'refresh-cw', route('system.update')],
        ['configurations', 'Configuración', 'settings', route('system.configuration.index')],
    ];
@endphp
<aside id="sidebar-left" class="sidebar-left system-sidebar" aria-label="Menú principal">
    <div class="system-mobile-sidebar-actions">
        <button type="button" class="system-mobile-close" data-system-close aria-label="Cerrar menú">Cerrar menú @include('system.layouts.partials.icon', ['name' => 'x'])</button>
    </div>
    <a href="{{ route('system.dashboard') }}" class="system-brand">
        <svg class="system-brand-mark" viewBox="0 0 36 36" aria-hidden="true"><rect x="3" y="7" width="20" height="26" rx="4" fill="#2F4FE0"/><rect x="13" y="3" width="20" height="26" rx="4" fill="#DA3448"/><rect x="17.5" y="10" width="11" height="2.6" rx="1.3" fill="white"/><rect x="17.5" y="15.5" width="7" height="2.6" rx="1.3" fill="white"/></svg>
        <span class="system-brand-copy"><span>Facturador<strong>Pro</strong></span><small>Panel de administración</small></span>
    </a>
    <nav class="system-navigation" aria-label="Administración">
        @foreach ($navigation as $item)
            @if ($loop->first)<span class="system-nav-group"><span>General</span></span>@endif
            @if ($item[0] === 'accounting')<span class="system-nav-group"><span>Sistema</span></span>@endif
            <a href="{{ $item[3] }}" data-nav-segment="{{ $item[0] }}" class="system-nav-link {{ $path === $item[0] ? 'is-active' : '' }}" title="{{ $item[1] }}" @if ($path === $item[0]) aria-current="page" @endif>
                @include('system.layouts.partials.icon', ['name' => $item[2]])
                <span class="system-nav-label">{{ $item[1] }}</span>
                @if ($item[0] === 'clients')<span class="system-nav-count">{{ $clientCount }}</span>@endif
            </a>
        @endforeach
    </nav>
    <div class="system-sidebar-footer">
        <a href="{{ route('system.configuration.index') }}" class="system-certificate" title="Certificado PSE: {{ $certificate ? 'configurado' : 'sin configurar' }}">
            <i class="system-cert-dot {{ $certificate ? 'is-ok' : 'is-off' }}" aria-hidden="true"></i>
            <span class="system-nav-label"><strong>Certificado PSE</strong><small>{{ $certificate ? 'Configurado' : 'Sin configurar' }}</small></span>
        </a>
        <div class="system-user">
            <a href="{{ route('system.users.create') }}" data-nav-segment="users" class="system-user-link {{ $path === 'users' ? 'is-active' : '' }}" title="Mi perfil" @if ($path === 'users') aria-current="page" @endif>
                <span class="system-user-avatar" aria-hidden="true">{{ $initials ?: 'A' }}</span>
                <span class="system-nav-label"><strong>{{ $adminName }}</strong><small>Administrador</small></span>
            </a>
            <button type="submit" form="logout-form" class="system-user-logout" aria-label="Cerrar sesión" title="Cerrar sesión">@include('system.layouts.partials.icon', ['name' => 'log-out'])</button>
            <form id="logout-form" action="{{ route('logout') }}" method="POST" hidden>@csrf</form>
        </div>
        <button type="button" class="system-collapse" data-system-collapse aria-label="Contraer menú" aria-expanded="true">
            @include('system.layouts.partials.icon', ['name' => 'panel-left-close'])<span>Contraer menú</span>
        </button>
    </div>
</aside>
<button type="button" class="system-menu-overlay" data-system-menu aria-label="Cerrar menú desde el fondo" tabindex="-1"></button>
