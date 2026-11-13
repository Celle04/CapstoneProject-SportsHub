@extends('layouts.auth')

@section('title', '403 · SNNHS SportsHub')

@section('content')
    @include('errors.partials.card', [
        'code' => '403',
        'title' => 'You do not have access to this page',
        'text' => 'This page belongs to a portal your account cannot open. If you need it, ask the administrator for help.',
        'detail' => $exception?->getMessage(),
    ])
@endsection
