<?php

namespace App\Http\Controllers\Vehicles;

use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Vehicles\Enums\VehicleType;
use App\Domain\Vehicles\Exceptions\VehicleCatalogException;
use App\Domain\Vehicles\Presenters\AdminVehiclePresenter;
use App\Domain\Vehicles\Services\VehicleCatalogService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\ListVehiclesRequest;
use App\Http\Requests\Vehicles\SaveVehicleRequest;
use App\Models\Category;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VehicleController extends Controller
{
    use HandlesAdminContext;

    public function index(
        ListVehiclesRequest $request,
        AdminVehiclePresenter $presenter,
    ): Response {
        $filters = $request->filters();
        $query = Vehicle::query()
            ->with(['category', 'photos'])
            ->withCount([
                'photos',
                'bookings',
                'priceTiers as active_price_tiers_count' => fn (Builder $query) => $query->where('is_active', true),
            ]);

        $this->applyFilters($query, $filters);

        $vehicles = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate((int) $filters['per_page'])
            ->withQueryString()
            ->through(fn (Vehicle $vehicle): array => $presenter->listItem($vehicle));

        return Inertia::render('vehicles/Index', [
            'vehicles' => $vehicles,
            'filters' => $filters,
            'summary' => [
                'total' => Vehicle::query()->count(),
                'active' => Vehicle::query()->where('is_active', true)->count(),
                'visible' => Vehicle::query()->where('is_visible_for_booking', true)->count(),
                'incomplete_pricing' => Vehicle::query()
                    ->has('priceTiers', '!=', 15, 'and', fn (Builder $query) => $query->where('is_active', true))
                    ->count(),
            ],
            'options' => [
                'types' => array_column(VehicleType::cases(), 'value'),
                'categories' => $this->categoryOptions(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('vehicles/Create', [
            'types' => array_column(VehicleType::cases(), 'value'),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(
        SaveVehicleRequest $request,
        VehicleCatalogService $catalog,
        AdminAuditService $audit,
    ): RedirectResponse {
        try {
            $vehicle = DB::transaction(function () use ($request, $catalog, $audit): Vehicle {
                $vehicle = $catalog->createVehicle($request->validated());
                $audit->record(
                    $this->admin($request),
                    $vehicle,
                    'vehicle.created',
                    null,
                    $this->auditValues($vehicle),
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );

                return $vehicle;
            });
        } catch (VehicleCatalogException $exception) {
            $this->throwCatalogValidation($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vehicle created. Add prices before publishing it.']);

        return to_route('vehicles.edit', $vehicle);
    }

    public function edit(Vehicle $vehicle, AdminVehiclePresenter $presenter): Response
    {
        $vehicle->load(['category', 'photos', 'priceTiers.season'])->loadCount([
            'photos',
            'bookings',
            'priceTiers as active_price_tiers_count' => fn (Builder $query) => $query->where('is_active', true),
        ]);

        return Inertia::render('vehicles/Edit', [
            'vehicle' => $presenter->detail($vehicle),
            'types' => array_column(VehicleType::cases(), 'value'),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(
        SaveVehicleRequest $request,
        Vehicle $vehicle,
        VehicleCatalogService $catalog,
        AdminAuditService $audit,
    ): RedirectResponse {
        $oldValues = $this->auditValues($vehicle);

        try {
            DB::transaction(function () use ($request, $vehicle, $catalog, $audit, $oldValues): void {
                $updated = $catalog->updateVehicle($vehicle, $request->validated());
                $audit->record(
                    $this->admin($request),
                    $updated,
                    'vehicle.updated',
                    $oldValues,
                    $this->auditValues($updated),
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );
            });
        } catch (VehicleCatalogException $exception) {
            $this->throwCatalogValidation($exception);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Vehicle saved.']);

        return to_route('vehicles.edit', $vehicle);
    }

    /**
     * @param  Builder<Vehicle>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) $filters['search']);

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('external_code', "%{$search}%")
                ->orWhereLike('inventory_code', "%{$search}%"));
        }

        if ($filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }

        if (is_numeric($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if ($filters['state'] === 'active') {
            $query->where('is_active', true);
        } elseif ($filters['state'] === 'inactive') {
            $query->where('is_active', false);
        }

        if ($filters['visibility'] === 'visible') {
            $query->where('is_visible_for_booking', true);
        } elseif ($filters['visibility'] === 'hidden') {
            $query->where('is_visible_for_booking', false);
        }

        if ($filters['pricing'] === 'complete') {
            $query->whereHas('priceTiers', fn (Builder $query) => $query->where('is_active', true), '=', 15);
        } elseif ($filters['pricing'] === 'incomplete') {
            $query->has('priceTiers', '!=', 15, 'and', fn (Builder $query) => $query->where('is_active', true));
        }
    }

    /** @return list<array<string, mixed>> */
    private function categoryOptions(): array
    {
        $categories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $options = [];

        foreach ($categories as $category) {
            $options[] = [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'type' => $category->getRawOriginal('vehicle_type'),
                'is_active' => (bool) $category->is_active,
            ];
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function auditValues(Vehicle $vehicle): array
    {
        return $vehicle->only([
            'external_code',
            'type',
            'category_id',
            'name',
            'inventory_code',
            'year',
            'description',
            'characteristics_text',
            'emoji',
            'is_active',
            'is_visible_for_booking',
            'sort_order',
            'pricing_profile',
        ]);
    }

    private function throwCatalogValidation(VehicleCatalogException $exception): never
    {
        $field = str_starts_with($exception->errorCode, 'category_') ? 'category_id' : 'is_visible_for_booking';

        throw ValidationException::withMessages([$field => $exception->getMessage()]);
    }
}
