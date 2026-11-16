@extends('tenant.layouts.auth')

@section('content')
<section class="auth auth__form-{{ $login->position_form }}">
    @include('tenant.auth.partials.side_left')
    <article class="auth__form">
        <form method="POST" action="{{ route('login') }}" novalidate>
            @csrf
            @include('tenant.auth.partials.form_logo')
            <h1 class="auth__title">{{ $company->trade_name ?: $company->name }}</h1>
            <p class="tn-auth__lede">Ingresa a tu cuenta para emitir y revisar tus comprobantes.</p>

            <div class="form-group">
                <label for="email">Correo electrónico</label>
                <input type="email" name="email" id="email" autocomplete="username" class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}" value="{{ old('email') }}" autofocus>
                @if ($errors->has('email'))
                <div class="invalid-feedback">{{ $errors->first('email') }}</div>
                @endif
            </div>
            <div class="form-group">
                <div class="tn-auth__label-row">
                    <label for="password">Contraseña</label>
                    <a href="{{ url('password/reset') }}">¿La olvidaste?</a>
                </div>
                <div class="tn-auth__input">
                    <input type="password" name="password" id="password" autocomplete="current-password" class="form-control {{ $errors->has('password') ? 'is-invalid' : '' }}">
                    <button type="button" class="btn-eye" id="btnEye" aria-label="Mostrar contraseña" aria-pressed="false">
                        <svg class="i-show" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="i-hide" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 6.6C3.7 8.5 2 12 2 12s3.6 7 10 7a9.6 9.6 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                    </button>
                </div>
                @if ($errors->has('password'))
                <div class="invalid-feedback d-block">{{ $errors->first('password') }}</div>
                @endif
            </div>
            <label class="tn-auth__remember"><input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}> Recordarme en este equipo</label>
            <button type="submit" class="btn btn-signin btn-block">
                Ingresar
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
            <p class="tn-auth__foot">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                Conexión cifrada · Facturación electrónica SUNAT
            </p>
            @include('tenant.auth.partials.socials')
        </form>
    </article>
</section>
@endsection
@push('scripts')
    <script>
        (function () {
            var input = document.getElementById('password');
            var eye = document.getElementById('btnEye');
            eye.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                eye.setAttribute('aria-pressed', show ? 'true' : 'false');
                eye.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
                input.focus();
            });
        })();
    </script>
@endpush
