@php
    $contact_address = trim((string) ($information->information_contact_address ?? ''));
    $contact_phone = trim((string) ($information->information_contact_phone ?? ''));
    $contact_email = trim((string) ($information->information_contact_email ?? ''));
    $has_social = !empty($information->link_facebook) || !empty($information->link_twitter) || !empty($information->link_youtube);
@endphp

<div class="sf-footer-main">
    <div class="sf-container">
        <div class="sf-footer-grid">
            <div>
                <h4 class="sf-footer-brand">{{ $storeName ?? 'Tienda virtual' }}</h4>
                <p class="sf-footer-desc">
                    {{ !empty($information->tag_support) ? $information->tag_support : 'Catálogo en línea actualizado con atención directa y despacho rápido.' }}
                </p>
                @if($has_social)
                    <div class="sf-footer-socials">
                        @if($information->link_facebook)
                            <a href="{{ $information->link_facebook }}" class="sf-footer-social-btn" target="_blank" rel="noopener noreferrer" aria-label="Facebook">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                        @endif
                        @if($information->link_twitter)
                            <a href="{{ $information->link_twitter }}" class="sf-footer-social-btn" target="_blank" rel="noopener noreferrer" aria-label="Twitter">
                                <i class="fab fa-twitter"></i>
                            </a>
                        @endif
                        @if($information->link_youtube)
                            <a href="{{ $information->link_youtube }}" class="sf-footer-social-btn" target="_blank" rel="noopener noreferrer" aria-label="YouTube">
                                <i class="fab fa-youtube"></i>
                            </a>
                        @endif
                    </div>
                @endif
            </div>

            <div>
                <h5 class="sf-footer-heading">Contacto</h5>
                <ul class="sf-footer-list">
                    @if($contact_address !== '')
                        <li><i class="fas fa-map-marker-alt mr-2"></i> {{ $contact_address }}</li>
                    @endif
                    @if($contact_phone !== '')
                        <li>
                            <i class="fab fa-whatsapp mr-2"></i>
                            <a href="{{ $whatsappUrl ?? ('tel:'.preg_replace('/[^0-9+]/', '', $contact_phone)) }}"
                               @if(!empty($whatsappUrl)) target="_blank" rel="noopener noreferrer" @endif>
                                {{ $contact_phone }}
                            </a>
                        </li>
                    @endif
                    @if($contact_email !== '' && $contact_email !== 'admin@mail.com')
                        <li>
                            <i class="far fa-envelope mr-2"></i>
                            <a href="mailto:{{ $contact_email }}">{{ $contact_email }}</a>
                        </li>
                    @endif
                </ul>
            </div>

            <div>
                <h5 class="sf-footer-heading">Enlaces rápidos</h5>
                <ul class="sf-footer-list">
                    <li><a href="{{ route('tenant.ecommerce.index') }}">Inicio / Catálogo</a></li>
                    <li><a href="{{ route('tenant_detail_cart') }}">Ver Carrito</a></li>
                    @guest
                        <li><a href="#" class="login-link" data-toggle="modal" data-target="#login_register_modal">Iniciar sesión / Registro</a></li>
                    @else
                        <li>
                            <a role="menuitem" href="{{ route('logout') }}" class="login-link"
                               onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                                Cerrar sesión
                            </a>
                            <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                                @csrf
                            </form>
                        </li>
                    @endguest
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="sf-footer-bottom">
    <div class="sf-container sf-footer-bottom-inner">
        <span>&copy; {{ date('Y') }} {{ $storeName ?? 'Tienda virtual' }}. Todos los derechos reservados.</span>
        @if(!empty($cardEnabled))
            <img src="{{ asset('porto-ecommerce/assets/images/payments.png') }}" alt="Medios de pago con tarjeta" class="footer-payments" style="max-height: 24px;">
        @endif
    </div>
</div>

@if(!empty($whatsappUrl))
    <a href="{{ $whatsappUrl }}?text={{ rawurlencode('Hola '.($storeName ?? '').', deseo información sobre sus productos.') }}"
       class="sf-wa-float"
       target="_blank"
       rel="noopener noreferrer"
       title="Escríbenos por WhatsApp"
       aria-label="Escríbenos por WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>
@endif

<div class="modal fade" id="moda-succes-add-product" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-body p-4">
                <div class="alert alert-success mb-3" role="alert">
                    <i class="icon-ok"></i> Tu producto se agregó al carrito
                </div>
                <div class="row align-items-center">
                    <div id="product_added_image" class="col-md-4 text-center"></div>
                    <div class="col-md-8">
                        <div id="product_added" class="product-single-details"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="{{ route('tenant_detail_cart') }}" class="btn btn-primary text-white">Ir al Carrito</a>
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Seguir Comprando</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-already-product" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-body p-4">
                <div class="alert alert-warning mb-0" role="alert" style="font-size: 15px;">
                    <i class="fas fa-exclamation-circle mr-2"></i> Este producto ya está agregado en tu carrito.
                </div>
            </div>
            <div class="modal-footer">
                <a href="{{ route('tenant_detail_cart') }}" class="btn btn-primary text-white">Ir al Carrito</a>
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Seguir Comprando</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="login_register_modal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title font-weight-bold" style="color: #144291;">Mi Cuenta</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <div class="container-fluid px-0">
                    <div class="row">
                        <div class="col-md-5 pr-md-4 border-right">
                            <h4 class="title mb-3" style="font-size: 18px; font-weight: 700;">Iniciar Sesión</h4>

                            <div id="msg_login" class="alert alert-danger" role="alert">
                                Usuario o Contraseña Incorrectos.
                            </div>

                            <form action="#" id="form_login">
                                <div class="form-group">
                                    <label for="email">Correo Electrónico:</label>
                                    <input type="email" required class="form-control" id="email"
                                        placeholder="correo@ejemplo.com" name="email">
                                </div>
                                <div class="form-group">
                                    <label for="pwd">Contraseña:</label>
                                    <input type="password" required class="form-control" id="pwd"
                                        placeholder="Ingrese su contraseña" name="password">
                                </div>

                                <button type="submit" class="btn btn-primary btn-block">Ingresar</button>
                            </form>
                        </div>
                        <div class="col-md-7 pl-md-4 mt-4 mt-md-0">
                            <h4 class="title mb-3" style="font-size: 18px; font-weight: 700;">Nuevo Registro</h4>
                            <div id="msg_register" class="alert alert-danger" role="alert">
                                <p id="msg_register_p" class="mb-0"></p>
                            </div>

                            <form autocomplete="off" action="#" id="form_register">
                                <div class="form-group">
                                    <label for="name_reg">Nombres:</label>
                                    <input type="text" required autocomplete="off" class="form-control" id="name_reg"
                                        placeholder="Ingrese su nombre" name="name">
                                </div>
                                <div class="form-group">
                                    <label for="email_reg">Correo Electrónico:</label>
                                    <input type="email" required autocomplete="off" class="form-control" id="email_reg"
                                        placeholder="correo@ejemplo.com" name="email">
                                </div>
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="pwd_reg">Contraseña:</label>
                                        <input type="password" required autocomplete="off" class="form-control" id="pwd_reg"
                                            placeholder="Mínimo 8 caracteres" name="pswd">
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="pwd_repeat_reg">Repita la Contraseña:</label>
                                        <input type="password" required autocomplete="off" class="form-control"
                                            id="pwd_repeat_reg" placeholder="Repita contraseña" name="pswd_rpt">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block">Registrarse</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script type="text/javascript" src="{{ asset('porto-ecommerce/assets/js/cart.js') }}"></script>
<script type="text/javascript">
    matchPassword();
    submitLogin();
    submitRegister();

    function matchPassword() {
        var password = document.getElementById("pwd_reg"),
            confirm_password = document.getElementById("pwd_repeat_reg");

        function validatePassword() {
            if (password.value != confirm_password.value) {
                confirm_password.setCustomValidity("El Password no coincide.");
            } else {
                confirm_password.setCustomValidity('');
            }
        }

        password.onchange = validatePassword;
        confirm_password.onkeyup = validatePassword;
    }

    function submitLogin() {
        $('#msg_login').hide();

        $('#form_login').submit(function (e) {
            e.preventDefault()
            $.ajax({
                type: "POST",
                dataType: 'JSON',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                url: "{{route('tenant_ecommerce_login')}}",
                data: $(this).serialize(),
                success: function (data) {
                    if (data.success) {
                        location.reload();
                    } else {
                        $('#msg_login').text('Usuario o contraseña incorrectos.').show();
                    }
                },
                error: function (error_data) {
                    $('#msg_login').text(requestErrorMessage(error_data)).show();
                }
            });
        })
    }

    // Human readable message for a failed AJAX call (rate limit, expired session, network, server error).
    function requestErrorMessage(error_data) {
        var status = error_data && error_data.status;
        if (status === 429) return 'Demasiados intentos. Espere un minuto e intente nuevamente.';
        if (status === 419) return 'Su sesión expiró. Recargue la página e intente nuevamente.';
        if (status === 0) return 'No hay conexión. Revise su internet e intente nuevamente.';
        return 'No se pudo completar la solicitud. Intente nuevamente.';
    }

    function submitRegister() {
        $('#msg_register').hide();

        $('#form_register').submit(function (e) {
            e.preventDefault()
            $.ajax({
                type: "POST",
                dataType: 'JSON',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                url: "{{route('tenant_ecommerce_store_user')}}",
                data: $(this).serialize(),
                success: function (data) {
                    if (data.success) {
                        location.reload();
                    } else {
                        $('#msg_register').show();
                        $('#msg_register_p').text(data.message)
                    }
                },
                error: function (error_data) {
                    $('#msg_register').show();
                    $('#msg_register_p').text(requestErrorMessage(error_data))
                }
            });
        })
    }
</script>
@endpush
