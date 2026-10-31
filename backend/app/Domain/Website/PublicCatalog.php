<?php

namespace App\Domain\Website;

use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\RentalPolicy;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicCatalog
{
    /** @return Builder<Vehicle> */
    public function query(): Builder
    {
        return $this->eligible(Vehicle::query())->with(['photos', 'category', 'pricingPreset']);
    }

    /** @param Builder<Vehicle> $query
     * @return Builder<Vehicle>
     */
    public function eligible(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_visible_for_booking', true)
            ->whereNull('archived_at')->where(fn (Builder $q) => $q->whereNull('category_id')->orWhereHas('category', fn (Builder $q) => $q->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('vehicle_type')->orWhereColumn('categories.vehicle_type', 'vehicles.type'))));
    }

    public function find(int $id): Vehicle
    {
        return $this->query()->findOrFail($id);
    }

    public function slug(Vehicle $vehicle): string
    {
        return $vehicle->id.'-'.(Str::slug($vehicle->name) ?: 'vehicle');
    }

    /** @return array<string, mixed> */
    public function quote(Vehicle $vehicle, string $start, string $end): array
    {
        $quote = app(PricingService::class)->quote($vehicle, RentalPeriod::fromStrings($start, $end));

        return [
            'start_date' => $quote->startsOn, 'end_date' => $quote->endsOn,
            'total_days' => $quote->totalDays, 'final_total' => $quote->finalTotal,
            'currency' => $quote->currency, 'average_daily' => round($quote->finalTotal / $quote->totalDays, 2),
            // RentalPolicy currently has no approved public payment schedule.
            // Do not turn unknown deposits into zero, or include them in rental price.
            'payments' => [
                'prepayment' => ['amount' => null, 'currency' => null, 'status' => 'pending_confirmation'],
                'deposit' => ['amount' => null, 'currency' => null, 'status' => 'pending_confirmation'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dates
     * @return array<string, mixed>
     */
    public function present(Vehicle $vehicle, string $locale, array $dates = []): array
    {
        $photos = $vehicle->photos->filter(fn ($photo) => $photo->disk === 'public'
            && ! str_contains($photo->file_path, '..') && ! str_starts_with($photo->file_path, '/'))
            ->map(fn ($photo) => ['url' => Storage::disk('public')->url($photo->file_path), 'alt' => $photo->alt_text ?: $vehicle->name])->values()->all();
        if ($photos === [] && config('website.demo') && app()->environment(['local', 'testing'])
            && str_starts_with($vehicle->external_code, 'WEBSITE-DEMO-')
            && $vehicle->pricingPreset?->name === 'HR-V 2023' && is_file(public_path('website/images/demo-honda-hrv.webp'))) {
            $photos[] = ['url' => '/website/images/demo-honda-hrv.webp', 'alt' => 'DEMO Honda HR-V 2023 — supplied reference image'];
        }
        $quote = null;
        if (! empty($dates['starts_on']) && ! empty($dates['ends_on'])) {
            try {
                $quote = $this->quote($vehicle, $dates['starts_on'], $dates['ends_on']);
            } catch (PricingException) {
                // Missing/incomplete pricing is not represented as an invented public price.
            }
        }
        $description = match ($locale) {
            'ru' => $vehicle->description_ru, 'uk' => $vehicle->description_ua, default => $vehicle->description
        };
        $minimumDays = RentalPolicy::query()->find(1)?->minimumFor($vehicle->category_id) ?? 1;
        $linkDates = collect($dates)->only(['starts_on', 'ends_on'])->filter(fn ($value) => is_string($value) && $value !== '')->all();

        return [
            'id' => (int) $vehicle->id, 'name' => $vehicle->name, 'type' => $vehicle->getRawOriginal('type'),
            'slug' => $this->slug($vehicle), 'url' => route('website.vehicle', ['locale' => $locale, 'slug' => $this->slug($vehicle), ...$linkDates]),
            'booking_url' => route('website.booking', ['locale' => $locale, 'vehicle' => $vehicle->id, ...$linkDates]),
            ...$vehicle->only(['year', 'brand', 'model', 'engine_cc', 'color', 'abs', 'seats', 'transmission']),
            'category' => $vehicle->category?->name, 'description' => $description ?: $vehicle->description,
            'photos' => $photos, 'primary_photo' => $photos[0] ?? null, 'quote' => $quote,
            'minimum_days' => $minimumDays,
            'pricePreview' => $this->pricePreview($vehicle, $minimumDays),
        ];
    }

    /** @return array<string, mixed> */
    private function pricePreview(Vehicle $vehicle, int $minimumDays): array
    {
        $today = WebsitePickupDate::earliest();
        $startsOn = $today->format('Y-m-d');
        $previews = ['starts_on' => $startsOn, 'minimum_days' => $minimumDays, 'day' => null, 'month' => null];
        foreach (['day' => $today->addDays($minimumDays), 'month' => $today->addMonthNoOverflow()] as $kind => $end) {
            try {
                $previews[$kind] = $this->quote($vehicle, $startsOn, $end->format('Y-m-d'));
            } catch (PricingException) {
                // Illustrative dated pricing only: not a fallback tariff or availability promise.
            }
        }

        return $previews;
    }
}
