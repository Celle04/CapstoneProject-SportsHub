<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#10069F">
    <title>@yield('title') | SNNHS SportsHub</title>
    <meta name="description" content="@yield('description')">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="stylesheet" href="{{ asset('css/theme.css') }}?v={{ filemtime(public_path('css/theme.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}?v={{ filemtime(public_path('css/landing.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}?v={{ filemtime(public_path('css/legal.css')) }}">
    <script>
        (function () {
            try {
                if (localStorage.getItem('sports_hub_theme') === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>
</head>
<body class="legal-page">
    <header class="site-header legal-header">
        <a class="site-brand" href="{{ route('landing') }}" aria-label="SNNHS SportsHub home">
            <img class="site-logo" src="{{ asset('images/snnhs logo.png') }}" alt="SNNHS logo">
            <span class="brand-text">
                <span class="site-name">SNNHS SportsHub</span>
                <span class="site-school">Surigao del Norte National High School</span>
            </span>
        </a>
        <div class="legal-header-actions">
            <button type="button" class="legal-theme-toggle" data-legal-theme-toggle aria-pressed="false">Dark mode</button>
            <a class="login-button" href="{{ route('landing') }}">Back to Home</a>
        </div>
    </header>

    <main class="legal-main">
        @yield('legal-content')
    </main>

    <footer class="site-footer legal-footer">
        <div class="footer-content">
            <div class="footer-brand">
                <img src="{{ asset('images/snnhs logo.png') }}" alt="SNNHS logo">
                <div>
                    <strong>SNNHS SportsHub</strong>
                    <span>Surigao del Norte National High School</span>
                </div>
            </div>
            <div class="footer-text">
                &copy; {{ now()->year }} Surigao del Norte National High School. All rights reserved.
                <nav class="legal-footer-links" aria-label="Legal information">
                    <a href="{{ route('privacy-policy') }}">Privacy Policy</a>
                    <a href="{{ route('terms-and-conditions') }}">Terms &amp; Conditions</a>
                </nav>
            </div>
        </div>
    </footer>

    <script>
        (function () {
            var button = document.querySelector('[data-legal-theme-toggle]');
            var root = document.documentElement;
            if (!button) { return; }

            function updateButton() {
                var dark = root.getAttribute('data-theme') === 'dark';
                button.textContent = dark ? 'Light mode' : 'Dark mode';
                button.setAttribute('aria-pressed', String(dark));
            }

            button.addEventListener('click', function () {
                var dark = root.getAttribute('data-theme') !== 'dark';
                if (dark) {
                    root.setAttribute('data-theme', 'dark');
                } else {
                    root.removeAttribute('data-theme');
                }
                try {
                    localStorage.setItem('sports_hub_theme', dark ? 'dark' : 'light');
                } catch (e) {}
                updateButton();
            });

            updateButton();
        })();
    </script>
</body>
</html>
