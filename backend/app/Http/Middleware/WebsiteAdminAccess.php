<?php

namespace App\Http\Middleware;

use App\Domain\Website\Content\WebsiteContentAuthorizer;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebsiteAdminAccess
{
    public static function validationEditor(Request $request): string
    {
        $vehicle = $request->route('vehicle');
        if ($vehicle === null && $request->routeIs('admin.website.media.destroy')) {
            $vehicle = $request->input('vehicle_id');
        }
        if ((is_string($vehicle) && ctype_digit($vehicle) && (int) $vehicle > 0) || (is_int($vehicle) && $vehicle > 0)) {
            return route('admin.website.cards.edit', ['vehicle' => $vehicle]);
        }
        $key = $request->route('key');
        if (is_string($key) && in_array($key, ['home', 'cars', 'scooters', 'contact', 'terms', 'privacy'], true)) {
            return route('admin.website.pages.edit', ['key' => $key]);
        }

        return $request->routeIs('admin.website.settings.*') ? route('admin.website.settings.edit') : route('admin.website.cards.index');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        abort_unless(config('website.cms.admin_enabled') === true && ! config('website.preview.enabled')
            && is_string($host) && strcasecmp($request->getHost(), $host) === 0, 404);
        $actor = $request->user();
        if (! $actor instanceof User) {
            return redirect()->route('login');
        }
        app(WebsiteContentAuthorizer::class)->authorize($actor);

        return $next($request);
    }
}
