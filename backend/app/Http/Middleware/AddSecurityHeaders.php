<?php

namespace App\Http\Middleware;

use App\Domain\Website\WebsiteSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->configureTrustedProxies();
        $nonce = config('security.csp.enabled') ? Vite::useCspNonce() : null;
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $isRecovery = $request->is('forgot-password', 'reset-password', 'reset-password/*');
        $response->headers->set('Referrer-Policy', $isRecovery ? 'no-referrer' : 'strict-origin-when-cross-origin');
        if ($isRecovery) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if ($request->is('website', 'website/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }
        if ($request->is('website-media/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            if (! WebsiteSettings::indexable() || $response->getStatusCode() >= 400) {
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }
        }
        // Apply these outside the website route middleware as well, including rejected
        // owner access, CSRF errors, validation failures and throttled submissions.
        if ($request->routeIs('website.*')) {
            $isPrivateWebsite = $request->routeIs('website.booking*', 'website.status', 'website.quote', 'website.availability');
            if (! WebsiteSettings::indexable() || $isPrivateWebsite || $request->query() !== []) {
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            }
            if ($isPrivateWebsite) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Referrer-Policy', 'no-referrer');
            }
        }
        $response->headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');
        if (config('website.preview.enabled')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        if (is_string($nonce)) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
        }

        if (config('security.hsts.enabled') && $request->isSecure()) {
            $value = 'max-age='.(int) config('security.hsts.max_age', 31536000);

            if (config('security.hsts.include_subdomains')) {
                $value .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }

    private function configureTrustedProxies(): void
    {
        $trustedProxies = config('security.trusted_proxies', []);

        if (! is_array($trustedProxies) || $trustedProxies === []) {
            return;
        }

        Request::setTrustedProxies(
            $trustedProxies,
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "connect-src 'self'",
            "font-src 'self' https://fonts.bunny.net data:",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "img-src 'self' data: blob:",
            "object-src 'none'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}' https://fonts.bunny.net",
        ];

        if (config('security.csp.upgrade_insecure_requests')) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
