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

<div class="card card-dashboard mt-3" id="sire-sunat">
    <div class="card-body">
        <h6 class="card-title">Comparar con SUNAT (API SIRE)</h6>
        <p class="text-muted mb-3">
            Descarga la propuesta RVIE de SUNAT del periodo seleccionado y la compara con tus ventas. Es solo lectura:
            no se envía, acepta ni reemplaza nada en SUNAT.
        </p>

        <div id="sunat-config" class="alert alert-warning d-none" role="alert">
            <span id="sunat-config-msg"></span>
            <a href="{{ route('tenant.companies.create') }}" class="alert-link">Ir a la configuración de la empresa</a>
        </div>
        <div id="sunat-demo" class="alert alert-info d-none" role="note">
            La empresa está en ambiente de pruebas. La API de SIRE de SUNAT no tiene ambiente de pruebas y solo responde con credenciales y datos de producción.
        </div>
        <div id="sunat-error" class="alert alert-danger d-none" role="alert"></div>
        <div id="sunat-info" class="alert alert-success d-none" role="status"></div>

        <div class="mb-3">
            <button type="button" class="btn btn-primary" id="sunat-request">Solicitar propuesta a SUNAT</button>
            <button type="button" class="btn btn-outline-secondary" id="sunat-reload">Actualizar lista</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Solicitada</th>
                        <th>Ticket</th>
                        <th>Estado</th>
                        <th>Detalle</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="sunat-requests"><tr><td colspan="5" class="text-center text-muted">Selecciona un periodo para ver sus solicitudes.</td></tr></tbody>
            </table>
        </div>

        <div id="sunat-result" class="d-none mt-3"></div>
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
    // ---- Comparison with SUNAT (SIRE API) ----
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var sunatBase = "{{ route('tenant.reports.sire_rvie.sunat.index') }}";
    var statusClass = {requested: 'badge-secondary', processing: 'badge-warning', ready: 'badge-info', failed: 'badge-danger', reconciled: 'badge-success'};
    var typeNames = {'01': 'Factura', '03': 'Boleta', '07': 'N. crédito', '08': 'N. débito'};

    function sunatMsg(id, msg) {
        var el = $(id);
        el.textContent = msg || '';
        el.classList.toggle('d-none', !msg);
    }

    function sunatCall(url, method) {
        var opts = {method: method || 'GET', credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
        if (method === 'POST') { opts.headers['X-CSRF-TOKEN'] = csrf; }
        return fetch(url, opts).then(function (r) {
            return r.json().then(function (j) { return {ok: r.ok, body: j}; }, function () { return {ok: false, body: {message: 'Respuesta no válida del servidor.'}}; });
        });
    }

    function loadRequests() {
        sunatMsg('sunat-error', '');
        if (!/^\d{6}$/.test(period())) { return; }
        sunatCall(sunatBase + '?period=' + period()).then(function (res) {
            if (!res.ok || !res.body.success) { return sunatMsg('sunat-error', res.body.message || 'No se pudo cargar las solicitudes.'); }
            var d = res.body;
            $('sunat-config').classList.toggle('d-none', d.configured);
            $('sunat-config-msg').textContent = d.config_message || '';
            $('sunat-demo').classList.toggle('d-none', !d.demo);
            $('sunat-request').disabled = !d.configured;
            $('sunat-requests').innerHTML = d.requests.map(function (r) {
                var actions = '';
                if (r.open) { actions += '<button type="button" class="btn btn-sm btn-outline-primary" data-act="refresh" data-id="' + esc(String(r.id)) + '">Actualizar estado</button>'; }
                if (r.has_summary) { actions += ' <button type="button" class="btn btn-sm btn-outline-success" data-act="show" data-id="' + esc(String(r.id)) + '">Ver resultado</button>'; }
                return '<tr><td>' + esc(r.requested_at || '') + '</td><td>' + esc(r.ticket || '-') + '</td><td><span class="badge '
                    + (statusClass[r.status] || 'badge-secondary') + '">' + esc(r.status_label) + '</span></td><td>' + esc(r.status_detail || '') + '</td><td>' + actions + '</td></tr>';
            }).join('') || '<tr><td colspan="5" class="text-center text-muted">Aún no hay solicitudes para este periodo.</td></tr>';
        }).catch(function () { sunatMsg('sunat-error', 'No se pudo cargar las solicitudes.'); });
    }

    function itemsTable(title, items, total, cols) {
        if (!total) { return ''; }
        var head = '<th>Fecha</th><th>Comprobante</th>' + cols.map(function (c) { return '<th class="text-right">' + esc(c[0]) + '</th>'; }).join('') + '<th>Observación</th>';
        var body = items.map(function (i) {
            return '<tr><td>' + esc(i.date || '') + '</td><td>' + esc((typeNames[i.type] || i.type) + ' ' + i.series + '-' + i.number) + '</td>'
                + cols.map(function (c) { return '<td class="text-right">' + (i[c[1]] === null || i[c[1]] === undefined ? '-' : money(i[c[1]])) + '</td>'; }).join('')
                + '<td>' + esc(i.note || '') + '</td></tr>';
        }).join('');
        var more = total > items.length ? '<p class="text-muted small">Se muestran ' + items.length + ' de ' + total + '.</p>' : '';
        return '<h6 class="mt-3">' + esc(title) + ' (' + total + ')</h6><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>' + more;
    }

    function renderSummary(r) {
        var s = r.summary, el = $('sunat-result');
        var html = '<h6>Resultado de la comparación &middot; periodo ' + esc(r.period) + '</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th></th><th class="text-right">Comprobantes</th><th class="text-right">Total (S/)</th></tr></thead><tbody>'
            + '<tr><td>SUNAT (propuesta)</td><td class="text-right">' + s.sunat.count + '</td><td class="text-right">' + money(s.sunat.total) + '</td></tr>'
            + '<tr><td>Sistema</td><td class="text-right">' + s.system.count + '</td><td class="text-right">' + money(s.system.total) + '</td></tr>'
            + '<tr><th>Diferencia (SUNAT - Sistema)</th><th class="text-right">' + (s.sunat.count - s.system.count) + '</th><th class="text-right">' + money(s.difference_total) + '</th></tr></tbody></table></div>';
        html += '<p>Coinciden: <strong>' + s.matched + '</strong></p>';
        if (s.in_sync) { html += '<div class="alert alert-success">Sin diferencias: la propuesta de SUNAT coincide con tus ventas.</div>'; }
        (s.warnings || []).forEach(function (w) { html += '<div class="alert alert-warning">' + esc(w) + '</div>'; });
        html += itemsTable('Solo en SUNAT', s.solo_en_sunat, s.solo_en_sunat_count, [['Total SUNAT', 'sunat_total']]);
        html += itemsTable('Solo en el sistema', s.solo_en_sistema, s.solo_en_sistema_count, [['Total sistema', 'system_total']]);
        html += itemsTable('Diferencias de importe', s.diferencias_monto, s.diferencias_monto_count, [['Total SUNAT', 'sunat_total'], ['Total sistema', 'system_total'], ['Diferencia', 'difference']]);
        html += itemsTable('Boletas consolidadas por rango con diferencia', s.diferencias_rango, s.diferencias_rango_count, [['Total SUNAT', 'sunat_total'], ['Total sistema', 'system_total'], ['Diferencia', 'difference']]);
        el.innerHTML = html;
        el.classList.remove('d-none');
    }

    $('sunat-request').addEventListener('click', function () {
        var btn = this;
        sunatMsg('sunat-error', ''); sunatMsg('sunat-info', '');
        if (!/^\d{6}$/.test(period())) { return sunatMsg('sunat-error', 'Selecciona un periodo valido.'); }
        btn.disabled = true;
        var url = sunatBase + '/request?period=' + period();
        sunatCall(url, 'POST').then(function (res) {
            if (!res.ok || !res.body.success) { sunatMsg('sunat-error', res.body.message || 'No se pudo solicitar la propuesta.'); } else {
                sunatMsg('sunat-info', res.body.message);
                if (res.body.request.summary) { renderSummary(res.body.request); }
            }
            loadRequests();
        }).catch(function () { sunatMsg('sunat-error', 'No se pudo solicitar la propuesta.'); })
          .then(function () { btn.disabled = false; });
    });

    $('sunat-reload').addEventListener('click', loadRequests);

    $('sunat-requests').addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) { return; }
        var id = btn.getAttribute('data-id'), act = btn.getAttribute('data-act');
        sunatMsg('sunat-error', ''); sunatMsg('sunat-info', '');
        btn.disabled = true;
        var call = act === 'refresh' ? sunatCall(sunatBase + '/' + id + '/refresh', 'POST') : sunatCall(sunatBase + '/' + id);
        call.then(function (res) {
            if (!res.ok || !res.body.success) { sunatMsg('sunat-error', res.body.message || 'No se pudo completar la acción.'); } else {
                if (res.body.message) { sunatMsg('sunat-info', res.body.message); }
                if (res.body.request.summary) { renderSummary(res.body.request); }
            }
            if (act === 'refresh') { loadRequests(); }
        }).catch(function () { sunatMsg('sunat-error', 'No se pudo completar la acción.'); })
          .then(function () { btn.disabled = false; });
    });

    $('sire-period').addEventListener('change', function () { $('sunat-result').classList.add('d-none'); loadRequests(); });
    loadRequests();
})();
</script>
@endpush
