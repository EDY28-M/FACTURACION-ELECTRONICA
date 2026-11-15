@extends('tenant.layouts.app')

@section('content')
<div class="page-header pr-0">
    <h2>
        <a href="/dashboard"><i class="fas fa-home"></i></a>
    </h2>
    <ol class="breadcrumbs">
        <li><a href="{{ url('list-reports') }}">Reportes</a></li>
        <li class="active"><span>SIRE - Registro de ventas (RVIE)</span></li>
    </ol>
</div>

<div class="card card-dashboard" id="sire-rvie">
    <div class="card-body">
        <h6 class="card-title">Reemplazo de la propuesta RVIE</h6>
        <p class="text-muted mb-3">
            Genera el archivo plano con tus comprobantes emitidos del periodo para reemplazar la propuesta de SUNAT.
        </p>

        <div class="form-row align-items-end mb-3">
            <div class="col-12 col-md-3">
                <label for="sire-period" class="mb-1">Periodo</label>
                <input type="month" id="sire-period" class="form-control" max="{{ now()->format('Y-m') }}" value="{{ now()->subMonthNoOverflow()->format('Y-m') }}">
            </div>
            <div class="col-12 col-md-auto mt-2 mt-md-0">
                <button type="button" class="btn btn-primary" id="sire-preview">Vista previa</button>
                <a class="btn btn-success disabled" id="sire-download" href="#" aria-disabled="true">Descargar ZIP para SIRE</a>
            </div>
        </div>

        <div id="sire-error" class="alert alert-danger d-none" role="alert"></div>

        <div id="sire-result" class="d-none">
            <p class="mb-2"><strong>Archivo:</strong> <span id="sire-filename"></span> &middot; <span id="sire-rows"></span> registro(s)</p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th class="text-right">Cantidad</th>
                            <th class="text-right">BI gravada</th>
                            <th class="text-right">IGV</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody id="sire-types"></tbody>
                    <tfoot id="sire-foot"></tfoot>
                </table>
            </div>
            <div id="sire-errors-box" class="alert alert-danger d-none" role="alert">
                <strong>Corrige estos comprobantes antes de descargar:</strong>
                <ul id="sire-errors" class="mb-0 mt-1"></ul>
            </div>
            <div id="sire-demo-box" class="form-check mb-3 d-none">
                <input type="checkbox" class="form-check-input" id="sire-force-demo">
                <label class="form-check-label" for="sire-force-demo">La empresa esta en ambiente de pruebas. Descargar el archivo de todas formas.</label>
            </div>
            <div id="sire-warnings-box" class="d-none">
                <h6>Advertencias</h6>
                <ul id="sire-warnings" class="mb-3"></ul>
            </div>
        </div>

        <div class="alert alert-info mb-0" role="note">
            Sube este archivo en SUNAT SOL &gt; SIRE &gt; RVIE &gt; Reemplazar propuesta. El registro se genera en SOL.
            Los importes se informan en soles; los comprobantes anulados se informan en cero; las notas de credito con importes negativos.
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var names = {'01': 'Factura', '03': 'Boleta', '07': 'Nota de credito', '08': 'Nota de debito'};
    var $ = function (id) { return document.getElementById(id); };
    var money = function (n) { return Number(n).toLocaleString('es-PE', {minimumFractionDigits: 2, maximumFractionDigits: 2}); };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    var base = "{{ route('tenant.reports.sire_rvie.preview') }}";
    var dl = "{{ route('tenant.reports.sire_rvie.download') }}";

    var canDownload = false;
    var demo = false;

    function refreshDownload() {
        var ok = canDownload && (!demo || $('sire-force-demo').checked);
        var a = $('sire-download');
        a.classList.toggle('disabled', !ok);
        if (ok) {
            a.removeAttribute('aria-disabled');
            a.setAttribute('href', dl + '?period=' + period() + (demo ? '&force_demo=1' : ''));
        } else {
            a.setAttribute('aria-disabled', 'true');
            a.setAttribute('href', '#');
        }
    }

    function period() { return $('sire-period').value.replace('-', ''); }

    function showError(msg) {
        $('sire-error').textContent = msg;
        $('sire-error').classList.remove('d-none');
        $('sire-result').classList.add('d-none');
        canDownload = false;
        refreshDownload();
    }

    $('sire-preview').addEventListener('click', function () {
        var btn = this;
        $('sire-error').classList.add('d-none');
        if (!/^\d{6}$/.test(period())) { return showError('Selecciona un periodo valido.'); }
        btn.disabled = true;
        fetch(base + '?period=' + period(), {headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'})
            .then(function (r) { return r.json().then(function (j) { return {ok: r.ok, body: j}; }); })
            .then(function (res) {
                if (!res.ok || !res.body.success) { return showError(res.body.message || 'No se pudo generar la vista previa.'); }
                var d = res.body;
                $('sire-filename').textContent = d.filename;
                $('sire-rows').textContent = d.rows_count;
                $('sire-types').innerHTML = d.totals.by_type.map(function (t) {
                    return '<tr><td>' + esc((names[t.type] || t.type) + ' (' + t.type + ')') + '</td><td class="text-right">' + t.count
                        + (t.voided ? ' <small class="text-muted">(' + t.voided + ' anulado(s))</small>' : '') + '</td><td class="text-right">'
                        + money(t.taxed) + '</td><td class="text-right">' + money(t.igv) + '</td><td class="text-right">' + money(t.total) + '</td></tr>';
                }).join('') || '<tr><td colspan="5" class="text-center text-muted">Sin comprobantes en el periodo. Se generara un archivo vacio.</td></tr>';
                $('sire-foot').innerHTML = '<tr><th>Total</th><th class="text-right">' + d.rows_count + '</th><th class="text-right">'
                    + money(d.totals.taxed) + '</th><th class="text-right">' + money(d.totals.igv) + '</th><th class="text-right">' + money(d.totals.total) + '</th></tr>';
                $('sire-warnings').innerHTML = d.warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('');
                $('sire-warnings-box').classList.toggle('d-none', d.warnings.length === 0);
                $('sire-errors').innerHTML = (d.errors || []).map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('');
                $('sire-errors-box').classList.toggle('d-none', !(d.errors && d.errors.length));
                demo = !!d.demo;
                $('sire-force-demo').checked = false;
                $('sire-demo-box').classList.toggle('d-none', !demo);
                $('sire-result').classList.remove('d-none');
                canDownload = !(d.errors && d.errors.length);
                refreshDownload();
            })
            .catch(function () { showError('No se pudo generar la vista previa.'); })
            .then(function () { btn.disabled = false; });
    });

    $('sire-period').addEventListener('change', function () {
        $('sire-result').classList.add('d-none');
        canDownload = false;
        refreshDownload();
    });
    $('sire-force-demo').addEventListener('change', refreshDownload);
    $('sire-download').addEventListener('click', function (e) { if (this.classList.contains('disabled')) { e.preventDefault(); } });
})();
</script>
@endpush
