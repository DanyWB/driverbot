<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'locale', 'internal_note'])]
#[Hidden(['internal_note'])]
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
}
