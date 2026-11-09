<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Check Application Status - SNNHS SportsHub</title>
    <link rel="stylesheet" href="{{ asset('css/theme.css') }}?v={{ filemtime(public_path('css/theme.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}?v={{ filemtime(public_path('css/landing.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/legal.css') }}?v={{ filemtime(public_path('css/legal.css')) }}">
    <style>
        .status-result { margin-top: 1rem; padding: 1rem 1.1rem; border-radius: 14px; border: 1px solid rgba(15,23,42,.1); background: #fff; }
        .status-badge { display:inline-flex; align-items:center; padding:.35rem .75rem; border-radius:999px; font-weight:700; font-size:.92rem; }
        .status-Pending { background:#fef3c7; color:#92400e; }
        .status-Under-Review { background:#dbeafe; color:#1d4ed8; }
        .status-Requires-Revision { background:#ffedd5; color:#c2410c; }
        .status-Approved { background:#dcfce7; color:#166534; }
        .status-Rejected { background:#fee2e2; color:#991b1b; }
        .status-grid { display:grid; gap:.75rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin:1rem 0; }
        .status-item span { display:block; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700; }
        .status-item strong { display:block; margin-top:.15rem; color:#0f172a; }
        .status-message { margin-top: .85rem; padding: .85rem 1rem; border-radius: 12px; background: #f8fafc; border: 1px solid rgba(15,23,42,.06); }
        .status-actions { display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1rem; }
    </style>
</head>
<body>
    <header class="site-header">
        <a class="site-brand" href="{{ url('/') }}" aria-label="SNNHS SportsHub home">
            <img class="site-logo" src="{{ asset('images/snnhs logo.png') }}" alt="SNNHS logo">
            <span class="brand-text">
                <span class="site-name">SNNHS SportsHub</span>
                <span class="site-school">Surigao del Norte National High School</span>
            </span>
        </a>
        <div class="header-actions">
            <a class="login-button" href="{{ route('application.create') }}">Apply</a>
            <a class="login-button" href="{{ route('login') }}">Login</a>
        </div>
    </header>

    <main class="application-page">
        <section class="application-card">
            <div class="application-heading">
                <div class="application-icon">🔎</div>
                <h1>Check Application Status</h1>
                <p>Enter your application reference number and the email address you used when applying. This protects your private information.</p>
            </div>

            <div class="application-info">
                <strong>Privacy notice</strong>
                <p>For your security, a reference number alone is not enough to view application details. We only show status information after verifying the email address used on the application.</p>
            </div>

            @if ($errors->any())
                <div class="form-errors">
                    <strong>Please correct the following:</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($notFound))
                <div class="form-errors">
                    <strong>No matching application found.</strong>
                    <ul>
                        <li>Check that your reference number and email address match the application you submitted.</li>
                    </ul>
                </div>
            @endif

            <form class="application-form" method="POST" action="{{ route('application.status.check') }}">
                @csrf
                <section class="form-section">
                    <div class="form-section-heading">
                        <div class="form-section-number">1</div>
                        <div>
                            <h2>Verification</h2>
                            <p>Use the reference number shown after submission.</p>
                        </div>
                    </div>
                    <label>
                        <span>Reference Number <b>*</b></span>
                        <input type="text" name="reference_number" value="{{ old('reference_number', $input['reference_number'] ?? '') }}" placeholder="SNNHS-APP-..." required autocomplete="off">
                    </label>
                    <label>
                        <span>Email Address <b>*</b></span>
                        <input type="email" name="email" value="{{ old('email', $input['email'] ?? '') }}" placeholder="Email used on the application" required autocomplete="email">
                    </label>
                </section>
                <div class="application-submit">
                    <button class="primary-button" type="submit">Check Status</button>
                </div>
            </form>

            @if (! empty($result))
                @php($statusKey = str_replace(' ', '-', $result->status))
                <section class="status-result" aria-live="polite">
                    <div style="display:flex; gap:.75rem; align-items:center; justify-content:space-between; flex-wrap:wrap;">
                        <div>
                            <span class="student-application-label" style="display:block; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700;">Current Status</span>
                            <span class="status-badge status-{{ $statusKey }}">{{ $result->status }}</span>
                        </div>
                        <div style="text-align:right;">
                            <span style="display:block; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; font-weight:700;">Reference</span>
                            <strong>{{ $result->reference_number }}</strong>
                        </div>
                    </div>

                    <div class="status-grid">
                        <div class="status-item"><span>Sport Applied For</span><strong>{{ $result->sportCategory?->name ?? $result->sport ?? 'Not specified' }}</strong></div>
                        <div class="status-item"><span>Submitted</span><strong>{{ $result->created_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</strong></div>
                        <div class="status-item"><span>Last Updated</span><strong>{{ ($result->reviewed_at ?? $result->updated_at)?->format('M j, Y g:i A') ?? 'Not recorded' }}</strong></div>
                    </div>

                    <div class="status-message">
                        <strong>What this means</strong>
                        <p style="margin:.35rem 0 0;">{{ $statusDefinitions[$result->status] ?? 'Your application status has been updated.' }}</p>
                    </div>

                    @if (filled($result->review_notes) && in_array($result->status, ['Rejected', 'Requires Revision'], true))
                        <div class="status-message">
                            <strong>{{ $result->status === 'Rejected' ? 'Explanation' : 'Revision Instructions' }}</strong>
                            <p style="margin:.35rem 0 0; white-space:pre-wrap;">{{ $result->status === 'Rejected' ? ($result->rejection_reason ?: $result->review_notes) : $result->review_notes }}</p>
                        </div>
                    @endif

                    @if (filled($result->admin_instructions))
                        <div class="status-message">
                            <strong>Instructions from SNNHS Sports Office</strong>
                            <p style="margin:.35rem 0 0; white-space:pre-wrap;">{{ $result->admin_instructions }}</p>
                        </div>
                    @endif

                    @if ($result->status === 'Approved')
                        <div class="status-message">
                            <strong>Next steps</strong>
                            <p style="margin:.35rem 0 0;">If an athlete account has been issued to you, use the login page to access the athlete dashboard. Follow any office instructions shown above. If you were told to visit the SNNHS Sports Office, bring your reference number and a valid school ID.</p>
                        </div>
                    @endif

                    <div class="status-actions">
                        <a class="primary-button" href="{{ route('login') }}" style="text-decoration:none;">Go to Login</a>
                        <a class="login-button" href="{{ route('application.status') }}" style="text-decoration:none;">Check Another</a>
                    </div>
                </section>
            @endif
        </section>
    </main>

    <footer class="site-footer">
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
                <nav class="public-policy-links" aria-label="Legal information">
                    <a href="{{ route('privacy-policy') }}">Privacy Policy</a>
                    <a href="{{ route('terms-and-conditions') }}">Terms &amp; Conditions</a>
                </nav>
            </div>
        </div>
    </footer>
</body>
</html>
