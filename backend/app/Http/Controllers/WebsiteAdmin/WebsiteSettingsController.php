<?php

namespace App\Http\Controllers\WebsiteAdmin;

use App\Domain\Website\Content\AdminWebsitePresenter;
use App\Domain\Website\Content\WebsiteDocumentService;
use App\Domain\Website\Content\WebsitePageSchema;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Website\SaveWebsiteDraftRequest;
use App\Http\Requests\Website\WebsiteRevisionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteSettingsController extends Controller
{
    use HandlesAdminContext;

    public function edit(Request $request, WebsiteDocumentService $service, AdminWebsitePresenter $presenter): Response
    {
        $actor = $this->admin($request);

        return Inertia::render('website-admin/settings/Edit', ['settings' => $presenter->document($actor, $service->settings($actor), true),
            'locales' => config('website.locales'), 'navigation_options' => WebsitePageSchema::KEYS,
            'capabilities' => ['edit' => true, 'publish' => true], 'actions' => array_combine(['draft', 'publish', 'unpublish'], array_map(fn ($action) => route('admin.website.settings.'.$action), ['draft', 'publish', 'unpublish']))]);
    }

    public function draft(SaveWebsiteDraftRequest $request, WebsiteDocumentService $service): RedirectResponse
    {
        $service->saveSettingsDraft($this->admin($request), $request->validated('content'), (int) $request->validated('expected_revision'));

        return $this->saved();
    }

    public function publish(WebsiteRevisionRequest $request, WebsiteDocumentService $service): RedirectResponse
    {
        $service->publishSettings($this->admin($request), (int) $request->validated('expected_revision'));

        return $this->saved();
    }

    public function unpublish(WebsiteRevisionRequest $request, WebsiteDocumentService $service): RedirectResponse
    {
        $service->unpublishSettings($this->admin($request), (int) $request->validated('expected_revision'));

        return $this->saved();
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Website settings saved.']);

        return to_route('admin.website.settings.edit', [], 303);
    }
}
