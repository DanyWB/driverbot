<?php

namespace Tests\Feature;

use App\Domain\Bookings\Data\BookingActor;
use App\Domain\Bookings\Data\CreateBookingData;
use App\Domain\Bookings\Enums\BookingSource;
use App\Domain\Bookings\Services\BookingService;
use App\Domain\Integrations\Bot\Services\ServiceAuditService;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\User;
use App\Models\VehicleOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

class BotApiBookingDocumentTest extends BotApiTestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_batch_booking_is_atomic_idempotent_and_exposes_client_safe_data(): void
    {
        $customer = $this->syncCustomer('200001');
        $firstVehicle = $this->bookableVehicle('Honda Click');
        $secondVehicle = $this->bookableVehicle('Yamaha NMAX');
        $payload = $this->bookingPayload($firstVehicle, $secondVehicle);
        $headers = $this->apiHeaders('200001', 'telegram-update-500-create');

        $this->withHeaders($headers)->postJson('/api/v1/bot/bookings', $payload)
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.booking.status', 'pending')
            ->assertJsonPath('data.0.booking.options.helmets_quantity', 2)
            ->assertJsonPath('data.0.booking.options.delivery_address', 'Thong Sala Pier')
            ->assertJsonPath('data.0.booking.terms.version', (string) config('business.terms_version'))
            ->assertJsonMissingPath('data.0.booking.admin_note');

        $this->assertDatabaseCount('bookings', 2);
        $this->assertDatabaseCount('booking_price_snapshots', 2);
        $this->assertDatabaseCount('vehicle_occupancies', 2);
        $this->assertDatabaseHas('booking_status_history', [
            'actor_type' => 'service',
            'actor_service_client_id' => $this->serviceClient->id,
        ]);
        $this->assertSame(2, Booking::query()->where('customer_id', $customer->id)->count());

        $this->withHeaders($headers)->postJson('/api/v1/bot/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('meta.idempotency_replayed', true);
        $this->assertDatabaseCount('bookings', 2);

        $this->withHeaders($this->apiHeaders('200001'))
            ->getJson('/api/v1/bot/customers/me/bookings?scope=current')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('meta.has_more', false);

        $firstPage = $this->withHeaders($this->apiHeaders('200001'))
            ->getJson('/api/v1/bot/customers/me/bookings?scope=current&limit=1&offset=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 1)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('meta.has_more', true);
        $firstPageId = $firstPage->json('data.0.public_id');
        $secondPage = $this->withHeaders($this->apiHeaders('200001'))
            ->getJson('/api/v1/bot/customers/me/bookings?scope=current&limit=1&offset=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 1)
            ->assertJsonPath('meta.offset', 1)
            ->assertJsonPath('meta.has_more', false);
        $secondPageId = $secondPage->json('data.0.public_id');
        $this->assertNotSame($firstPageId, $secondPageId);
    }

    public function test_batch_rolls_back_every_item_when_one_vehicle_is_unavailable(): void
    {
        $this->syncCustomer('200002');
        $firstVehicle = $this->bookableVehicle('Honda Click');
        $busyVehicle = $this->bookableVehicle('Yamaha NMAX');
        $otherCustomer = Customer::factory()->create();
        app(BookingService::class)->create(
            new CreateBookingData(
                customerId: $otherCustomer->id,
                vehicleId: $busyVehicle->id,
                startsOn: '2026-08-10',
                endsOn: '2026-08-16',
                source: BookingSource::AdminManual,
            ),
            BookingActor::admin(User::factory()->create()->id),
        );

        $this->withHeaders($this->apiHeaders('200002', 'atomic-conflict'))
            ->postJson('/api/v1/bot/bookings', $this->bookingPayload($firstVehicle, $busyVehicle))
            ->assertConflict()
            ->assertJsonPath('error.code', 'VEHICLE_UNAVAILABLE');

        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(1, VehicleOccupancy::query()->count());
        $this->assertDatabaseMissing('bookings', ['vehicle_id' => $firstVehicle->id]);
    }

    public function test_bot_booking_rejects_past_and_excessively_long_periods(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-09 11:00:00', 'Asia/Bangkok'));
        $this->syncCustomer('200008');
        $vehicle = $this->bookableVehicle();
        $past = $this->bookingPayload($vehicle);
        $past['items'][0]['starts_on'] = '2026-08-08';
        $past['items'][0]['ends_on'] = '2026-08-08';

        $this->withHeaders($this->apiHeaders('200008', 'past-booking'))
            ->postJson('/api/v1/bot/bookings', $past)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['items.0.starts_on'], 'error.fields');

        $long = $this->bookingPayload($vehicle);
        $long['items'][0]['ends_on'] = '2027-08-11';

        $this->withHeaders($this->apiHeaders('200008', 'long-booking'))
            ->postJson('/api/v1/bot/bookings', $long)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.ends_on'], 'error.fields');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_batch_rejects_duplicate_vehicles_and_invalid_time_ranges(): void
    {
        $this->syncCustomer('200010');
        $vehicle = $this->bookableVehicle();
        $duplicate = $this->bookingPayload($vehicle);
        $secondItem = $duplicate['items'][0];
        $secondItem['client_reference'] = 'second-reference';
        $duplicate['items'][] = $secondItem;

        $this->withHeaders($this->apiHeaders('200010', 'duplicate-vehicle'))
            ->postJson('/api/v1/bot/bookings', $duplicate)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.vehicle_id'], 'error.fields');

        $invalidTime = $this->bookingPayload($vehicle);
        $invalidTime['items'][0]['ends_on'] = '2026-08-10';
        $invalidTime['items'][0]['return_time'] = '10:30';

        $this->withHeaders($this->apiHeaders('200010', 'invalid-time-range'))
            ->postJson('/api/v1/bot/bookings', $invalidTime)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.return_time'], 'error.fields');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_delivery_booking_requires_a_non_blank_address(): void
    {
        $this->syncCustomer('200009');
        $vehicle = $this->bookableVehicle();
        $payload = $this->bookingPayload($vehicle);
        $payload['items'][0]['delivery_address'] = '   ';

        $this->withHeaders($this->apiHeaders('200009', 'delivery-without-address'))
            ->postJson('/api/v1/bot/bookings', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['items.0.delivery_address'], 'error.fields');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_customer_cannot_read_another_booking_and_cancel_is_idempotent(): void
    {
        $this->syncCustomer('200003');
        $vehicle = $this->bookableVehicle();
        $response = $this->withHeaders($this->apiHeaders('200003', 'create-one'))
            ->postJson('/api/v1/bot/bookings', $this->bookingPayload($vehicle))
            ->assertCreated();
        $publicId = (string) $response->json('data.0.booking.public_id');

        $this->syncCustomer('200004');
        $this->withHeaders($this->apiHeaders('200004'))
            ->getJson("/api/v1/bot/bookings/{$publicId}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'BOOKING_NOT_FOUND');

        $headers = $this->apiHeaders('200003', 'cancel-one');
        $this->withHeaders($headers)
            ->postJson("/api/v1/bot/bookings/{$publicId}/cancel", ['reason' => 'Plans changed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled_by_client');
        $this->withHeaders($headers)
            ->postJson("/api/v1/bot/bookings/{$publicId}/cancel", ['reason' => 'Plans changed'])
            ->assertOk()
            ->assertJsonPath('meta.idempotency_replayed', true);
        $this->assertDatabaseCount('vehicle_occupancies', 0);
    }

    public function test_admin_cancellation_reason_is_exposed_without_internal_notes(): void
    {
        $this->syncCustomer('200010');
        $vehicle = $this->bookableVehicle();
        $response = $this->withHeaders($this->apiHeaders('200010', 'create-for-admin-cancel'))
            ->postJson('/api/v1/bot/bookings', $this->bookingPayload($vehicle))
            ->assertCreated();
        $publicId = (string) $response->json('data.0.booking.public_id');
        $booking = Booking::query()->where('public_id', $publicId)->sole();
        $booking->forceFill(['admin_note' => 'Internal capacity note'])->save();
        $reason = 'Unavailable <b>today</b> & tomorrow';

        app(BookingService::class)->cancelByAdmin(
            $booking,
            BookingActor::admin(User::factory()->create()->id),
            $reason,
        );

        $this->withHeaders($this->apiHeaders('200010'))
            ->getJson("/api/v1/bot/bookings/{$publicId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation.reason', $reason)
            ->assertJsonMissingPath('data.admin_note');

        $this->withHeaders($this->apiHeaders('200010'))
            ->getJson('/api/v1/bot/customers/me/bookings?scope=history')
            ->assertOk()
            ->assertJsonPath('data.0.cancellation.reason', $reason)
            ->assertJsonMissingPath('data.0.admin_note');
    }

    public function test_approved_booking_requires_manager_cancellation_inside_24_hours(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-09 11:00:00', 'Asia/Bangkok'));
        config()->set('business.manager_telegram', '@drive_phangan');

        $this->syncCustomer('200006');
        $vehicle = $this->bookableVehicle();
        $response = $this->withHeaders($this->apiHeaders('200006', 'create-approved'))
            ->postJson('/api/v1/bot/bookings', $this->bookingPayload($vehicle))
            ->assertCreated();
        $publicId = (string) $response->json('data.0.booking.public_id');
        $booking = Booking::query()->where('public_id', $publicId)->sole();

        app(BookingService::class)->approve(
            $booking,
            BookingActor::admin(User::factory()->create()->id),
        );

        $this->withHeaders($this->apiHeaders('200006'))
            ->getJson("/api/v1/bot/bookings/{$publicId}")
            ->assertOk()
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.cancellation.requires_manager', true)
            ->assertJsonPath('data.cancellation.manager_telegram', '@drive_phangan');

        $this->withHeaders($this->apiHeaders('200006', 'cancel-approved'))
            ->postJson("/api/v1/bot/bookings/{$publicId}/cancel")
            ->assertConflict()
            ->assertJsonPath('error.code', 'CLIENT_CANCELLATION_REQUIRES_MANAGER')
            ->assertJsonPath('error.details.manager_telegram', '@drive_phangan');

        $this->assertSame('approved', $booking->fresh()->getRawOriginal('status'));
        $this->assertDatabaseHas('vehicle_occupancies', ['booking_id' => $booking->id]);
    }

    public function test_private_document_upload_is_owned_validated_and_replayed_without_duplicate(): void
    {
        Storage::fake('private');
        $customer = $this->syncCustomer('200005');
        $headers = $this->apiHeaders('200005', 'passport-upload');

        $this->withHeaders($headers)->post('/api/v1/bot/customers/me/documents', [
            'type' => 'passport',
            'document' => UploadedFile::fake()->image('passport.jpg', 1200, 800),
        ])->assertCreated()->assertJsonPath('data.type', 'passport');

        $document = CustomerDocument::query()->sole();
        $this->assertSame($customer->id, $document->customer_id);
        $this->assertSame('customer', $document->uploaded_by);
        Storage::disk('private')->assertExists($document->file_path);

        $this->withHeaders($headers)->post('/api/v1/bot/customers/me/documents', [
            'type' => 'passport',
            'document' => UploadedFile::fake()->image('passport.jpg', 1200, 800),
        ])->assertCreated()->assertJsonPath('meta.idempotency_replayed', true);
        $this->assertDatabaseCount('customer_documents', 1);

        $this->withHeaders($headers)->post('/api/v1/bot/customers/me/documents', [
            'type' => 'passport',
            'document' => UploadedFile::fake()->image('passport.jpg', 600, 400),
        ])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_document_file_is_removed_when_database_transaction_fails(): void
    {
        Storage::fake('private');
        $this->syncCustomer('200007');
        $audit = Mockery::mock(ServiceAuditService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(ServiceAuditService::class, $audit);

        $this->withHeaders($this->apiHeaders('200007', 'failed-passport-upload'))
            ->post('/api/v1/bot/customers/me/documents', [
                'type' => 'passport',
                'document' => UploadedFile::fake()->image('passport.jpg', 1200, 800),
            ])
            ->assertInternalServerError()
            ->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertDatabaseCount('customer_documents', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
