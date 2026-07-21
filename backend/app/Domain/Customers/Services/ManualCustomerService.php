<?php

namespace App\Domain\Customers\Services;

use App\Domain\Bookings\Exceptions\BookingException;
use App\Domain\Customers\Enums\ContactType;
use App\Models\Customer;

class ManualCustomerService
{
    public function resolve(
        ?int $customerId,
        ?string $name,
        ?string $phone = null,
        ?string $telegramUsername = null,
    ): Customer {
        if ($customerId !== null) {
            $customer = Customer::query()->find($customerId);

            if (! $customer instanceof Customer) {
                throw new BookingException('customer_not_found', 'Customer was not found.', 404);
            }

            return $customer;
        }

        $name = trim((string) $name);

        if ($name === '') {
            throw new BookingException('customer_name_required', 'Customer name is required.', 422);
        }

        $customer = Customer::query()->create([
            'name' => $name,
            'locale' => 'en',
        ]);

        $this->addContact($customer, ContactType::Phone, $phone);
        $this->addContact($customer, ContactType::TelegramUsername, $telegramUsername);

        return $customer;
    }

    private function addContact(Customer $customer, ContactType $type, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value === '') {
            return;
        }

        $normalized = match ($type) {
            ContactType::Phone => $this->normalizePhone($value),
            ContactType::TelegramUsername => mb_strtolower(ltrim($value, '@')),
            default => mb_strtolower($value),
        };

        $customer->contacts()->create([
            'type' => $type,
            'value' => $type === ContactType::TelegramUsername ? '@'.ltrim($value, '@') : $value,
            'normalized_value' => $normalized,
            'is_primary' => true,
        ]);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with(trim($phone), '+') ? "+{$digits}" : $digits;
    }
}
