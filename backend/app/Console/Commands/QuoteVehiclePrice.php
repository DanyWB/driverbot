<?php

namespace App\Console\Commands;

use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use InvalidArgumentException;

class QuoteVehiclePrice extends Command
{
    /** @var string */
    protected $signature = 'pricing:quote
        {vehicle : Vehicle external code}
        {starts_on : Inclusive start date in YYYY-MM-DD}
        {ends_on : Inclusive end date in YYYY-MM-DD}
        {--allow-hidden : Allow an administrative quote for hidden/inactive equipment}';

    /** @var string */
    protected $description = 'Calculate a diagnostic vehicle quote using the production PricingService';

    public function handle(PricingService $pricing): int
    {
        $vehicleCode = (string) $this->argument('vehicle');
        $vehicle = Vehicle::query()->where('external_code', $vehicleCode)->first();

        if (! $vehicle instanceof Vehicle) {
            $this->error("Vehicle not found: {$vehicleCode}");

            return self::FAILURE;
        }

        try {
            $period = RentalPeriod::fromStrings(
                (string) $this->argument('starts_on'),
                (string) $this->argument('ends_on'),
                (string) config('business.timezone'),
            );
            $quote = $pricing->quote($vehicle, $period, requireBookable: ! $this->option('allow-hidden'));
        } catch (PricingException|InvalidArgumentException $exception) {
            $this->error($exception instanceof PricingException
                ? "{$exception->errorCode}: {$exception->getMessage()}"
                : $exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($quote->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
