<?php

namespace Tests\Feature;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Shared\Data\IdempotencyResponse;
use App\Domain\Shared\Services\IdempotencyService;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_operation_is_replayed_without_running_it_twice(): void
    {
        $calls = 0;
        $service = app(IdempotencyService::class);
        $operation = function () use (&$calls): IdempotencyResponse {
            $calls++;

            return new IdempotencyResponse(201, ['booking_id' => 'booking-1']);
        };

        $first = $service->execute(null, 'bookings.create', 'request-1', ['vehicle' => 10, 'dates' => ['from' => 'a', 'to' => 'b']], $operation);
        $replayed = $service->execute(null, 'bookings.create', 'request-1', ['dates' => ['to' => 'b', 'from' => 'a'], 'vehicle' => 10], $operation);

        $this->assertSame(1, $calls);
        $this->assertFalse($first->replayed);
        $this->assertTrue($replayed->replayed);
        $this->assertSame($first->body, $replayed->body);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_reusing_a_key_with_another_payload_is_rejected(): void
    {
        $service = app(IdempotencyService::class);
        $operation = fn (): IdempotencyResponse => new IdempotencyResponse(200, ['ok' => true]);
        $service->execute(null, 'bookings.create', 'request-1', ['vehicle' => 10], $operation);

        try {
            $service->execute(null, 'bookings.create', 'request-1', ['vehicle' => 11], $operation);
            $this->fail('An idempotency key was reused with another payload.');
        } catch (BookingException $exception) {
            $this->assertSame('idempotency_key_reused', $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        }
    }

    public function test_failed_operation_rolls_back_the_key_and_can_be_retried(): void
    {
        $service = app(IdempotencyService::class);

        try {
            $service->execute(null, 'bookings.create', 'request-retry', ['vehicle' => 10], function (): IdempotencyResponse {
                Customer::factory()->create();

                throw new RuntimeException('Temporary failure');
            });
            $this->fail('A failed operation unexpectedly completed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Temporary failure', $exception->getMessage());
        }

        $this->assertDatabaseMissing('idempotency_keys', ['idempotency_key' => 'request-retry']);
        $this->assertDatabaseCount('customers', 0);

        $response = $service->execute(
            null,
            'bookings.create',
            'request-retry',
            ['vehicle' => 10],
            fn (): IdempotencyResponse => new IdempotencyResponse(201, ['ok' => true]),
        );

        $this->assertSame(201, $response->status);
    }
}
