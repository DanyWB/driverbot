<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_id', 'event_type', 'requested_generation', 'completed_generation', 'claimed_generation',
    'available_at', 'lease_token', 'locked_until', 'retry_count', 'unexpected_event_id', 'last_error',
    'uncertain_since', 'last_convergence_at', 'convergence_checks', 'convergence_failures', 'manual_review_required',
])]
class GoogleCalendarReconciliation extends Model
{
    protected $table = 'google_calendar_reconciliations';

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_generation' => 'integer',
            'completed_generation' => 'integer',
            'claimed_generation' => 'integer',
            'retry_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'uncertain_since' => 'immutable_datetime',
            'last_convergence_at' => 'immutable_datetime',
            'convergence_checks' => 'integer',
            'convergence_failures' => 'integer',
            'manual_review_required' => 'boolean',
        ];
    }
}
