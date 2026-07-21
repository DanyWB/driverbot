<?php

namespace App\Models;

use App\Domain\Availability\Enums\OccupancyType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'booking_id', 'type', 'starts_on', 'ends_on', 'blocks_availability', 'label', 'created_by_admin_id', 'metadata'])]
class VehicleOccupancy extends Model
{
    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => OccupancyType::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'blocks_availability' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
