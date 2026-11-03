<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the common security response headers to every web response.
 *
 * The Content-Security-Policy is sent in report-only mode on purpose: the
 * current Blade templates still use inline <script> blocks, inline style
 * attributes and a few inline event handlers, so an enforced policy would
 * break the portal. Report-Only lets the browser log violations (and shows
 * exactly what must be removed) without changing what visitors see. Once the
 * inline code is gone, rename the header here to Content-Security-Policy to
 * enforce it.
 */
class SecurityHeadersMiddleware
{
    /**
     * The policy an enforced CSP will use once the inline code is removed.
     */
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."style-src 'self' 'unsafe-inline'; "
        ."script-src 'self' 'unsafe-inline'; "
        ."frame-ancestors 'self'; "
        ."base-uri 'self'; "
        ."form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        // Report-only for now: the browser records violations but still runs
        // the page, so nothing on the portal breaks.
        if (! $headers->has('Content-Security-Policy-Report-Only')) {
            $headers->set('Content-Security-Policy-Report-Only', self::CONTENT_SECURITY_POLICY);
        }

        // Stop the browser guessing a content type, block framing and stop
        // full URLs leaking to other sites in the Referer header.
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // HSTS is only meaningful (and only safe) over HTTPS.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
