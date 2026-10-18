<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RollbackCompatibilityGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $legacyBackend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyBackend = storage_path('framework/testing/rollback-guard-'.bin2hex(random_bytes(6)));
        mkdir($this->legacyBackend, 0777, true);
        touch($this->legacyBackend.'/artisan');
    }

    protected function tearDown(): void
    {
        if (is_file($this->legacyBackend.'/artisan')) {
            unlink($this->legacyBackend.'/artisan');
        }
        if (is_dir($this->legacyBackend)) {
            rmdir($this->legacyBackend);
        }

        parent::tearDown();
    }

    public function test_current_policy_target_is_compatible_without_legacy_data_restrictions(): void
    {
        $booking = Booking::factory()->create();
        DB::table('bookings')->where('id', $booking->id)->update([
            'request_policy_version' => 2,
            'status' => 'cancelled_unavailable',
        ]);

        $this->rollbackGuard(base_path())
            ->expectsOutputToContain('supports the current booking request policy')
            ->assertSuccessful();
    }

    public function test_pristine_additive_schema_allows_a_legacy_target(): void
    {
        $this->rollbackGuard($this->legacyBackend)
            ->expectsOutputToContain('legacy rollback is compatible')
            ->assertSuccessful();
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('incompatibleLegacyDataProvider')]
    public function test_legacy_target_is_rejected_after_current_generation_data_exists(string $table, array $attributes): void
    {
        if ($table === 'customers') {
            $record = Customer::factory()->create();
        } else {
            $record = Booking::factory()->create();
        }
        DB::table($table)->where('id', $record->id)->update($attributes);

        $this->rollbackGuard($this->legacyBackend)
            ->expectsOutputToContain('incompatible with current data')
            ->assertFailed();
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function incompatibleLegacyDataProvider(): iterable
    {
        yield 'request policy v2' => ['bookings', ['request_policy_version' => 2]];
        yield 'new terminal status' => ['bookings', ['status' => 'cancelled_unavailable']];
        yield 'financial detail' => ['bookings', ['prepayment_amount' => 1500, 'prepayment_currency' => 'THB']];
        yield 'booking-local detail' => ['bookings', ['details_version' => 2, 'contact_name' => 'Local contact']];
        yield 'persistent terms acceptance' => ['customers', ['terms_version' => '2026-10', 'terms_accepted_at' => '2026-10-18 00:00:00']];
    }

    public function test_unavailable_database_schema_denies_legacy_rollback(): void
    {
        Schema::drop('bookings');

        $this->rollbackGuard($this->legacyBackend)
            ->expectsOutputToContain('Database compatibility inspection failed')
            ->assertFailed();
    }

    private function rollbackGuard(string $targetBackend): PendingCommand
    {
        $command = $this->artisan('operations:rollback-guard', ['target_backend' => $targetBackend]);
        if (! $command instanceof PendingCommand) {
            self::fail('The rollback guard did not return a pending Artisan command.');
        }

        return $command;
    }
}
