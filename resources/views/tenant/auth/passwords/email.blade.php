@extends('tenant.layouts.auth')

@section('content')
<section class="auth">
    @include('tenant.auth.partials.side_left')
    <article class="auth__form">
        <form class="form-material" id="loginform" method="POST" action="{{ route('password.email') }}">
            @include('tenant.auth.partials.form_logo')
            <h1 class="auth__title">Recupera tu contraseña</h1>
            <p>Escribe tu correo y te enviaremos un enlace para crear una nueva.</p>
            @if (session('status'))
                <br>
                <div class="alert alert-success" role="alert">
                    {{ session('status') }}
                </div>
            @endif
            @csrf

            <div class="form-group">
                <label for="email">Correo electrónico</label>
                <div class="">
                    <input id="email" type="email" class="form-control{{ $errors->has('email') ? ' is-invalid' : '' }}" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" placeholder="tu@empresa.pe">

                    @if ($errors->has('email'))
                        <span class="invalid-feedback" role="alert">
                            <strong>{{ $errors->first('email') }}</strong>
                        </span>
                    @endif
                </div>
            </div>
            <div class="form-group">
                <button class="btn btn-signin btn-block" type="submit">Enviar enlace</button>
                <br>
                <a href="{{ url('login') }}" class="btn btn-link">
                    Volver a iniciar sesión
                </a>
            </div>
            @include('tenant.auth.partials.socials')
        </form>
    </article>
</section>
@endsection
