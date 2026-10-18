<?php

namespace Tests\Feature;

use App\Domain\Pricing\Exceptions\PricingException;
use App\Domain\Pricing\Services\PricingService;
use App\Domain\Pricing\ValueObjects\RentalPeriod;
use App\Models\Category;
use App\Models\RentalPolicy;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_saves_global_and_category_minimum_and_validation_preserves_it(): void
    {
        $category = Category::factory()->create();
        $this->actingAs(User::factory()->create())->put('/categories/rental-policy', [
            'minimum_days' => 3,
            'categories' => [['id' => $category->id, 'minimum_days' => 5]],
        ])->assertRedirect('/categories');
        $policy = RentalPolicy::query()->findOrFail(1);
        $this->assertSame(3, $policy->minimumFor(null));
        $this->assertSame(5, $policy->minimumFor($category->id));
        $this->put('/categories/rental-policy', ['minimum_days' => 0, 'categories' => []])->assertSessionHasErrors('minimum_days');
        $this->assertSame(3, $policy->fresh()->minimumFor(null));
        $this->put('/categories/rental-policy', [
            'minimum_days' => 3,
            'categories' => [['id' => $category->id, 'minimum_days' => '']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $policy->fresh()->minimumFor($category->id));
    }

    public function test_pricing_rejects_a_short_period_before_calculating_in_every_channel(): void
    {
        RentalPolicy::query()->create(['id' => 1, 'minimum_days' => 3]);
        $vehicle = Vehicle::factory()->create(['is_active' => true, 'is_visible_for_booking' => true]);
        foreach ([true, false] as $customerChannel) {
            try {
                app(PricingService::class)->quote($vehicle, RentalPeriod::fromStrings('2026-10-01', '2026-10-03'), $customerChannel);
                $this->fail('Short rental was accepted.');
            } catch (PricingException $error) {
                $this->assertSame('minimum_rental_days', $error->errorCode);
                $this->assertSame(3, $error->context['minimum_days']);
            }
        }
    }

    public function test_unauthenticated_user_cannot_change_policy(): void
    {
        $this->put('/categories/rental-policy', ['minimum_days' => 1, 'categories' => []])->assertRedirect('/login');
        $this->assertDatabaseCount('rental_policies', 0);
    }
}
