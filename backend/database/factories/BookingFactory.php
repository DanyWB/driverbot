<?php

namespace Database\Factories;

use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Booking> */
class BookingFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsOn = fake()->dateTimeBetween('now', '+2 months');
        $endsOn = (clone $startsOn)->modify('+'.fake()->numberBetween(0, 14).' days');

        return [
            'customer_id' => Customer::factory(),
            'vehicle_id' => Vehicle::factory(),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => BookingStatus::Process,
            'source' => BookingSource::Telegram,
        ];
    }
}
