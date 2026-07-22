<?php

namespace App\Http\Controllers\Api\V1\Bot;

use App\Domain\Integrations\Bot\Exceptions\BotApiException;
use App\Domain\Integrations\Bot\Presenters\BotApiPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bot\AvailableBotVehiclesRequest;
use App\Http\Requests\Api\V1\Bot\ListBotVehiclesRequest;
use App\Http\Requests\Api\V1\Bot\VehicleAvailabilityRequest;
use App\Http\Responses\BotApiResponse;
use App\Models\Category;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function categories(Request $request, BotApiPresenter $presenter): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('vehicles', fn (Builder $query) => $query
                ->where('is_active', true)
                ->where('is_visible_for_booking', true))
            ->withCount(['vehicles as vehicles_count' => fn (Builder $query) => $query
                ->where('is_active', true)
                ->where('is_visible_for_booking', true)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => $presenter->category($category))
            ->values()
            ->all();

        return BotApiResponse::success($request, $categories);
    }

    public function vehicles(ListBotVehiclesRequest $request, BotApiPresenter $presenter): JsonResponse
    {
        $query = $this->vehicleQuery();
        $this->filters($query, $request->validated());

        return BotApiResponse::success($request, $query->get()
            ->map(fn (Vehicle $vehicle): array => $presenter->vehicle($vehicle))
            ->values()
            ->all());
    }

    public function available(AvailableBotVehiclesRequest $request, BotApiPresenter $presenter): JsonResponse
    {
        $data = $request->validated();
        $query = $this->vehicleQuery();
        $this->filters($query, $data);
        $query->whereDoesntHave('occupancies', fn (Builder $query) => $query
            ->where('blocks_availability', true)
            ->where('starts_on', '<=', $data['end_date'])
            ->where('ends_on', '>=', $data['start_date']));

        return BotApiResponse::success($request, $query->get()
            ->map(fn (Vehicle $vehicle): array => $presenter->vehicle($vehicle))
            ->values()
            ->all());
    }

    public function show(Request $request, int $vehicle, BotApiPresenter $presenter): JsonResponse
    {
        $model = $this->vehicleQuery()->find($vehicle);

        if (! $model instanceof Vehicle) {
            throw new BotApiException('vehicle_not_found', 'Vehicle was not found.', 404);
        }

        return BotApiResponse::success($request, $presenter->vehicle($model));
    }

    public function availability(VehicleAvailabilityRequest $request, int $vehicle): JsonResponse
    {
        $model = $this->vehicleQuery()->find($vehicle);

        if (! $model instanceof Vehicle) {
            throw new BotApiException('vehicle_not_found', 'Vehicle was not found.', 404);
        }

        $data = $request->validated();
        $start = CarbonImmutable::parse((string) $data['start_date'])->startOfDay();
        $end = CarbonImmutable::parse((string) $data['end_date'])->startOfDay();
        $blocked = [];
        $occupancies = $model->occupancies()
            ->where('blocks_availability', true)
            ->where('starts_on', '<=', $data['end_date'])
            ->where('ends_on', '>=', $data['start_date'])
            ->get(['starts_on', 'ends_on']);

        foreach ($occupancies as $occupancy) {
            $cursor = CarbonImmutable::parse($occupancy->starts_on)->max($start);
            $last = CarbonImmutable::parse($occupancy->ends_on)->min($end);

            while ($cursor->lte($last)) {
                $blocked[$cursor->toDateString()] = true;
                $cursor = $cursor->addDay();
            }
        }

        $unavailableDates = array_keys($blocked);
        sort($unavailableDates);

        return BotApiResponse::success($request, [
            'vehicle_id' => $model->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'available' => $unavailableDates === [],
            'unavailable_dates' => $unavailableDates,
        ]);
    }

    /** @return Builder<Vehicle> */
    private function vehicleQuery(): Builder
    {
        return Vehicle::query()
            ->where('is_active', true)
            ->where('is_visible_for_booking', true)
            ->with([
                'category' => function (Relation $relation): void {
                    $relation->getQuery()->withCount([
                        'vehicles' => fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->where('is_visible_for_booking', true),
                    ]);
                },
                'photos',
            ])
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * @param  Builder<Vehicle>  $query
     * @param  array<string, mixed>  $filters
     */
    private function filters(Builder $query, array $filters): void
    {
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
    }
}
