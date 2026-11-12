/**
 * The three dots in the Actions column of a table row.
 *
 * The panel holds the actions of that row, and the row itself can be clicked
 * to open the record. The menu closes when the click lands somewhere else, on
 * Escape, or after a menu item was used.
 */
(function () {
    'use strict';

    var menus = Array.prototype.slice.call(document.querySelectorAll('[data-row-menu]'));

    if (!menus.length) {
        return;
    }

    function panelOf(menu) {
        return menu.querySelector('[data-row-menu-panel]');
    }

    function triggerOf(menu) {
        return menu.querySelector('[data-row-menu-trigger]');
    }

    function close(menu) {
        var panel = panelOf(menu);
        var trigger = triggerOf(menu);

        if (panel) {
            panel.hidden = true;
        }

        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
    }

    function closeAll(except) {
        menus.forEach(function (menu) {
            if (menu !== except) {
                close(menu);
            }
        });
    }

    function open(menu) {
        var panel = panelOf(menu);
        var trigger = triggerOf(menu);

        if (!panel) {
            return;
        }

        closeAll(menu);

        panel.hidden = false;

        if (trigger) {
            trigger.setAttribute('aria-expanded', 'true');
        }

        // The panel is anchored to the trigger and flipped upwards when there
        // is no room underneath it.
        panel.style.top = '';
        panel.style.bottom = '';

        var rect = trigger.getBoundingClientRect();
        var spaceBelow = window.innerHeight - rect.bottom;
        var spaceAbove = rect.top;

        if (spaceBelow < 180 && spaceAbove > spaceBelow) {
            panel.style.top = 'auto';
            panel.style.bottom = 'calc(100% + 6px)';
        } else {
            panel.style.bottom = 'auto';
            panel.style.top = 'calc(100% + 6px)';
        }
    }

    function isOpen(menu) {
        var panel = panelOf(menu);

        return panel ? !panel.hidden : false;
    }

    menus.forEach(function (menu) {
        var trigger = triggerOf(menu);

        if (!trigger) {
            return;
        }

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            isOpen(menu) ? close(menu) : open(menu);
        });

        // A menu item that is used closes the menu behind it.
        var panel = panelOf(menu);

        if (panel) {
            panel.addEventListener('click', function (event) {
                if (event.target.closest('a, button')) {
                    close(menu);
                }
            });
        }
    });

    // Close when the click lands outside of the menu.
    document.addEventListener('click', function (event) {
        var open = menus.filter(isOpen)[0];

        if (!open) {
            return;
        }

        if (!open.contains(event.target)) {
            close(open);
        }
    });

    // Close on Escape.
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        var open = menus.filter(isOpen)[0];

        if (open) {
            close(open);
        }
    });

    /*
     * A row can carry the address of its record, so the row itself opens it.
     * A click on a link, a button or a menu is left to that control.
     */
    document.addEventListener('click', function (event) {
        var row = event.target.closest('tr[data-row-link]');

        if (!row) {
            return;
        }

        if (event.target.closest('a, button, input, select, textarea, [data-row-menu]')) {
            return;
        }

        var url = row.getAttribute('data-row-link');

        if (url) {
            window.location.href = url;
        }
    });
})();
