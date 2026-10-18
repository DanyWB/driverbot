<?php

namespace App\Domain\Administration\Services;

use App\Actions\Fortify\ResetUserPassword;
use App\Domain\Notifications\Data\RenderedTelegramMessage;
use App\Domain\Notifications\Services\TelegramClient;
use App\Domain\Shared\Enums\ActorType;
use App\Jobs\NotifyTelegramPasswordChanged;
use App\Models\AdminTelegramBinding;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class TelegramPasswordRecovery
{
    public const TTL_MINUTES = 15;

    public function __construct(private readonly TelegramClient $telegram) {}

    public function deliver(string $email, int $requestedAt): void
    {
        // Do not deliver old queued requests after an outage.
        if ($requestedAt < now()->subMinutes(5)->getTimestamp()) {
            return;
        }

        try {
            DB::transaction(function () use ($email): void {
                $admin = User::query()->where('email', $email)->lockForUpdate()->first();
                if (! $this->eligible($admin)) {
                    return;
                }

                $binding = $admin->telegramBinding()->lockForUpdate()->first();
                if (! $this->privateBinding($binding)) {
                    return;
                }

                // Use Laravel's hashed, expiring, throttled token repository.
                Password::broker()->sendResetLink(['email' => $email], function (User $user, string $token) use ($binding): void {
                    $envelope = rtrim(strtr(Crypt::encryptString(json_encode([
                        'token' => $token,
                        'admin_id' => $user->id,
                        'email' => $user->email,
                        'binding_id' => $binding->id,
                        'generation' => $binding->generation,
                        'password' => hash('sha256', $user->password),
                        'expires' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
                    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
                    // APP_URL is trusted configuration, never the incoming Host header.
                    $url = rtrim((string) config('app.url'), '/').'/reset-password/'.rawurlencode($envelope)
                        .'?'.http_build_query(['email' => $user->email]);
                    $locale = in_array($binding->locale, ['ru', 'ua'], true) ? $binding->locale : 'en';
                    $this->telegram->send(new RenderedTelegramMessage(
                        $binding->telegram_chat_id,
                        (string) __('recovery.telegram_message', ['minutes' => self::TTL_MINUTES], $locale),
                        ['inline_keyboard' => [[[
                            'text' => (string) __('recovery.button', [], $locale),
                            'url' => $url,
                        ]]]],
                    ));
                });
            });
        } catch (Throwable) {
            // HTTP exceptions may contain the bot token or recovery URL: never log them.
            Log::warning('Telegram password recovery delivery failed; no password was changed.');
        }
    }

    /** @param array{email: string, token: string, password: string, password_confirmation: string} $input */
    public function reset(#[\SensitiveParameter] array $input): string
    {
        try {
            $payload = json_decode(Crypt::decryptString(strtr($input['token'], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return Password::INVALID_TOKEN;
        }

        if (! is_array($payload)
            || ! is_int($payload['admin_id'] ?? null)
            || ! is_int($payload['binding_id'] ?? null)
            || ! is_int($payload['generation'] ?? null)
            || ! is_int($payload['expires'] ?? null)
            || ! is_string($payload['token'] ?? null)
            || ! is_string($payload['password'] ?? null)
            || ($payload['email'] ?? null) !== $input['email']
            || $payload['expires'] <= now()->getTimestamp()) {
            return Password::INVALID_TOKEN;
        }

        // Same lock order as binding lifecycle. Serializes token consumption as well.
        return DB::transaction(function () use ($input, $payload): string {
            $admin = User::query()->lockForUpdate()->find($payload['admin_id']);
            if (! $this->eligible($admin) || $admin->email !== $input['email']
                || ! hash_equals($payload['password'], hash('sha256', $admin->password))) {
                return Password::INVALID_TOKEN;
            }
            $binding = $admin->telegramBinding()->lockForUpdate()->first();
            if (! $this->privateBinding($binding)
                || $binding->id !== $payload['binding_id']
                || $binding->generation !== $payload['generation']) {
                return Password::INVALID_TOKEN;
            }

            return Password::broker()->reset(
                [...$input, 'token' => $payload['token']],
                function (User $user) use ($input, $binding): void {
                    app(ResetUserPassword::class)->reset($user, $input);
                    $user->forceFill([
                        'remember_token' => Str::random(60),
                        'session_version' => $user->session_version + 1,
                    ])->save();
                    AuditLog::query()->create([
                        'actor_type' => ActorType::System,
                        'subject_type' => User::class,
                        'subject_id' => $user->id,
                        'action' => 'admin.password_reset_via_telegram',
                        'new_values' => ['binding_id' => $binding->id, 'generation' => $binding->generation],
                        'created_at' => now(),
                    ]);
                    DB::afterCommit(function () use ($user, $binding): void {
                        try {
                            event(new PasswordReset($user));
                            NotifyTelegramPasswordChanged::dispatch($user->id, $binding->id, $binding->generation);
                        } catch (Throwable) {
                            // The password is committed: an unavailable queue must not report a failed reset.
                            Log::warning('Password was reset but its follow-up notification could not be queued.');
                        }
                    });
                },
            );
        });
    }

    public function notifyChanged(int $adminId, int $bindingId, int $generation): void
    {
        try {
            DB::transaction(function () use ($adminId, $bindingId, $generation): void {
                $admin = User::query()->lockForUpdate()->find($adminId);
                if (! $this->eligible($admin)) {
                    return;
                }
                $binding = $admin->telegramBinding()->lockForUpdate()->first();
                if (! $this->privateBinding($binding) || $binding->id !== $bindingId || $binding->generation !== $generation) {
                    return;
                }
                $locale = in_array($binding->locale, ['ru', 'ua'], true) ? $binding->locale : 'en';
                $this->telegram->send(new RenderedTelegramMessage(
                    $binding->telegram_chat_id,
                    (string) __('recovery.changed', [], $locale),
                ));
            });
        } catch (Throwable) {
            Log::warning('Telegram password change notice delivery failed.');
        }
    }

    /** @phpstan-assert-if-true User $admin */
    private function eligible(?User $admin): bool
    {
        return $admin !== null && $admin->is_active && $admin->email_verified_at !== null;
    }

    /** @phpstan-assert-if-true AdminTelegramBinding $binding */
    private function privateBinding(?AdminTelegramBinding $binding): bool
    {
        return $binding !== null && $binding->isConnected()
            && preg_match('/^[1-9][0-9]{0,19}$/', (string) $binding->telegram_user_id) === 1
            && $binding->telegram_user_id === $binding->telegram_chat_id;
    }
}
