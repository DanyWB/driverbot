<?php

namespace Tests\Feature;

use App\Domain\Shared\Services\AdminAuditService;
use App\Models\Booking;
use App\Models\BookingPriceSnapshot;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerDocument;
use App\Models\CustomerIdentity;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class AdminCustomersDocumentsCsvTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->admin = User::factory()->create();
    }

    public function test_customer_routes_and_private_documents_require_authentication(): void
    {
        $customer = Customer::factory()->create();
        Storage::fake('private');
        Storage::disk('private')->put('customers/1/documents/test.pdf', 'private');
        $document = CustomerDocument::query()->create([
            'customer_id' => $customer->id,
            'type' => 'passport',
            'disk' => 'private',
            'file_path' => 'customers/1/documents/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 7,
            'uploaded_by' => 'admin',
        ]);

        $this->get(route('customers.index'))->assertRedirect(route('login'));
        $this->get(route('customers.show', $customer))->assertRedirect(route('login'));
        $this->get(route('documents.download', $document))->assertRedirect(route('login'));
    }

    public function test_admin_searches_customer_and_opens_history_with_default_booking_selection(): void
    {
        $customer = Customer::factory()->create(['name' => 'Alex Search']);
        CustomerContact::query()->create([
            'customer_id' => $customer->id,
            'type' => 'phone',
            'value' => '+66 81 234 5678',
            'normalized_value' => '+66812345678',
            'is_primary' => true,
        ]);
        CustomerIdentity::query()->create([
            'customer_id' => $customer->id,
            'provider' => 'telegram',
            'external_id' => '987654321',
        ]);
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->admin)
            ->get(route('customers.index', ['search' => '812345678']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/Index')
                ->where('customers.total', 1)
                ->where('customers.data.0.name', 'Alex Search'));

        $this->actingAs($this->admin)
            ->get(route('customers.index', ['search' => '987654321']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/Index')
                ->where('customers.total', 1)
                ->where('customers.data.0.name', 'Alex Search'));

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('customers/Show')
                ->where('customer.id', $customer->id)
                ->where('bookings.total', 1)
                ->where('bookings.data.0.public_id', $booking->public_id));

        $this->actingAs($this->admin)
            ->get(route('bookings.create', ['customer_id' => $customer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bookings/Create')
                ->where('default_customer.id', $customer->id)
                ->where('default_customer.name', 'Alex Search'));
    }

    public function test_admin_uploads_downloads_and_deletes_private_customer_document(): void
    {
        Storage::fake('private');
        $customer = Customer::factory()->create();

        $this->actingAs($this->admin)->post(route('customers.documents.store', $customer), [
            'type' => 'passport',
            'document' => UploadedFile::fake()->create('passport.pdf', 120, 'application/pdf'),
        ])->assertRedirect(route('customers.show', $customer));

        $document = CustomerDocument::query()->sole();
        Storage::disk('private')->assertExists($document->file_path);
        Storage::disk('public')->assertMissing($document->file_path);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => CustomerDocument::class,
            'subject_id' => (string) $document->id,
            'action' => 'customer_document.uploaded',
        ]);

        $this->actingAs($this->admin)
            ->get(route('documents.download', $document))
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload('passport.pdf');

        $path = (string) $document->file_path;
        $this->actingAs($this->admin)
            ->delete(route('documents.destroy', $document))
            ->assertRedirect(route('customers.show', $customer));

        Storage::disk('private')->assertMissing($path);
        $this->assertDatabaseMissing('customer_documents', ['id' => $document->id]);
    }

    public function test_booking_document_is_linked_and_invalid_mime_is_rejected(): void
    {
        Storage::fake('private');
        $booking = Booking::factory()->create();

        $this->actingAs($this->admin)->post(route('bookings.documents.store', $booking), [
            'type' => 'driver_license',
            'document' => UploadedFile::fake()->image('license.jpg'),
        ])->assertRedirect(route('bookings.show', $booking));

        $this->assertDatabaseHas('customer_documents', [
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'type' => 'driver_license',
        ]);

        $this->actingAs($this->admin)->post(route('bookings.documents.store', $booking), [
            'type' => 'other',
            'document' => UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
        ])->assertSessionHasErrors('document');
        $this->assertDatabaseCount('customer_documents', 1);
    }

    public function test_failed_document_audit_rolls_back_database_and_private_file(): void
    {
        Storage::fake('private');
        $customer = Customer::factory()->create();
        $this->mock(AdminAuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable.'));
        });

        $this->actingAs($this->admin)->post(route('customers.documents.store', $customer), [
            'type' => 'passport',
            'document' => UploadedFile::fake()->create('passport.pdf', 120, 'application/pdf'),
        ])->assertServerError();

        $this->assertDatabaseCount('customer_documents', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_csv_uses_current_filters_bom_excel_delimiter_and_formula_protection(): void
    {
        $matching = $this->bookingForCsv('=Formula Customer', '@Formula Bike', 'admin_phone', "\t=internal formula");
        $this->bookingForCsv('Other Customer', 'Other Bike', 'telegram', null);

        $response = $this->actingAs($this->admin)->get(route('bookings.export', [
            'scope' => 'all',
            'source' => 'admin_phone',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('booking_id;status;source;client_name', $csv);
        $this->assertStringContainsString((string) $matching->public_id, $csv);
        $this->assertStringContainsString("'=Formula Customer", $csv);
        $this->assertStringContainsString("'@Formula Bike", $csv);
        $this->assertStringContainsString("'\t=internal formula", $csv);
        $this->assertStringNotContainsString('Other Customer', $csv);
    }

    private function bookingForCsv(string $customerName, string $vehicleName, string $source, ?string $adminNote): Booking
    {
        $customer = Customer::factory()->create(['name' => $customerName]);
        $vehicle = Vehicle::factory()->create(['name' => $vehicleName]);
        $booking = Booking::factory()->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'source' => $source,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-07',
            'admin_note' => $adminNote,
        ]);
        BookingPriceSnapshot::query()->create([
            'booking_id' => $booking->id,
            'version' => 1,
            'total_days' => 7,
            'tier_key' => '7d',
            'calculated_total' => '700.000000',
            'rounded_total' => 700,
            'manual_total' => null,
            'final_total' => 700,
            'currency' => 'THB',
            'breakdown' => [],
            'pricing_source' => 'automatic',
            'calculated_at' => now(),
        ]);

        return $booking;
    }
}
