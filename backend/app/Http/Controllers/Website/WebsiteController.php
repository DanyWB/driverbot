<?php

namespace App\Http\Controllers\Website;

use App\Domain\Availability\Services\BookingAvailabilityService;
use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Website\Content\WebsitePageAssembler;
use App\Domain\Website\GuestBookingService;
use App\Domain\Website\PublicCatalog;
use App\Domain\Website\WebsitePickupDate;
use App\Domain\Website\WebsitePreviewGuard;
use App\Domain\Website\WebsiteSettings;
use App\Domain\Website\WebsiteValidation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Website\StoreGuestBookingRequest;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WebsiteController extends Controller
{
    public function __construct(private readonly PublicCatalog $catalog) {}

    public function home(Request $request, string $locale): Response
    {
        $vehicles = $this->catalog->query()->orderBy('sort_order')->orderBy('id')->limit(6)->get()
            ->map(fn ($vehicle) => $this->catalog->present($vehicle, $locale))->all();

        return $this->page($request, 'home', compact('vehicles'));
    }

    public function catalog(Request $request, string $locale, string $catalog): Response
    {
        $base = $this->catalog->query()->where('type', $catalog === 'cars' ? 'car' : 'scooter');
        $categoryOptions = Category::query()->whereIn('id', (clone $base)->select('category_id'))->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])->all();
        $filters = $request->validate([
            ...$this->dateRules(false),
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'brand' => ['nullable', 'string', 'max:100'], 'model' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:100'], 'engine_cc' => ['nullable', 'integer', 'between:1,10000'],
            'year' => ['nullable', 'integer', 'between:1900,2200'], 'seats' => ['nullable', 'integer', 'between:1,100'],
            'abs' => ['nullable', 'boolean'], 'transmission' => ['nullable', Rule::in(['automatic', 'manual', 'semi_automatic'])],
            'category_id' => ['nullable', 'integer', 'min:1', Rule::in(array_column($categoryOptions, 'id'))],
        ], WebsiteValidation::messages($locale), WebsiteValidation::attributes($locale));
        $filterOptions = [];
        foreach (['brand', 'model', 'color', 'engine_cc', 'year', 'seats', 'abs', 'transmission'] as $field) {
            $filterOptions[$field] = (clone $base)->whereNotNull($field)->distinct()->orderBy($field)->pluck($field)->all();
        }
        $query = clone $base;
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        foreach (array_keys($filterOptions) as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['q'])) {
            $query->whereLike('name', '%'.$filters['q'].'%');
        }
        if (! empty($filters['starts_on']) && ! empty($filters['ends_on'])) {
            $query->whereDoesntHave('occupancies', fn (Builder $q) => $q->where('blocks_availability', true)
                ->where('starts_on', '<=', $filters['ends_on'])->where('ends_on', '>=', $filters['starts_on']));
        }
        $vehicles = $query->orderBy('sort_order')->orderBy('id')->paginate(12)->withQueryString()
            ->through(fn ($vehicle) => $this->catalog->present($vehicle, $locale, $filters));

        return $this->page($request, 'catalog', compact('vehicles', 'catalog', 'filters', 'filterOptions', 'categoryOptions'));
    }

    public function vehicle(Request $request, string $locale, string $slug): Response|RedirectResponse
    {
        $model = $this->catalog->find((int) explode('-', $slug, 2)[0]);
        if ($slug !== $this->catalog->slug($model)) {
            return redirect()->route('website.vehicle', ['locale' => $locale, 'slug' => $this->catalog->slug($model)], 301);
        }

        return $this->page($request, 'vehicle', $this->vehicleData($request, $locale, $model));
    }

    public function booking(Request $request, string $locale, string $vehicle): Response
    {
        $model = $this->catalog->find((int) $vehicle);
        $this->ownerHash($request);

        return $this->page($request, 'booking', [
            ...$this->vehicleData($request, $locale, $model), 'idempotencyKey' => (string) Str::uuid(),
            'submitUrl' => route('website.booking.store', ['locale' => $locale, 'vehicle' => $model->id]),
            'contactChannels' => ['telegram_username', 'whatsapp', 'phone', 'email'],
        ]);
    }

    public function store(StoreGuestBookingRequest $request, string $locale, string $vehicle, GuestBookingService $bookings): RedirectResponse
    {
        abort_unless($this->acceptingRequests($locale), 503);
        try {
            $booking = $bookings->create((int) $vehicle, $locale, $this->ownerHash($request), $request->validated());
        } catch (PricingException|BookingException $exception) {
            throw ValidationException::withMessages(['starts_on' => $this->errorMessage($exception->errorCode, $locale)]);
        }

        return redirect()->route('website.status', ['locale' => $locale, 'reference' => $booking->public_id], 303);
    }

    public function status(Request $request, string $locale, string $reference): Response
    {
        $owner = $request->session()->get('website_owner');
        abort_unless(is_string($owner), 404);
        $model = Booking::query()->where('public_id', $reference)->whereIn('id', DB::table('website_booking_requests')
            ->select('booking_id')->where('owner_hash', hash('sha256', $owner)))->with(['vehicle', 'latestPriceSnapshot'])->firstOrFail();
        $submission = DB::table('website_booking_requests')->where('booking_id', $model->id)->first();
        $snapshot = $model->latestPriceSnapshot;
        $booking = [
            'reference' => $model->public_id, 'status' => $model->bookingStatus()->value,
            'starts_on' => substr((string) $model->getRawOriginal('starts_on'), 0, 10), 'ends_on' => substr((string) $model->getRawOriginal('ends_on'), 0, 10),
            'pickup_time' => substr($model->pickup_time ?? '12:00', 0, 5), 'return_time' => substr($model->return_time ?? '12:00', 0, 5),
            'vehicle_name' => $model->vehicle->name, 'price' => $snapshot ? ['final_total' => (int) $snapshot->final_total, 'currency' => $snapshot->currency] : null,
            'pending_expires_at' => $model->pendingExpiresAt()?->toIso8601String(),
            'contact_channel' => $submission->contact_channel, 'contact_delivery' => 'manual', 'terms_version' => $model->terms_version,
            'payments' => $model->paymentsSummary(),
        ];

        return $this->page($request, 'status', compact('booking'));
    }

    public function quote(Request $request, string $locale, string $vehicle): JsonResponse
    {
        $dates = $request->validate($this->dateRules(true), WebsiteValidation::messages($locale), WebsiteValidation::attributes($locale));
        $model = $this->catalog->find((int) $vehicle);
        $available = app(BookingAvailabilityService::class)->isAvailable($model, $dates['starts_on'], $dates['ends_on']);
        if (! $available) {
            return response()->json(['error' => ['code' => 'vehicle_unavailable']], 409);
        }
        try {
            return response()->json(['data' => ['available' => true, 'quote' => $this->catalog->quote($model, $dates['starts_on'], $dates['ends_on']),
                'pickup_time' => '12:00', 'return_time' => '12:00', 'timezone' => 'Asia/Bangkok']]);
        } catch (PricingException $exception) {
            return response()->json(['error' => ['code' => $exception->errorCode]], 422);
        }
    }

    public function availability(Request $request, string $locale, string $vehicle): JsonResponse
    {
        $dates = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], WebsiteValidation::messages($locale), WebsiteValidation::attributes($locale));
        if (CarbonImmutable::parse($dates['from'])->diffInDays(CarbonImmutable::parse($dates['to'])) > 366) {
            throw ValidationException::withMessages(['to' => __('website.validation.dates', [], $locale)]);
        }
        $model = $this->catalog->find((int) $vehicle);
        $busy = $model->occupancies()->where('blocks_availability', true)->where('starts_on', '<=', $dates['to'])
            ->where('ends_on', '>=', $dates['from'])->get(['starts_on', 'ends_on'])
            ->map(fn ($row) => ['starts_on' => max($dates['from'], substr((string) $row->getRawOriginal('starts_on'), 0, 10)), 'ends_on' => min($dates['to'], substr((string) $row->getRawOriginal('ends_on'), 0, 10))])->all();

        return response()->json(['data' => [...$dates, 'busy' => $busy, 'inclusive' => true, 'timezone' => 'Asia/Bangkok']]);
    }

    public function policy(Request $request, string $locale, string $policy): Response
    {
        return $this->page($request, 'policy', ['policy' => $policy, 'policyContent' => config("website.policies.$policy.$locale")]);
    }

    public function robots(): Response
    {
        $body = $this->indexable() ? "User-agent: *\nDisallow: /*/book/\nDisallow: /*/requests/\nSitemap: ".$this->origin()."/sitemap.xml\n" : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = [];
        if ($this->indexable()) {
            foreach (config('website.locales') as $locale) {
                $urls[] = $this->origin().'/'.$locale;
                foreach (['cars', 'scooters'] as $catalog) {
                    $urls[] = $this->origin().'/'.$locale.'/'.$catalog;
                }
                foreach ($this->catalog->query()->orderBy('id')->get() as $vehicle) {
                    $urls[] = $this->origin().'/'.$locale.'/vehicles/'.$this->catalog->slug($vehicle);
                }
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $url) {
            $xml .= '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function vehicleData(Request $request, string $locale, Vehicle $model): array
    {
        $dates = $request->validate($this->dateRules(false), WebsiteValidation::messages($locale), WebsiteValidation::attributes($locale));
        $vehicle = $this->catalog->present($model, $locale, $dates);
        $quote = $vehicle['quote'];
        $quoteError = null;
        if (! empty($dates['starts_on']) && ! empty($dates['ends_on'])) {
            if (! app(BookingAvailabilityService::class)->isAvailable($model, $dates['starts_on'], $dates['ends_on'])) {
                $quote = null;
                $quoteError = 'vehicle_unavailable';
            } elseif ($quote === null) {
                $quoteError = 'pricing_unavailable';
            }
        }

        return [
            'vehicle' => $vehicle, 'dates' => ['starts_on' => $dates['starts_on'] ?? null, 'ends_on' => $dates['ends_on'] ?? null],
            'quote' => $quote, 'quoteError' => $quoteError,
            'availabilityUrl' => route('website.availability', ['locale' => $locale, 'vehicle' => $model->id]),
            'quoteUrl' => route('website.quote', ['locale' => $locale, 'vehicle' => $model->id]),
        ];
    }

    /** @param array<string, mixed> $data */
    private function page(Request $request, string $view, array $data): Response
    {
        $locale = $request->route('locale');
        $cmsKey = $view === 'catalog' ? $data['catalog'] : ($view === 'policy' ? $data['policy'] : $view);
        $data = app(WebsitePageAssembler::class)->assemble($cmsKey, $locale, $data, $data['dates'] ?? $data['filters'] ?? []);
        $cmsSettings = $data['cms_settings'] ?? null;
        $siteName = $cmsSettings['business']['name'] ?? config('website.name');
        $navigation = ['home' => route('website.home', ['locale' => $locale])];
        foreach (['cars', 'scooters'] as $catalog) {
            $navigation[$catalog] = route('website.catalog', ['locale' => $locale, 'catalog' => $catalog]);
        }
        foreach (['terms', 'privacy', 'contact'] as $policy) {
            $navigation[$policy] = route('website.policy', ['locale' => $locale, 'policy' => $policy]);
        }
        $languages = [];
        $languageQuery = [];
        $queryCandidate = $data['filters'] ?? $data['dates'] ?? [];
        if (is_array($queryCandidate)) {
            foreach (['starts_on', 'ends_on', 'q', 'brand', 'model', 'engine_cc', 'color', 'abs', 'seats', 'transmission', 'year', 'page', 'category_id'] as $field) {
                $value = $queryCandidate[$field] ?? null;
                if (is_scalar($value) && $value !== '') {
                    $languageQuery[$field] = $value;
                }
            }
        }
        foreach (config('website.locales') as $language) {
            $languages[] = ['locale' => $language, 'url' => route($request->route()->getName(), [...$request->route()->parameters(), ...$languageQuery, 'locale' => $language])];
        }
        $private = in_array($view, ['booking', 'status'], true);
        $path = '/'.ltrim($request->path(), '/');
        $canonical = $this->origin().$path;
        $titleKey = 'website.meta.'.($view === 'catalog' ? $data['catalog'] : ($view === 'policy' ? $data['policy'] : $view)).'_title';
        $title = __($titleKey, [], $locale);
        if ($title === $titleKey) {
            $title = config('website.name');
        }
        if ($view === 'vehicle' || $view === 'booking') {
            $title = $data['vehicle']['name'].' | '.config('website.name');
        }
        $descriptionKey = 'website.meta.description';
        $description = __($descriptionKey, [], $locale);
        $cmsMetadata = $data['vehicle']['presentation'] ?? $data['cms_page'] ?? null;
        if (! $private && is_array($cmsMetadata)) {
            $title = $cmsMetadata['meta_title'] ?: $title;
            $description = $cmsMetadata['meta_description'] ?: $description;
        }
        $seo = [
            'title' => $title, 'description' => $description === $descriptionKey ? config('website.name') : $description,
            'canonical' => $canonical, 'robots' => ! $private && $request->query() === [] && $this->indexable() ? 'index, follow' : 'noindex, nofollow',
            'hreflang' => $private ? [] : array_map(fn ($language) => ['locale' => $language['locale'], 'url' => $this->origin().parse_url($language['url'], PHP_URL_PATH)], $languages),
            'og_image' => ! $private && is_file(public_path('website/images/home-hero.webp')) ? $this->origin().'/website/images/home-hero.webp' : null,
            'json_ld' => [],
        ];
        if (! $private && ! empty($cmsMetadata['cover']['variants']['card_2x']['url'])) {
            $seo['og_image'] = $this->origin().parse_url($cmsMetadata['cover']['variants']['card_2x']['url'], PHP_URL_PATH);
        }
        if (! $private) {
            $seo['json_ld'][] = ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $siteName, 'url' => $this->origin().'/'.$locale];
            if ($view === 'vehicle') {
                $seo['json_ld'][] = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => $siteName, 'item' => $this->origin().'/'.$locale],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $data['vehicle']['presentation']['title'] ?? $data['vehicle']['name'], 'item' => $canonical],
                ]];
            }
        }
        $contacts = array_filter(config('website.contacts'), fn ($url) => is_string($url)
            && (str_starts_with($url, 'https://') || str_starts_with($url, 'mailto:')) && ! preg_match('/[\x00-\x20]/', $url));
        $website = [
            'name' => $siteName, 'demo' => (bool) config('website.demo'), 'contacts' => $cmsSettings === null ? $contacts : array_filter($cmsSettings['contacts']),
            'business' => array_filter(config('website.business')), 'terms_version' => config('website.terms_version'),
            'channels' => array_fill_keys(['telegram_username', 'whatsapp', 'phone', 'email'], 'manual'),
            'accepting_requests' => $this->acceptingRequests($locale),
            'today_date' => WebsitePickupDate::today()->format('Y-m-d'),
            'earliest_pickup_date' => WebsitePickupDate::earliest()->format('Y-m-d'),
        ];
        if ($cmsSettings !== null) {
            $website['business'] = array_filter(array_intersect_key($cmsSettings['business'], array_flip(['legal_name', 'address', 'telephone'])));
            $website['navigation_order'] = $cmsSettings['navigation'];
            $website['footer_note'] = $cmsSettings['footer_note'][$locale] ?? '';
        }

        return response()->view('website.'.$view, [...$data, ...compact('locale', 'website', 'navigation', 'languages', 'seo')]);
    }

    /** @return array<string, list<string>> */
    private function dateRules(bool $required): array
    {
        return [
            'starts_on' => [$required ? 'required' : 'nullable', 'required_with:ends_on', 'date_format:Y-m-d', 'after_or_equal:'.WebsitePickupDate::earliest()->format('Y-m-d')],
            'ends_on' => [$required ? 'required' : 'nullable', 'required_with:starts_on', 'date_format:Y-m-d', 'after:starts_on', 'before_or_equal:'.now('Asia/Bangkok')->addYears(2)->format('Y-m-d')],
        ];
    }

    private function ownerHash(Request $request): string
    {
        if (! is_string($request->session()->get('website_owner'))) {
            $request->session()->put('website_owner', Str::random(64));
        }

        return hash('sha256', $request->session()->get('website_owner'));
    }

    private function acceptingRequests(string $locale): bool
    {
        return (bool) config('website.accepting_requests') && filled(config('website.terms_version'))
            && filled(config("website.policies.terms.$locale")) && filled(config("website.policies.privacy.$locale"))
            && (! config('website.demo') || app()->environment(['local', 'testing']) || app(WebsitePreviewGuard::class)->verified());
    }

    private function indexable(): bool
    {
        return WebsiteSettings::indexable();
    }

    private function origin(): string
    {
        return WebsiteSettings::origin();
    }

    private function errorMessage(string $code, string $locale): string
    {
        $key = 'website.errors.'.$code;
        $message = __($key, [], $locale);

        return $message === $key ? $code : $message;
    }
}
