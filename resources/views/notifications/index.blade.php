@extends('layouts.portal')

@section('content')
<section class="notification-inbox">
    <header class="notification-inbox-heading">
        <div><span>SPORTSHUB</span><h1>Notifications</h1><p>Updates related to your sports activities and account.</p></div>
        @if (auth()->user()->unreadNotifications()->exists())
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="button notification-mark-all" type="submit">Mark all read</button>
            </form>
        @endif
    </header>

    <div class="notification-inbox-list">
        @forelse ($notifications as $notification)
            <article class="card notification-inbox-item {{ $notification->read_at ? 'is-read' : 'is-unread' }}">
                <a class="notification-inbox-link" href="{{ route('notifications.open', $notification->id) }}">
                    <span class="notification-inbox-copy">
                        <span class="notification-inbox-title"><strong>{{ data_get($notification->data, 'title', 'SportsHub update') }}</strong>@if (! $notification->read_at)<span>NEW</span>@endif</span>
                        <span class="notification-inbox-message">{{ data_get($notification->data, 'message', 'You have a new update.') }}</span>
                        <small>{{ $notification->created_at?->format('M j, Y g:i A') }}</small>
                    </span>
                </a>
                <div class="notification-inbox-actions">
                    @if (! $notification->read_at)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                            @csrf
                            <button class="text-link" type="submit">Mark read</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <div class="notification-inbox-empty"><span aria-hidden="true"><svg><use href="#icon-bell"></use></svg></span><strong>You're all caught up.</strong><p>New updates will appear here.</p></div>
        @endforelse
    </div>

    @if ($notifications->hasPages())
        <nav class="notification-inbox-pagination" aria-label="Notification pages">
            @if ($notifications->previousPageUrl())<a class="text-link" href="{{ $notifications->previousPageUrl() }}">&larr; Newer</a>@else<span></span>@endif
            <span>Page {{ $notifications->currentPage() }}</span>
            @if ($notifications->nextPageUrl())<a class="text-link" href="{{ $notifications->nextPageUrl() }}">Older &rarr;</a>@else<span></span>@endif
        </nav>
    @endif
</section>
@endsection