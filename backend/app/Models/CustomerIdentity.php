<?php

namespace App\Models;

use App\Domain\Customers\Enums\IdentityProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'provider', 'external_id', 'metadata'])]
class CustomerIdentity extends Model
{
    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => IdentityProvider::class,
            'metadata' => 'array',
        ];
    }
}
