<?php

namespace App\Domain\Integrations\GoogleCalendar\Enums;

enum GoogleCalendarSyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Failed = 'failed';
    case DeletePending = 'delete_pending';
    case Deleted = 'deleted';
}
