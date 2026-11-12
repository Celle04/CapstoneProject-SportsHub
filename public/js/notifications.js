/**
 * The notification bell keeps itself up to date.
 *
 * The portal asks the server for the state of the bell now and then, so a
 * notification that arrives while the page is open shows up right away - the
 * page does not have to be reloaded to see it. The same happens when the tab
 * is opened again after it was in the background.
 *
 * The panel itself is rendered by the server (the same partial the page
 * uses), so a notification that arrives live looks exactly like one that was
 * there when the page was loaded.
 */
(function () {
    'use strict';

    var container = document.querySelector('[data-notification-feed]');

    if (!container) {
        return;
    }

    var feedUrl = container.getAttribute('data-notification-feed');
    var panel = container.querySelector('[data-notification-panel]');
    var badge = container.querySelector('[data-notification-badge]');
    var trigger = container.querySelector('[data-notification-toggle]');

    var POLL_INTERVAL = 20000;

    /*
     * The state the page was rendered with. The first answer from the server
     * is compared against it, so a notification that arrived while the page
     * was loading is noticed right away.
     */
    var state = {
        unread: parseInt(container.getAttribute('data-notification-unread') || '0', 10),
        latestId: container.getAttribute('data-notification-latest') || null,
        primed: true,
    };
    var running = false;

    function badgeText(unread) {
        return unread > 99 ? '99+' : String(unread);
    }

    function paintBadge(unread) {
        if (!badge) {
            return;
        }

        badge.textContent = badgeText(unread);
        badge.classList.toggle('is-empty', unread <= 0);
        badge.setAttribute(
            'aria-label',
            unread + (unread === 1 ? ' unread notification' : ' unread notifications')
        );
    }

    /* A short pulse on the bell tells the user that something just arrived. */
    function announce() {
        if (!trigger) {
            return;
        }

        trigger.classList.remove('has-new-notification');

        // Force the animation to start again, even when it is already running.
        void trigger.offsetWidth;

        trigger.classList.add('has-new-notification');
        window.setTimeout(function () {
            trigger.classList.remove('has-new-notification');
        }, 4000);
    }

    function paint(data) {
        if (!data || typeof data.unread !== 'number') {
            return;
        }

        var latestId =
            data.latest_id === null || data.latest_id === undefined
                ? null
                : String(data.latest_id);
        var changed = data.unread !== state.unread || latestId !== state.latestId;

        state.unread = data.unread;
        state.latestId = latestId;

        paintBadge(data.unread);

        if (!changed) {
            return;
        }

        if (panel && typeof data.panel === 'string' && data.panel !== '') {
            panel.innerHTML = data.panel;
        }

        if (data.unread > 0) {
            announce();
        }
    }

    function poll() {
        if (running || document.visibilityState !== 'visible' || !feedUrl) {
            return;
        }

        running = true;

        fetch(feedUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                paint(data);
            })
            .catch(function () {
                // No connection: the next attempt tries again.
            })
            .then(function () {
                running = false;
            });
    }

    /*
     * Marking a notification as read does not reload the page either: the
     * server is asked to do it and the bell is repainted from the answer.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (!form.matches('[data-notification-read], [data-notification-read-all]')) {
            return;
        }

        event.preventDefault();

        var button = form.querySelector('button[type="submit"]');

        if (button) {
            button.disabled = true;
        }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(function () {
                poll();
            })
            .catch(function () {
                // Without a connection the normal submit still works.
                form.submit();
            });
    });

    // The bell is checked again as soon as the tab is looked at again.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            poll();
        }
    });

    window.setInterval(poll, POLL_INTERVAL);
})();
