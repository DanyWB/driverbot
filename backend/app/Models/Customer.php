<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'locale', 'internal_note', 'private_data'])]
#[Hidden(['internal_note', 'private_data'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /** @return HasMany<CustomerContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    /** @return HasMany<CustomerIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasOne<Booking, $this> */
    public function latestBooking(): HasOne
    {
        return $this->hasOne(Booking::class)->latestOfMany();
    }

    /** @return HasMany<CustomerDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    /** @return array<string, mixed> */
    public function privateData(): array
    {
        $value = $this->getAttribute('private_data');

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $value */
    public function replacePrivateData(array $value): void
    {
        $this->setAttribute('private_data', $value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'private_data' => 'encrypted:array',
        ];
    }
}
