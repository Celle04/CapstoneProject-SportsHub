/**
 * The tabs of a list switch without reloading the whole page.
 *
 * Clicking a tab asks the server for that tab and swaps the list that is on
 * the screen. The address bar follows along, so the tab has its own page and
 * the back button still works. If the request cannot be made, the tab opens
 * as a normal link.
 */
(function () {
    'use strict';

    var panel = document.querySelector('[data-tab-panel]');

    if (!panel) {
        return;
    }

    var links = Array.prototype.slice.call(document.querySelectorAll('[data-tab-link]'));
    var busy = false;

    /**
     * The filters that are in use stay in place while the tab changes.
     */
    function targetUrl(href) {
        var url = new URL(href, window.location.origin);
        var current = new URLSearchParams(window.location.search);

        ['search', 'sort', 'archived', 'event_id'].forEach(function (key) {
            if (current.get(key) && !url.searchParams.has(key)) {
                url.searchParams.set(key, current.get(key));
            }
        });

        return url;
    }

    function markActive(url) {
        links.forEach(function (link) {
            var linkUrl = new URL(link.getAttribute('href'), window.location.origin);
            var same = linkUrl.pathname === url.pathname && linkUrl.searchParams.get('tab') === url.searchParams.get('tab');

            link.classList.toggle('is-active', same);
            link.setAttribute('aria-current', same ? 'true' : 'false');
        });
    }

    function swap(html, url, push) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var incoming = parsed.querySelector('[data-tab-panel]');

        if (!incoming) {
            return false;
        }

        panel.innerHTML = incoming.innerHTML;

        markActive(url);

        if (push) {
            window.history.pushState({ tab: url.searchParams.get('tab') }, '', url);
        } else {
            window.history.replaceState({ tab: url.searchParams.get('tab') }, '', url);
        }

        // The new rows carry their own timestamps, so they are refreshed.
        document.dispatchEvent(new CustomEvent('relative-time:refresh'));

        return true;
    }

    function load(url, push) {
        if (busy) {
            return;
        }

        busy = true;

        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html', 'X-Requested-With': 'tab-switch' },
        })
            .then(function (response) {
                return response.ok ? response.text() : null;
            })
            .then(function (html) {
                if (!html) {
                    throw new Error('the tab could not be loaded');
                }

                if (!swap(html, url, push !== false)) {
                    throw new Error('the tab could not be loaded');
                }
            })
            .catch(function () {
                // Without a connection the tab opens as a normal link.
                window.location.href = url.href;
            })
            .then(function () {
                busy = false;
            });
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-tab-link]');

        if (link && link.getAttribute('href')) {
            event.preventDefault();
            load(targetUrl(link.getAttribute('href')), true);

            return;
        }

        // The pages of a list follow the same rule.
        var page = event.target.closest('.pagination a');

        if (page && page.getAttribute('href')) {
            event.preventDefault();
            load(targetUrl(page.getAttribute('href')), true);
        }
    });

    // The back button walks through the tabs that were visited.
    window.addEventListener('popstate', function () {
        load(targetUrl(window.location.href), false);
    });
})();
