/*
 * Registers the SNNHS SportsHub service worker.
 *
 * The service worker is one directory above this script, alongside the
 * manifest and public assets. Resolve both URL and scope from this script so
 * deployments at the domain root and in a subfolder behave the same way.
 */
(function () {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', function () {
        var script = document.currentScript
            || document.querySelector('script[src$="js/offline.js"]');
        var scriptUrl = script && script.src ? script.src : window.location.href;
        var appBase = new URL('../', scriptUrl);
        var swUrl = new URL('sw.js', appBase).href;

        navigator.serviceWorker.register(swUrl, { scope: appBase.pathname }).catch(function (error) {
            console.error('SportsHub service worker registration failed:', error);
        });
    });
})();
