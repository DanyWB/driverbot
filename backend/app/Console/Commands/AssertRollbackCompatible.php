<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AssertRollbackCompatible extends Command
{
    protected $signature = 'operations:rollback-guard
        {target_backend : Absolute path to the backend directory of the rollback target}';

    protected $description = 'Fail closed when a legacy release cannot safely read the current booking data';

    public function handle(): int
    {
        $argument = $this->argument('target_backend');
        if (! is_string($argument) || trim($argument) === '') {
            $this->error('Rollback compatibility check requires a target backend directory.');

            return self::FAILURE;
        }

        $targetBackend = realpath($argument);
        if ($targetBackend === false || ! is_dir($targetBackend) || ! is_file($targetBackend.DIRECTORY_SEPARATOR.'artisan')) {
            $this->error('Rollback target backend cannot be inspected; rollback denied.');

            return self::FAILURE;
        }

        try {
            if (! is_file($targetBackend.'/app/Http/Middleware/EnsureAdminSessionVersion.php')
                && Schema::hasColumn('admin_users', 'session_version')
                && DB::table('admin_users')->where('session_version', '>', 0)->exists()) {
                $this->error('Rollback denied: target would restore sessions invalidated by Telegram password recovery.');

                return self::FAILURE;
            }
        } catch (Throwable) {
            $this->error('Session compatibility inspection failed; rollback denied.');

            return self::FAILURE;
        }

        if (is_file($targetBackend.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR.'Bookings'.DIRECTORY_SEPARATOR.'Services'.DIRECTORY_SEPARATOR.'PendingRequestPolicy.php')) {
            $this->info('Rollback target supports the current booking request policy.');

            return self::SUCCESS;
        }

        try {
            $reasons = $this->legacyIncompatibilityReasons();
        } catch (Throwable) {
            $this->error('Database compatibility inspection failed; rollback to the legacy release is denied.');

            return self::FAILURE;
        }

        if ($reasons !== []) {
            $this->error('Rollback to the legacy release is incompatible with current data: '.implode(', ', $reasons).'.');

            return self::FAILURE;
        }

        $this->info('No current-generation booking data was found; legacy rollback is compatible.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function legacyIncompatibilityReasons(): array
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', 'status')) {
            throw new \RuntimeException('Bookings schema is unavailable.');
        }

        $reasons = [];
        if (DB::table('bookings')->where('status', 'cancelled_unavailable')->exists()) {
            $reasons[] = 'cancelled_unavailable bookings exist';
        }

        if ($this->bookingDataExists('request_policy_version', fn (Builder $query): Builder => $query->where('request_policy_version', '>=', 2))) {
            $reasons[] = 'request policy v2 bookings exist';
        }

        if ($this->bookingDataExists('details_version', fn (Builder $query): Builder => $query->where('details_version', '>', 1))
            || $this->anyBookingColumnHasValue(['contact_name', 'contact_phone', 'contact_telegram'])) {
            $reasons[] = 'booking-local contact or detail changes exist';
        }

        if ($this->anyBookingColumnHasValue([
            'prepayment_amount',
            'prepayment_currency',
            'prepayment_received_at',
            'prepayment_received_by_admin_id',
            'deposit_amount',
            'deposit_currency',
            'deposit_received_at',
            'deposit_received_by_admin_id',
        ])) {
            $reasons[] = 'prepayment or deposit data exists';
        }

        if (Schema::hasTable('customers') && $this->anyColumnHasValue('customers', ['terms_version', 'terms_accepted_at'])) {
            $reasons[] = 'versioned customer terms acceptance exists';
        }

        return $reasons;
    }

    /** @param callable(Builder): Builder $constraint */
    private function bookingDataExists(string $column, callable $constraint): bool
    {
        if (! Schema::hasColumn('bookings', $column)) {
            return false;
        }

        return $constraint(DB::table('bookings'))->exists();
    }

    /** @param list<string> $columns */
    private function anyBookingColumnHasValue(array $columns): bool
    {
        return $this->anyColumnHasValue('bookings', $columns);
    }

    /** @param list<string> $columns */
    private function anyColumnHasValue(string $table, array $columns): bool
    {
        $present = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
        if ($present === []) {
            return false;
        }

        return DB::table($table)
            ->where(function (Builder $query) use ($present): void {
                foreach ($present as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->exists();
    }
}
