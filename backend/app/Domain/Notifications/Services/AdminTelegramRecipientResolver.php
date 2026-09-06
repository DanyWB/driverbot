<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Data\AdminTelegramRecipient;
use App\Domain\Notifications\Exceptions\NotificationDeliveryException;
use App\Domain\Notifications\Exceptions\NotificationNotApplicableException;
use App\Models\AdminTelegramBinding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AdminTelegramRecipientResolver
{
    private const LEGACY_RECIPIENT = 'admin';

    private const BINDING_PATTERN = '/^admin-binding:([1-9][0-9]*):v([0-9]+)$/';

    /** @return Collection<int, AdminTelegramRecipient> */
    public function recipients(?int $excludedAdminId = null): Collection
    {
        if (! $this->bindingsTableExists()) {
            return $excludedAdminId === null ? $this->legacyRecipients() : collect();
        }

        $bindings = $this->activeBindings()
            ->when(
                $excludedAdminId !== null,
                fn (Builder $query): Builder => $query->where('admin_user_id', '<>', $excludedAdminId),
            )
            ->orderBy('id')
            ->get();

        if ($bindings->isNotEmpty()) {
            return $bindings->map(fn (AdminTelegramBinding $binding): AdminTelegramRecipient => $this->fromBinding($binding));
        }

        // Once a DB binding has existed, a tombstone keeps DB mode authoritative.
        // This prevents an old env recipient from silently coming back after unlink.
        return AdminTelegramBinding::query()->exists()
            ? collect()
            : ($excludedAdminId === null ? $this->legacyRecipients() : collect());
    }

    public function resolve(string $recipient): AdminTelegramRecipient
    {
        if ($recipient === self::LEGACY_RECIPIENT) {
            if ($this->bindingsTableExists() && AdminTelegramBinding::query()->exists()) {
                throw new NotificationNotApplicableException(
                    'The legacy Telegram administrator recipient was replaced by database bindings.',
                );
            }

            $legacy = $this->legacyRecipient();

            if (! $legacy instanceof AdminTelegramRecipient) {
                throw NotificationDeliveryException::permanent('Legacy Telegram administrator recipient is not configured.');
            }

            return $legacy;
        }

        if (preg_match(self::BINDING_PATTERN, $recipient, $matches) !== 1) {
            throw NotificationDeliveryException::permanent('Unknown Telegram administrator recipient.');
        }

        if (! $this->bindingsTableExists()) {
            throw new NotificationNotApplicableException('The Telegram administrator binding is unavailable.');
        }

        $binding = $this->activeBindings()
            ->whereKey((int) $matches[1])
            ->where('generation', (int) $matches[2])
            ->first();

        if (! $binding instanceof AdminTelegramBinding) {
            throw new NotificationNotApplicableException('The Telegram administrator binding was superseded or disconnected.');
        }

        return $this->fromBinding($binding);
    }

    public function hasConfiguredRecipient(): bool
    {
        return $this->recipients()->isNotEmpty();
    }

    public function usesLegacyFallback(): bool
    {
        return $this->recipients()->contains(
            fn (AdminTelegramRecipient $recipient): bool => $recipient->bindingId === null,
        );
    }

    /** @return Builder<AdminTelegramBinding> */
    private function activeBindings(): Builder
    {
        return AdminTelegramBinding::query()
            ->whereNotNull('admin_user_id')
            ->whereNotNull('telegram_user_id')
            ->whereNotNull('telegram_chat_id')
            ->whereNotNull('connected_at')
            ->whereNull('disconnected_at')
            ->whereHas('admin', function (Builder $query): void {
                $query->where('is_active', true)->whereNotNull('email_verified_at');
            });
    }

    private function fromBinding(AdminTelegramBinding $binding): AdminTelegramRecipient
    {
        return new AdminTelegramRecipient(
            "admin-binding:{$binding->id}:v{$binding->generation}",
            (string) $binding->telegram_chat_id,
            $this->locale($binding->locale),
            (int) $binding->id,
            (int) $binding->generation,
        );
    }

    /** @return Collection<int, AdminTelegramRecipient> */
    private function legacyRecipients(): Collection
    {
        $recipient = $this->legacyRecipient();

        return $recipient instanceof AdminTelegramRecipient ? collect([$recipient]) : collect();
    }

    private function legacyRecipient(): ?AdminTelegramRecipient
    {
        $chatId = trim((string) config('notifications.telegram.admin_chat_id'));

        if (preg_match('/^-?[1-9][0-9]{4,19}$/', $chatId) !== 1) {
            return null;
        }

        return new AdminTelegramRecipient(
            self::LEGACY_RECIPIENT,
            $chatId,
            $this->locale(config('notifications.telegram.admin_locale', 'ru')),
        );
    }

    private function bindingsTableExists(): bool
    {
        return Schema::hasTable('admin_telegram_bindings');
    }

    private function locale(mixed $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        return in_array($locale, ['ru', 'en', 'ua'], true) ? $locale : 'ru';
    }
}
