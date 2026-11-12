{{--
    The panel of the notification bell.

    The page and the live feed render the same partial, so a notification that
    arrives while the page is open looks exactly like one that was there when
    the page was loaded.
--}}
<header class="portal-notification-header">
    <div class="portal-notification-header-info">
        <h2 class="portal-notification-header-title">Notifications</h2>
        @if ($unreadNotificationCount > 0)
            <span class="portal-notification-header-count">{{ $unreadNotificationCount }} unread</span>
        @endif
    </div>
    <div class="portal-notification-header-actions">
        @if ($unreadNotificationCount > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}" class="portal-notification-mark-all" data-notification-read-all>
                @csrf
                <button class="portal-notification-mark-all-btn" type="submit" title="Mark all as read"><svg class="portal-notification-mark-all-icon" aria-hidden="true"><use href="#icon-check"></use></svg><span class="portal-notification-mark-all-label">Mark all as read</span></button>
            </form>
        @endif
        <button type="button" class="portal-notification-close" data-notification-close aria-label="Close notifications"><svg aria-hidden="true"><use href="#icon-x"></use></svg></button>
    </div>
</header>
<div class="portal-notification-list" role="menu">
    @forelse ($recentNotifications as $notification)
        <article class="portal-notification-item {{ $notification->read_at ? 'is-read' : 'is-unread' }}">
            <a class="portal-notification-content" role="menuitem" href="{{ route('notifications.open', $notification->id) }}">
                <span class="portal-notification-icon-wrap"><svg aria-hidden="true"><use href="#icon-bell"></use></svg></span>
                <span class="portal-notification-copy">
                    <span class="portal-notification-title-row">
                        <strong class="portal-notification-title">{{ data_get($notification->data, 'title', 'SportsHub update') }}</strong>
                        @if (! $notification->read_at)
                            <span class="portal-notification-unread-dot" aria-hidden="true"></span>
                        @endif
                    </span>
                    <span class="portal-notification-message">{{ data_get($notification->data, 'message', 'You have a new update.') }}</span>
                    <small class="portal-notification-time relative-time" data-posted-at="{{ \App\Support\RelativeTime::machine($notification->created_at) }}">{{ \App\Support\RelativeTime::of($notification->created_at) }}</small>
                </span>
            </a>
            <div class="portal-notification-actions">
                @if (! $notification->read_at)
                    <form method="POST" action="{{ route('notifications.read', $notification->id) }}" class="portal-notification-mark-read" data-notification-read>
                        @csrf
                        <button class="text-link" type="submit">Mark as read</button>
                    </form>
                @endif
            </div>
        </article>
    @empty
        <div class="portal-notification-empty">
            <span class="portal-notification-empty-icon" aria-hidden="true"><svg><use href="#icon-bell"></use></svg></span>
            <strong class="portal-notification-empty-title">You're all caught up.</strong>
            <p class="portal-notification-empty-subtitle">New updates will appear here.</p>
        </div>
    @endforelse
</div>
