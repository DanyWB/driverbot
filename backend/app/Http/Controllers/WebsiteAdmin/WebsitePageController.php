<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Domain\Website\Content\AdminWebsitePresenter;
use App\Domain\Website\Content\WebsiteContentAuthorizer;
use App\Domain\Website\Content\WebsiteDocumentService;
use App\Domain\Website\Content\WebsitePageSchema;
use App\Domain\Website\Content\WebsiteStaticAssets;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Website\SaveWebsiteDraftRequest;
use App\Http\Requests\Website\WebsiteRevisionRequest;
use App\Models\WebsitePage;
use App\Models\WebsitePageRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebsitePageController extends Controller
{
    use HandlesAdminContext;

    public function index(Request $request): Response
    {
        app(WebsiteContentAuthorizer::class)->authorize($this->admin($request));
        $documents = WebsitePage::query()->get()->keyBy('key');
        $pages = [];
        foreach (WebsitePageSchema::KEYS as $key) {
            $page = $documents->get($key);
            $pages[] = ['key' => $key, 'revision' => $page === null ? 0 : $page->revision, 'published_revision' => $page?->published_revision,
                'published_at' => $page?->published_at?->format(DATE_ATOM), 'status' => $page === null ? 'none' : ($page->published_at === null ? 'draft' : 'published'),
                'edit_url' => route('admin.website.pages.edit', ['key' => $key])];
        }

        return Inertia::render('website-admin/pages/Index', ['pages' => $pages, 'locales' => config('website.locales')]);
    }

    public function edit(Request $request, string $key, WebsiteDocumentService $service, AdminWebsitePresenter $presenter, WebsiteStaticAssets $assets): Response
    {
        $actor = $this->admin($request);
        $document = $service->page($actor, $key);
        $history = $document?->history()->limit(50)->get(['revision', 'created_at'])->map(fn (WebsitePageRevision $revision): array => [
            'revision' => $revision->revision, 'published_at' => $revision->created_at?->format(DATE_ATOM),
            'restore_url' => route('admin.website.pages.restore', ['key' => $key, 'revision' => $revision->revision]),
        ])->all() ?? [];
        $actions = [];
        foreach (['draft', 'publish', 'unpublish'] as $action) {
            $actions[$action] = route('admin.website.pages.'.$action, ['key' => $key]);
        }
        $actions['preview'] = [];
        foreach (config('website.locales') as $locale) {
            $actions['preview'][$locale] = route('admin.website.pages.preview', ['key' => $key, 'locale' => $locale]);
        }

        return Inertia::render('website-admin/pages/Edit', ['page' => ['key' => $key, ...$presenter->document($actor, $document)],
            'history' => $history, 'block_types' => WebsitePageSchema::TYPES, 'static_assets' => $assets->options(),
            'locales' => config('website.locales'), 'limits' => ['max_blocks' => 30, 'max_bytes' => 262144, 'steps_items' => 8, 'faq_items' => 20],
            'capabilities' => ['edit' => true, 'publish' => ! in_array($key, ['terms', 'privacy'], true) || config('website.cms.policy_publishing_enabled') === true], 'actions' => $actions]);
    }

    public function draft(SaveWebsiteDraftRequest $request, string $key, WebsiteDocumentService $service): RedirectResponse
    {
        $service->savePageDraft($this->admin($request), $key, $request->validated('content'), (int) $request->validated('expected_revision'));

        return $this->saved($key);
    }

    public function publish(WebsiteRevisionRequest $request, string $key, WebsiteDocumentService $service): RedirectResponse
    {
        $service->publishPage($this->admin($request), $key, (int) $request->validated('expected_revision'));

        return $this->saved($key);
    }

    public function unpublish(WebsiteRevisionRequest $request, string $key, WebsiteDocumentService $service): RedirectResponse
    {
        $service->unpublishPage($this->admin($request), $key, (int) $request->validated('expected_revision'));

        return $this->saved($key);
    }

    public function restore(WebsiteRevisionRequest $request, string $key, string $revision, WebsiteDocumentService $service): RedirectResponse
    {
        $service->restorePage($this->admin($request), $key, (int) $revision, (int) $request->validated('expected_revision'));

        return $this->saved($key);
    }

    private function saved(string $key): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Website page saved.']);

        return to_route('admin.website.pages.edit', ['key' => $key], 303);
    }
}
