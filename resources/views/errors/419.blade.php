@extends('layouts.auth')

@section('title', '419 · SNNHS SportsHub')

@section('content')
    @include('errors.partials.card', [
        'code' => '419',
        'title' => 'Your session has expired',
        'text' => 'For safety, forms stop working after a period of inactivity. Go back to your portal and try again.',
        'detail' => '',
    ])
@endsection
