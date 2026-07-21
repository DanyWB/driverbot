<?php

namespace App\Http\Controllers\Vehicles;

use App\Domain\Shared\Services\AdminAuditService;
use App\Domain\Vehicles\Enums\VehicleType;
use App\Domain\Vehicles\Exceptions\VehicleCatalogException;
use App\Domain\Vehicles\Services\VehicleCatalogService;
use App\Http\Controllers\Concerns\HandlesAdminContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicles\SaveCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use HandlesAdminContext;

    public function index(): Response
    {
        return Inertia::render('categories/Index', [
            'categories' => Category::query()
                ->withCount(['vehicles', 'vehicles as visible_vehicles_count' => fn ($query) => $query->where('is_visible_for_booking', true)])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category): array => $this->present($category)),
            'types' => array_column(VehicleType::cases(), 'value'),
        ]);
    }

    public function store(
        SaveCategoryRequest $request,
        VehicleCatalogService $catalog,
        AdminAuditService $audit,
    ): RedirectResponse {
        $category = DB::transaction(function () use ($request, $catalog, $audit): Category {
            $category = $catalog->createCategory($request->validated());
            $audit->record(
                $this->admin($request),
                $category,
                'category.created',
                null,
                $this->present($category),
                $this->requestId($request),
                $request->ip(),
                $this->userAgent($request),
            );

            return $category;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Category {$category->name} created."]);

        return to_route('categories.index');
    }

    public function update(
        SaveCategoryRequest $request,
        Category $category,
        VehicleCatalogService $catalog,
        AdminAuditService $audit,
    ): RedirectResponse {
        $oldValues = $this->present($category);

        try {
            DB::transaction(function () use ($request, $category, $catalog, $audit, $oldValues): void {
                $updated = $catalog->updateCategory($category, $request->validated());
                $audit->record(
                    $this->admin($request),
                    $updated,
                    'category.updated',
                    $oldValues,
                    $this->present($updated),
                    $this->requestId($request),
                    $request->ip(),
                    $this->userAgent($request),
                );
            });
        } catch (VehicleCatalogException $exception) {
            throw ValidationException::withMessages(['vehicle_type' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Category saved.']);

        return to_route('categories.index');
    }

    /** @return array<string, mixed> */
    private function present(Category $category): array
    {
        return [
            'id' => (int) $category->id,
            'code' => (string) $category->code,
            'name' => (string) $category->name,
            'vehicle_type' => $category->getRawOriginal('vehicle_type'),
            'description' => $category->description,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
            'vehicles_count' => (int) ($category->getAttribute('vehicles_count') ?? $category->vehicles()->count()),
            'visible_vehicles_count' => (int) ($category->getAttribute('visible_vehicles_count') ?? $category->vehicles()->where('is_visible_for_booking', true)->count()),
        ];
    }
}
