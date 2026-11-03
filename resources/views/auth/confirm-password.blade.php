@extends('layouts.auth')

@section('title', 'Confirm Password | SNNHS SportsHub')

@section('content')
    <div class="auth-heading">
        <h2>Confirm your password</h2>
        <p>This action changes sensitive records. Enter your password to continue.</p>
    </div>

    <form class="auth-form" method="POST" action="{{ route('password.confirm.store') }}" aria-label="Confirm password form" data-loading-label="Confirming...">
        @csrf

        <div class="auth-field">
            <label for="password">Password</label>
            <span class="auth-control">
                <svg class="auth-control-icon" aria-hidden="true"><use href="#icon-lock"></use></svg>
                <input id="password" class="auth-input-toggle" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required autofocus>
                <button class="auth-toggle" type="button" data-password-toggle="password" aria-label="Show password" aria-pressed="false">
                    <svg aria-hidden="true"><use href="#icon-eye"></use></svg>
                </button>
            </span>
            @error('password')<p class="auth-error">{{ $message }}</p>@enderror
        </div>

        <button class="button auth-submit" type="submit">Confirm</button>

        <a class="auth-back" href="{{ url('/') }}"><svg aria-hidden="true"><use href="#icon-arrow-left"></use></svg> Back to Home</a>
    </form>
@endsection
