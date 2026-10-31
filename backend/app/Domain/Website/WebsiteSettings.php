<?php

namespace App\Domain\Website;

class WebsiteSettings
{
    public static function indexable(): bool
    {
        $url = config('website.url');
        $parts = is_string($url) ? parse_url($url) : false;
        $host = is_array($parts) ? strtolower(rtrim($parts['host'] ?? '', '.')) : '';

        return app()->environment('production') && config('website.indexable') && ! config('website.demo')
            && is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && is_array($parts)
            && ($parts['scheme'] ?? '') === 'https' && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! isset($parts['query']) && ! isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! in_array($host, ['localhost', '::1', '[::1]'], true)
            && ! str_ends_with($host, '.localhost') && ! str_starts_with($host, '127.');
    }

    public static function origin(): string
    {
        $url = config('website.url') ?: config('app.url');
        $parts = parse_url((string) $url);
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return 'http://localhost';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
