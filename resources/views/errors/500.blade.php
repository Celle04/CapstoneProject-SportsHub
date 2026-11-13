@extends('layouts.auth')

@section('title', '500 · SNNHS SportsHub')

@section('content')
    @include('errors.partials.card', [
        'code' => '500',
        'title' => 'Something went wrong on our side',
        'text' => 'The error was recorded. Please try again; if it keeps happening, report it to the administrator.',
        'detail' => '',
    ])
@endsection
