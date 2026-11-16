<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sin acceso</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f4f5f7; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f2937; }
        .box { max-width: 420px; margin: 16px; padding: 32px; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); text-align: center; }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { margin: 0 0 20px; line-height: 1.5; color: #4b5563; }
        a { color: #0f5fa8; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="box">
        <h1>Sin acceso</h1>
        <p>{{ $message }} Solicite al administrador que habilite los módulos necesarios para su usuario.</p>
        <a href="{{ route('logout') }}" onclick="event.preventDefault(); document.getElementById('logout-form').submit();">Cerrar sesión</a>
        <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display:none">@csrf</form>
    </div>
</body>
</html>
