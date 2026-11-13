@extends('layouts.auth')

@section('title', '404 · SNNHS SportsHub')

@section('content')
    @include('errors.partials.card', [
        'code' => '404',
        'title' => 'We could not find that page',
        'text' => 'The address may be old or mistyped. Open your portal and continue from there.',
        'detail' => '',
    ])
@endsection
