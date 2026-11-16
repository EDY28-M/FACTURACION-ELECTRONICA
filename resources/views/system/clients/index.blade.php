@extends('system.layouts.app')

@section('content')

    <system-clients-index :delete-permission="{{ json_encode(config('tenant.admin_delete_client')) }}"></system-clients-index>

@endsection