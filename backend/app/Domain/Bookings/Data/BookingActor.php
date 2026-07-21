<?php

namespace App\Domain\Bookings\Data;

use App\Domain\Shared\Enums\ActorType;
use InvalidArgumentException;

final readonly class BookingActor
{
    public function __construct(
        public ActorType $type,
        public ?int $adminId = null,
        public ?int $customerId = null,
        public ?int $serviceClientId = null,
    ) {
        $valid = match ($type) {
            ActorType::System => $adminId === null && $customerId === null && $serviceClientId === null,
            ActorType::Admin => $adminId !== null && $adminId > 0 && $customerId === null && $serviceClientId === null,
            ActorType::Customer => $customerId !== null && $customerId > 0 && $adminId === null && $serviceClientId === null,
            ActorType::Service => $serviceClientId !== null && $serviceClientId > 0 && $adminId === null && $customerId === null,
        };

        if (! $valid) {
            throw new InvalidArgumentException('Booking actor identifiers do not match the actor type.');
        }
    }

    public static function system(): self
    {
        return new self(ActorType::System);
    }

    public static function admin(int $adminId): self
    {
        return new self(ActorType::Admin, adminId: $adminId);
    }

    public static function customer(int $customerId): self
    {
        return new self(ActorType::Customer, customerId: $customerId);
    }

    public static function service(int $serviceClientId): self
    {
        return new self(ActorType::Service, serviceClientId: $serviceClientId);
    }
}
