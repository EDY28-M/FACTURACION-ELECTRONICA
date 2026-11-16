@extends('system.layouts.app')

@section('content')
@php
    use Illuminate\Support\Facades\Crypt;
    $folderQuery = $current_folder ? '&f=' . Crypt::encrypt($current_folder) : '';
    $levels = [
        'error' => ['error', 'Error'], 'critical' => ['error', 'Error'], 'alert' => ['error', 'Error'], 'emergency' => ['error', 'Error'],
        'warning' => ['warning', 'Advertencia'],
    ];
    $levelFor = function ($log) use ($levels) {
        $key = strtolower($log['level'] ?? '');
        return $levels[$key] ?? ['info', 'Información'];
    };
    $totalLogs = is_array($logs) ? count($logs) : 0;
    $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
    if ($totalLogs) { foreach ($logs as $entry) { $counts[$levelFor($entry)[0]]++; } }
    $logLimit = 500;
    if ($totalLogs > $logLimit) { $logs = array_slice($logs, 0, $logLimit, true); }
    $fileUrl = function ($file, $folder = null) {
        return '?l=' . Crypt::encrypt($file) . ($folder ? '&f=' . Crypt::encrypt($folder) : '');
    };
@endphp
<div v-pre class="system-dashboard" id="system-logs">
    <header class="system-page-heading">
        <div>
            <h1>Logs</h1>
            <p>Actividad registrada del sistema y de las cuentas de clientes</p>
        </div>
        @if($current_file)
        <div class="system-page-actions">
            <a class="system-button" data-no-shell href="?dl={{ Crypt::encrypt($current_file) }}{{ $folderQuery }}">@include('system.layouts.partials.icon', ['name' => 'download'])Descargar</a>
            <a class="system-button" data-confirm="¿Vaciar el contenido de este archivo de log?" href="?clean={{ Crypt::encrypt($current_file) }}{{ $folderQuery }}">@include('system.layouts.partials.icon', ['name' => 'eraser'])Vaciar</a>
            <a class="system-button" data-confirm="¿Eliminar este archivo de log?" href="?del={{ Crypt::encrypt($current_file) }}{{ $folderQuery }}">@include('system.layouts.partials.icon', ['name' => 'trash-2'])Eliminar</a>
            @if(count($files) > 1)
                <a class="system-button" data-confirm="¿Eliminar todos los archivos de log?" href="?delall=true{{ $folderQuery }}">@include('system.layouts.partials.icon', ['name' => 'trash-2'])Eliminar todos</a>
            @endif
        </div>
        @endif
    </header>

    @if ($logs !== null)
        <div class="system-kpis">
            <div><span>Registros</span><strong>{{ $totalLogs }}</strong></div>
            <div><span>Errores</span><strong>{{ $counts['error'] }}</strong></div>
            <div><span>Advertencias</span><strong>{{ $counts['warning'] }}</strong></div>
            <div><span>Información</span><strong>{{ $counts['info'] }}</strong></div>
        </div>
    @endif

    <section class="system-panel">
        <div class="system-toolbar" id="log-filters">
            <div class="system-filter">
                <label for="log-file">Archivo</label>
                <select id="log-file" class="system-select" data-file-select>
                    @if(!$current_file)<option value="">Selecciona un archivo</option>@endif
                    @foreach($folders as $folder)
                        <option value="?f={{ Crypt::encrypt($folder) }}" @if($current_folder == $folder && !$current_file) selected @endif>Carpeta: {{ $folder }}</option>
                        @if($current_folder == $folder)
                            @foreach($folder_files as $file)
                                <option value="{{ $fileUrl($file, $folder) }}" @if($current_file == $file) selected @endif>{{ $folder }} / {{ $file }}</option>
                            @endforeach
                        @endif
                    @endforeach
                    @foreach($files as $file)
                        <option value="{{ $fileUrl($file) }}" @if($current_file == $file && !$current_folder) selected @endif>{{ $file }}</option>
                    @endforeach
                </select>
            </div>
            <div class="system-filter system-filter-grow">
                <label for="log-search">Buscar</label>
                <div class="system-input-icon">
                    @include('system.layouts.partials.icon', ['name' => 'search'])
                    <input id="log-search" type="search" placeholder="Texto, contexto o archivo" autocomplete="off">
                </div>
            </div>
            <div class="system-filter">
                <label for="log-type">Tipo</label>
                <select id="log-type" class="system-select">
                    <option value="">Todos</option>
                    <option value="error">Error</option>
                    <option value="warning">Advertencia</option>
                    <option value="info">Información</option>
                </select>
            </div>
            <div class="system-filter">
                <label for="log-from">Desde</label>
                <input id="log-from" class="system-date" type="date">
            </div>
            <div class="system-filter">
                <label for="log-to">Hasta</label>
                <input id="log-to" class="system-date" type="date">
            </div>
            <button type="button" class="system-button" id="log-clear">Limpiar filtros</button>
        </div>

        @if ($logs === null)
            <p class="system-table-empty">El archivo supera los 50 MB y no se puede mostrar. Descárgalo para revisarlo.</p>
        @else
            @if($totalLogs > $logLimit)<p class="system-hint" style="padding:10px 14px 0">Mostrando los {{ $logLimit }} registros más recientes de {{ $totalLogs }}.</p>@endif
            <div class="table-responsive">
                <table class="system-client-table system-log-table" id="log-table" @if(!count($logs)) hidden @endif>
                    <thead>
                        <tr>
                            <th style="width:170px">Fecha</th>
                            <th style="width:150px">Tipo</th>
                            <th>Descripción</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($logs as $key => $log)
                        @php [$class, $label] = $levelFor($log); $date = $log['date'] ?? ''; @endphp
                        <tr data-level="{{ $class }}" data-date="{{ substr($date, 0, 10) }}" data-text="{{ strtolower(($log['context'] ?? '') . ' ' . ($log['text'] ?? '') . ' ' . ($log['in_file'] ?? '')) }}">
                            <td class="system-log-date">
                                @if(preg_match('/^(\S+)\s+(\S+)/', $date, $m)){{ $m[1] }}<small>{{ $m[2] }}</small>@else{{ $date }}@endif
                            </td>
                            <td><span class="system-badge system-badge-log-{{ $class }}"><i class="system-dot"></i>{{ $label }}</span></td>
                            <td class="system-log-text">
                                @if(!empty($log['context']))<span class="system-log-context">{{ $log['context'] }}</span>@endif
                                {{ $log['text'] }}
                                @if(isset($log['in_file']))<small class="system-mono">{{ $log['in_file'] }}</small>@endif
                                @if(!empty($log['stack']))
                                    <details><summary>Ver detalle</summary><pre class="system-mono">{{ trim($log['stack']) }}</pre></details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="system-empty-state" id="log-empty" @if(count($logs)) hidden @endif>
                <svg viewBox="0 0 96 80" width="96" height="80" aria-hidden="true"><rect x="14" y="10" width="44" height="56" rx="6" fill="#fff" stroke="#cbd1dc" stroke-width="2"/><rect x="30" y="18" width="44" height="56" rx="6" fill="#f5f6f9" stroke="#cbd1dc" stroke-width="2"/><circle cx="68" cy="56" r="14" fill="#2445d6"/><path d="M62 56h12" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>
                <h2 id="log-empty-title">No hay actividad registrada</h2>
                <p id="log-empty-text">Los errores, advertencias y eventos aparecerán aquí.</p>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
<script>
(function () {
    if (typeof window.initSystemLogs === 'function') {
        window.initSystemLogs();
    }
})();
</script>
@endpush
