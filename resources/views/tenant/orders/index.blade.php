@extends('tenant.layouts.app')

@section('content')
    <tenant-orders-index :user="{{ json_encode(array_merge(auth()->user()->toArray(), ['api_token' => auth()->user()->api_token])) }}"></tenant-orders-index>
@endsection
