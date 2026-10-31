<?php

namespace App\Domain\Website;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit isolated-preview boundary. Never weakens ordinary production checks. */
class WebsitePreviewGuard
{
    private bool $verified = false;

    public function verified(): bool
    {
        return $this->verified;
    }

    public function assertConfiguration(): void
    {
        $host = config('website.preview.host');
        $connection = config('database.connections.'.config('database.default'));
        $origin = is_string($host) ? 'https://'.$host : null;
        $hash = config('website.preview.snapshot_sha256');
        $instance = config('website.preview.instance_id');
        $safe = config('website.preview.enabled') && app()->environment('production') && ! config('app.debug')
            && config('website.enabled') && config('website.demo') && ! config('website.indexable')
            && is_string($host) && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host)
            && in_array($host, ['drive-preview.2-26-60-62.sslip.io', 'drive-preview.2-26-60-62.nip.io'], true)
            && ! str_contains($host, '..') && str_contains($host, '.')
            && config('app.url') === $origin && config('website.url') === $origin && config('website.domain') === $host
            && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash)
            && is_string($instance) && preg_match('/^[a-f0-9]{32}$/D', $instance)
            && is_bool(config('website.preview.public_access'))
            && is_string(config('website.preview.auth_user')) && preg_match('/^[A-Za-z0-9_-]{4,64}$/D', config('website.preview.auth_user'))
            && is_array($connection) && ($connection['driver'] ?? null) === 'pgsql' && empty($connection['url'])
            && ($connection['database'] ?? null) === 'drive_phangan_preview' && ($connection['username'] ?? null) === 'drive_phangan_preview'
            && in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            && storage_path() === '/srv/drive-phangan-preview/shared/storage'
            && config('session.driver') === 'file' && config('cache.default') === 'file'
            && config('session.cookie') === 'drive_phangan_preview_session' && empty(config('session.domain'))
            && config('session.secure') === true && config('session.http_only') === true && config('session.same_site') === 'lax'
            && in_array(config('queue.default'), [null, 'null'], true) && config('mail.default') === 'array'
            && ! filled(config('notifications.telegram.bot_token')) && ! filled(config('notifications.telegram.admin_chat_id'))
            && config('notifications.telegram.api_base_url') === 'http://127.0.0.1:9'
            && array_filter(config('website.contacts'), fn ($value) => filled($value)) === []
            && config('security.trusted_proxies') === [] && config('security.csp.enabled') === true
            && ! config('health.redis') && ! config('health.scheduler');
        $safe = $safe && config('business.currency') === 'THB';
        foreach (['pricing_source_sha256', 'mapping_sha256', 'media_manifest_sha256'] as $field) {
            $value = config('website.preview.'.$field);
            $safe = $safe && is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value);
        }
        if (! $safe) {
            throw new RuntimeException('Isolated preview configuration is invalid.');
        }
    }

    public function assertDatabaseIdentity(): void
    {
        $this->assertConfiguration();
        app(PreviewFilesystemBoundary::class)->verify('/srv/drive-phangan-preview/shared/storage', ['app', 'app/public', 'app/private', 'framework', 'framework/cache', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']);
        $bootstrap = base_path('bootstrap/cache');
        if (! str_starts_with($bootstrap, '/srv/drive-phangan-preview/releases/')) {
            throw new RuntimeException('Preview bootstrap cache is not release-isolated.');
        }
        app(PreviewFilesystemBoundary::class)->verify($bootstrap, []);
        $identity = DB::selectOne('SELECT current_database() AS database_name, current_user AS role_name, rolsuper, rolcreatedb, rolcreaterole, rolreplication FROM pg_roles WHERE rolname = current_user');
        if ($identity === null || $identity->database_name !== 'drive_phangan_preview' || $identity->role_name !== 'drive_phangan_preview') {
            throw new RuntimeException('Isolated preview database identity is invalid.');
        }
        if ($identity->rolsuper || $identity->rolcreatedb || $identity->rolcreaterole || $identity->rolreplication) {
            throw new RuntimeException('Preview role has excessive database privileges.');
        }
    }

    public function verifyRuntime(): void
    {
        if ($this->verified) {
            return;
        }
        $this->assertDatabaseIdentity();
        $marker = DB::table('website_preview_metadata')->where('instance_id', config('website.preview.instance_id'))->first();
        if ($marker === null || $marker->marker !== 'WEBSITE PREVIEW ONLY - NO PRODUCTION WRITES'
            || ! hash_equals(config('website.preview.snapshot_sha256'), $marker->snapshot_sha256)
            || ! hash_equals(config('website.preview.pricing_source_sha256'), $marker->pricing_source_sha256)
            || ! hash_equals(config('website.preview.mapping_sha256'), $marker->mapping_sha256)
            || ! hash_equals(config('website.preview.media_manifest_sha256'), $marker->media_manifest_sha256)
            || DB::table('customer_identities')->exists() || DB::table('admin_telegram_bindings')->exists()
            || DB::table('service_api_clients')->exists() || DB::table('admin_users')->where('is_active', true)->exists()) {
            throw new RuntimeException('Isolated preview dataset marker or recipient isolation is invalid.');
        }
        $this->verified = true;
    }
}
