<?php

namespace App\Console\Commands;

use App\Models\GoogleCalendarReconciliation;
use Illuminate\Console\Command;

/** Read-only operational view of uncertain Google Calendar mappings. */
class ReportGoogleCalendarConvergence extends Command
{
    protected $signature = 'google-calendar:convergence-status {--limit=100 : Maximum unresolved mappings to display}';

    protected $description = 'Report unresolved Google Calendar convergence work';

    public function handle(): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $rows = GoogleCalendarReconciliation::query()
            ->with('booking:id,public_id', 'booking.googleCalendarEvents')
            ->where(function ($query): void {
                $query->whereNotNull('uncertain_since')
                    ->orWhereColumn('requested_generation', '>', 'completed_generation')
                    ->orWhere('retry_count', '>', 0)
                    ->orWhere('manual_review_required', true)
                    ->orWhereNotNull('unexpected_event_id')
                    ->orWhere(function ($lease): void {
                        $lease->whereNotNull('lease_token')
                            ->where(function ($expired): void {
                                $expired->whereNull('locked_until')->orWhere('locked_until', '<=', now());
                            });
                    });
            })
            ->orderByDesc('manual_review_required')
            ->orderBy('available_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No unresolved uncertain Google Calendar mappings.');

            return self::SUCCESS;
        }

        $this->table(
            ['Booking', 'Event', 'State', 'Uncertain since', 'Uncertain age', 'Requested/Done', 'Next check', 'Last verified', 'Checks', 'Failures', 'Last error', 'Unexpected ID', 'Manual review', 'Warning'],
            $rows->map(fn (GoogleCalendarReconciliation $row): array => [
                $row->booking?->public_id ?? '(missing)',
                $row->event_type,
                $this->state($row),
                $row->uncertain_since?->toDateTimeString() ?? '',
                $row->uncertain_since?->diffForHumans(now(), true) ?? '',
                $row->requested_generation.'/'.$row->completed_generation,
                $row->available_at?->toDateTimeString() ?? '(unset)',
                $row->booking?->googleCalendarEvents->firstWhere('event_type', $row->event_type)?->last_remote_verified_at?->toDateTimeString() ?? 'never',
                $row->convergence_checks,
                $row->convergence_failures,
                $this->safeErrorSummary($row->last_error),
                $row->unexpected_event_id ?? '',
                $row->manual_review_required ? 'yes' : 'no',
                $this->warning($row),
            ])->all(),
        );
        $this->line('Rows shown: '.$rows->count().'; overdue: '.$rows->filter(fn ($row): bool => $this->state($row) === 'overdue')->count());
        return self::SUCCESS;
    }

    private function state(GoogleCalendarReconciliation $row): string
    {
        $mapping = $row->booking?->googleCalendarEvents->firstWhere('event_type', $row->event_type);
        if (($row->lease_token !== null && $row->locked_until?->isFuture())
            || ($mapping?->sync_lease_token !== null && $mapping?->sync_locked_until?->isFuture())) {
            return 'processing';
        }
        if ((int) $row->requested_generation <= (int) $row->completed_generation) {
            return 'idle';
        }

        return $row->available_at?->isFuture() ? 'waiting' : 'overdue';
    }

    private function warning(GoogleCalendarReconciliation $row): string
    {
        $checks = max(1, (int) config('google_calendar.convergence.warning_after_checks', 100));
        $ageDays = max(1, (int) config('google_calendar.convergence.warning_after_days', 7));
        $ageWarning = $row->uncertain_since !== null && $row->uncertain_since->lessThanOrEqualTo(now()->subDays($ageDays));

        return (int) $row->convergence_checks >= $checks || $ageWarning ? 'quota review' : '';
    }

    /** Never render arbitrary remote exception text; retain detailed diagnostics in protected storage. */
    private function safeErrorSummary(?string $error): string
    {
        if ($error === null || trim($error) === '') {
            return '';
        }

        $normalized = strtolower($error);
        if (str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out')) {
            return 'Remote request timed out; outcome may be uncertain';
        }
        if (str_contains($normalized, 'quota') || str_contains($normalized, 'rate limit') || str_contains($normalized, '429')) {
            return 'Remote API quota or rate limit';
        }
        if (str_contains($normalized, 'permission') || str_contains($normalized, 'forbidden') || str_contains($normalized, '403')) {
            return 'Remote API authorization or permission failure';
        }
        if (str_contains($normalized, 'unexpected event id')) {
            return 'Unexpected remote event ID; see the separate ID column';
        }

        return 'Remote operation failed; detailed error withheld from CLI';
    }
}
