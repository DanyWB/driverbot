<?php

namespace App\Http\Middleware;

use App\Domain\Website\WebsiteSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebsiteAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('website.enabled'), 404);
        if ($domain = config('website.domain')) {
            abort_unless(strcasecmp($request->getHost(), $domain) === 0, 404);
        }
        if ($locale = $request->route('locale')) {
            abort_unless(in_array($locale, config('website.locales'), true), 404);
            app()->setLocale($locale);
        }
        $response = $next($request);
        if (! WebsiteSettings::indexable()
            || $request->query() !== [] || $request->routeIs('website.booking*', 'website.status', 'website.quote', 'website.availability')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
        if ($request->routeIs('website.booking*', 'website.status', 'website.quote', 'website.availability')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
