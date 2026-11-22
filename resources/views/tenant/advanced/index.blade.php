@extends('tenant.layouts.app')

@section('content')
    <div class="row">
        <div class="col-xl-10 col-12">
            <tenant-configurations-form :type-user="{{ json_encode(auth()->user()->type) }}"></tenant-configurations-form>
        </div>
    </div>
@endsection
