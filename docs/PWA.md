# SportsHub Progressive Web App

SportsHub uses the existing Laravel public directory for its PWA files:

- `public/manifest.webmanifest` describes the installable app and references the supplied SportsHub PNG icons.
- `public/sw.js` caches the public app shell and static assets. It never stores navigation responses, authenticated pages, or API responses. If a navigation cannot reach the server, it displays the neutral offline page instead.
- `public/offline.html` explains that account details and changes require a connection.
- `public/js/offline.js` registers the worker relative to its own URL, so the app can be deployed at a domain root or under a subdirectory.
- The install controls are provided on the public landing page and the shared student/admin portal layout. Supported browsers show their native install prompt; other browsers get platform-specific instructions.

## Deployment

Use HTTPS for the hosted site. Service workers and native PWA installation require a secure context; browsers allow localhost for development. Set `APP_URL` to the canonical HTTPS URL before building Laravel's configuration cache. Keep the manifest, worker, offline page, icon files, and referenced static assets publicly readable.

When changing the app-shell asset list or offline behavior, increment `SW_VERSION` in `public/sw.js`. The updated worker installs a new cache and removes the previous SportsHub cache after activation. Users need an internet connection to sign in, load private information, or submit changes; private content is intentionally not available offline.

## Installing

- **Android:** choose **Install App** in SportsHub or use the browser menu's **Install app** / **Add to Home screen** action.
- **iPhone or iPad:** open SportsHub in Safari, tap **Share**, choose **Add to Home Screen**, then tap **Add**.
- **Desktop:** use the browser's install icon or choose **Install SportsHub** from the browser menu.
