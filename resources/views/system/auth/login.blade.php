@extends('system.layouts.auth')

@section('content')
    @php
        $emailError = $errors->first('email');
        $passwordError = $errors->first('password');
    @endphp

    <main class="auth-shell">
        <section class="auth-card" aria-labelledby="auth-title">
            <header class="auth-header">
                <a class="auth-logo" href="{{ url('/') }}">
                    <span class="auth-logo-mark">
                        <svg viewBox="0 0 36 36" aria-hidden="true"><rect x="3" y="7" width="20" height="26" rx="4" fill="#2445D6"/><rect x="13" y="3" width="20" height="26" rx="4" fill="#DA3448"/><rect x="17.5" y="10" width="11" height="2.6" rx="1.3" fill="#fff"/><rect x="17.5" y="15.5" width="7" height="2.6" rx="1.3" fill="#fff"/></svg>
                    </span>
                    <span class="auth-logo-text">Facturador<strong>Pro</strong></span>
                </a>
                <h1 id="auth-title">Iniciar sesión</h1>
            </header>

            @if ($emailError || $passwordError)
                <div class="auth-alert" role="alert">
                    <span>{{ $emailError ?: $passwordError }}</span>
                </div>
            @endif

            <form class="auth-form" method="POST" action="{{ route('login') }}" novalidate>
                @csrf
                <div class="auth-field">
                    <label for="email">Correo electrónico</label>
                    <div class="auth-input {{ $emailError ? 'is-invalid' : '' }}">
                        <svg class="auth-icon auth-field-icon" aria-hidden="true"><use href="{{ asset('images/system-icons.svg') }}?v={{ filemtime(public_path('images/system-icons.svg')) }}#mail"></use></svg>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" autofocus required
                               placeholder="nombre@empresa.com" @if ($emailError) aria-invalid="true" aria-describedby="email-error" @endif>
                    </div>
                    @if ($emailError)
                        <p class="auth-error" id="email-error">{{ $emailError }}</p>
                    @endif
                </div>

                <div class="auth-field">
                    <label for="password">Contraseña</label>
                    <div class="auth-input {{ $passwordError ? 'is-invalid' : '' }}">
                        <svg class="auth-icon auth-field-icon" aria-hidden="true"><use href="{{ asset('images/system-icons.svg') }}?v={{ filemtime(public_path('images/system-icons.svg')) }}#lock-keyhole"></use></svg>
                        <input id="password" type="password" name="password" autocomplete="current-password" required
                               placeholder="Ingresa tu contraseña"
                               @if ($passwordError) aria-invalid="true" aria-describedby="password-error" @endif>
                        <button type="button" class="auth-toggle" data-toggle-password aria-label="Mostrar contraseña" aria-pressed="false">
                            <svg class="auth-icon" aria-hidden="true"><use href="{{ asset('images/system-icons.svg') }}?v={{ filemtime(public_path('images/system-icons.svg')) }}#eye"></use></svg>
                        </button>
                    </div>
                    @if ($passwordError)
                        <p class="auth-error" id="password-error">{{ $passwordError }}</p>
                    @endif
                </div>

                <label class="auth-check" for="RememberMe">
                    <input name="remember" id="RememberMe" type="checkbox" {{ old('remember') ? 'checked' : '' }}>
                    <span>Recordarme en este equipo</span>
                </label>

                <button type="submit" class="auth-submit">
                    <span>Iniciar sesión</span>
                    <svg class="auth-icon auth-submit-arrow" aria-hidden="true"><use href="{{ asset('images/system-icons.svg') }}?v={{ filemtime(public_path('images/system-icons.svg')) }}#arrow-right"></use></svg>
                </button>
            </form>

            <footer class="auth-card-footer">
                <span class="auth-status">
                    <span class="auth-status-dot" aria-hidden="true"></span>
                    Panel de administración
                </span>
                <span class="auth-badge">
                    <svg class="auth-icon" aria-hidden="true"><use href="{{ asset('images/system-icons.svg') }}?v={{ filemtime(public_path('images/system-icons.svg')) }}#shield-check"></use></svg>
                    Acceso seguro
                </span>
            </footer>
        </section>
    </main>
@endsection

@push('scripts')
    <script>
        (function () {
            var button = document.querySelector('[data-toggle-password]');
            var input = document.getElementById('password');
            if (!button || !input) return;
            var icon = button.querySelector('use');
            var base = icon.getAttribute('href').split('#')[0];
            button.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-pressed', String(show));
                button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
                icon.setAttribute('href', base + (show ? '#eye-off' : '#eye'));
            });
        })();
    </script>
@endpush
