@extends('layouts.auth')

@section('title', '422 · SNNHS SportsHub')

@section('content')
    @include('errors.partials.card', [
        'code' => '422',
        'title' => 'That action cannot be completed right now',
        'text' => 'Go back to your portal and try again.',
        'detail' => $exception?->getMessage(),
    ])
@endsection
