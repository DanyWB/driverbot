<?php

namespace App\Models;

use App\Domain\Pricing\Enums\PricingSource;
use App\Domain\Pricing\Enums\PricingTier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['booking_id', 'version', 'total_days', 'tier_key', 'calculated_total', 'rounded_total', 'manual_total', 'final_total', 'currency', 'breakdown', 'pricing_source', 'overridden_by_admin_id', 'override_reason', 'calculated_at'])]
class BookingPriceSnapshot extends Model
{
    /** @return array<int, array<string, mixed>> */
    public function breakdownItems(): array
    {
        $value = $this->getAttribute('breakdown');

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function overriddenByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by_admin_id');
    }

    public function pricingSource(): PricingSource
    {
        return PricingSource::from((string) $this->getRawOriginal('pricing_source'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tier_key' => PricingTier::class,
            'pricing_source' => PricingSource::class,
            'calculated_total' => 'decimal:6',
            'breakdown' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Booking price snapshots are immutable.'));
    }
}
