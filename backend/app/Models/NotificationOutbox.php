<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['deduplication_key', 'event_type', 'channel', 'recipient', 'payload', 'status', 'attempts', 'available_at', 'processing_started_at', 'sent_at', 'failed_at', 'discarded_at', 'provider_message_id', 'last_error'])]
class NotificationOutbox extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISCARDED = 'discarded';

    protected $table = 'notification_outbox';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }
}
