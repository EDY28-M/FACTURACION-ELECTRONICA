<div class="dropdown cart-dropdown">
    @guest
        <a class="login-link sf-login-btn" href="#" data-toggle="modal" data-target="#login_register_modal">
            <i class="far fa-user"></i>
            <span class="d-none d-sm-inline">Ingresar</span>
        </a>
    @else
        <a href="#" class="dropdown-toggle" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"
            data-display="static" title="{{ Auth::user()->email }}">
            <i class="far fa-user"></i>
        </a>
        <div class="dropdown-menu">
            <div class="dropdownmenu-wrapper">
                <div class="dropdown-cart-total d-flex align-items-center justify-content-between" style="gap: 12px;">
                    <span style="font-size: 12.5px; word-break: break-all;">{{ Auth::user()->email }}</span>
                    <a href="#" role="menuitem" class="btn-logout text-danger" data-toggle="tooltip" data-placement="bottom"
                        title="Cerrar Sesión" onclick="event.preventDefault(); logout();" style="font-size: 16px;">
                        <i class="fas fa-power-off"></i>
                    </a>
                </div>
            </div>
        </div>
    @endguest
</div>
