{{-- Company favicon (uploaded in Empresa > Imágenes). tenant-shell.js keeps it in sync on in-app navigation. --}}
@if (!empty($vc_favicon_url))
    <link rel="icon" type="image/png" href="{{ $vc_favicon_url }}" data-tenant-favicon="1"/>
@endif
