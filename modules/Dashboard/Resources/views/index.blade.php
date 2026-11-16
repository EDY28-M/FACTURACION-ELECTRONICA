@extends('tenant.layouts.app')

@section('content')

    @if(!empty($bootstrap))
        <script type="application/json" id="tenant-dash-bootstrap" v-pre>{!! $bootstrap['json'] !!}</script>
    @endif

    <tenant-dashboard-index
    	:type-user="{{ json_encode(auth()->user()->type) }}"
    	:user-name="{{ json_encode(auth()->user()->name) }}"
    	:user-id="{{ json_encode(auth()->id()) }}"
    	:soap-company="{{ json_encode($soap_company) }}">
    </tenant-dashboard-index>

@endsection
