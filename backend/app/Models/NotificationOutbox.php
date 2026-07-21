<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['deduplication_key', 'event_type', 'channel', 'recipient', 'payload', 'status', 'attempts', 'available_at', 'sent_at', 'last_error'])]
class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
