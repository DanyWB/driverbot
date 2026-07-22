<?php

namespace App\Domain\Integrations\Bot\Services;

use App\Domain\Customers\Enums\ContactType;
use App\Domain\Customers\Enums\IdentityProvider;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerIdentity;
use Illuminate\Support\Facades\DB;

class TelegramCustomerService
{
    /** @param array<string, mixed> $attributes */
    public function sync(array $attributes): Customer
    {
        $telegramId = (string) $attributes['telegram_id'];

        return DB::transaction(function () use ($telegramId, $attributes): Customer {
            $this->lockIdentityKey($telegramId);
            $identity = CustomerIdentity::query()
                ->where('provider', IdentityProvider::Telegram->value)
                ->where('external_id', $telegramId)
                ->lockForUpdate()
                ->first();

            if (! $identity instanceof CustomerIdentity) {
                $customer = Customer::query()->create([
                    'name' => "Telegram #{$telegramId}",
                    'locale' => $this->locale($attributes['locale'] ?? null),
                    'private_data' => [
                        'name_completed' => false,
                        'language_completed' => false,
                    ],
                ]);
                $identity = $customer->identities()->create([
                    'provider' => IdentityProvider::Telegram,
                    'external_id' => $telegramId,
                    'metadata' => [],
                ]);
            } else {
                $customer = Customer::query()->lockForUpdate()->findOrFail($identity->customer_id);
            }

            $metadata = $identity->metadataValues();
            $identity->forceFill([
                'metadata' => array_filter([
                    ...$metadata,
                    'username' => $this->nullable($attributes['username'] ?? null),
                    'first_name' => $this->nullable($attributes['first_name'] ?? null),
                    'last_name' => $this->nullable($attributes['last_name'] ?? null),
                ], fn (mixed $value): bool => $value !== null),
            ])->save();

            if (array_key_exists('username', $attributes)) {
                $this->syncContact($customer, ContactType::TelegramUsername, $attributes['username']);
            }

            return $customer->fresh(['contacts', 'identities', 'documents']) ?? $customer;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Customer $customer, array $attributes): Customer
    {
        return DB::transaction(function () use ($customer, $attributes): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $private = $customer->privateData();

            if (array_key_exists('name', $attributes)) {
                $customer->name = trim((string) $attributes['name']);
                $private['name_completed'] = true;
            }

            if (array_key_exists('locale', $attributes)) {
                $customer->locale = $this->locale($attributes['locale']);
                $private['language_completed'] = true;
            }

            if (array_key_exists('passport_number', $attributes)) {
                $passport = $this->nullable($attributes['passport_number']);

                if ($passport === null) {
                    unset($private['passport_number']);
                } else {
                    $private['passport_number'] = $passport;
                }
            }

            $customer->replacePrivateData($private);
            $customer->save();

            if (array_key_exists('phone', $attributes)) {
                $this->syncContact($customer, ContactType::Phone, $attributes['phone']);
            }

            if (array_key_exists('username', $attributes)) {
                $this->syncContact($customer, ContactType::TelegramUsername, $attributes['username']);
            }

            return $customer->fresh(['contacts', 'identities', 'documents']) ?? $customer;
        }, 3);
    }

    private function syncContact(Customer $customer, ContactType $type, mixed $value): void
    {
        $value = $this->nullable($value);
        $contact = $customer->contacts()->where('type', $type->value)->where('is_primary', true)->first();

        if ($value === null) {
            $contact?->delete();

            return;
        }

        $normalized = $type === ContactType::Phone
            ? $this->normalizePhone($value)
            : mb_strtolower(ltrim($value, '@'));
        $display = $type === ContactType::TelegramUsername ? '@'.ltrim($value, '@') : $value;

        if ($contact instanceof CustomerContact) {
            $contact->forceFill(['value' => $display, 'normalized_value' => $normalized])->save();

            return;
        }

        $customer->contacts()->create([
            'type' => $type,
            'value' => $display,
            'normalized_value' => $normalized,
            'is_primary' => true,
        ]);
    }

    private function lockIdentityKey(string $telegramId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["telegram:{$telegramId}"]);
        }
    }

    private function locale(mixed $locale): string
    {
        $locale = explode('-', strtolower(trim((string) $locale)))[0];

        if ($locale === 'uk') {
            return 'ua';
        }

        return in_array($locale, ['ru', 'en', 'ua'], true) ? $locale : 'ru';
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with(trim($phone), '+') ? "+{$digits}" : $digits;
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
