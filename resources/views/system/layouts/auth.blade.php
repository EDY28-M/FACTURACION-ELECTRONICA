<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="system-auth">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Acceso al sistema | FacturadorPro</title>

    <link rel="stylesheet" href="{{ asset('css/system-auth.css') }}?v={{ filemtime(public_path('css/system-auth.css')) }}" />
</head>

<body>
    @yield('content')

    @stack('scripts')
</body>

</html>
