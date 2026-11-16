{{-- Stage beside the sign-in form: the tenant's own background image when one was uploaded
     (Configuración del login > Cambiar imagen de fondo), otherwise the animated illustration. --}}
@php
    $tnBgPath = (string) parse_url($login->image ?? '', PHP_URL_PATH);
    $tnStageImage = (($login->type ?? null) === 'image' && $tnBgPath !== '' && !\Illuminate\Support\Str::endsWith($tnBgPath, '/images/login-v2.svg'))
        ? $login->image
        : null;

    // Company logo in the corner chosen in "Posición del logo de la empresa"
    $tnStageLogo = $useLoginGlobal
        ? ($login->logo ?? null)
        : ($company->logo ? asset('storage/uploads/logos/' . $company->logo) : null);
    $tnLogoPos = in_array($login->position_logo ?? '', ['top-left', 'top-right', 'bottom-left', 'bottom-right'])
        ? $login->position_logo
        : 'top-left';
    $tnBrandName = $company->trade_name ?: $company->name;
@endphp
<article class="auth__image tn-stage" aria-hidden="true">
    <div class="tn-stage__top">
        @if ($tnStageLogo && $tnLogoPos === 'top-left')
            <img class="tn-stage__logo" src="{{ $tnStageLogo }}" alt="{{ $tnBrandName }}" />
        @else
            <div class="tn-stage__mark">
                <svg viewBox="0 0 24 24" fill="none"><rect x="3" y="2.5" width="18" height="19" rx="5" fill="#2F6FDF"/><path d="M8 9h8M8 13h5" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>
                Facturación electrónica
            </div>
        @endif
        @if ($tnStageLogo && $tnLogoPos === 'top-right')
            <img class="tn-stage__logo" src="{{ $tnStageLogo }}" alt="{{ $tnBrandName }}" />
        @endif
    </div>

    @if ($tnStageImage)
        <img class="tn-stage__image" src="{{ $tnStageImage }}" alt="" />
    @else
        {{-- Lottie scene: "Business team brainstorming" by Abdul Latif (LottieFiles, Lottie Simple License), recoloured to the portal blue --}}
        <div class="tn-stage__scene" id="tnLoginScene" data-src="{{ asset('animations/login-team.json') }}"></div>
    @endif

    <div class="tn-stage__bottom">
        <div class="tn-stage__caption">
            <h2>Cada comprobante, emitido y aceptado por SUNAT.</h2>
            <p>Tus facturas, boletas y notas en un solo lugar, con su constancia de recepción.</p>
            @if ($tnStageLogo && $tnLogoPos === 'bottom-left')
                <img class="tn-stage__logo tn-stage__logo--below" src="{{ $tnStageLogo }}" alt="{{ $tnBrandName }}" />
            @endif
        </div>
        @if ($tnStageLogo && $tnLogoPos === 'bottom-right')
            <img class="tn-stage__logo" src="{{ $tnStageLogo }}" alt="{{ $tnBrandName }}" />
        @endif
    </div>
</article>
@unless ($tnStageImage)
<script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie_light.min.js" defer></script>
<script>
    window.addEventListener('DOMContentLoaded', function () {
        var box = document.getElementById('tnLoginScene');
        if (!box || !window.lottie) return;
        var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var anim = window.lottie.loadAnimation({
            container: box,
            renderer: 'svg',
            loop: !still,
            autoplay: !still,
            path: box.getAttribute('data-src'),
            rendererSettings: { preserveAspectRatio: 'xMidYMid meet', progressiveLoad: true }
        });
        anim.addEventListener('DOMLoaded', function () {
            if (still) anim.goToAndStop(150, true);
            box.classList.add('is-ready');
        });
    });
</script>
@endunless
