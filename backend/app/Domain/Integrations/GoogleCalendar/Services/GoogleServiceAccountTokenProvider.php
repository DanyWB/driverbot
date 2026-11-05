<?php

namespace App\Domain\Integrations\GoogleCalendar\Services;

use App\Domain\Integrations\GoogleCalendar\Data\GoogleCalendarOperationContext;
use App\Domain\Integrations\GoogleCalendar\Exceptions\GoogleCalendarProcessingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Issues OAuth access tokens for a Google service account (JWT bearer grant), cached until shortly before expiry. */
class GoogleServiceAccountTokenProvider
{
    public const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    /** @var array{client_email: string, private_key: string}|null */
    private ?array $credentials = null;

    public function token(GoogleCalendarOperationContext $context): string
    {
        $credentials = $this->credentials();
        $cacheKey = 'google_calendar:access_token:'.hash('sha256', $credentials['client_email']);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $assertion = $this->signJwt($credentials, $now);

        try {
            $response = $context->configureHttpRequest(Http::asForm())->post(
                (string) config('google_calendar.token_url'),
                ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
            );
        } catch (ConnectionException $exception) {
            // No calendar write has happened yet, so the outcome is known: nothing was applied.
            throw GoogleCalendarProcessingException::transient('Google OAuth token request failed: '.$exception->getMessage(), $exception);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw GoogleCalendarProcessingException::transient('Google OAuth token endpoint returned HTTP '.$response->status().'.');
        }
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            $error = (string) ($response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status());

            throw GoogleCalendarProcessingException::permanent('Google OAuth token request rejected: '.$error);
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    /** @return array{client_email: string, private_key: string} */
    public function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $raw = trim((string) config('google_calendar.service_account.credentials_json'));
        if ($raw !== '' && ! str_starts_with($raw, '{')) {
            $decoded = base64_decode($raw, true);
            $raw = $decoded === false ? $raw : trim($decoded);
        }

        if ($raw === '') {
            $path = trim((string) config('google_calendar.service_account.credentials_path'));
            if ($path === '') {
                throw GoogleCalendarProcessingException::permanent('Google Calendar credentials are not configured (GOOGLE_CALENDAR_CREDENTIALS_PATH or GOOGLE_CALENDAR_CREDENTIALS_JSON).');
            }
            if (! preg_match('/^([a-zA-Z]:[\\\\\/]|[\\\\\/])/', $path)) {
                $path = base_path($path);
            }
            if (! is_file($path) || ! is_readable($path)) {
                throw GoogleCalendarProcessingException::permanent('Google Calendar credentials file is not readable.');
            }
            $raw = (string) file_get_contents($path);
        }

        $json = json_decode($raw, true);
        if (! is_array($json) || ! is_string($json['client_email'] ?? null) || ! is_string($json['private_key'] ?? null)) {
            throw GoogleCalendarProcessingException::permanent('Google Calendar credentials are not a valid service-account key.');
        }

        return $this->credentials = ['client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    /** @param array{client_email: string, private_key: string} $credentials */
    private function signJwt(array $credentials, int $now): string
    {
        $segments = [
            $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url((string) json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => (string) config('google_calendar.token_url'),
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $key = openssl_pkey_get_private($credentials['private_key']);
        if ($key === false || ! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw GoogleCalendarProcessingException::permanent('Google Calendar service-account private key cannot sign requests.');
        }
        $segments[] = $this->base64Url($signature);

        return implode('.', $segments);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
