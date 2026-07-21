<?php

namespace App\Domain\Bookings\Queries;

use App\Domain\Bookings\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

class AdminBookingQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Booking>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Booking::query()
            ->with(['customer.contacts', 'vehicle', 'latestPriceSnapshot'])
            ->withCount('documents');

        $this->applyFilters($query, $filters);

        [$sort, $direction] = $this->sorting($filters);
        $perPage = in_array((int) ($filters['per_page'] ?? 25), [15, 25, 50], true)
            ? (int) $filters['per_page']
            : 25;

        return $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LazyCollection<int, Booking>
     */
    public function getForExport(array $filters): LazyCollection
    {
        $query = Booking::query()
            ->with(['customer.contacts', 'vehicle', 'latestPriceSnapshot'])
            ->withCount('documents');

        $this->applyFilters($query, $filters);
        [$sort, $direction] = $this->sorting($filters);

        return $query
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->lazy(500);
    }

    /**
     * @param  Builder<Booking>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->whereLike('public_id', "%{$search}%")
                    ->orWhereHas('customer', function (Builder $query) use ($search): void {
                        $query->whereLike('name', "%{$search}%")
                            ->orWhereHas('contacts', fn (Builder $query) => $query
                                ->whereLike('value', "%{$search}%")
                                ->orWhereLike('normalized_value', "%{$search}%"));
                    })
                    ->orWhereHas('vehicle', fn (Builder $query) => $query
                        ->whereLike('name', "%{$search}%")
                        ->orWhereLike('inventory_code', "%{$search}%"));
            });
        }

        $status = BookingStatus::tryFrom((string) ($filters['status'] ?? ''));

        if ($status instanceof BookingStatus) {
            $query->where('status', $status->value);
        } else {
            $scope = (string) ($filters['scope'] ?? 'active');

            if ($scope === 'active') {
                $query->whereIn('status', [BookingStatus::Pending->value, BookingStatus::Approved->value, BookingStatus::Active->value]);
            } elseif ($scope === 'archive') {
                $query->whereIn('status', [
                    BookingStatus::Completed->value,
                    BookingStatus::Cancelled->value,
                    BookingStatus::CancelledByClient->value,
                    BookingStatus::Expired->value,
                    BookingStatus::NoShow->value,
                ]);
            }
        }

        if (is_numeric($filters['vehicle_id'] ?? null)) {
            $query->where('vehicle_id', (int) $filters['vehicle_id']);
        }

        if (is_string($filters['vehicle_type'] ?? null) && $filters['vehicle_type'] !== '') {
            $query->whereHas('vehicle', fn (Builder $query) => $query->where('type', $filters['vehicle_type']));
        }

        if (is_string($filters['source'] ?? null) && $filters['source'] !== '') {
            $query->where('source', $filters['source']);
        }

        if (is_string($filters['starts_from'] ?? null) && $filters['starts_from'] !== '') {
            $query->where('ends_on', '>=', $filters['starts_from']);
        }

        if (is_string($filters['starts_to'] ?? null) && $filters['starts_to'] !== '') {
            $query->where('starts_on', '<=', $filters['starts_to']);
        }

        if (($filters['documents'] ?? null) === 'yes') {
            $query->has('documents');
        } elseif (($filters['documents'] ?? null) === 'no') {
            $query->doesntHave('documents');
        }
    }

    /** @param array<string, mixed> $filters
     * @return array{string, 'asc'|'desc'}
     */
    private function sorting(array $filters): array
    {
        $sort = in_array($filters['sort'] ?? null, ['created_at', 'updated_at', 'starts_on', 'ends_on', 'status'], true)
            ? (string) $filters['sort']
            : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return [$sort, $direction];
    }
}
