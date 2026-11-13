@php
    /*
     * Shared body of the framework error pages. Every card names what went
     * wrong in plain language and gives exactly one way forward: back to the
     * portal the signed-in account actually owns (guests go to the sign-in
     * screen), so a wrong address is never a dead end.
     */
    $user = auth()->user();
    $isStudent = ($user?->role ?? '') === 'Student';
    $homeRoute = $user
        ? ($isStudent ? 'student.dashboard' : 'dashboard')
        : 'login';
    $homeLabel = ! $user
        ? 'Sign in'
        : ($isStudent ? 'Go to my portal' : 'Go to the admin portal');
@endphp
<div class="error-page-body">
    <p class="error-page-code">{{ $code }}</p>
    <h2 class="error-page-title">{{ $title }}</h2>
    <p class="error-page-text">{{ $text }}</p>
    @if (trim((string) $detail) !== '')
        <p class="error-page-text"><strong>{{ $detail }}</strong></p>
    @endif
    <div class="error-page-actions">
        <a class="button" href="{{ route($homeRoute) }}">{{ $homeLabel }}</a>
    </div>
</div>
