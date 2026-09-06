<?php

namespace App\Domain\Administration\Services;

use App\Domain\Administration\Data\AdminTelegramBindingMutation;
use App\Domain\Administration\Data\IssuedAdminTelegramBindingCode;
use App\Domain\Administration\Exceptions\AdminTelegramBindingException;
use App\Models\AdminTelegramBinding;
use App\Models\AdminTelegramBindingCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

class AdminTelegramBindingService
{
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const CODE_LENGTH = 8;

    public function issueCode(User $admin): IssuedAdminTelegramBindingCode
    {
        return DB::transaction(function () use ($admin): IssuedAdminTelegramBindingCode {
            $lockedAdmin = User::query()
                ->whereKey($admin->getKey())
                ->lockForUpdate()
                ->first();

            if (! $this->eligibleAdmin($lockedAdmin)) {
                throw AdminTelegramBindingException::invalidCode();
            }

            AdminTelegramBindingCode::query()
                ->where('admin_user_id', $lockedAdmin->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $code = $this->newCode();
            $expiresAt = now()->addMinutes($this->codeTtlMinutes());
            AdminTelegramBindingCode::query()->create([
                'admin_user_id' => $lockedAdmin->id,
                'code_hash' => $this->codeHash($code),
                'expires_at' => $expiresAt,
            ]);

            return new IssuedAdminTelegramBindingCode($code, $expiresAt);
        }, 3);
    }

    /**
     * @param array{
     *     code: string,
     *     telegram_id: string,
     *     chat_id: string,
     *     chat_type: string,
     *     username?: string|null,
     *     first_name?: string|null,
     *     last_name?: string|null,
     *     locale?: string|null
     * } $attributes
     */
    public function connect(array $attributes): AdminTelegramBindingMutation
    {
        $hash = $this->codeHash($attributes['code']);
        $candidate = AdminTelegramBindingCode::query()->where('code_hash', $hash)->first();

        if (! $candidate instanceof AdminTelegramBindingCode) {
            throw AdminTelegramBindingException::invalidCode();
        }

        try {
            return DB::transaction(function () use ($attributes, $candidate, $hash): AdminTelegramBindingMutation {
                $admin = User::query()->lockForUpdate()->find($candidate->admin_user_id);
                $code = AdminTelegramBindingCode::query()
                    ->where('code_hash', $hash)
                    ->lockForUpdate()
                    ->first();

                if (! $code instanceof AdminTelegramBindingCode
                    || ! $this->eligibleAdmin($admin)
                    || (int) $code->admin_user_id !== (int) $admin->id
                    || $code->consumed_at !== null
                    || $code->revoked_at !== null
                    || $code->expires_at->isPast()) {
                    throw AdminTelegramBindingException::invalidCode();
                }

                $telegramId = $attributes['telegram_id'];
                $this->lockTelegramIdentityKey($telegramId);

                $conflict = AdminTelegramBinding::query()
                    ->where(function ($query) use ($telegramId): void {
                        $query->where('telegram_user_id', $telegramId)
                            ->orWhere('telegram_chat_id', $telegramId);
                    })
                    ->where(function ($query) use ($admin): void {
                        $query->whereNull('admin_user_id')
                            ->orWhere('admin_user_id', '<>', $admin->id);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($conflict instanceof AdminTelegramBinding) {
                    throw AdminTelegramBindingException::telegramAccountAlreadyBound();
                }

                $binding = AdminTelegramBinding::query()
                    ->where('admin_user_id', $admin->id)
                    ->lockForUpdate()
                    ->first();
                $oldValues = $binding instanceof AdminTelegramBinding
                    ? $this->snapshot($binding)
                    : null;

                if (! $binding instanceof AdminTelegramBinding) {
                    $binding = new AdminTelegramBinding(['admin_user_id' => $admin->id]);
                }

                $binding->forceFill([
                    'admin_user_id' => $admin->id,
                    'telegram_user_id' => $telegramId,
                    'telegram_chat_id' => $attributes['chat_id'],
                    'username' => $this->username($attributes['username'] ?? null),
                    'first_name' => $this->nullableString($attributes['first_name'] ?? null),
                    'last_name' => $this->nullableString($attributes['last_name'] ?? null),
                    'locale' => $this->locale($attributes['locale'] ?? null),
                    'generation' => max(0, (int) $binding->generation) + 1,
                    'connected_at' => now(),
                    'disconnected_at' => null,
                    'last_tested_at' => null,
                ])->save();

                $code->forceFill(['consumed_at' => now()])->save();
                AdminTelegramBindingCode::query()
                    ->where('admin_user_id', $admin->id)
                    ->where('id', '<>', $code->id)
                    ->whereNull('consumed_at')
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);

                return new AdminTelegramBindingMutation($binding->refresh(), $oldValues);
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            throw AdminTelegramBindingException::telegramAccountAlreadyBound();
        }
    }

    public function disconnect(User $admin): ?AdminTelegramBindingMutation
    {
        return DB::transaction(function () use ($admin): ?AdminTelegramBindingMutation {
            $lockedAdmin = User::query()
                ->whereKey($admin->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedAdmin instanceof User) {
                return null;
            }

            AdminTelegramBindingCode::query()
                ->where('admin_user_id', $lockedAdmin->id)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            $binding = AdminTelegramBinding::query()
                ->where('admin_user_id', $lockedAdmin->id)
                ->lockForUpdate()
                ->first();

            if (! $binding instanceof AdminTelegramBinding || ! $binding->isConnected()) {
                return null;
            }

            $oldValues = $this->snapshot($binding);
            $binding->forceFill([
                'telegram_user_id' => null,
                'telegram_chat_id' => null,
                'username' => null,
                'first_name' => null,
                'last_name' => null,
                'locale' => null,
                'generation' => max(0, (int) $binding->generation) + 1,
                'disconnected_at' => now(),
                'last_tested_at' => null,
            ])->save();

            return new AdminTelegramBindingMutation($binding->refresh(), $oldValues);
        }, 3);
    }

    public function revokeExposedCode(string $plainCode): ?AdminTelegramBindingCode
    {
        $hash = $this->codeHash($plainCode);

        return DB::transaction(function () use ($hash): ?AdminTelegramBindingCode {
            $code = AdminTelegramBindingCode::query()
                ->where('code_hash', $hash)
                ->lockForUpdate()
                ->first();

            if (! $code instanceof AdminTelegramBindingCode
                || $code->consumed_at !== null
                || $code->revoked_at !== null) {
                return null;
            }

            $code->forceFill(['revoked_at' => now()])->save();

            return $code->refresh();
        }, 3);
    }

    public function connectedBinding(User $admin): ?AdminTelegramBinding
    {
        $binding = AdminTelegramBinding::query()
            ->where('admin_user_id', $admin->id)
            ->whereNotNull('telegram_user_id')
            ->whereNotNull('telegram_chat_id')
            ->whereNotNull('connected_at')
            ->whereNull('disconnected_at')
            ->first();

        return $binding instanceof AdminTelegramBinding ? $binding : null;
    }

    /** @return array<string, mixed> */
    public function snapshot(AdminTelegramBinding $binding): array
    {
        return [
            'admin_user_id' => $binding->admin_user_id,
            'telegram_user_id' => $binding->telegram_user_id,
            'telegram_chat_id' => $binding->telegram_chat_id,
            'username' => $binding->username,
            'first_name' => $binding->first_name,
            'last_name' => $binding->last_name,
            'locale' => $binding->locale,
            'generation' => (int) $binding->generation,
            'connected_at' => $binding->connected_at?->toIso8601String(),
            'disconnected_at' => $binding->disconnected_at?->toIso8601String(),
            'last_tested_at' => $binding->last_tested_at?->toIso8601String(),
        ];
    }

    private function eligibleAdmin(?User $admin): bool
    {
        return $admin instanceof User
            && $admin->is_active
            && $admin->email_verified_at !== null;
    }

    private function newCode(): string
    {
        $raw = '';
        $lastIndex = strlen(self::CODE_ALPHABET) - 1;

        for ($index = 0; $index < self::CODE_LENGTH; $index++) {
            $raw .= self::CODE_ALPHABET[random_int(0, $lastIndex)];
        }

        return substr($raw, 0, 4).'-'.substr($raw, 4);
    }

    private function codeHash(string $code): string
    {
        $normalized = strtoupper(str_replace('-', '', trim($code)));
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new LogicException('APP_KEY is required to hash Telegram binding codes.');
        }

        return hash_hmac('sha256', $normalized, $secret);
    }

    private function codeTtlMinutes(): int
    {
        return min(60, max(1, (int) config('notifications.telegram.binding_code_ttl_minutes', 10)));
    }

    private function lockTelegramIdentityKey(string $telegramId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["admin-telegram:{$telegramId}"]);
        }
    }

    private function username(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : ltrim($value, '@');
    }

    private function locale(mixed $value): string
    {
        $locale = explode('-', strtolower(trim((string) $value)))[0];

        if ($locale === 'uk') {
            return 'ua';
        }

        if (in_array($locale, ['ru', 'en', 'ua'], true)) {
            return $locale;
        }

        $fallback = strtolower(trim((string) config('notifications.telegram.admin_locale', 'ru')));

        return in_array($fallback, ['ru', 'en', 'ua'], true) ? $fallback : 'ru';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}
