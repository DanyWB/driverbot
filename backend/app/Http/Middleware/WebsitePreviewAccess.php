<?php

namespace App\Http\Middleware;

use App\Domain\Website\WebsitePreviewGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WebsitePreviewAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('website.preview.enabled')) {
            return $next($request);
        }
        $path = $request->getPathInfo();
        $publicPath = preg_match('~^/(?:en|ru|uk)(?:/(?:cars|scooters|vehicles/[0-9]+-[a-z0-9-]+|vehicles/[0-9]+/(?:quote|availability)|book/[0-9]+|requests/[a-f0-9-]{36}|policies/(?:terms|privacy|contact)))?/?$~D', $path)
            || in_array($path, ['/', '/robots.txt', '/sitemap.xml'], true)
            || preg_match('~^/website-media/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/(?:card|card_2x|detail)/[a-f0-9]{64}\.webp$~D', $path);
        $allowedMethod = in_array($request->method(), ['GET', 'HEAD'], true)
            || ($request->isMethod('POST') && preg_match('~^/(?:en|ru|uk)/book/[0-9]+$~D', $path));
        // Public preview is an explicit server setting, never a client header or
        // a fabricated REMOTE_USER. Session ownership still protects requests.
        $accessAllowed = config('website.preview.public_access') === true
            || $request->server('REMOTE_USER') === config('website.preview.auth_user');
        abort_unless($request->getHost() === config('website.preview.host') && $publicPath && $allowedMethod
            && $accessAllowed, 404);
        try {
            app(WebsitePreviewGuard::class)->verifyRuntime();
        } catch (Throwable) {
            abort(503, 'Preview is unavailable.');
        }
        if ($path === '/') {
            return redirect('/en');
        }

        return $next($request);
    }
}
