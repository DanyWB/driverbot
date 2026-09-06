<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $admin_user_id
 * @property string|null $telegram_user_id
 * @property string|null $telegram_chat_id
 * @property string|null $username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $locale
 * @property int $generation
 * @property Carbon|null $connected_at
 * @property Carbon|null $disconnected_at
 * @property Carbon|null $last_tested_at
 */
#[Fillable(['admin_user_id', 'telegram_user_id', 'telegram_chat_id', 'username', 'first_name', 'last_name', 'locale', 'generation', 'connected_at', 'disconnected_at', 'last_tested_at'])]
class AdminTelegramBinding extends Model
{
    /** @return BelongsTo<User, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function isConnected(): bool
    {
        return $this->admin_user_id !== null
            && $this->connected_at !== null
            && $this->disconnected_at === null
            && is_string($this->telegram_user_id)
            && $this->telegram_user_id !== ''
            && is_string($this->telegram_chat_id)
            && $this->telegram_chat_id !== '';
    }

    public function recipientKey(): string
    {
        return "admin-binding:{$this->getKey()}:v{$this->generation}";
    }

    /** @param Builder<AdminTelegramBinding> $query */
    public function scopeConnected(Builder $query): void
    {
        $query->whereNotNull('admin_user_id')
            ->whereNotNull('telegram_user_id')
            ->whereNotNull('telegram_chat_id')
            ->whereNotNull('connected_at')
            ->whereNull('disconnected_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }
}
