<?php

namespace Tests\Feature;

use App\Domain\Customers\Services\CustomerTermsService;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingDetailsAndTermsTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipts_are_independent_audited_and_contacts_are_booking_local(): void
    {
        $booking = Booking::factory()->create();
        $admin = User::factory()->create();
        $original = $booking->customer->name;
        $other = Booking::factory()->create(['customer_id' => $booking->customer_id]);
        $data = $this->payload();
        $url = route('bookings.details.update', $booking);
        $this->actingAs($admin)->patch($url, $data)->assertSessionHasNoErrors();
        $booking->refresh();
        $this->assertSame('Full Client Name', $booking->contact_name);
        $this->assertSame($original, $booking->customer->fresh()->name);
        $this->assertNull($other->fresh()->contact_name);
        $this->assertSame('1500.00', $booking->prepayment_amount);
        $this->assertSame('200.00', $booking->deposit_amount);
        $this->assertSame($admin->id, $booking->prepayment_received_by_admin_id);
        $this->assertNotNull($booking->prepayment_received_at);
        $this->assertNull($booking->deposit_received_at);
        $this->assertSame('process', $booking->getRawOriginal('status'));
        $this->assertSame(2, $booking->details_version);
        $this->patch($url, $data)->assertSessionHasErrors('expected_details_version');
        $data['expected_details_version'] = 2;
        $data['payments']['prepayment']['amount'] = 1600;
        $this->patch($url, $data)->assertSessionHasNoErrors();
        $this->assertNull($booking->refresh()->prepayment_received_at);
        $this->assertSame('USD', $booking->deposit_currency);

        $data['expected_details_version'] = 3;
        $data['payments']['prepayment']['received'] = false;
        $data['payments']['deposit']['received'] = true;
        $this->patch($url, $data)->assertSessionHasNoErrors();
        $this->assertNotNull($booking->refresh()->deposit_received_at);
        $this->assertNull($booking->prepayment_received_at);

        $data['expected_details_version'] = 4;
        $data['payments']['deposit']['currency'] = 'THB';
        $this->patch($url, $data)->assertSessionHasNoErrors();
        $this->assertNull($booking->refresh()->deposit_received_at);
        $this->assertNull($booking->deposit_received_by_admin_id);
        $this->assertNull($booking->prepayment_received_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'booking.details_updated']);
    }

    public function test_receipt_requires_amount_and_money_precision_is_validated(): void
    {
        $booking = Booking::factory()->create();
        $url = route('bookings.details.update', $booking);
        $this->patch($url, $this->payload())->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        foreach ([null, -1, '10.001'] as $invalid) {
            $data = $this->payload();
            $data['payments']['prepayment']['amount'] = $invalid;
            $this->patchJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('payments.prepayment.amount');
        }
        $data = $this->payload();
        $data['payments']['deposit']['currency'] = 'EUR';
        $this->patchJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('payments.deposit.currency');
    }

    public function test_terms_acceptance_survives_reload_and_repeating_does_not_change_timestamp(): void
    {
        $customer = Customer::factory()->create();
        config(['business.terms_version' => 'test-v1']);
        $terms = app(CustomerTermsService::class);
        $terms->accept($customer, 'test-v1');
        $accepted = $customer->fresh()->terms_accepted_at;
        $this->travel(10)->minutes();
        $terms->accept($customer->fresh(), 'test-v1');
        $this->assertTrue($accepted->equalTo($customer->fresh()->terms_accepted_at));
        config(['business.terms_version' => 'test-v2']);
        $terms->accept($customer->fresh(), 'test-v2');
        $this->assertSame('test-v2', $customer->fresh()->terms_version);
        $this->assertTrue($customer->fresh()->terms_accepted_at->greaterThan($accepted));
    }

    private function payload(): array
    {
        return ['expected_details_version' => 1, 'contact_name' => 'Full Client Name', 'contact_phone' => '+660000000', 'contact_telegram' => '@client',
            'payments' => ['prepayment' => ['amount' => '1500', 'currency' => 'THB', 'received' => true],
                'deposit' => ['amount' => '200', 'currency' => 'USD', 'received' => false]]];
    }
}
