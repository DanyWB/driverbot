<?php

namespace App\Domain\Website\Content;

use App\Domain\Shared\Services\AdminAuditService;
use App\Models\User;
use App\Models\WebsiteDocument;
use App\Models\WebsitePage;
use App\Models\WebsitePageRevision;
use App\Models\WebsiteSiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebsiteDocumentService
{
    public function __construct(private readonly WebsiteContentAuthorizer $authorization,
        private readonly WebsitePageSchema $pages, private readonly WebsiteSettingsSchema $settings,
        private readonly AdminAuditService $audit) {}

    public function page(User $actor, string $key): ?WebsitePage
    {
        $this->authorization->authorize($actor);
        $this->pages->key($key);

        return WebsitePage::query()->where('key', $key)->first();
    }

    public function settings(User $actor): ?WebsiteSiteSettings
    {
        $this->authorization->authorize($actor);

        return WebsiteSiteSettings::query()->find(1);
    }

    /** @param array<string,mixed> $content */
    public function savePageDraft(User $actor, string $key, array $content, int $expected): WebsiteDocument
    {
        return $this->mutate($actor, $key, 'draft', $expected, $content);
    }

    public function publishPage(User $actor, string $key, int $expected): WebsiteDocument
    {
        return $this->mutate($actor, $key, 'publish', $expected);
    }

    public function unpublishPage(User $actor, string $key, int $expected): WebsiteDocument
    {
        return $this->mutate($actor, $key, 'unpublish', $expected);
    }

    public function restorePage(User $actor, string $key, int $revision, int $expected): WebsiteDocument
    {
        return $this->mutate($actor, $key, 'restore', $expected, historicRevision: $revision);
    }

    /** @param array<string,mixed> $content */
    public function saveSettingsDraft(User $actor, array $content, int $expected): WebsiteDocument
    {
        return $this->mutate($actor, '@settings', 'draft', $expected, $content);
    }

    public function publishSettings(User $actor, int $expected): WebsiteDocument
    {
        return $this->mutate($actor, '@settings', 'publish', $expected);
    }

    public function unpublishSettings(User $actor, int $expected): WebsiteDocument
    {
        return $this->mutate($actor, '@settings', 'unpublish', $expected);
    }

    /** @param array<string,mixed> $content */
    private function mutate(User $actor, string $key, string $action, int $expected, array $content = [], ?int $historicRevision = null): WebsiteDocument
    {
        $this->authorization->authorize($actor);
        $isSettings = $key === '@settings';
        if (! $isSettings) {
            $this->pages->key($key);
        }
        $draft = $action === 'draft' ? ($isSettings ? $this->settings->normalize($content) : $this->pages->normalize($content)) : null;

        return DB::transaction(function () use ($actor, $key, $action, $expected, $historicRevision, $isSettings, $draft): WebsiteDocument {
            $admin = $this->authorization->authorize($actor, true);
            // Serializes absent singleton/page creation across different administrators.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['website:document:'.$key]);
            }
            $document = $isSettings ? WebsiteSiteSettings::query()->whereKey(1)->lockForUpdate()->first()
                : WebsitePage::query()->where('key', $key)->lockForUpdate()->first();
            $actualRevision = $document === null ? 0 : $document->revision;
            if ($expected < 0 || $actualRevision !== $expected) {
                throw ValidationException::withMessages(['revision' => 'Website content changed. Refresh before saving or publishing.']);
            }
            if ($document === null && $action !== 'draft') {
                throw ValidationException::withMessages(['content' => 'Save a website draft before this action.']);
            }
            $old = $document?->only(['revision', 'published_revision', 'published_at']);
            $document ??= $isSettings ? new WebsiteSiteSettings : new WebsitePage;
            $document->forceFill($isSettings ? ['id' => 1] : ['key' => $key]);
            $next = $expected + 1;
            if ($action === 'draft') {
                $document->forceFill(['draft_content' => $draft]);
            } elseif ($action === 'publish') {
                if (! $isSettings && in_array($key, ['terms', 'privacy'], true) && config('website.cms.policy_publishing_enabled') !== true) {
                    throw ValidationException::withMessages(['content' => 'Policy publication requires the deployment-owned legal approval gate.']);
                }
                $published = $isSettings ? $this->settings->normalize($document->draft_content) : $this->pages->normalize($document->draft_content, true);
                $document->forceFill(['published_content' => $published, 'published_revision' => $next,
                    'published_at' => now(), 'published_by_admin_id' => $admin->id]);
            } elseif ($action === 'unpublish') {
                $document->forceFill(['published_content' => null, 'published_revision' => null,
                    'published_at' => null, 'published_by_admin_id' => null]);
            } elseif ($action === 'restore') {
                $historic = WebsitePageRevision::query()->where('website_page_id', $document->id)->where('revision', $historicRevision)->first();
                if ($isSettings || $historic === null) {
                    throw ValidationException::withMessages(['revision' => 'Website page revision not found.']);
                }
                $document->forceFill(['draft_content' => $this->pages->normalize($historic->content)]);
            }
            $document->forceFill(['revision' => $next, 'updated_by_admin_id' => $admin->id])->save();
            if (! $isSettings && $action === 'publish') {
                (new WebsitePageRevision)->forceFill(['website_page_id' => $document->id, 'revision' => $next,
                    'content' => $document->published_content, 'created_by_admin_id' => $admin->id, 'created_at' => now()])->save();
            }
            $this->audit->record($admin, $document, 'website.'.($isSettings ? 'settings' : 'page').'_'.match ($action) {
                'draft' => 'draft_saved', 'publish' => 'published', 'unpublish' => 'unpublished', 'restore' => 'restored_to_draft',
                default => throw new \LogicException('Unknown internal website document action.'),
            }, $old, ['revision' => $next, 'restored_revision' => $historicRevision]);

            return $document;
        });
    }
}
