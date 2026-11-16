@extends('system.layouts.app')

@section('content')

    <system-configuration :configuration='@json($configuration)'></system-configuration>

@endsection
