<?php

namespace App\Models;

use App\Domain\Imports\Enums\ImportRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'source_filename', 'source_sha256', 'status', 'summary', 'created_by_admin_id', 'started_at', 'finished_at'])]
class DataImportRun extends Model
{
    use HasUuids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ImportRunStatus::class,
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
