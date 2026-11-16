{{-- Brand mark above the form: the configured logo when one exists, never a placeholder --}}
@php
    $tnFormLogo = null;
    if ($login->show_logo_in_form ?? false) {
        if ($useLoginGlobal) {
            $tnFormLogo = $login->logo ?? null;
        } elseif ($company->logo) {
            $tnFormLogo = asset('storage/uploads/logos/' . $company->logo);
        }
    }
@endphp
@if ($tnFormLogo)
    <img class="auth__logo-form" src="{{ $tnFormLogo }}" alt="{{ $company->trade_name ?: $company->name }}" />
@endif
